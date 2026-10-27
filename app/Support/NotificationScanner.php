<?php

namespace App\Support;

use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\PeriodeOpname;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\OpnameDetail;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\Request as ModelsRequest;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Menerjemahkan keadaan gudang menjadi notifikasi.
 *
 * Seluruh method `periksa*` bersifat idempoten: ia menyatakan keadaan apa yang
 * sedang benar, bukan menambahkan baris. Menjalankannya dua kali berturut-turut
 * tidak menghasilkan apa pun yang berbeda dari menjalankannya sekali.
 *
 * Sifat itu punya konsekuensi rancangan yang penting: pemeriksaan reaktif —
 * yang dipanggil dari controller setelah stok berubah — hanyalah **percepatan**,
 * bukan syarat kebenaran. Bila sebuah jalur perubahan stok lupa memanggilnya
 * (misalnya modul lain yang memakai StockLedger), notifikasinya tetap muncul
 * pada pemeriksaan terjadwal berikutnya. Yang hilang hanya kesegeraan, bukan
 * datanya.
 *
 * Karena itu hook tidak dipasang di dalam StockLedger. Selain menjaga kontrak
 * lintas sub sistem itu tetap sesederhana mungkin, `DB::afterCommit()` tidak
 * pernah dijalankan di bawah RefreshDatabase — seluruh uji dibungkus transaksi
 * yang tidak pernah commit — sehingga fiturnya justru menjadi tak teruji.
 */
class NotificationScanner
{
    /** Draf opname dianggap menggantung setelah sekian hari tanpa selesai. */
    private const HARI_DRAF_MENGGANTUNG = 7;

    /** Batas barang dianggap tidak bergerak. */
    private const HARI_TIDAK_BERGERAK = 180;

    /** Seberapa awal periode yang akan berakhir mulai diingatkan. */
    private const HARI_JELANG_AKHIR_PERIODE = 30;

    /** Usulan pengadaan dianggap menggantung setelah sekian hari belum diputuskan. */
    private const HARI_USULAN_MENGGANTUNG = 7;

    /**
     * Usulan yang sudah disetujui dianggap menganggur setelah sekian hari
     * belum ditindaklanjuti menjadi realisasi. Disamakan dengan ambang usulan
     * menggantung: keduanya menagih hal yang sama — dokumen yang berhenti di
     * meja seseorang.
     */
    private const HARI_USULAN_BELUM_DIREALISASI = 7;

    /**
     * Realisasi yang sudah disetujui dianggap terlupakan setelah sekian hari
     * belum juga ditandai sudah dibelanjakan. Ambangnya sama dengan usulan
     * yang belum direalisasi, karena yang ditagih pun sama: dokumen yang
     * berhenti di meja Pejabat Pengadaan (ADR 0035).
     */
    private const HARI_REALISASI_BELUM_DIBELANJAKAN = 7;

    /**
     * Jeda wajar antara barang diterima dan BAST bertanda tangan diunggah.
     *
     * Sengaja tidak nol: urutan fisiknya cetak → tanda tangan → pindai →
     * unggah, dan ADR 0018 memang membiarkan berkasnya menyusul supaya stok
     * tidak tertahan di belakang alur pemindaian. Yang diingatkan adalah
     * kelalaian, bukan jeda yang normal.
     */
    private const HARI_BAST_BELUM_DIUNGGAH = 3;

    /**
     * Jenis mutasi manual yang mengurangi stok tanpa dokumen sumber. Pada
     * instansi pemeriksa, justru inilah yang paling layak diketahui Admin.
     */
    private const MUTASI_SENSITIF = [
        MutationType::BarangRusak,
        MutationType::BarangHilang,
        MutationType::Kadaluarsa,
        MutationType::KoreksiPencatatan,
    ];

    /**
     * Seluruh pemeriksaan berkala. Dipanggil perintah `notifikasi:periksa`.
     */
    public static function periksaSemua(): void
    {
        self::periksaSeluruhBarang();
        self::periksaBarangTidakBergerak();
        self::periksaOpname();
        self::periksaPeriodeOpname();
        self::periksaSeluruhUsulan();
        self::periksaSeluruhRealisasi();
        self::periksaSeluruhUsulanBarang();
    }

    /**
     * Keadaan stok satu barang. Dipanggil setelah stok atau ambang batasnya
     * berubah supaya notifikasinya muncul dan menghilang seketika.
     *
     * @param  EloquentCollection<int, User>|null  $penerima
     */
    public static function periksaBarang(Item $item, ?EloquentCollection $penerima = null): void
    {
        $penerima ??= self::pengawasGudang();

        // Barang yang diarsipkan tidak lagi dilayani, jadi kekurangan stoknya
        // bukan lagi hal yang perlu ditindak. Keadaan yang tersisa ditutup.
        $status = $item->is_active ? $item->stockStatus() : null;

        $nama = "{$item->item_code} — {$item->item_name}";
        $satuan = $item->unit->label();
        $tautan = route('items.show', $item, false);

        Notifier::terapkanKeadaan(
            berlaku: $status === StockStatus::Habis,
            penerima: $penerima,
            type: NotificationType::StokHabis,
            dedupKey: NotificationType::StokHabis->dedupKey($item->item_id),
            title: "Stok habis: {$item->item_name}",
            body: "{$nama} tidak bersisa sehingga permintaan unit tidak dapat dilayani. Ajukan usulan pengadaan, atau arsipkan barang bila memang tidak dipakai lagi.",
            actionUrl: $tautan,
            subject: $item,
        );

        Notifier::terapkanKeadaan(
            berlaku: $status === StockStatus::Menipis,
            penerima: $penerima,
            type: NotificationType::StokMenipis,
            dedupKey: NotificationType::StokMenipis->dedupKey($item->item_id),
            title: "Stok menipis: {$item->item_name}",
            body: sprintf(
                '%s tersisa %s %s, sudah menyentuh batas minimum %s %s. Pertimbangkan usulan pengadaan sebelum habis.',
                $nama,
                number_format($item->stock_current, 0, ',', '.'),
                $satuan,
                number_format($item->stock_min, 0, ',', '.'),
                $satuan,
            ),
            actionUrl: $tautan,
            subject: $item,
        );

        Notifier::terapkanKeadaan(
            berlaku: $status === StockStatus::Berlebih,
            penerima: $penerima,
            type: NotificationType::StokBerlebih,
            dedupKey: NotificationType::StokBerlebih->dedupKey($item->item_id),
            title: "Stok berlebih: {$item->item_name}",
            body: sprintf(
                '%s tersimpan %s %s, melampaui batas maksimum %s %s. Tinjau kembali kebutuhan supaya tidak menumpuk.',
                $nama,
                number_format($item->stock_current, 0, ',', '.'),
                $satuan,
                number_format($item->stock_max, 0, ',', '.'),
                $satuan,
            ),
            actionUrl: $tautan,
            subject: $item,
        );
    }

    /**
     * Keadaan stok seluruh barang, termasuk yang sudah diarsipkan supaya
     * notifikasi lamanya ikut ditutup.
     */
    public static function periksaSeluruhBarang(): void
    {
        $penerima = self::pengawasGudang();

        Item::query()->orderBy('item_code')->chunk(200, function ($items) use ($penerima) {
            foreach ($items as $item) {
                self::periksaBarang($item, $penerima);
            }
        });
    }

    /**
     * Barang bersisa yang tidak pernah bermutasi dalam waktu lama — calon
     * barang mati yang layak dievaluasi atau diarsipkan.
     */
    public static function periksaBarangTidakBergerak(): void
    {
        $penerima = self::pengawasGudang();
        $batas = now()->subDays(self::HARI_TIDAK_BERGERAK);

        // Barang yang stoknya nol sengaja dikecualikan: ia sudah ditandai
        // sebagai stok habis, dan dua notifikasi untuk satu barang hanya
        // menambah bising tanpa menambah informasi.
        $mati = Item::query()
            ->aktif()
            ->where('stock_current', '>', 0)
            ->where('created_at', '<', $batas)
            ->whereDoesntHave('stockMutations', fn ($query) => $query->where('created_at', '>=', $batas))
            ->pluck('item_id')
            ->all();

        Item::query()->where('stock_current', '>', 0)->chunk(200, function ($items) use ($penerima, $mati) {
            foreach ($items as $item) {
                Notifier::terapkanKeadaan(
                    berlaku: in_array($item->item_id, $mati, true),
                    penerima: $penerima,
                    type: NotificationType::BarangTidakBergerak,
                    dedupKey: NotificationType::BarangTidakBergerak->dedupKey($item->item_id),
                    title: "Barang tidak bergerak: {$item->item_name}",
                    body: sprintf(
                        '%s — %s tidak mengalami mutasi apa pun selama lebih dari %d bulan, padahal masih tersimpan %s %s. Tinjau apakah barang ini masih dibutuhkan.',
                        $item->item_code,
                        $item->item_name,
                        (int) round(self::HARI_TIDAK_BERGERAK / 30),
                        number_format($item->stock_current, 0, ',', '.'),
                        $item->unit->label(),
                    ),
                    actionUrl: route('items.show', $item, false),
                    subject: $item,
                );
            }
        });
    }

    /**
     * Keadaan tiap dokumen opname: siap diposting, atau menggantung terlalu
     * lama. Opname yang sudah selesai menutup keduanya.
     */
    public static function periksaOpname(): void
    {
        $admin = Notifier::penerimaBerperan(UserRole::Admin);
        $batas = now()->subDays(self::HARI_DRAF_MENGGANTUNG);

        StockOpname::query()->with('user')->chunk(100, function ($opnames) use ($admin, $batas) {
            foreach ($opnames as $opname) {
                $siap = $opname->siapDiposting();

                // Menggantung dan siap diposting dibuat saling eksklusif: draf
                // yang sudah lengkap butuh satu ajakan yang jelas, bukan dua
                // notifikasi tentang dokumen yang sama.
                $menggantung = $opname->isDraf() && ! $siap && $opname->created_at->lt($batas);

                $pembuat = $opname->user;
                $penerimaSiap = $pembuat !== null ? new EloquentCollection([$pembuat]) : new EloquentCollection;
                $penerimaMenggantung = $pembuat !== null
                    ? $admin->concat([$pembuat])->unique('user_id')
                    : $admin;

                $tautan = route('opname.edit', $opname, false);

                Notifier::terapkanKeadaan(
                    berlaku: $siap,
                    penerima: $penerimaSiap,
                    type: NotificationType::OpnameSiapDiposting,
                    dedupKey: NotificationType::OpnameSiapDiposting->dedupKey($opname->opname_id),
                    title: "Siap diposting: {$opname->judul()}",
                    body: "Seluruh barang pada {$opname->judul()} sudah dicacah. Periksa sekali lagi lalu posting supaya penyesuaian masuk ke buku mutasi.",
                    actionUrl: $tautan,
                    subject: $opname,
                );

                Notifier::terapkanKeadaan(
                    berlaku: $menggantung,
                    penerima: $penerimaMenggantung,
                    type: NotificationType::OpnameDrafMenggantung,
                    dedupKey: NotificationType::OpnameDrafMenggantung->dedupKey($opname->opname_id),
                    title: "Draf menggantung: {$opname->judul()}",
                    body: sprintf(
                        'Draf %s dibuat %s dan masih menyisakan %d barang yang belum dicacah. Lanjutkan pencacahan atau hapus drafnya.',
                        $opname->judul(),
                        $opname->created_at->diffForHumans(),
                        $opname->ringkasan()['belum_dicacah'],
                    ),
                    actionUrl: $tautan,
                    subject: $opname,
                );
            }
        });
    }

    /**
     * Triwulan berjalan yang akan atau sudah berakhir tanpa dokumen opname.
     *
     * Hanya triwulan yang diperiksa: semester, tahunan, dan insidental tidak
     * punya jadwal yang bisa disimpulkan sendiri oleh sistem.
     */
    public static function periksaPeriodeOpname(): void
    {
        $penerima = self::pengawasGudang();
        $tahun = (int) now()->year;

        foreach (self::akhirTriwulan($tahun) as $periode => $akhir) {
            $periode = PeriodeOpname::from($periode);

            // Triwulan yang belum mendekati akhirnya belum perlu diingatkan.
            $jatuhTempo = now()->startOfDay()
                ->gte($akhir->copy()->subDays(self::HARI_JELANG_AKHIR_PERIODE));

            $sudahAda = StockOpname::query()
                ->where('period_type', $periode)
                ->where('period_year', $tahun)
                ->exists();

            $terlambat = now()->startOfDay()->gt($akhir);

            Notifier::terapkanKeadaan(
                berlaku: $jatuhTempo && ! $sudahAda,
                penerima: $penerima,
                type: NotificationType::PeriodeBelumDiopname,
                dedupKey: NotificationType::PeriodeBelumDiopname->dedupKey("{$tahun}:{$periode->value}"),
                title: sprintf('Opname %s %d belum dibuat', $periode->label(), $tahun),
                body: sprintf(
                    '%s %d (%s) %s pada %s dan belum ada dokumen stok opname. Buat drafnya agar pencacahan sempat diselesaikan.',
                    $periode->label(),
                    $tahun,
                    rtrim($periode->description(), '.'),
                    $terlambat ? 'sudah berakhir' : 'akan berakhir',
                    $akhir->translatedFormat('j F Y'),
                ),
                actionUrl: route('opname.create', absolute: false),
            );
        }
    }

    /**
     * Keadaan satu usulan pengadaan sepanjang hidupnya: menunggu persetujuan,
     * menggantung terlalu lama tanpa diputuskan, atau sudah disetujui tetapi
     * belum ditindaklanjuti menjadi realisasi. Dipanggil setelah usulan
     * diajukan, diputuskan, atau direalisasi supaya notifikasinya muncul dan
     * menghilang seketika.
     *
     * Dua keadaan pertama dibuat saling eksklusif seperti pasangan
     * OpnameSiapDiposting/OpnameDrafMenggantung: usulan yang sama tidak perlu
     * dua notifikasi berbeda tentang dokumen yang belum diputuskan. Keadaan
     * ketiga tidak mungkin bertabrakan dengan keduanya karena menuntut usulan
     * sudah `Disetujui`.
     *
     * @param  EloquentCollection<int, User>|null  $penerima
     */
    public static function periksaUsulan(ProcurementRequest $procurementRequest, ?EloquentCollection $penerima = null): void
    {
        $penerima ??= self::pejabatPengadaan();

        $menunggu = $procurementRequest->isDiajukan();
        $kadaluarsa = $procurementRequest->created_at->lt(now()->subDays(self::HARI_USULAN_MENGGANTUNG));

        $judul = $procurementRequest->judul();
        $tautan = route('procurement-requests.show', $procurementRequest, false);
        $pengaju = $procurementRequest->user;

        Notifier::terapkanKeadaan(
            berlaku: $menunggu && ! $kadaluarsa,
            penerima: $penerima,
            type: NotificationType::UsulanMenungguPersetujuan,
            dedupKey: NotificationType::UsulanMenungguPersetujuan->dedupKey($procurementRequest->procurement_request_id),
            title: "Usulan baru menunggu persetujuan: {$judul}",
            body: sprintf(
                '%s diajukan oleh %s dan menunggu keputusan Anda.',
                $judul,
                $pengaju?->fullname ?? 'pengguna yang sudah tidak aktif',
            ),
            actionUrl: $tautan,
            subject: $procurementRequest,
        );

        // Pengaju ikut diberi tahu begitu usulannya sendiri menggantung —
        // ia paling berkepentingan usulannya segera diputuskan.
        $penerimaMenggantung = $pengaju !== null
            ? $penerima->concat([$pengaju])->unique('user_id')
            : $penerima;

        Notifier::terapkanKeadaan(
            berlaku: $menunggu && $kadaluarsa,
            penerima: $penerimaMenggantung,
            type: NotificationType::UsulanMenggantung,
            dedupKey: NotificationType::UsulanMenggantung->dedupKey($procurementRequest->procurement_request_id),
            title: "Usulan menggantung: {$judul}",
            body: sprintf(
                '%s diajukan %s dan masih belum disetujui maupun ditolak. Segera putuskan.',
                $judul,
                $procurementRequest->created_at->diffForHumans(),
            ),
            actionUrl: $tautan,
            subject: $procurementRequest,
        );

        self::periksaUsulanBelumDirealisasi($procurementRequest, $penerima, $judul, $tautan);
    }

    /**
     * Keadaan: usulan sudah disetujui tetapi belum ditindaklanjuti menjadi
     * realisasi.
     *
     * Menutup satu-satunya serah terima di seluruh alur pengadaan yang dulu
     * tidak punya penagih. Perpindahan 1&rarr;2 dijaga UsulanMenungguPersetujuan
     * dan UsulanMenggantung, 2&rarr;3 dijaga RealisasiBelumDibelanjakan lalu
     * RealisasiMenungguPenerimaan, dan 3&rarr;berkas dijaga BastBelumDiunggah — sementara jarak antara
     * "disetujui" dan "direalisasi" sunyi, padahal justru di situ barang yang
     * sudah disetujui dapat terlupakan tanpa jejak.
     *
     * Usulan yang realisasinya **dibatalkan** tidak ikut ditagih: menurut
     * ADR 0024 riwayat usulan itu memang sudah selesai, dan barang yang masih
     * dibutuhkan diajukan lewat usulan baru.
     *
     * @param  EloquentCollection<int, User>  $penerima
     */
    private static function periksaUsulanBelumDirealisasi(
        ProcurementRequest $procurementRequest,
        EloquentCollection $penerima,
        string $judul,
        string $tautan,
    ): void {
        // Ambangnya dihitung dari kapan usulan diputuskan. `updated_at` cukup
        // mewakilinya: satu-satunya hal yang mengubah baris usulan setelah
        // dibuat adalah keputusan setujui/tolak, atau transisi ke
        // DisetujuiSelesai saat direalisasikan (ADR 0028) — keduanya sama-sama
        // menandakan usulan tidak lagi menganggur.
        $menganggur = $procurementRequest->isDisetujuiMenungguRealisasi()
            && $procurementRequest->updated_at->lt(now()->subDays(self::HARI_USULAN_BELUM_DIREALISASI));

        Notifier::terapkanKeadaan(
            berlaku: $menganggur,
            penerima: $penerima,
            type: NotificationType::UsulanBelumDirealisasi,
            dedupKey: NotificationType::UsulanBelumDirealisasi->dedupKey($procurementRequest->procurement_request_id),
            title: "Belum direalisasi: {$judul}",
            body: sprintf(
                '%s sudah disetujui %s tetapi belum ditindaklanjuti menjadi realisasi pengadaan. Buka usulannya lalu tekan Realisasikan, atau tinjau kembali bila barangnya sudah tidak diperlukan.',
                $judul,
                $procurementRequest->updated_at->diffForHumans(),
            ),
            actionUrl: $tautan,
            subject: $procurementRequest,
        );
    }

    /**
     * Keadaan seluruh usulan pengadaan. Menyapu seluruh baris, bukan hanya
     * yang berstatus Diajukan, supaya usulan yang baru diputuskan tetapi
     * pemeriksaan reaktifnya sempat gagal tetap ikut ditutup di sini.
     */
    public static function periksaSeluruhUsulan(): void
    {
        $penerima = self::pejabatPengadaan();

        ProcurementRequest::query()->with('user')->chunk(100, function ($usulan) use ($penerima) {
            foreach ($usulan as $procurementRequest) {
                self::periksaUsulan($procurementRequest, $penerima);
            }
        });
    }

    /**
     * Keadaan satu realisasi pengadaan: menunggu persetujuan, terlalu lama
     * belum dibelanjakan, menunggu konfirmasi Pengelola Gudang bahwa barang
     * sudah diterima, atau sudah diterima tetapi berkas BAST bertanda tangan
     * belum diunggah. Dipanggil setelah realisasi dibuat, diputuskan, ditandai
     * sudah dibelanjakan, penerimaannya dikonfirmasi, atau berkasnya
     * diunggah/dihapus supaya notifikasinya muncul dan menghilang seketika.
     *
     * Keempat keadaan tidak akan pernah berlaku bersamaan: masing-masing
     * menuntut status yang berbeda — MenungguPersetujuan, ProsesBelanja,
     * MenungguPenerimaan, dan sudah Diterima.
     *
     * Penerimanya pun berbeda, karena itu tiga koleksi: persetujuan ditagih ke
     * Kasubbag Umum dan TI, belanja ke Pejabat Pengadaan, penerimaan dan berkas
     * BAST ke pengawas gudang.
     *
     * @param  EloquentCollection<int, User>|null  $penerima
     * @param  EloquentCollection<int, User>|null  $pemberiPersetujuan
     * @param  EloquentCollection<int, User>|null  $pembelanja
     */
    public static function periksaRealisasi(
        Procurement $procurement,
        ?EloquentCollection $penerima = null,
        ?EloquentCollection $pemberiPersetujuan = null,
        ?EloquentCollection $pembelanja = null,
    ): void {
        $penerima ??= self::pengawasGudang();
        $pemberiPersetujuan ??= self::pemberiPersetujuan();
        $pembelanja ??= self::pejabatPengadaan();

        $judul = $procurement->judul();

        // Realisasi langsung tidak punya dokumen hulu yang dapat dibuka untuk
        // menilai apakah belanjanya perlu, jadi dasarnya disebut di sini —
        // pada realisasi langsung, dasar itulah yang justru dinilai, bukan cuma
        // harganya (ADR 0031).
        $dasar = $procurement->tanpaUsulan()
            ? sprintf(
                ' Dibuat tanpa usulan dengan dasar %s.',
                strtolower($procurement->basis_reason?->label() ?? 'yang tidak dicatat'),
            )
            : '';

        Notifier::terapkanKeadaan(
            berlaku: $procurement->isMenungguPersetujuan(),
            penerima: $pemberiPersetujuan,
            type: NotificationType::RealisasiMenungguPersetujuan,
            dedupKey: NotificationType::RealisasiMenungguPersetujuan->dedupKey($procurement->procurement_id),
            title: "Realisasi menunggu persetujuan: {$judul}",
            body: sprintf(
                '%s dari %s senilai Rp%s menunggu keputusan Anda.%s Periksa penyedia, barang, dan harganya, lalu setujui atau tolak sebelum barang dipesan.',
                $judul,
                $procurement->supplier?->supplier_name ?? 'penyedia tidak diketahui',
                number_format($procurement->nilaiTotal(), 0, ',', '.'),
                $dasar,
            ),
            actionUrl: route('procurements.show', $procurement, false),
            subject: $procurement,
        );

        self::periksaBelumDibelanjakan($procurement, $pembelanja);

        // Baru terbit setelah barangnya dibelanjakan, bukan saat disetujui:
        // sebelum ADR 0035 gudang sudah ditagih menunggu barang yang belum
        // dibeli siapa pun. Realisasi lama yang disetujui sebelum ADR itu
        // tidak punya tanggal belanja, jadi kalimatnya menyesuaikan.
        Notifier::terapkanKeadaan(
            berlaku: $procurement->isMenungguPenerimaan(),
            penerima: $penerima,
            type: NotificationType::RealisasiMenungguPenerimaan,
            dedupKey: NotificationType::RealisasiMenungguPenerimaan->dedupKey($procurement->procurement_id),
            title: "Realisasi menunggu penerimaan: {$judul}",
            body: sprintf(
                '%s dari %s %s dan menunggu konfirmasi barang diterima. Periksa fisik barang lalu konfirmasi penerimaan supaya stok bertambah.',
                $judul,
                $procurement->supplier?->supplier_name ?? 'penyedia tidak diketahui',
                $procurement->purchased_at
                    ? 'sudah dibelanjakan '.$procurement->purchased_at->translatedFormat('j F Y')
                    : 'sudah disetujui',
            ),
            actionUrl: route('procurements.show', $procurement, false),
            subject: $procurement,
        );

        self::periksaBerkasBast($procurement, $penerima);
    }

    /**
     * Keadaan: realisasi sudah disetujui berhari-hari lalu, tetapi belum juga
     * ditandai sudah dibelanjakan.
     *
     * Menutup jarak yang terbuka oleh ADR 0035. Dulu tagihan
     * RealisasiMenungguPenerimaan terbit seketika saat disetujui dan menjaga
     * seluruh rentang sampai barang diterima; kini ia baru terbit setelah
     * belanja, sehingga rentang disetujui&rarr;dibelanjakan butuh penagihnya
     * sendiri. Tidak seketika, karena peristiwa RealisasiDisetujui sudah
     * memberi tahu Pejabat Pengadaan pada saat itu — yang ditagih di sini
     * adalah kelalaian, bukan belanja yang wajar memakan beberapa hari.
     *
     * Ambangnya dihitung dari `reviewed_at`, yaitu kapan persetujuan yang
     * sedang berlaku diberikan.
     *
     * @param  EloquentCollection<int, User>  $penerima
     */
    private static function periksaBelumDibelanjakan(Procurement $procurement, EloquentCollection $penerima): void
    {
        $terlupakan = $procurement->isProsesBelanja()
            && $procurement->reviewed_at !== null
            && $procurement->reviewed_at->lt(now()->subDays(self::HARI_REALISASI_BELUM_DIBELANJAKAN));

        Notifier::terapkanKeadaan(
            berlaku: $terlupakan,
            penerima: $penerima,
            type: NotificationType::RealisasiBelumDibelanjakan,
            dedupKey: NotificationType::RealisasiBelumDibelanjakan->dedupKey($procurement->procurement_id),
            title: "Belum dibelanjakan: {$procurement->judul()}",
            body: sprintf(
                '%s dari %s sudah disetujui %s tetapi belum ditandai sudah dibelanjakan. Setelah belanja, buka realisasinya lalu tekan Tandai Sudah Dibelanjakan beserta fotonya, atau batalkan bila barangnya tidak jadi dibeli.',
                $procurement->judul(),
                $procurement->supplier?->supplier_name ?? 'penyedia tidak diketahui',
                $procurement->reviewed_at?->diffForHumans() ?? '',
            ),
            actionUrl: route('procurements.show', $procurement, false),
            subject: $procurement,
        );
    }

    /**
     * Keadaan: barang sudah diterima berhari-hari lalu, tetapi berkas BAST
     * bertanda tangan belum juga diunggah.
     *
     * Inilah imbangan dari kelonggaran ADR 0018. Penerimaan sengaja tidak
     * menunggu berkasnya supaya stok tidak tertahan — dan justru karena itu
     * ketiadaan berkas perlu ditagih, kalau tidak kelonggaran itu berubah
     * menjadi kelalaian yang tidak pernah ketahuan.
     *
     * @param  EloquentCollection<int, User>  $penerima
     */
    private static function periksaBerkasBast(Procurement $procurement, EloquentCollection $penerima): void
    {
        $procurement->loadMissing('receipt');
        $receipt = $procurement->receipt;

        $terlambat = $procurement->isDiterima()
            && $receipt !== null
            && $receipt->received_at->lt(now()->subDays(self::HARI_BAST_BELUM_DIUNGGAH))
            && ! $procurement->bastSudahDiunggah();

        Notifier::terapkanKeadaan(
            berlaku: $terlambat,
            penerima: $penerima,
            type: NotificationType::BastBelumDiunggah,
            dedupKey: NotificationType::BastBelumDiunggah->dedupKey($procurement->procurement_id),
            title: "BAST belum diunggah: {$procurement->judul()}",
            // Tanpa nomor BAST: nomornya baru diisi saat dicetak (ADR 0042),
            // dan keadaan ini justru paling sering berlaku sebelum itu.
            body: sprintf(
                'Barang %s diterima %s, tetapi berkas BAST bertanda tangannya belum diunggah. Cetak BAST dari sistem, mintakan tanda tangan, lalu unggah hasil pindaiannya.',
                $procurement->judul(),
                $receipt?->received_at->translatedFormat('j F Y') ?? '-',
            ),
            actionUrl: route('procurements.show', $procurement, false),
            subject: $procurement,
        );
    }

    /**
     * Keadaan seluruh realisasi pengadaan. Menyapu seluruh baris, bukan hanya
     * yang berstatus MenungguPenerimaan, supaya realisasi yang baru
     * dikonfirmasi tetapi pemeriksaan reaktifnya sempat gagal tetap ikut
     * ditutup di sini.
     */
    public static function periksaSeluruhRealisasi(): void
    {
        $penerima = self::pengawasGudang();
        $pemberiPersetujuan = self::pemberiPersetujuan();
        $pembelanja = self::pejabatPengadaan();

        // `details` ikut dimuat karena isi notifikasi persetujuan menyebut nilai
        // total realisasinya.
        Procurement::query()
            ->with('supplier', 'receipt', 'details')
            ->chunk(100, function ($procurements) use ($penerima, $pemberiPersetujuan, $pembelanja) {
                foreach ($procurements as $procurement) {
                    self::periksaRealisasi($procurement, $penerima, $pemberiPersetujuan, $pembelanja);
                }
            });
    }

    /**
     * Peristiwa: penerimaan barang pengadaan dikonfirmasi, sehingga stok
     * sudah bertambah.
     *
     * Penerimanya adalah Pejabat Pengadaan yang merealisasi (`Procurement::user_id`),
     * bukan pengaju usulan — ia yang paling berkepentingan tahu barang yang
     * direalisasikannya sudah sampai. Pelakunya sendiri (Pengelola Gudang yang
     * mengonfirmasi) tidak diberi tahu.
     */
    public static function catatPenerimaanDikonfirmasi(Procurement $procurement, User $pelaku): void
    {
        self::periksaRealisasi($procurement);

        $procurement->loadMissing('user', 'supplier');
        $perealisasi = $procurement->user;

        if ($perealisasi === null || $perealisasi->user_id === $pelaku->user_id) {
            return;
        }

        $judul = $procurement->judul();

        Notifier::catatPeristiwa(
            penerima: new EloquentCollection([$perealisasi]),
            type: NotificationType::PenerimaanDikonfirmasi,
            dedupKey: NotificationType::PenerimaanDikonfirmasi->dedupKey($procurement->procurement_id),
            title: "Barang {$judul} sudah diterima",
            body: sprintf(
                '%s mengonfirmasi penerimaan %s dari %s. Stok sudah bertambah.',
                $pelaku->fullname,
                $judul,
                $procurement->supplier?->supplier_name ?? 'penyedia',
            ),
            actionUrl: route('procurements.show', $procurement, false),
            subject: $procurement,
        );
    }

    /**
     * Peristiwa: realisasi pengadaan diputuskan Kasubbag Umum dan TI.
     *
     * Penerimanya Pejabat Pengadaan yang membuat realisasi itu — dialah yang
     * harus memesan barang bila disetujui, atau memperbaiki dokumennya bila
     * ditolak. Pelakunya sendiri tidak diberi tahu; Admin yang memutuskan
     * sebagai pengganti pun tidak mengirimi dirinya sendiri.
     *
     * Memanggil `periksaRealisasi()` lebih dulu supaya tagihan
     * RealisasiMenungguPersetujuan tertutup seketika. Tagihan
     * RealisasiMenungguPenerimaan baru terbit ke gudang bila barangnya sudah
     * dibelanjakan sebelum realisasi diubah dan disetujui ulang; selebihnya
     * menunggu ditandai sudah dibelanjakan (ADR 0035).
     *
     * "Disetujui" ditanyakan sebagai kebalikan dari Ditolak, bukan dari status
     * tujuannya: sejak ADR 0035 persetujuan berujung pada dua status berbeda.
     *
     * Lihat ADR 0030.
     */
    public static function catatRealisasiDiputuskan(Procurement $procurement, User $pelaku): void
    {
        self::periksaRealisasi($procurement);

        $procurement->loadMissing('user', 'supplier');
        $perealisasi = $procurement->user;

        if ($perealisasi === null || $perealisasi->user_id === $pelaku->user_id) {
            return;
        }

        $judul = $procurement->judul();
        $disetujui = ! $procurement->isDitolak();
        $type = $disetujui ? NotificationType::RealisasiDisetujui : NotificationType::RealisasiDitolak;

        Notifier::catatPeristiwa(
            penerima: new EloquentCollection([$perealisasi]),
            type: $type,
            dedupKey: $type->dedupKey($procurement->procurement_id),
            title: $disetujui ? "Disetujui: {$judul}" : "Ditolak: {$judul}",
            body: $disetujui
                ? sprintf(
                    '%s menyetujui %s dari %s. %s%s',
                    $pelaku->fullname,
                    $judul,
                    $procurement->supplier?->supplier_name ?? 'penyedia',
                    $procurement->isProsesBelanja()
                        ? 'Barang sudah boleh dibelanjakan; setelah belanja, tandai sudah dibelanjakan beserta fotonya supaya gudang menunggu barangnya.'
                        : 'Barangnya sudah dibelanjakan sebelumnya, jadi gudang langsung menunggu konfirmasi penerimaannya.',
                    $procurement->review_note ? ' Catatan: '.$procurement->review_note : '',
                )
                : sprintf(
                    '%s menolak %s dari %s. Alasan: %s. Perbaiki realisasinya lalu simpan untuk mengajukannya ulang.',
                    $pelaku->fullname,
                    $judul,
                    $procurement->supplier?->supplier_name ?? 'penyedia',
                    $procurement->review_note ?? '-',
                ),
            actionUrl: route('procurements.show', $procurement, false),
            subject: $procurement,
        );
    }

    /**
     * Peristiwa: realisasi pengadaan dibatalkan, sehingga barangnya tidak akan
     * datang.
     *
     * Penerimanya Pengelola Gudang beserta Admin — merekalah yang sebelumnya
     * diberi tahu untuk menunggu barang lewat RealisasiMenungguPenerimaan.
     * Notifikasi keadaan itu memang menutup dirinya sendiri di sini (statusnya
     * tidak lagi MenungguPenerimaan), tetapi menghilang diam-diam saja tidak
     * cukup: yang perlu diketahui gudang bukan "tidak ada lagi yang ditunggu",
     * melainkan bahwa barangnya tidak jadi datang, dan mengapa.
     *
     * Pelakunya sendiri tidak diberi tahu — ia baru saja melakukannya.
     */
    public static function catatRealisasiDibatalkan(Procurement $procurement, User $pelaku): void
    {
        self::periksaRealisasi($procurement);

        $procurement->loadMissing('supplier');
        $judul = $procurement->judul();

        Notifier::catatPeristiwa(
            penerima: self::pengawasGudang()->reject(
                fn (User $user) => $user->user_id === $pelaku->user_id,
            ),
            type: NotificationType::RealisasiDibatalkan,
            dedupKey: NotificationType::RealisasiDibatalkan->dedupKey($procurement->procurement_id),
            title: "Dibatalkan: {$judul}",
            body: sprintf(
                '%s membatalkan %s dari %s. Alasan: %s. Keterangan: %s',
                $pelaku->fullname,
                $judul,
                $procurement->supplier?->supplier_name ?? 'penyedia',
                $procurement->cancel_reason?->label() ?? 'tidak dicatat',
                $procurement->cancel_note ?: 'tidak diisi',
            ),
            actionUrl: route('procurements.show', $procurement, false),
            subject: $procurement,
        );
    }

    /**
     * Keadaan satu usulan barang baru: menunggu diperiksa dan didaftarkan
     * Pengelola Gudang. Dipanggil setelah usulan diajukan maupun diputuskan
     * supaya tagihannya muncul dan menghilang seketika (ADR 0032).
     *
     * Penerimanya pengawas gudang, karena master barang wewenang mereka — dan
     * karena pemeriksaan duplikat itulah bagian paling bernilai dari langkah ini.
     *
     * @param  EloquentCollection<int, User>|null  $penerima
     */
    public static function periksaUsulanBarang(ItemProposal $proposal, ?EloquentCollection $penerima = null): void
    {
        $penerima ??= self::pengawasGudang();

        Notifier::terapkanKeadaan(
            berlaku: $proposal->isDiajukan(),
            penerima: $penerima,
            type: NotificationType::UsulanBarangMenungguKeputusan,
            dedupKey: NotificationType::UsulanBarangMenungguKeputusan->dedupKey($proposal->item_proposal_id),
            title: "Usulan barang baru: {$proposal->item_name}",
            body: sprintf(
                '%s mengusulkan barang baru "%s" (%s). Periksa apakah barangnya benar-benar belum ada, lalu daftarkan atau tolak dengan alasan.',
                $proposal->proposedBy?->fullname ?? 'Seseorang',
                $proposal->item_name,
                $proposal->unit->label(),
            ),
            actionUrl: route('item-proposals.show', $proposal, false),
            subject: $proposal,
        );
    }

    /**
     * Keadaan seluruh usulan barang baru. Menyapu seluruh baris, bukan hanya
     * yang Diajukan, supaya usulan yang baru diputuskan tetapi pemeriksaan
     * reaktifnya sempat gagal tetap ikut ditutup di sini.
     */
    public static function periksaSeluruhUsulanBarang(): void
    {
        $penerima = self::pengawasGudang();

        ItemProposal::query()
            ->with('proposedBy')
            ->chunk(100, function ($usulan) use ($penerima) {
                foreach ($usulan as $proposal) {
                    self::periksaUsulanBarang($proposal, $penerima);
                }
            });
    }

    /**
     * Peristiwa: usulan barang baru diputuskan Pengelola Gudang.
     *
     * Penerimanya pengusul — dialah yang menunggu jawaban sebelum dapat
     * melanjutkan pengadaan atau permintaannya. Pelakunya sendiri tidak diberi
     * tahu, dan pengusul yang akunnya sudah diarsipkan tidak ditagih apa pun.
     */
    public static function catatUsulanBarangDiputuskan(ItemProposal $proposal, User $pelaku): void
    {
        // Menutup tagihan keputusan lebih dulu: syaratnya sudah tidak
        // terpenuhi begitu statusnya berpindah.
        self::periksaUsulanBarang($proposal);

        $pengusul = $proposal->proposedBy;

        if ($pengusul === null || $pengusul->user_id === $pelaku->user_id) {
            return;
        }

        if ($proposal->isDisetujui()) {
            Notifier::catatPeristiwa(
                penerima: new EloquentCollection([$pengusul]),
                type: NotificationType::UsulanBarangDisetujui,
                dedupKey: NotificationType::UsulanBarangDisetujui->dedupKey($proposal->item_proposal_id),
                title: "Usulan barang \"{$proposal->item_name}\" disetujui",
                body: sprintf(
                    '%s mendaftarkan "%s" sebagai %s. Stoknya masih nol sampai barangnya benar-benar diterima gudang, tetapi sudah dapat dimasukkan ke realisasi pengadaan.',
                    $pelaku->fullname,
                    $proposal->item_name,
                    $proposal->item?->item_code ?? 'barang baru',
                ),
                actionUrl: $proposal->item === null
                    ? route('item-proposals.show', $proposal, false)
                    : route('items.show', $proposal->item, false),
                subject: $proposal,
            );

            return;
        }

        if ($proposal->isDitolak()) {
            Notifier::catatPeristiwa(
                penerima: new EloquentCollection([$pengusul]),
                type: NotificationType::UsulanBarangDitolak,
                dedupKey: NotificationType::UsulanBarangDitolak->dedupKey($proposal->item_proposal_id),
                title: "Usulan barang \"{$proposal->item_name}\" ditolak",
                body: sprintf(
                    '%s menolak usulan "%s" dengan alasan: %s',
                    $pelaku->fullname,
                    $proposal->item_name,
                    $proposal->decision_note ?? 'tidak dicatat',
                ),
                actionUrl: route('item-proposals.show', $proposal, false),
                subject: $proposal,
            );
        }
    }

    /**
     * Peristiwa: usulan pengadaan disetujui atau ditolak.
     *
     * Pelakunya sendiri tidak diberi tahu. Bila pengaju sudah diarsipkan
     * (akun tidak aktif), tidak ada yang perlu diberi tahu.
     */
    public static function catatUsulanDiputuskan(ProcurementRequest $procurementRequest, User $pelaku): void
    {
        $pengaju = $procurementRequest->user;

        if ($pengaju === null || $pengaju->user_id === $pelaku->user_id) {
            return;
        }

        $judul = $procurementRequest->judul();
        $tautan = route('procurement-requests.show', $procurementRequest, false);
        $ringkasan = $procurementRequest->ringkasan();

        if ($procurementRequest->sudahDisetujui()) {
            Notifier::catatPeristiwa(
                penerima: new EloquentCollection([$pengaju]),
                type: NotificationType::UsulanDisetujui,
                dedupKey: NotificationType::UsulanDisetujui->dedupKey($procurementRequest->procurement_request_id),
                title: "{$judul} disetujui",
                body: sprintf(
                    '%s menyetujui %s: %d dari %d barang disetujui, total %s dari %s unit yang diminta.',
                    $pelaku->fullname,
                    $judul,
                    $ringkasan['barang_disetujui'],
                    $ringkasan['barang'],
                    number_format($ringkasan['disetujui'], 0, ',', '.'),
                    number_format($ringkasan['diminta'], 0, ',', '.'),
                ),
                actionUrl: $tautan,
                subject: $procurementRequest,
            );
        } elseif ($procurementRequest->isDitolak()) {
            Notifier::catatPeristiwa(
                penerima: new EloquentCollection([$pengaju]),
                type: NotificationType::UsulanDitolak,
                dedupKey: NotificationType::UsulanDitolak->dedupKey($procurementRequest->procurement_request_id),
                title: "{$judul} ditolak",
                body: sprintf(
                    '%s menolak %s. Periksa kembali kebutuhan atau ajukan usulan baru bila masih diperlukan.',
                    $pelaku->fullname,
                    $judul,
                ),
                actionUrl: $tautan,
                subject: $procurementRequest,
            );
        }

        self::periksaUsulan($procurementRequest);
    }

    /**
     * Keadaan permintaan barang sesuai tahap pekerjaan yang sedang terbuka.
     *
     * Semua keadaan lama ditutup lebih dulu karena penerimanya berganti sesuai
     * pemilik pekerjaan pada tahap tersebut: Kepala Unit, Pengelola Gudang,
     * lalu Admin.
     */
    public static function periksaPermintaan(ModelsRequest $request): void
    {
        $request->loadMissing('user');

        $kepalaUnit = $request->user?->unit
            ? User::query()
                ->where('is_active', true)
                ->where('role', UserRole::KepalaUnit->value)
                ->where('unit', $request->user->unit)
                ->get()
            : new EloquentCollection;

        $peminta = $request->user?->is_active
            ? new EloquentCollection([$request->user])
            : new EloquentCollection;

        $pengawas = self::pengawasGudang();
        $admin = Notifier::penerimaBerperan(UserRole::Admin);

        Notifier::terapkanKeadaan(
            berlaku: $request->status === 'submitted',
            penerima: $kepalaUnit,
            type: NotificationType::PermintaanDiajukan,
            dedupKey: NotificationType::PermintaanDiajukan->dedupKey($request->request_id),
            title: "Permintaan {$request->request_number} menunggu persetujuan",
            body: "Permintaan barang dari unit {$request->user?->unit} perlu diperiksa dan disetujui.",
            actionUrl: route('request.show', $request, absolute: false),
            subject: $request,
        );

        Notifier::terapkanKeadaan(
            berlaku: $request->status === 'submitted',
            penerima: $admin,
            type: NotificationType::PermintaanDiajukan,
            dedupKey: NotificationType::PermintaanDiajukan->dedupKey($request->request_id),
            title: "Permintaan {$request->request_number} menunggu persetujuan",
            body: "Permintaan barang dari unit {$request->user?->unit} perlu diperiksa dan disetujui.",
            actionUrl: route('request.show', $request, absolute: false),
            subject: $request,
        );

        Notifier::terapkanKeadaan(
            berlaku: $request->status === 'approved_by_head',
            penerima: $pengawas,
            type: NotificationType::PermintaanDisetujuiKepalaUnit,
            dedupKey: NotificationType::PermintaanDisetujuiKepalaUnit->dedupKey($request->request_id),
            title: "Permintaan {$request->request_number}: ".NotificationType::PermintaanDisetujuiKepalaUnit->label(),
            body: NotificationType::PermintaanDisetujuiKepalaUnit->description(),
            actionUrl: route('request.show', $request, absolute: false),
            subject: $request,
        );

        Notifier::terapkanKeadaan(
            berlaku: $request->status === 'processed_by_staff',
            penerima: $admin,
            type: NotificationType::PermintaanDisetujuiPengelolaGudang,
            dedupKey: NotificationType::PermintaanDisetujuiPengelolaGudang->dedupKey($request->request_id),
            title: "Permintaan {$request->request_number}: ".NotificationType::PermintaanDisetujuiPengelolaGudang->label(),
            body: NotificationType::PermintaanDisetujuiPengelolaGudang->description(),
            actionUrl: route('request.show', $request, absolute: false),
            subject: $request,
        );

        Notifier::terapkanKeadaan(
            berlaku: $request->status === 'completed',
            penerima: $peminta,
            type: NotificationType::PermintaanSelesai,
            dedupKey: NotificationType::PermintaanSelesai->dedupKey($request->request_id),
            title: "Permintaan {$request->request_number}: ".NotificationType::PermintaanSelesai->label(),
            body: NotificationType::PermintaanSelesai->description(),
            actionUrl: route('request.show', $request, absolute: false),
            subject: $request,
        );
        
        Notifier::terapkanKeadaan(
            berlaku: $request->status === 'rejected',
            penerima: $peminta,
            type: NotificationType::PermintaanDitolak,
            dedupKey: NotificationType::PermintaanDitolak->dedupKey($request->request_id),
            title: "Permintaan {$request->request_number}: ".NotificationType::PermintaanDitolak->label(),
            body: NotificationType::PermintaanDitolak->description(),
            actionUrl: route('request.show', $request, absolute: false),
            subject: $request,
        );
    }

    /**
     * Sinkronkan keadaan awal permintaan setelah diajukan.
     */
    public static function catatPermintaanDiajukan(ModelsRequest $request): void
    {
        self::periksaPermintaan($request);
    }

    /**
     * Peristiwa: opname selesai diposting.
     *
     * Dipanggil sekali per posting, bukan sekali per barang, sehingga posting
     * yang menyentuh puluhan barang tetap menghasilkan satu baris ringkasan.
     */
    public static function catatOpnameDiposting(StockOpname $opname, User $pelaku): void
    {
        $ringkasan = $opname->ringkasan();

        Notifier::catatPeristiwa(
            penerima: self::pengawasGudang()->reject(
                fn (User $user) => $user->user_id === $pelaku->user_id,
            ),
            type: NotificationType::OpnameDiposting,
            dedupKey: NotificationType::OpnameDiposting->dedupKey($opname->opname_id),
            title: "{$opname->judul()} telah diposting",
            body: sprintf(
                '%s memposting %s: %d barang dicacah, %d selisih, %s lebih dan %s kurang. Penyesuaian sudah masuk ke buku mutasi.',
                $pelaku->fullname,
                $opname->judul(),
                $ringkasan['dicacah'],
                $ringkasan['selisih'],
                number_format($ringkasan['lebih'], 0, ',', '.'),
                number_format($ringkasan['kurang'], 0, ',', '.'),
            ),
            actionUrl: route('opname.show', $opname, false),
            subject: $opname,
        );

        self::catatStokBergerakSetelahDicacah($opname);
        self::periksaBarangTerdampak($opname);
    }

    /**
     * Selaraskan keadaan stok barang yang stoknya diubah oleh posting.
     *
     * Dilakukan sekaligus di sini, bukan per mutasi di dalam OpnamePoster,
     * supaya posting yang menyentuh puluhan barang tetap satu kali pemeriksaan
     * dengan satu kali pengambilan daftar penerima.
     */
    private static function periksaBarangTerdampak(StockOpname $opname): void
    {
        $terdampak = $opname->details()
            ->where('adjustment_amount', '!=', 0)
            ->pluck('item_id');

        if ($terdampak->isEmpty()) {
            return;
        }

        $penerima = self::pengawasGudang();

        Item::query()->whereIn('item_id', $terdampak)->each(
            fn (Item $item) => self::periksaBarang($item, $penerima),
        );
    }

    /**
     * Peristiwa: mutasi manual yang mengurangi stok tanpa dokumen sumber.
     *
     * Pelakunya sendiri tidak diberi tahu — ia baru saja melakukannya.
     */
    public static function catatMutasiManual(StockMutation $mutation, User $pelaku): void
    {
        if (! in_array($mutation->mutation_type, self::MUTASI_SENSITIF, true)) {
            return;
        }

        $mutation->loadMissing('item');

        Notifier::catatPeristiwa(
            penerima: Notifier::penerimaBerperan(UserRole::Admin)->reject(
                fn (User $user) => $user->user_id === $pelaku->user_id,
            ),
            type: NotificationType::MutasiManualSensitif,
            dedupKey: NotificationType::MutasiManualSensitif->dedupKey($mutation->mutation_id),
            title: "{$mutation->mutation_type->label()}: {$mutation->item->item_name}",
            body: sprintf(
                '%s mencatat %s sebanyak %s %s pada %s. Sisa stok %s. Keterangan: %s',
                $pelaku->fullname,
                strtolower($mutation->mutation_type->label()),
                $mutation->signedAmount(),
                $mutation->item->unit->label(),
                $mutation->item->item_code,
                number_format($mutation->stock_after, 0, ',', '.'),
                $mutation->notes ?: 'tidak diisi',
            ),
            actionUrl: route('items.kartu-persediaan', $mutation->item, false),
            subject: $mutation->item,
        );
    }

    /**
     * Peristiwa: stok sebuah barang bergerak di antara pencacahan dan posting,
     * sehingga penyesuaian yang diterapkan berbeda dari hasil cacah.
     *
     * Ini satu-satunya cara petugas mengetahuinya; laporan opname mencatatnya,
     * tetapi tidak ada yang memberitahukannya.
     */
    private static function catatStokBergerakSetelahDicacah(StockOpname $opname): void
    {
        $pembuat = $opname->user;

        if ($pembuat === null) {
            return;
        }

        $bergerak = $opname->details()
            ->with('item')
            ->get()
            ->filter(fn (OpnameDetail $detail) => $detail->stokBerubahSejakDicacah());

        if ($bergerak->isEmpty()) {
            return;
        }

        Notifier::catatPeristiwa(
            penerima: new EloquentCollection([$pembuat]),
            type: NotificationType::StokBergerakSetelahDicacah,
            dedupKey: NotificationType::StokBergerakSetelahDicacah->dedupKey($opname->opname_id),
            title: "Stok bergerak setelah dicacah pada {$opname->judul()}",
            body: sprintf(
                '%d barang (%s) mengalami mutasi setelah dicacah, sehingga stok akhirnya tidak sama dengan hasil cacah. Ini bukan kesalahan — transaksi yang sah memang tidak dibatalkan oleh opname. Periksa laporan bila perlu.',
                $bergerak->count(),
                $bergerak->take(3)->map(fn (OpnameDetail $d) => $d->item->item_code)->implode(', ')
                    .($bergerak->count() > 3 ? ', dan lainnya' : ''),
            ),
            actionUrl: route('opname.show', $opname, false),
            subject: $opname,
        );
    }

    /**
     * Pengelola Gudang beserta Admin — penerima baku urusan pergudangan.
     *
     * @return EloquentCollection<int, User>
     */
    private static function pengawasGudang(): EloquentCollection
    {
        return Notifier::penerimaBerperan(UserRole::PengelolaGudang, UserRole::Admin);
    }

    /**
     * Pejabat Pengadaan beserta Admin — penerima baku keputusan usulan.
     *
     * @return EloquentCollection<int, User>
     */
    private static function pejabatPengadaan(): EloquentCollection
    {
        return Notifier::penerimaBerperan(UserRole::PejabatPengadaan, UserRole::Admin);
    }

    /**
     * Kasubbag Umum dan TI beserta Admin — penerima baku antrean persetujuan
     * realisasi pengadaan. Admin ikut karena `Gate::before` memang meloloskannya
     * pada `setujui-pengadaan` (ADR 0030).
     *
     * @return EloquentCollection<int, User>
     */
    private static function pemberiPersetujuan(): EloquentCollection
    {
        return Notifier::penerimaBerperan(UserRole::KasubbagUmumTi, UserRole::Admin);
    }

    /**
     * Tanggal akhir tiap triwulan pada tahun yang diminta.
     *
     * @return array<string, \Illuminate\Support\Carbon>
     */
    private static function akhirTriwulan(int $tahun): array
    {
        return [
            PeriodeOpname::TriwulanI->value => now()->setDate($tahun, 3, 31)->endOfDay(),
            PeriodeOpname::TriwulanII->value => now()->setDate($tahun, 6, 30)->endOfDay(),
            PeriodeOpname::TriwulanIII->value => now()->setDate($tahun, 9, 30)->endOfDay(),
            PeriodeOpname::TriwulanIV->value => now()->setDate($tahun, 12, 31)->endOfDay(),
        ];
    }
}
