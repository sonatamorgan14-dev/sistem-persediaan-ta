<?php

namespace App\Support;

use App\Enums\MutationType;
use App\Exceptions\InsufficientStockException;
use App\Models\Item;
use App\Models\StockMutation;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Satu-satunya jalur perubahan stok barang.
 *
 * ==========================================================================
 *  KONTRAK LINTAS SUB SISTEM — dipakai bersama oleh dua pengembang
 * ==========================================================================
 * Repositori ini dikerjakan dua orang: sub sistem pengadaan & manajemen gudang,
 * dan sub sistem permintaan barang. Kedua sub sistem WAJIB memakai kelas ini.
 *
 *  1. JANGAN PERNAH menulis `items.stock_current` secara langsung. Kolom itu
 *     sengaja dikeluarkan dari $fillable pada App\Models\Item. Menulisnya
 *     langsung membuat buku mutasi dan kartu persediaan menampilkan angka yang
 *     tidak sesuai stok sebenarnya.
 *
 *  2. Penyerahan barang atas permintaan unit kerja dicatat sebagai:
 *
 *         StockLedger::record(
 *             item: $item,
 *             type: MutationType::Keluar,
 *             amount: $jumlahDiserahkan,
 *             recordedBy: $request->user(),
 *             reference: $permintaan,              // model App\Models\Request
 *             referenceNumber: $permintaan->nd_number,
 *             notes: "Penyerahan ke {$permintaan->user->unit->label()} sesuai ND {$permintaan->nd_number}",
 *         );
 *
 *     `reference_type` diturunkan sendiri dari morph map, jadi tidak perlu
 *     dikirim. Keterangan sebaiknya dirakit dari data dokumen yang nyata, bukan
 *     kalimat tetap yang mengandung asumsi proses bisnis.
 *
 *  3. Stok yang tidak mencukupi melempar InsufficientStockException. Pemanggil
 *     bertanggung jawab menerjemahkannya menjadi galat validasi yang ramah,
 *     bukan membiarkannya menjadi 500.
 *
 *  4. Seluruh argumen bersifat *named*. Penambahan parameter di kemudian hari
 *     tidak akan merusak pemanggil yang sudah ada — jangan mengubah urutan atau
 *     menghapus parameter tanpa mengabari pengembang sub sistem lainnya.
 *
 *  5. PENAMBAHAN STOK DARI PENGADAAN. Menyelesaikan realisasi pengadaan TIDAK
 *     menambah stok. Pengadaan berpindah ke status "Menunggu Penerimaan", lalu
 *     stok baru bertambah ketika Pengelola Gudang mengonfirmasi barang fisik
 *     diterima — dan mutasi Masuk diatribusikan kepadanya, bukan kepada Pejabat
 *     Pengadaan. Ini penerapan pemisahan tugas: pihak yang mengadakan barang
 *     tidak boleh sekaligus menyatakan barang itu masuk gudang. Lihat ADR 0008.
 * ==========================================================================
 */
class StockLedger
{
    /**
     * Catat satu mutasi dan sesuaikan stok barang.
     *
     * @param  int  $amount  Jumlah mutasi. Untuk jenis berarah tetap nilainya
     *                       harus positif; arah ditentukan jenis mutasi. Untuk
     *                       jenis dua arah (Penyesuaian, KoreksiPencatatan),
     *                       tanda nilai menentukan arah.
     * @param  Model|null  $reference  Dokumen sumber, mis. Procurement atau Request.
     *                                 `reference_type` diturunkan dari morph map.
     * @param  string|null  $referenceNumber  Nomor dokumen resmi, mis. '045/ND/2026'.
     *                                        Disalin permanen supaya laporan lama
     *                                        tetap menampilkan nomor yang sama
     *                                        meski dokumennya kelak berubah.
     *
     * @throws InsufficientStockException bila stok akhir menjadi negatif
     * @throws InvalidArgumentException bila jumlah tidak masuk akal
     */
    public static function record(
        Item $item,
        MutationType $type,
        int $amount,
        ?User $recordedBy = null,
        ?string $notes = null,
        ?Model $reference = null,
        ?string $referenceNumber = null,
        ?CarbonInterface $date = null,
    ): StockMutation {
        $signed = self::signedAmount($type, $amount);

        return DB::transaction(function () use (
            $item, $type, $signed, $recordedBy, $notes,
            $reference, $referenceNumber, $date
        ) {
            // Kunci baris barang supaya dua transaksi bersamaan tidak saling
            // menimpa hasil perhitungan stok.
            $locked = Item::query()->lockForUpdate()->findOrFail($item->getKey());

            $newStock = $locked->stock_current + $signed;

            if ($newStock < 0) {
                throw new InsufficientStockException($locked, abs($signed), $locked->stock_current);
            }

            $mutation = StockMutation::create([
                'item_id' => $locked->item_id,
                'user_id' => $recordedBy?->getKey(),
                'mutation_type' => $type,
                'amount' => $signed,
                'stock_after' => $newStock,
                'mutation_date' => $date ?? now(),
                'reference_id' => $reference?->getKey(),
                // Alias jenis dokumen diambil dari morph map, bukan dikirim
                // pemanggil, supaya tidak mungkin tidak cocok dengan modelnya.
                'reference_type' => $reference?->getMorphClass(),
                'reference_number' => $referenceNumber,
                'notes' => $notes,
            ]);

            $locked->forceFill(['stock_current' => $newStock])->save();

            // Selaraskan instance yang dipegang pemanggil.
            $item->setAttribute('stock_current', $newStock);
            $item->syncOriginalAttribute('stock_current');

            return $mutation;
        });
    }

    /**
     * Ubah jumlah mentah menjadi nilai bertanda sesuai jenis mutasi.
     */
    private static function signedAmount(MutationType $type, int $amount): int
    {
        // Jenis dua arah mengikuti tanda nilai masukan.
        if ($type->isBidirectional()) {
            if ($amount === 0) {
                throw new InvalidArgumentException(
                    sprintf('Jumlah %s tidak boleh nol.', $type->label()),
                );
            }

            return $amount;
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException(
                sprintf('Jumlah mutasi %s harus lebih besar dari nol.', $type->label()),
            );
        }

        return $type->direction() * $amount;
    }
}
