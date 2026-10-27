<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.unit` beralih dari teks bebas ke nilai enum App\Enums\UnitKerja.
 *
 * Peta ditulis langsung di sini, tidak mengimpor enum, supaya migrasi tetap
 * berjalan sama walau enumnya kelak berubah. Selain sembilan nama resmi, peta
 * memuat nama lama yang pernah dipakai: "Perwakilan" kini bernama Kepala
 * Perwakilan, "Subbagian Umum dan TU" adalah salah ketik "TI", dan
 * "Subauditorat Lampung I" adalah nama lama Bidang Pemeriksaan Lampung I.
 */
return new class extends Migration
{
    private const LABEL = [
        'kepala_perwakilan' => 'Kepala Perwakilan',
        'sekretariat_perwakilan' => 'Sekretariat Perwakilan',
        'bidang_pemeriksaan_lampung_i' => 'Bidang Pemeriksaan Lampung I',
        'bidang_pemeriksaan_lampung_ii' => 'Bidang Pemeriksaan Lampung II',
        'subbagian_humas_tu' => 'Subbagian Humas dan TU',
        'subbagian_sdm' => 'Subbagian SDM',
        'subbagian_keuangan' => 'Subbagian Keuangan',
        'subbagian_umum_ti' => 'Subbagian Umum dan TI',
        'subbagian_hukum' => 'Subbagian Hukum',
    ];

    private const ALIAS = [
        'Perwakilan' => 'kepala_perwakilan',
        'Subbagian Umum dan TU' => 'subbagian_umum_ti',
        'Subauditorat Lampung I' => 'bidang_pemeriksaan_lampung_i',
    ];

    public function up(): void
    {
        $peta = array_flip(self::LABEL) + self::ALIAS;

        // Nilai yang tidak dikenali akan membuat cast enum gagal saat akun
        // dimuat (termasuk saat login), jadi lebih baik berhenti di sini.
        $asing = DB::table('users')
            ->whereNotIn('unit', [...array_keys($peta), ...array_keys(self::LABEL)])
            ->distinct()
            ->pluck('unit');

        if ($asing->isNotEmpty()) {
            throw new RuntimeException(
                'Unit kerja tidak dikenali: '.$asing->implode(', ').'. Sesuaikan manual lalu ulangi migrasi.'
            );
        }

        foreach ($peta as $lama => $baru) {
            DB::table('users')->where('unit', $lama)->update(['unit' => $baru]);
        }
    }

    public function down(): void
    {
        foreach (self::LABEL as $nilai => $label) {
            DB::table('users')->where('unit', $nilai)->update(['unit' => $label]);
        }
    }
};
