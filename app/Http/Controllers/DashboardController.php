<?php

namespace App\Http\Controllers;

use App\Enums\ItemProposalStatus;
use App\Enums\MutationType;
use App\Enums\OpnameStatus;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\Request as ModelsRequest;
use App\Models\StockMutation;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Dasbor berbasis peran. Setiap bagian dijaga gate yang sama dengan rute yang
 * ditautkannya, dan setiap angka dihitung langsung dari data — bukan dari baris
 * notifikasi, yang bisa dikosongkan pengguna dan sebagian baru muncul setelah
 * ambang hari. Setiap angka yang bisa diklik memakai filter daftar tujuan yang
 * menghasilkan angka yang sama. Lihat ADR 0040.
 */
class DashboardController extends Controller
{
    private const BULAN_GRAFIK = 6;

    private const BARANG_KRITIS = 5;

    /**
     * Status permintaan barang yang masih berjalan, sama dengan ringkasan
     * "pending" di RequestController::index() milik sub sistem Permintaan.
     */
    private const PERMINTAAN_BERJALAN = ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance'];

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        // Gate baca, bukan `kelola-*`: tautan dan grafik di sini hanya membuka
        // halaman baca, yang juga terbuka bagi Kasubbag (ADR 0041).
        $bisaGudang = $user->can('akses-gudang');
        $bisaPengadaan = $user->can('akses-pengadaan');

        return view('pages.dashboard.index', [
            'title' => 'Dasbor',
            'user' => $user,
            'salam' => $this->salam(),
            'aksesCepat' => $this->aksesCepat($user),
            'tugas' => $this->perluTindakan($user),
            'stok' => $bisaGudang || $bisaPengadaan ? $this->kondisiStok(bertaut: $bisaGudang) : null,
            'alurPermintaan' => $this->alurPermintaan($user),
            'alur' => $bisaPengadaan ? $this->alurPengadaan() : null,
            'grafikMutasi' => $bisaGudang ? $this->grafikMutasi() : null,
            'grafikNilai' => $user->can('kelola-pengadaan') || $user->can('setujui-pengadaan')
                ? $this->grafikNilaiPengadaan()
                : null,
        ]);
    }

    private function salam(): string
    {
        $jam = now()->hour;

        return match (true) {
            $jam >= 4 && $jam < 11 => 'Selamat pagi',
            $jam >= 11 && $jam < 15 => 'Selamat siang',
            $jam >= 15 && $jam < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };
    }

    /**
     * Ubin modul. Disaring dengan gate yang sama dengan middleware rutenya,
     * sehingga tidak ada ubin yang berujung 403.
     *
     * @return array<int, array{label: string, url: string, ikon: string, warna: string}>
     */
    private function aksesCepat(User $user): array
    {
        $ubin = [
            ['Permintaan Barang', 'request.index', null, 'inbox', 'sky'],
            ['Master Barang', 'items.index', 'akses-gudang', 'package', 'brand'],
            ['Mutasi Stok', 'mutations.index', 'akses-gudang', 'arrows', 'success'],
            ['Stok Opname', 'opname.index', 'akses-gudang', 'clipboard-check', 'orange'],
            ['Usulan Pengadaan', 'procurement-requests.index', 'akses-pengadaan', 'file-plus', 'purple'],
            ['Realisasi Pengadaan', 'procurements.index', 'akses-pengadaan', 'cart', 'amber'],
            ['Usulan Barang Baru', 'item-proposals.index', 'akses-pengadaan', 'package-plus', 'pink'],
            ['Penyedia Barang', 'suppliers.index', 'akses-penyedia', 'building', 'teal'],
            ['Manajemen Pengguna', 'users.index', 'kelola-pengguna', 'users', 'rose'],
            ['Log Aktivitas', 'activity-logs.index', 'kelola-pengguna', 'history', 'gray'],
        ];

        return collect($ubin)
            ->filter(fn (array $baris) => $baris[2] === null || $user->can($baris[2]))
            ->map(fn (array $baris) => [
                'label' => $baris[0],
                'url' => route($baris[1]),
                'ikon' => $baris[3],
                'warna' => $baris[4],
            ])
            ->values()
            ->all();
    }

    /**
     * Antrean pekerjaan. Hanya baris yang jumlahnya lebih dari nol yang
     * dikembalikan.
     *
     * @return array<int, array{label: string, jumlah: int, url: string, ikon: string, warna: string}>
     */
    private function perluTindakan(User $user): array
    {
        $tugas = [];

        if ($user->can('setujui-pengadaan')) {
            $tugas[] = $this->tugasRealisasi('Realisasi menunggu persetujuan', ProcurementStatus::MenungguPersetujuan, 'badge-check', 'amber');
        }

        if ($user->can('kelola-pengadaan')) {
            $tugas[] = $this->tugasUsulan('Usulan menunggu keputusan', ProcurementRequestStatus::Diajukan, 'file-text');
            $tugas[] = $this->tugasUsulan('Usulan siap direalisasi', ProcurementRequestStatus::DisetujuiMenungguRealisasi, 'file-check');
            $tugas[] = $this->tugasRealisasi('Realisasi ditolak', ProcurementStatus::Ditolak, 'file-x', 'error');
            $tugas[] = $this->tugasRealisasi('Belum dibelanjakan', ProcurementStatus::ProsesBelanja, 'bag', 'amber');
        }

        if ($user->can('kelola-gudang')) {
            $tugas[] = $this->tugasRealisasi('Barang menunggu penerimaan', ProcurementStatus::MenungguPenerimaan, 'truck', 'amber');
            $tugas[] = [
                'label' => 'Usulan barang baru',
                'jumlah' => ItemProposal::query()->where('status', ItemProposalStatus::Diajukan)->count(),
                'url' => route('item-proposals.index', ['status' => ItemProposalStatus::Diajukan->value]),
                'ikon' => 'package-plus',
                'warna' => 'pink',
            ];
            // Jumlah barang di dalam draf, bukan jumlah dokumen: draf selalu
            // tunggal (ADR 0027), yang berarti bagi Pengelola Gudang adalah
            // berapa barang yang menunggu diusulkan.
            $tugas[] = [
                'label' => 'Draf usulan pengadaan',
                'jumlah' => (int) ProcurementRequest::query()->draf()->withCount('details')->first()?->details_count,
                'url' => route('procurement-requests.draft.edit'),
                'ikon' => 'file-plus',
                'warna' => 'purple',
            ];
            $tugas[] = [
                'label' => 'Opname belum selesai',
                'jumlah' => StockOpname::query()->where('status', OpnameStatus::Draf)->count(),
                'url' => route('opname.index', ['status' => OpnameStatus::Draf->value]),
                'ikon' => 'clipboard-check',
                'warna' => 'orange',
            ];
        }

        array_push($tugas, ...$this->tugasPermintaan($user));

        return array_values(array_filter($tugas, fn (array $baris) => $baris['jumlah'] > 0));
    }

    /**
     * @return array{label: string, jumlah: int, url: string, ikon: string, warna: string}
     */
    private function tugasRealisasi(string $label, ProcurementStatus $status, string $ikon, string $warna): array
    {
        return [
            'label' => $label,
            'jumlah' => Procurement::query()->where('status', $status)->count(),
            'url' => route('procurements.index', ['status' => $status->value]),
            'ikon' => $ikon,
            'warna' => $warna,
        ];
    }

    /**
     * @return array{label: string, jumlah: int, url: string, ikon: string, warna: string}
     */
    private function tugasUsulan(string $label, ProcurementRequestStatus $status, string $ikon): array
    {
        return [
            'label' => $label,
            'jumlah' => ProcurementRequest::query()->where('status', $status)->count(),
            'url' => route('procurement-requests.index', ['status' => $status->value]),
            'ikon' => $ikon,
            'warna' => 'purple',
        ];
    }

    /**
     * Antrean permintaan barang (sub sistem Alma). Pemetaan peran mengikuti
     * penerima NotificationScanner::periksaPermintaan(), dan cakupannya
     * mengikuti RequestController::index() — permintaan umum dikecualikan,
     * Kepala Unit hanya melihat unitnya, Pengguna hanya miliknya — supaya
     * angkanya sama dengan daftar yang ditautkan.
     *
     * @return array<int, array{label: string, jumlah: int, url: string, ikon: string, warna: string}>
     */
    private function tugasPermintaan(User $user): array
    {
        $permintaan = fn (): Builder => ModelsRequest::query()->where('type', '!=', 'umum');
        $baris = fn (string $label, int $jumlah, ?string $status) => [
            'label' => $label,
            'jumlah' => $jumlah,
            'url' => route('request.index', $status === null ? [] : ['status' => $status]),
            'ikon' => 'inbox',
            'warna' => 'sky',
        ];

        $tugas = [];

        if ($user->isAdmin()) {
            $tugas[] = $baris('Permintaan menunggu persetujuan', $permintaan()->where('status', 'submitted')->count(), 'submitted');
        }

        if ($user->can('kelola-permintaan')) {
            $tugas[] = $baris('Permintaan perlu diproses', $permintaan()->where('status', 'approved_by_head')->count(), 'approved_by_head');
        }

        if ($user->isAdmin()) {
            $tugas[] = $baris('Permintaan menunggu penyelesaian', $permintaan()->where('status', 'processed_by_staff')->count(), 'processed_by_staff');
        }

        if ($user->hasRole(UserRole::KepalaUnit)) {
            $tugas[] = $baris(
                'Permintaan menunggu persetujuan',
                $permintaan()
                    ->where('status', 'submitted')
                    ->whereHas('user', fn (Builder $query) => $query->where('unit', $user->unit))
                    ->count(),
                'submitted',
            );
        }

        if ($user->hasRole(UserRole::Pengguna)) {
            $tugas[] = $baris(
                'Permintaan saya yang berjalan',
                $permintaan()
                    ->where('user_id', $user->user_id)
                    ->whereIn('status', self::PERMINTAAN_BERJALAN)
                    ->count(),
                null,
            );
        }

        return $tugas;
    }

    /**
     * Sebaran status stok barang aktif, ditambah barang yang paling mendesak
     * diisi ulang. `bertaut` false bagi peran tanpa `akses-gudang` (Pejabat
     * Pengadaan): datanya tetap berguna untuk merencanakan pengadaan, tetapi
     * halaman barang tertutup baginya.
     *
     * @return array{total: int, sebaran: array<int, array{status: StockStatus, jumlah: int, persen: float, url: ?string}>, kritis: \Illuminate\Support\Collection<int, Item>, bertaut: bool}
     */
    private function kondisiStok(bool $bertaut): array
    {
        $jumlah = collect(StockStatus::cases())->mapWithKeys(
            fn (StockStatus $status) => [$status->value => Item::aktif()->stockStatus($status)->count()],
        );
        $total = (int) $jumlah->sum();

        return [
            'total' => $total,
            'sebaran' => collect(StockStatus::cases())
                ->map(fn (StockStatus $status) => [
                    'status' => $status,
                    'jumlah' => $jumlah[$status->value],
                    'persen' => $total > 0 ? round($jumlah[$status->value] / $total * 100, 2) : 0.0,
                    'url' => $bertaut ? route('items.index', ['stock' => $status->value, 'status' => 'aktif']) : null,
                ])
                ->all(),
            // Rasio stok terhadap minimum, bukan selisihnya: kurang 5 dari
            // minimum 10 lebih mendesak daripada kurang 5 dari minimum 100.
            'kritis' => Item::aktif()
                ->diBawahMinimum()
                ->orderByRaw('CASE WHEN stock_min > 0 THEN stock_current * 1.0 / stock_min ELSE 0 END')
                ->orderBy('item_code')
                ->limit(self::BARANG_KRITIS)
                ->get(),
            'bertaut' => $bertaut,
        ];
    }

    /**
     * Jumlah permintaan barang di tiap tahap, dengan cakupan yang sama seperti
     * daftar permintaan (Request::terlihatOleh): Pengguna hanya miliknya,
     * Kepala Unit hanya unitnya. Permintaan umum dikecualikan karena punya
     * daftarnya sendiri. Ditolak menempel pada tahap Selesai sebagai akhir
     * yang lain: penolakan bisa terjadi di tahap mana pun, dan daftar
     * permintaan tidak bisa menyaring menurut tahap asal penolakannya.
     *
     * @return array<int, array{label: string, jumlah: int, url: string, ditolak?: array{jumlah: int, url: string}}>
     */
    private function alurPermintaan(User $user): array
    {
        $jumlah = ModelsRequest::query()
            ->where('type', '!=', 'umum')
            ->terlihatOleh($user)
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');
        $tahap = fn (string $status) => [
            'label' => ModelsRequest::labelStatus($status),
            'jumlah' => (int) ($jumlah[$status] ?? 0),
            'url' => route('request.index', ['status' => $status]),
        ];

        $selesai = $tahap('completed');
        $ditolak = $tahap('rejected');

        if ($ditolak['jumlah'] > 0) {
            $selesai['ditolak'] = ['jumlah' => $ditolak['jumlah'], 'url' => $ditolak['url']];
        }

        return [
            $tahap('submitted'),
            $tahap('approved_by_head'),
            $tahap('processed_by_staff'),
            $tahap('in_issuance'),
            $selesai,
        ];
    }

    /**
     * Jumlah dokumen di tiap tahap alur pengadaan. Draf tidak ikut (ADR 0027).
     * Tahap terakhir adalah total Diterima, bukan "bulan ini": filter tanggal
     * daftar realisasi menyaring `procurement_date`, bukan tanggal terima,
     * sehingga angka bulanan tidak bisa ditautkan ke daftar yang sama.
     *
     * @return array<int, array{label: string, jumlah: int, url: string, ditolak?: array{jumlah: int, url: string}}>
     */
    private function alurPengadaan(): array
    {
        $usulan = fn (ProcurementRequestStatus $status) => [
            'label' => $status === ProcurementRequestStatus::Diajukan ? 'Usulan Diajukan' : $status->label(),
            'jumlah' => ProcurementRequest::query()->where('status', $status)->count(),
            'url' => route('procurement-requests.index', ['status' => $status->value]),
        ];
        $realisasi = fn (ProcurementStatus $status) => [
            'label' => $status->label(),
            'jumlah' => Procurement::query()->where('status', $status)->count(),
            'url' => route('procurements.index', ['status' => $status->value]),
        ];

        $menungguPersetujuan = $realisasi(ProcurementStatus::MenungguPersetujuan);
        $ditolak = $realisasi(ProcurementStatus::Ditolak);

        // Ditolak menempel pada tahap persetujuan karena bukan keadaan akhir:
        // dokumennya diperbaiki lalu kembali menunggu persetujuan (ADR 0030).
        if ($ditolak['jumlah'] > 0) {
            $menungguPersetujuan['ditolak'] = ['jumlah' => $ditolak['jumlah'], 'url' => $ditolak['url']];
        }

        return [
            $usulan(ProcurementRequestStatus::Diajukan),
            $usulan(ProcurementRequestStatus::DisetujuiMenungguRealisasi),
            $menungguPersetujuan,
            $realisasi(ProcurementStatus::ProsesBelanja),
            $realisasi(ProcurementStatus::MenungguPenerimaan),
            $realisasi(ProcurementStatus::Diterima),
        ];
    }

    /**
     * Wadah bulan untuk grafik: enam bulan terakhir, termasuk bulan berjalan.
     *
     * @return \Illuminate\Support\Collection<string, Carbon>
     */
    private function bulanGrafik(): \Illuminate\Support\Collection
    {
        $awal = now()->startOfMonth()->subMonths(self::BULAN_GRAFIK - 1);

        return collect(range(0, self::BULAN_GRAFIK - 1))
            ->mapWithKeys(fn (int $i) => [
                $awal->copy()->addMonths($i)->format('Y-m') => $awal->copy()->addMonths($i),
            ]);
    }

    /**
     * Jumlah transaksi masuk dan keluar per bulan menurut `mutation_date`.
     *
     * Jumlah baris, bukan kuantitas: satuan barang bercampur (rim, botol, pcs),
     * sehingga menjumlahkan kuantitasnya tidak bermakna. Saldo awal dikecualikan
     * karena bukan pergerakan barang. Arah dibaca dari tanda `amount`, yang
     * sudah ditetapkan StockLedger, sehingga penyesuaian dan koreksi ikut di
     * sisi yang benar. Pengelompokan per bulan di PHP supaya sama di MySQL dan
     * SQLite.
     *
     * @return array{bulan: array<int, string>, bulanPanjang: array<int, string>, masuk: array<int, int>, keluar: array<int, int>, total: int}
     */
    private function grafikMutasi(): array
    {
        $bulan = $this->bulanGrafik();
        $masuk = $bulan->map(fn () => 0)->all();
        $keluar = $masuk;

        $mutasi = StockMutation::query()
            ->where('mutation_type', '!=', MutationType::SaldoAwal)
            ->whereDate('mutation_date', '>=', $bulan->first())
            ->whereDate('mutation_date', '<=', now()->endOfMonth())
            ->get(['mutation_date', 'amount']);

        foreach ($mutasi as $baris) {
            $kunci = $baris->mutation_date->format('Y-m');

            if ($baris->amount > 0) {
                $masuk[$kunci]++;
            } elseif ($baris->amount < 0) {
                $keluar[$kunci]++;
            }
        }

        return [
            'bulan' => $bulan->map(fn (Carbon $tanggal) => $tanggal->translatedFormat('M'))->values()->all(),
            'bulanPanjang' => $bulan->map(fn (Carbon $tanggal) => $tanggal->translatedFormat('F Y'))->values()->all(),
            'masuk' => array_values($masuk),
            'keluar' => array_values($keluar),
            'total' => array_sum($masuk) + array_sum($keluar),
        ];
    }

    /**
     * Nilai barang yang diterima per bulan menurut tanggal terima, dari
     * `amount_received` (ADR 0017) lewat Procurement::nilaiDiterima() — rumus
     * yang sama dengan rekap daftar realisasi. Hanya status Diterima, sehingga
     * realisasi yang ditolak atau dibatalkan tidak pernah terhitung (ADR 0026).
     *
     * @return array{bulan: array<int, string>, bulanPanjang: array<int, string>, nilai: array<int, int>, total: int}
     */
    private function grafikNilaiPengadaan(): array
    {
        $bulan = $this->bulanGrafik();
        $nilai = $bulan->map(fn () => 0)->all();

        $diterima = Procurement::query()
            ->where('status', ProcurementStatus::Diterima)
            ->whereHas('receipt', fn (Builder $query) => $query
                ->where('received_at', '>=', $bulan->first())
                ->where('received_at', '<=', now()->endOfMonth()))
            ->with(['receipt', 'details'])
            ->get();

        foreach ($diterima as $procurement) {
            $nilai[$procurement->receipt->received_at->format('Y-m')] += $procurement->nilaiDiterima();
        }

        return [
            'bulan' => $bulan->map(fn (Carbon $tanggal) => $tanggal->translatedFormat('M'))->values()->all(),
            'bulanPanjang' => $bulan->map(fn (Carbon $tanggal) => $tanggal->translatedFormat('F Y'))->values()->all(),
            'nilai' => array_values($nilai),
            'total' => array_sum($nilai),
        ];
    }
}
