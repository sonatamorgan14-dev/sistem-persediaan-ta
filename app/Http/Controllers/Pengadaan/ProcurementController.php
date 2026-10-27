<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\CancelProcurementRequest;
use App\Http\Requests\Pengadaan\StoreProcurementRequest;
use App\Http\Requests\Pengadaan\UpdateProcurementRequest;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\ProcurementDetail;
use App\Models\ProcurementRequest;
use App\Models\Supplier;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Realisasi pengadaan — dibuat Pejabat Pengadaan dari satu usulan yang sudah
 * disetujui, menautkannya ke penyedia. Berhenti di status MenungguPenerimaan
 * sampai Pengelola Gudang mengonfirmasi penerimaan lewat
 * ProcurementReceiptController; stok baru bertambah saat itu. Lihat ADR 0008,
 * ADR 0015, dan ADR 0016.
 *
 * Mengurus pula keluaran cetak Tahap 4: BAST per realisasi, serta laporan
 * pengadaan PDF dan CSV yang isinya mengikuti penyaring yang sama dengan
 * layar (ADR 0009).
 */
class ProcurementController extends Controller
{
    private const PER_PAGE = 10;

    /**
     * Batas aman ekspor. Lebih kecil daripada batas laporan mutasi karena satu
     * realisasi membawa seluruh rincian barangnya, bukan satu baris.
     */
    private const EXPORT_LIMIT = 2000;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('pages.procurements.index', [
            'title' => 'Realisasi Pengadaan',
            'procurements' => $this->filteredQuery($filters)
                ->with(['supplier', 'details'])
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'filters' => $filters,
            'summary' => $this->summary($filters),
            // Termasuk penyedia yang sudah diarsipkan: realisasi lama tetap
            // atas namanya dan harus tetap dapat disaring.
            'suppliers' => Supplier::query()
                ->orderBy('supplier_name')
                ->pluck('supplier_name', 'supplier_id')
                ->all(),
        ]);
    }

    /**
     * Formulir realisasi, dua pintu masuk.
     *
     * Dengan `?procurement_request_id=` — tombol "Realisasikan" pada halaman
     * usulan: usulannya wajib sudah disetujui dan belum punya realisasi.
     *
     * Tanpa query itu — tombol "Buat Realisasi Langsung" pada daftar realisasi:
     * Pejabat Pengadaan menyusun sendiri daftar barangnya, dan gantinya wajib
     * menyebutkan dasar pengadaannya (ADR 0031). Query yang **ada tetapi tidak
     * sah** tetap ditolak seperti sebelumnya, bukan diam-diam dialihkan ke
     * formulir langsung — salah tempel tautan tidak boleh berubah diam-diam
     * menjadi pengadaan tanpa usulan.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $procurementRequest = null;

        if ($request->query('procurement_request_id') !== null) {
            $procurementRequest = ProcurementRequest::with('details.item')
                ->find((string) $request->query('procurement_request_id', ''));

            if ($procurementRequest === null) {
                return redirect()
                    ->route('procurement-requests.index')
                    ->with('error', 'Usulan pengadaan tidak ditemukan.');
            }

            if (! $procurementRequest->isDisetujuiMenungguRealisasi()) {
                return redirect()
                    ->route('procurement-requests.show', $procurementRequest)
                    ->with('error', 'Usulan ini tidak dapat direalisasikan. Realisasi hanya dari usulan yang sudah disetujui dan belum memiliki realisasi.');
            }
        }

        return view('pages.procurements.create', [
            'title' => $procurementRequest === null ? 'Realisasi Pengadaan Langsung' : 'Realisasi Pengadaan',
            'procurementRequest' => $procurementRequest,
            'rincianDisetujui' => $procurementRequest === null
                ? collect()
                : $procurementRequest->details->filter(fn ($detail) => $detail->disetujui())->values(),
            'suppliers' => Supplier::aktif()->orderBy('supplier_name')->get(['supplier_id', 'supplier_name']),
            // Untuk pemilih "barang tambahan" — Pejabat Pengadaan boleh
            // merealisasikan barang di luar usulan (ADR 0029), dan pada
            // realisasi langsung seluruh barisnya justru dari pemilih ini.
            'items' => Item::aktif()->orderBy('item_code')->get(['item_id', 'item_code', 'item_name', 'unit']),
        ]);
    }

    public function store(StoreProcurementRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $rincian = $request->rincianDisetujui();

        $usulanId = $data['procurement_request_id'] ?? null;

        $procurement = DB::transaction(function () use ($request, $data, $rincian, $usulanId) {
            $procurement = Procurement::create([
                'procurement_request_id' => $usulanId,
                'supplier_id' => $data['supplier_id'],
                'procurement_date' => $data['procurement_date'],

                // Dasar pengadaan hanya diisi pada realisasi langsung. Pada
                // realisasi berusulan dasarnya adalah usulan itu sendiri, dan
                // menyimpan alasan kedua di sini hanya akan menjadi data yang
                // tidak pernah ditampilkan (ADR 0031).
                'basis_reason' => $usulanId === null ? $data['basis_reason'] : null,
                'basis_note' => $usulanId === null ? $data['basis_note'] : null,

                // Dokumennya tersimpan, tetapi belum berlaku: penyedia, barang,
                // dan harganya justru yang dinilai Kasubbag Umum dan TI lebih
                // dulu (ADR 0030).
                'status' => ProcurementStatus::MenungguPersetujuan,
                'user_id' => $request->user()->user_id,
            ]);

            $procurement->details()->createMany(
                array_map(fn (array $item) => [
                    // Baris usulan mengirim procurement_request_detail_id (item_id
                    // diturunkan dari sana); baris tambahan mengirim item_id
                    // langsung — lihat StoreProcurementRequest::withValidator().
                    'item_id' => empty($item['procurement_request_detail_id'])
                        ? $item['item_id']
                        : $rincian->get($item['procurement_request_detail_id'])->item_id,
                    'amount' => $item['amount'],
                    'unit_price' => $item['unit_price'],
                ], $data['items']),
            );

            // Satu-satunya tempat baris `procurements` lahir dari sebuah
            // usulan, sehingga satu-satunya tempat transisi ini terjadi.
            // Permanen — tidak pernah berbalik meski realisasinya kelak
            // dibatalkan (ADR 0028). Realisasi langsung tidak punya usulan
            // untuk ditransisikan.
            if ($usulanId !== null) {
                ProcurementRequest::whereKey($usulanId)
                    ->update(['status' => ProcurementRequestStatus::DisetujuiSelesai]);
            }

            return $procurement;
        });

        AuditLogger::record(
            user: $request->user(),
            activity: $usulanId === null
                ? "Membuat realisasi pengadaan langsung: {$procurement->judul()} dengan dasar {$procurement->basis_reason->label()}"
                : "Membuat realisasi pengadaan: {$procurement->judul()}",
            module: AuditModule::Pengadaan,
            event: AuditEvent::Tambah,
            subject: $procurement,
        );

        NotificationScanner::periksaRealisasi($procurement->fresh('supplier'));

        // Usulannya baru saja berhenti menganggur, jadi tagihan
        // UsulanBelumDirealisasi ikut ditutup seketika alih-alih menunggu
        // sapuan terjadwal — sejalan dengan catatUsulanDiputuskan() yang juga
        // menyegarkan keadaan usulannya sendiri.
        if ($usulanId !== null) {
            NotificationScanner::periksaUsulan($procurement->procurementRequest);
        }

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} berhasil dibuat.");
    }

    public function show(Procurement $procurement): View
    {
        return view('pages.procurements.show', [
            'title' => $procurement->judul(),
            'procurement' => $procurement->load([
                'user', 'supplier', 'procurementRequest', 'details.item',
                'receipt.receivedBy', 'documents.uploadedBy', 'cancelledBy', 'reviewedBy', 'purchasedBy',
            ]),
        ]);
    }

    /**
     * Koreksi realisasi yang belum dikonfirmasi diterima.
     *
     * Selama barang belum dikonfirmasi diterima belum ada apa pun yang terjadi
     * secara fisik — stok belum bertambah, tidak ada mutasi maupun BAST —
     * sehingga dokumennya masih boleh diperbaiki. Pola yang sama dengan draf
     * opname yang boleh diubah sampai diposting (ADR 0010). Lihat ADR 0025.
     *
     * Sejak ADR 0030 pintu ini melebar ke dua keadaan baru sekaligus: realisasi
     * yang masih menunggu persetujuan, dan realisasi yang **ditolak** —
     * memperbaiki lalu menyimpannya adalah satu-satunya cara mengajukannya
     * ulang.
     */
    public function edit(Procurement $procurement): View|RedirectResponse
    {
        if (! $procurement->masihBerjalan()) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', "{$procurement->judul()} tidak dapat diubah karena sudah dikonfirmasi diterima atau dibatalkan.");
        }

        return view('pages.procurements.edit', [
            'title' => "Ubah {$procurement->judul()}",
            'procurement' => $procurement->load(['supplier', 'procurementRequest.details', 'details.item']),
            'suppliers' => Supplier::aktif()->orderBy('supplier_name')->get(['supplier_id', 'supplier_name']),
            'items' => Item::aktif()->orderBy('item_code')->get(['item_id', 'item_code', 'item_name', 'unit']),
        ]);
    }

    public function update(UpdateProcurementRequest $request, Procurement $procurement): RedirectResponse
    {
        if (! $procurement->masihBerjalan()) {
            return back()->with(
                'error',
                "{$procurement->judul()} tidak dapat diubah karena sudah dikonfirmasi diterima atau dibatalkan.",
            );
        }

        $data = $request->validated();
        // Sejak ADR 0035 persetujuan yang masih berlaku ada di dua status.
        $sebelumnyaDisetujui = $procurement->isProsesBelanja() || $procurement->isMenungguPenerimaan();

        DB::transaction(function () use ($procurement, $data) {
            $procurement->forceFill([
                'supplier_id' => $data['supplier_id'],
                'procurement_date' => $data['procurement_date'],

                // Hanya berlaku pada realisasi langsung; usulan asalnya tidak
                // dapat dipindah, jadi keadaan "tanpa usulan" tidak pernah
                // berubah sepanjang hidup dokumen ini (ADR 0031).
                'basis_reason' => $procurement->tanpaUsulan() ? $data['basis_reason'] : null,
                'basis_note' => $procurement->tanpaUsulan() ? $data['basis_note'] : null,

                // Satu aturan menutup dua lubang sekaligus (ADR 0030).
                // Pertama: realisasi yang ditolak tidak punya rute "ajukan
                // ulang" tersendiri — menyimpan perbaikannya itulah
                // pengajuan ulangnya. Kedua: tanpa ini, persetujuan dapat
                // diakali — mintakan persetujuan atas harga yang wajar, lalu
                // ubah harganya setelah disetujui. Isi dokumen berubah,
                // penilaiannya pun harus diulang.
                'status' => ProcurementStatus::MenungguPersetujuan,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'review_note' => null,
            ])->save();

            // Baris usulan diperbarui di tempat, bukan dihapus lalu dibuat
            // ulang: `procurement_detail_id` yang stabil membuat formulir
            // penerimaan tetap menunjuk baris yang sama. Baris tambahan yang
            // baru (tanpa procurement_detail_id) dibuat sekarang; yang hilang
            // dari kiriman berarti sengaja dihapus (ADR 0029) — validasi sudah
            // memastikan baris dari usulan tidak mungkin hilang di sini.
            $dikirim = [];

            foreach ($data['items'] as $baris) {
                if (empty($baris['procurement_detail_id'])) {
                    $baru = $procurement->details()->create([
                        'item_id' => $baris['item_id'],
                        'amount' => $baris['amount'],
                        'unit_price' => $baris['unit_price'],
                    ]);
                    $dikirim[] = $baru->procurement_detail_id;

                    continue;
                }

                $procurement->details
                    ->firstWhere('procurement_detail_id', $baris['procurement_detail_id'])
                    ?->forceFill([
                        'amount' => $baris['amount'],
                        'unit_price' => $baris['unit_price'],
                    ])->save();
                $dikirim[] = $baris['procurement_detail_id'];
            }

            $procurement->details
                ->reject(fn (ProcurementDetail $detail) => in_array($detail->procurement_detail_id, $dikirim, true))
                ->each(fn (ProcurementDetail $detail) => $detail->delete());
        });

        AuditLogger::record(
            user: $request->user(),
            activity: "Mengubah realisasi pengadaan: {$procurement->judul()}",
            module: AuditModule::Pengadaan,
            event: AuditEvent::Ubah,
            subject: $procurement,
        );

        // Isi notifikasi RealisasiMenungguPenerimaan memuat nama penyedia,
        // sehingga ia basi begitu penyedianya diganti. Pemeriksaan ini sekaligus
        // menutup tagihan penerimaan yang sudah tidak berlaku dan membuka
        // tagihan persetujuan yang baru.
        NotificationScanner::periksaRealisasi($procurement->fresh(['supplier', 'receipt']));

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', $sebelumnyaDisetujui
                ? "{$procurement->judul()} berhasil diperbarui dan perlu disetujui ulang."
                : "{$procurement->judul()} berhasil diperbarui dan diajukan ke Kasubbag Umum dan TI.");
    }

    /**
     * Membatalkan realisasi yang tidak akan pernah sampai ke penerimaan.
     *
     * Hanya mungkin selama barang belum dikonfirmasi diterima. Begitu barang
     * diterima, stoknya sudah bertambah lewat mutasi yang immutable (ADR 0005)
     * dan tidak ada lagi yang boleh dibatalkan — karena itu tidak ada satu pun
     * mutasi yang perlu dibalik di sini, dan `StockLedger` tidak tersentuh sama
     * sekali. Lihat ADR 0024.
     *
     * Sejak ADR 0030 inilah jalan keluar bagi realisasi yang **ditolak** dan
     * memang tidak akan diperbaiki: penolakan sendiri tidak terminal, jadi
     * tanpa pembatalan dokumennya akan menggantung selamanya.
     */
    public function cancel(CancelProcurementRequest $request, Procurement $procurement): RedirectResponse
    {
        // Ditolak dengan pesan, bukan 500 — pola yang sama dengan penjaga
        // keputusan ganda pada ProcurementRequestController::approve().
        if (! $procurement->masihBerjalan()) {
            return back()->with(
                'error',
                "{$procurement->judul()} tidak dapat dibatalkan karena sudah dikonfirmasi diterima atau sudah dibatalkan sebelumnya.",
            );
        }

        $alasan = $request->alasan();

        $procurement->forceFill([
            'status' => ProcurementStatus::Dibatalkan,
            'cancelled_at' => now(),
            'cancelled_by' => $request->user()->user_id,
            'cancel_reason' => $alasan,
            'cancel_note' => $request->input('cancel_note'),
        ])->save();

        AuditLogger::record(
            user: $request->user(),
            activity: "Membatalkan {$procurement->judul()} dengan alasan {$alasan->label()}",
            module: AuditModule::Pengadaan,
            event: AuditEvent::Batal,
            subject: $procurement,
        );

        // Menutup RealisasiMenungguPenerimaan (syaratnya tidak lagi terpenuhi)
        // sekaligus memberi tahu gudang bahwa barangnya tidak jadi datang.
        NotificationScanner::catatRealisasiDibatalkan(
            $procurement->fresh(['supplier', 'receipt']),
            $request->user(),
        );

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "{$procurement->judul()} berhasil dibatalkan.");
    }

    /**
     * BAST siap tanda tangan, dicetak dari sistem dengan format resmi BPK:
     * serah terima dari Pelaksana Belanja kepada Petugas Persediaan, rincian
     * barangnya di lampiran. Lihat ADR 0042.
     *
     * Isinya memakai `amount_received` — yang benar-benar datang — bukan
     * `amount` yang dipesan. Menandatangani jumlah pesanan atas barang yang
     * datang kurang justru membuat dokumennya berbohong. Lihat ADR 0017.
     *
     * Baru tersedia setelah penerimaan dikonfirmasi dan nomornya diisi —
     * nomor itu diisi lewat `ProcurementReceiptController::nomorBast()`.
     */
    public function bast(Procurement $procurement): Response|RedirectResponse
    {
        $procurement->load(['supplier', 'details.item', 'receipt.receivedBy', 'purchasedBy', 'user']);

        if ($procurement->receipt === null) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', 'BAST baru dapat dicetak setelah penerimaan barang dikonfirmasi.');
        }

        if ($procurement->receipt->bast_number === null) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', 'Isi nomor BAST terlebih dahulu.');
        }

        $pdf = Pdf::loadView('pdf.bast-pengadaan', [
            'procurement' => $procurement,
            'receipt' => $procurement->receipt,
        ])->setPaper('a4', 'portrait');

        // Garis miring diganti lebih dulu: Str::slug membuangnya begitu saja,
        // sehingga "001/UP_TUP/445237/2026" menjadi satu deret angka.
        return $pdf->stream('bast-'.Str::slug(str_replace('/', '-', $procurement->receipt->bast_number)).'.pdf');
    }

    public function exportPdf(Request $request): Response|RedirectResponse
    {
        $filters = $this->filters($request);

        if ($tooMany = $this->guardExportSize($filters)) {
            return $tooMany;
        }

        $pdf = Pdf::loadView('pdf.laporan-pengadaan', [
            'procurements' => $this->rincianLengkap($filters),
            'filters' => $filters,
            'summary' => $this->summary($filters),
            'namaPenyedia' => $this->namaPenyedia($filters),
            'dicetakOleh' => $request->user()->fullname,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream('laporan-pengadaan-'.now()->format('Ymd-His').'.pdf');
    }

    public function exportCsv(Request $request): StreamedResponse|RedirectResponse
    {
        $filters = $this->filters($request);

        if ($tooMany = $this->guardExportSize($filters)) {
            return $tooMany;
        }

        $procurements = $this->rincianLengkap($filters);
        $filename = 'laporan-pengadaan-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($procurements) {
            $handle = fopen('php://output', 'wb');

            // BOM UTF-8 supaya Excel membaca karakter Indonesia dengan benar.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Tanggal', 'Realisasi', 'Penyedia', 'Status', 'Dasar', 'Kode Barang', 'Nama Barang',
                'Satuan', 'Dipesan', 'Harga Satuan', 'Nilai Dipesan', 'Diterima',
                'Kurang Kirim', 'Nilai Diterima', 'Nomor BAST', 'Tanggal Diterima',
            ], ';');

            foreach ($procurements as $procurement) {
                foreach ($procurement->details as $detail) {
                    fputcsv($handle, [
                        $procurement->procurement_date->format('d/m/Y'),
                        $procurement->judul(),
                        $procurement->supplier?->supplier_name ?? '-',
                        $procurement->status->label(),
                        $procurement->dasarRingkas(),
                        $detail->item?->item_code ?? '-',
                        $detail->item?->item_name ?? '-',
                        $detail->item?->unit->label() ?? '-',
                        $detail->amount,
                        $detail->unit_price,
                        $detail->subtotal(),
                        $detail->sudahDiterima() ? $detail->amount_received : '',
                        $detail->kurangKirim(),
                        ($detail->amount_received ?? 0) * $detail->unit_price,
                        $procurement->receipt?->bast_number ?? '',
                        $procurement->receipt?->received_at->format('d/m/Y') ?? '',
                    ], ';');
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Penyaring yang dibaca sekali lalu dipakai bersama oleh layar, PDF, dan
     * CSV supaya isi laporan selalu identik dengan yang dilihat pengguna.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return [
            'status' => (string) $request->query('status', ''),
            'sumber' => (string) $request->query('sumber', ''),
            'supplier' => (string) $request->query('supplier', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return Procurement::query()
            ->filter($filters)
            ->latest('procurement_date')
            ->latest('created_at');
    }

    /**
     * Realisasi terpilih beserta seluruh isinya, diurutkan menaik supaya
     * laporan terbaca sebagai kronologi — kebalikan dari daftar di layar yang
     * menaruh yang terbaru di atas.
     *
     * @param  array<string, string>  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, Procurement>
     */
    private function rincianLengkap(array $filters)
    {
        return Procurement::query()
            ->filter($filters)
            // `procurementRequest` ikut dimuat karena kolom Dasar pada laporan
            // menyebut judul usulan asalnya (ADR 0031).
            ->with(['supplier', 'receipt', 'procurementRequest', 'details.item'])
            ->orderBy('procurement_date')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Rekap periode: cacah dokumen menurut status, dan dua sisi nilainya.
     *
     * Nilai dipesan dan nilai diterima sengaja dipisah — selisih keduanya
     * adalah kekurangan kiriman penyedia, angka yang justru hilang bila
     * keduanya dilebur jadi satu. Lihat ADR 0017.
     *
     * Realisasi yang dibatalkan **maupun ditolak** tidak ikut dihitung pada
     * `total` maupun `nilai`: keduanya dokumen yang pernah ada tetapi tidak
     * pernah menjadi transaksi. Cacahnya berdiri sendiri, sehingga tidak ada
     * yang disembunyikan sekaligus menyisakan invarian yang enak dibaca —
     * `total = menunggu_persetujuan + proses_belanja + menunggu + diterima`.
     * Lihat ADR 0026, ADR 0030, dan ADR 0035.
     *
     * `langsung` adalah **irisan** dari `total`, bukan penjumlahnya, sehingga
     * invarian di atas tidak terganggu. Angka ini yang membuat kebiasaan
     * memakai pintu tanpa usulan terlihat alih-alih hanya terasa (ADR 0031).
     *
     * @param  array<string, string>  $filters
     * @return array<string, int>
     */
    private function summary(array $filters): array
    {
        $base = Procurement::query()->filter($filters);
        $gagal = ProcurementStatus::takPernahJadiTransaksi();

        // Subkueri, bukan daftar id yang ditarik lebih dulu: jumlah realisasi
        // yang tersaring tidak dibatasi apa pun di layar daftar.
        $rincian = fn () => ProcurementDetail::query()->whereIn(
            'procurement_id',
            Procurement::query()
                ->filter($filters)
                ->whereNotIn('status', $gagal)
                ->select('procurement_id'),
        );

        return [
            'total' => (clone $base)->whereNotIn('status', $gagal)->count(),
            'langsung' => (clone $base)->whereNotIn('status', $gagal)->whereNull('procurement_request_id')->count(),
            'menunggu_persetujuan' => (clone $base)->where('status', ProcurementStatus::MenungguPersetujuan)->count(),
            'proses_belanja' => (clone $base)->where('status', ProcurementStatus::ProsesBelanja)->count(),
            'menunggu' => (clone $base)->where('status', ProcurementStatus::MenungguPenerimaan)->count(),
            'diterima' => (clone $base)->where('status', ProcurementStatus::Diterima)->count(),
            'ditolak' => (clone $base)->where('status', ProcurementStatus::Ditolak)->count(),
            'dibatalkan' => (clone $base)->where('status', ProcurementStatus::Dibatalkan)->count(),
            'nilai' => (int) $rincian()->sum(DB::raw('amount * unit_price')),
            'nilai_diterima' => (int) $rincian()->sum(DB::raw('COALESCE(amount_received, 0) * unit_price')),
        ];
    }

    /**
     * Nama penyedia yang sedang disaring, untuk dicetak di kepala laporan.
     *
     * @param  array<string, string>  $filters
     */
    private function namaPenyedia(array $filters): ?string
    {
        if ($filters['supplier'] === '') {
            return null;
        }

        return Supplier::query()->find($filters['supplier'])?->supplier_name;
    }

    /**
     * Tolak ekspor yang terlalu besar sebelum data dimuat ke memori.
     *
     * @param  array<string, string>  $filters
     */
    private function guardExportSize(array $filters): ?RedirectResponse
    {
        if (Procurement::query()->filter($filters)->count() <= self::EXPORT_LIMIT) {
            return null;
        }

        return back()->with('error', sprintf(
            'Data yang dipilih melebihi %s realisasi. Persempit rentang tanggal terlebih dahulu.',
            number_format(self::EXPORT_LIMIT, 0, ',', '.'),
        ));
    }
}
