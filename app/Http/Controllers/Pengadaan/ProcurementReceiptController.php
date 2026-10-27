<?php

namespace App\Http\Controllers\Pengadaan;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Exceptions\ProcurementTidakSiapException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pengadaan\ConfirmProcurementReceiptRequest;
use App\Http\Requests\Pengadaan\UpdateBastNumberRequest;
use App\Models\Procurement;
use App\Support\AuditLogger;
use App\Support\ProcurementReceiptPoster;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Konfirmasi penerimaan barang pengadaan — tugas Pengelola Gudang, terpisah
 * dari yang merealisasi (Pejabat Pengadaan). Lihat ADR 0008.
 *
 * Peristiwa sekali jadi: begitu dikonfirmasi, `ProcurementReceiptPoster`
 * menambah stok lewat StockLedger dan mengunci realisasi ke status Diterima.
 * Tidak ada rute ubah maupun hapus — kecuali nomor BAST, yang bukan bagian
 * dari peristiwanya dan diisi menyusul saat BAST dicetak (ADR 0042).
 */
class ProcurementReceiptController extends Controller
{
    public function create(Procurement $procurement): View|RedirectResponse
    {
        // Gudang tidak menerima barang yang belum ditandai dibelanjakan: fakta
        // belanja dan fotonya dicatat oleh yang membelanjakan, bukan ditebak
        // oleh yang menerima (ADR 0035).
        if ($procurement->isProsesBelanja()) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', ProcurementTidakSiapException::belumDibelanjakan()->getMessage());
        }

        if (! $procurement->isMenungguPenerimaan()) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', "{$procurement->judul()} belum disetujui, sudah dikonfirmasi diterima, atau dibatalkan.");
        }

        return view('pages.procurements.confirm-receipt', [
            'title' => "Konfirmasi Penerimaan — {$procurement->judul()}",
            'procurement' => $procurement->load(['supplier', 'details.item']),
        ]);
    }

    public function store(ConfirmProcurementReceiptRequest $request, Procurement $procurement): RedirectResponse
    {
        $data = $request->validated();

        try {
            ProcurementReceiptPoster::post(
                procurement: $procurement,
                diterima: array_map(fn ($jumlah) => (int) $jumlah, $data['received']),
                actor: $request->user(),
                receivedAt: Carbon::parse($data['received_at']),
                notes: $data['notes'] ?? null,
            );
        } catch (ProcurementTidakSiapException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('procurements.show', $procurement)
            ->with('status', "Penerimaan {$procurement->judul()} berhasil dikonfirmasi.");
    }

    /**
     * Mengisi atau mengoreksi nomor BAST, lalu langsung mencetak BAST-nya.
     *
     * Nomornya berasal dari agenda kantor, bukan dari sistem, sehingga baru
     * diisi saat BAST hendak dicetak pertama kali. Koreksi diizinkan selama
     * BAST bertanda tangan belum diunggah: sesudahnya nomor itu sudah tercetak
     * pada bukti yang diarsipkan. Lihat ADR 0042.
     */
    public function nomorBast(UpdateBastNumberRequest $request, Procurement $procurement): RedirectResponse
    {
        $receipt = $procurement->receipt;

        if ($receipt === null) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', 'BAST baru dapat dicetak setelah penerimaan barang dikonfirmasi.');
        }

        if (! $procurement->nomorBastDapatDiubah()) {
            return redirect()
                ->route('procurements.show', $procurement)
                ->with('error', 'Nomor BAST terkunci karena BAST bertanda tangan sudah diunggah.');
        }

        $lama = $receipt->bast_number;
        $baru = $request->validated('bast_number');

        if ($lama !== $baru) {
            $receipt->update(['bast_number' => $baru]);

            AuditLogger::record(
                user: $request->user(),
                activity: $lama === null
                    ? "Mengisi nomor BAST {$baru} untuk {$procurement->judul()}"
                    : "Mengubah nomor BAST {$procurement->judul()} dari {$lama} menjadi {$baru}",
                module: AuditModule::Penerimaan,
                event: AuditEvent::Ubah,
                subject: $procurement,
            );
        }

        return redirect()->route('procurements.bast', $procurement);
    }
}
