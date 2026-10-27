<?php

namespace App\Support;

/**
 * Bilangan bulat dalam kata-kata bahasa Indonesia, untuk tanggal terbilang
 * pada dokumen resmi — "Delapan belas", "Dua ribu dua puluh lima".
 *
 * Hasilnya huruf kecil. Kapitalisasi urusan dokumennya, karena format resmi
 * BAST hanya mengapitalkan huruf pertama tiap bilangan (ADR 0042).
 */
class Terbilang
{
    private const SATUAN = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima',
        'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas',
    ];

    public static function angka(int $n): string
    {
        if ($n === 0) {
            return 'nol';
        }

        if ($n < 0) {
            return 'minus '.self::angka(-$n);
        }

        // Bagian yang bernilai nol meninggalkan spasi ganda ("dua puluh  ribu");
        // dirapikan sekali di sini daripada dijaga di setiap cabang.
        return trim(preg_replace('/\s+/', ' ', self::susun($n)));
    }

    private static function susun(int $n): string
    {
        return match (true) {
            $n < 12 => self::SATUAN[$n],
            $n < 20 => self::SATUAN[$n - 10].' belas',
            $n < 100 => self::SATUAN[intdiv($n, 10)].' puluh '.self::susun($n % 10),
            // "seratus" dan "seribu", bukan "satu ratus" dan "satu ribu".
            $n < 200 => 'seratus '.self::susun($n - 100),
            $n < 1_000 => self::SATUAN[intdiv($n, 100)].' ratus '.self::susun($n % 100),
            $n < 2_000 => 'seribu '.self::susun($n - 1_000),
            $n < 1_000_000 => self::susun(intdiv($n, 1_000)).' ribu '.self::susun($n % 1_000),
            $n < 1_000_000_000 => self::susun(intdiv($n, 1_000_000)).' juta '.self::susun($n % 1_000_000),
            default => self::susun(intdiv($n, 1_000_000_000)).' miliar '.self::susun($n % 1_000_000_000),
        };
    }
}
