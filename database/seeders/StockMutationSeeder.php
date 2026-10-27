<?php

namespace Database\Seeders;

use App\Enums\MutationType;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\User;
use App\Support\StockLedger;
use Illuminate\Database\Seeder;

class StockMutationSeeder extends Seeder
{
    /**
     * Contoh pergerakan stok selama 30 hari terakhir untuk pengembangan.
     *
     * Semuanya lewat StockLedger sehingga stock_after dan stock_current tetap
     * konsisten dengan buku mutasi.
     *
     * CATATAN — keterbatasan data contoh ini:
     *
     * - `reference_id` sengaja dibiarkan null karena modul pengadaan dan
     *   permintaan belum ada, jadi belum ada baris dokumen untuk ditunjuk.
     *   `reference_type` tetap diisi supaya kolom Sumber pada layar riwayat
     *   bisa diuji. Begitu modulnya jadi, seeder ini harus menunjuk dokumen
     *   yang sungguh ada.
     * - Nomor dokumen di bawah adalah PERKIRAAN bergaya dokumen dinas. Format
     *   resmi BPK belum dikonfirmasi; ini hanya memengaruhi data contoh.
     */
    public function run(): void
    {
        // Mutasi Masuk diatribusikan ke Pengelola Gudang, bukan Pejabat
        // Pengadaan, karena dialah yang mengonfirmasi barang fisik diterima —
        // penerapan pemisahan tugas. Lihat ADR 0008.
        $petugas = User::where('role', UserRole::PengelolaGudang)->first()
            ?? User::where('role', UserRole::Admin)->first();

        $items = Item::aktif()->get();

        if ($items->isEmpty()) {
            $this->command?->warn('Belum ada barang. Jalankan ItemSeeder terlebih dahulu.');

            return;
        }

        $dicatat = 0;
        $dilewati = 0;

        foreach ($items as $index => $item) {
            foreach ($this->skenario($index) as [$type, $amount, $hariLalu, $refNumber, $refType, $notes]) {
                try {
                    $mutation = StockLedger::record(
                        item: $item,
                        type: $type,
                        amount: $amount,
                        recordedBy: $petugas,
                        notes: $notes,
                        referenceNumber: $refNumber,
                        date: now()->subDays($hariLalu),
                    );

                    $dicatat++;
                } catch (InsufficientStockException) {
                    // Stok contoh tidak selalu cukup untuk tiap skenario; lewati saja.
                    $dilewati++;

                    continue;
                }

                // StockLedger menurunkan reference_type dari model dokumen yang
                // dikirim. Karena modul dokumennya belum ada, jenis sumber diisi
                // di sini memakai objek yang dikembalikan record() — jangan
                // mencarinya ulang lewat query, karena seluruh baris seeder
                // dibuat pada detik yang sama sehingga urutannya tidak pasti.
                if ($refType !== null) {
                    $mutation->forceFill(['reference_type' => $refType])->save();
                }
            }
        }

        $this->command?->info("Mutasi contoh dibuat: {$dicatat} baris ({$dilewati} dilewati karena stok tidak cukup).");
    }

    /**
     * Rangkaian mutasi per barang. Variasi ditentukan indeks barang supaya data
     * contoh tidak seragam tetapi tetap dapat diulang.
     *
     * Keterangan dirakit dari data dokumen yang realistis — bukan kalimat tetap
     * yang mengandung asumsi jadwal pengadaan.
     *
     * @return array<int, array{0: MutationType, 1: int, 2: int, 3: ?string, 4: ?string, 5: string}>
     */
    private function skenario(int $index): array
    {
        $seri = str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
        $penyedia = ['CV Sinar Jaya', 'CV Mitra Utama', 'PT Karya Mandiri', 'CV Bumi Lestari'][$index % 4];
        $unit = [UnitKerja::SubbagianUmumTi, UnitKerja::BidangPemeriksaanLampungI, UnitKerja::SekretariatPerwakilan][$index % 3]->label();

        $dasar = [
            [MutationType::Masuk, 24, 26, "{$seri}/PPBJ/2026", 'pengadaan',
                "Penerimaan pengadaan {$seri}/PPBJ/2026 — {$penyedia}"],
            [MutationType::Keluar, 5, 21, "{$seri}/ND/2026", 'permintaan',
                "Penyerahan ke {$unit} sesuai ND {$seri}/ND/2026"],
            [MutationType::Keluar, 3, 12, '1'.$seri.'/ND/2026', 'permintaan',
                "Penyerahan ke {$unit} sesuai ND 1{$seri}/ND/2026"],
        ];

        // Setiap barang keempat mendapat satu mutasi manual sebagai contoh.
        $manual = match ($index % 4) {
            0 => [[MutationType::BarangRusak, 2, 8, null, null, 'Kemasan rusak saat penyimpanan.']],
            1 => [[MutationType::HibahMasuk, 10, 9, null, null, 'Hibah dari kantor pusat.']],
            2 => [[MutationType::ReturDariUnit, 4, 5, null, null, "Dikembalikan {$unit} karena kelebihan."]],
            default => [[MutationType::Kadaluarsa, 1, 3, null, null, 'Melewati masa pakai.']],
        };

        return [...$dasar, ...$manual];
    }
}
