<?php

namespace App\Enums;

/**
 * Satuan barang persediaan.
 *
 * Daftar tetap dipakai agar rekap laporan tidak terpecah karena penulisan
 * yang berbeda-beda ("Buah" / "buah" / "bh"). Menambah satuan baru cukup
 * dengan menambahkan satu case di sini.
 */
enum SatuanBarang: string
{
    case Buah = 'buah';
    case Rim = 'rim';
    case Box = 'box';
    case Pak = 'pak';
    case Dus = 'dus';
    case Lusin = 'lusin';
    case Unit = 'unit';
    case Set = 'set';
    case Botol = 'botol';
    case Kilogram = 'kilogram';
    case Liter = 'liter';
    case Lembar = 'lembar';

    // Satuan dari master barang awal (database/seeders/data/barang.csv).
    case Bal = 'bal';
    case Batang = 'batang';
    case Blister = 'blister';
    case Blok = 'blok';
    case Buku = 'buku';
    case Bungkus = 'bungkus';
    case Butir = 'butir';
    case Dirigen = 'dirigen';
    case Dummy = 'dummy';
    case Eksemplar = 'eksemplar';
    case Galon = 'galon';
    case Gulung = 'gulung';
    case Kaleng = 'kaleng';
    case Kaplet = 'kaplet';
    case Karung = 'karung';
    case Kotak = 'kotak';
    case Meter = 'meter';
    case Paket = 'paket';
    case Pasang = 'pasang';
    case Pcs = 'pcs';
    case Pot = 'pot';
    case Potong = 'potong';
    case Roll = 'roll';
    case Sachet = 'sachet';
    case Slop = 'slop';
    case Strip = 'strip';
    case Tablet = 'tablet';
    case Tabung = 'tabung';
    case Tube = 'tube';
    case Vial = 'vial';

    public function label(): string
    {
        return match ($this) {
            self::Buah => 'Buah',
            self::Rim => 'Rim',
            self::Box => 'Box',
            self::Pak => 'Pak',
            self::Dus => 'Dus',
            self::Lusin => 'Lusin',
            self::Unit => 'Unit',
            self::Set => 'Set',
            self::Botol => 'Botol',
            self::Kilogram => 'Kilogram',
            self::Liter => 'Liter',
            self::Lembar => 'Lembar',
            self::Bal => 'Bal',
            self::Batang => 'Batang',
            self::Blister => 'Blister',
            self::Blok => 'Blok',
            self::Buku => 'Buku',
            self::Bungkus => 'Bungkus',
            self::Butir => 'Butir',
            self::Dirigen => 'Dirigen',
            self::Dummy => 'Dummy',
            self::Eksemplar => 'Eksemplar',
            self::Galon => 'Galon',
            self::Gulung => 'Gulung',
            self::Kaleng => 'Kaleng',
            self::Kaplet => 'Kaplet',
            self::Karung => 'Karung',
            self::Kotak => 'Kotak',
            self::Meter => 'Meter',
            self::Paket => 'Paket',
            self::Pasang => 'Pasang',
            self::Pcs => 'Pcs',
            self::Pot => 'Pot',
            self::Potong => 'Potong',
            self::Roll => 'Roll',
            self::Sachet => 'Sachet',
            self::Slop => 'Slop',
            self::Strip => 'Strip',
            self::Tablet => 'Tablet',
            self::Tabung => 'Tabung',
            self::Tube => 'Tube',
            self::Vial => 'Vial',
        };
    }

    /**
     * @return array<string, string> nilai => label, untuk dropdown form.
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $satuan) => $carry + [$satuan->value => $satuan->label()],
            [],
        );
    }
}
