<?php

namespace App\Enums;

/**
 * Unit kerja di BPK Perwakilan Provinsi Lampung.
 *
 * Nilai enum disimpan sebagai string pada kolom `users.unit`. Daftar tetap
 * dipakai karena otorisasi (mis. Permintaan Umum milik Subbagian Umum dan TI)
 * dan pencocokan Kepala Unit dengan pemohon bergantung pada nilai yang sama
 * persis; teks bebas membuat salah ketik langsung memutus hak akses.
 *
 * Label umumnya tanpa sebutan jabatan karena Nota Dinas mencetak penanda
 * tangannya sebagai "Kepala {label}"; pengecualiannya Kepala Perwakilan, lihat
 * jabatanKepala().
 */
enum UnitKerja: string
{
    case KepalaPerwakilan = 'kepala_perwakilan';
    case SekretariatPerwakilan = 'sekretariat_perwakilan';
    case BidangPemeriksaanLampungI = 'bidang_pemeriksaan_lampung_i';
    case BidangPemeriksaanLampungII = 'bidang_pemeriksaan_lampung_ii';
    case SubbagianHumasTu = 'subbagian_humas_tu';
    case SubbagianSdm = 'subbagian_sdm';
    case SubbagianKeuangan = 'subbagian_keuangan';
    case SubbagianUmumTi = 'subbagian_umum_ti';
    case SubbagianHukum = 'subbagian_hukum';

    public function label(): string
    {
        return match ($this) {
            self::KepalaPerwakilan => 'Kepala Perwakilan',
            self::SekretariatPerwakilan => 'Sekretariat Perwakilan',
            self::BidangPemeriksaanLampungI => 'Bidang Pemeriksaan Lampung I',
            self::BidangPemeriksaanLampungII => 'Bidang Pemeriksaan Lampung II',
            self::SubbagianHumasTu => 'Subbagian Humas dan TU',
            self::SubbagianSdm => 'Subbagian SDM',
            self::SubbagianKeuangan => 'Subbagian Keuangan',
            self::SubbagianUmumTi => 'Subbagian Umum dan TI',
            self::SubbagianHukum => 'Subbagian Hukum',
        };
    }

    /**
     * Kode unit pada nomor naskah dinas, bagian setelah "DJPKN-V." di
     * mis. 51/B/ND/DJPKN-V.BLP.1.4/KEU.02.02/10/2026.
     */
    public function kodeNaskah(): string
    {
        return match ($this) {
            self::KepalaPerwakilan => 'BLP',
            self::SekretariatPerwakilan => 'BLP.1',
            self::BidangPemeriksaanLampungI => 'BLP.2',
            self::BidangPemeriksaanLampungII => 'BLP.3',
            self::SubbagianHumasTu => 'BLP.1.1',
            self::SubbagianSdm => 'BLP.1.2',
            self::SubbagianKeuangan => 'BLP.1.3',
            self::SubbagianUmumTi => 'BLP.1.4',
            self::SubbagianHukum => 'BLP.1.5',
        };
    }

    /**
     * Jabatan pimpinan unit untuk blok tanda tangan Nota Dinas.
     */
    public function jabatanKepala(): string
    {
        return match ($this) {
            self::KepalaPerwakilan => $this->label(),
            default => 'Kepala '.$this->label(),
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $unit) => $carry + [$unit->value => $unit->label()],
            [],
        );
    }
}
