<?php

namespace Database\Seeders;

use App\Enums\MutationType;
use App\Enums\SatuanBarang;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\User;
use App\Support\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ItemSeeder extends Seeder
{
    private const TANGGAL_SALDO_AWAL = '2026-10-01';

    /**
     * Master barang dan saldo awal kantor, dari dua berkas di data/.
     *
     * Barang yang tidak tercantum di saldo awal tetap didaftarkan, tetapi
     * diarsipkan: stoknya nol dan kodenya harus ada agar tidak didaftarkan
     * ulang dengan kode lain. Stok awal ditulis lewat StockLedger, bukan
     * langsung ke stock_current, supaya buku mutasi konsisten sejak awal.
     */
    public function run(): void
    {
        $saldo = collect($this->bacaCsv('saldo_awal_2026_10_01.csv'))
            ->mapWithKeys(fn (array $baris) => [$baris['item_code'] => (int) $baris['qty']]);

        $petugas = User::where('role', UserRole::PengelolaGudang)->first();
        $tanggal = CarbonImmutable::parse(self::TANGGAL_SALDO_AWAL);

        $dibuat = 0;
        $diarsipkan = 0;

        DB::transaction(function () use ($saldo, $petugas, $tanggal, &$dibuat, &$diarsipkan) {
            foreach ($this->bacaCsv('barang.csv') as $baris) {
                $stokAwal = $saldo->get($baris['item_code'], 0);

                $item = Item::firstOrCreate(
                    ['item_code' => $baris['item_code']],
                    [
                        'item_name' => $baris['item_name'],
                        'unit' => SatuanBarang::from(strtolower($baris['unit'])),
                        'stock_min' => 1,
                        'stock_max' => 1000,
                        'is_active' => $stokAwal > 0,
                    ],
                );

                // Hanya isi saldo awal untuk barang yang baru dibuat.
                if (! $item->wasRecentlyCreated) {
                    continue;
                }

                $dibuat++;

                if ($stokAwal === 0) {
                    $diarsipkan++;

                    continue;
                }

                StockLedger::record(
                    item: $item,
                    type: MutationType::SaldoAwal,
                    amount: $stokAwal,
                    recordedBy: $petugas,
                    notes: 'Saldo awal per 1 Oktober 2026.',
                    date: $tanggal,
                );
            }
        });

        $this->command?->info("Master barang dibuat: {$dibuat} barang ({$diarsipkan} diarsipkan karena tanpa saldo awal).");
    }

    /**
     * @return list<array<string, string>> baris CSV berkunci nama kolom.
     */
    private function bacaCsv(string $berkas): array
    {
        $handle = fopen(database_path("seeders/data/{$berkas}"), 'r');
        $kolom = fgetcsv($handle, escape: '');
        $baris = [];

        while (($data = fgetcsv($handle, escape: '')) !== false) {
            if ($data === [null]) {
                continue;
            }

            $baris[] = array_combine($kolom, array_map('trim', $data));
        }

        fclose($handle);

        return $baris;
    }
}
