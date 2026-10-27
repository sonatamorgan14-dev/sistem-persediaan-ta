<?php

use App\Support\Terbilang;

it('menuliskan bilangan dalam kata-kata', function (int $angka, string $kata) {
    expect(Terbilang::angka($angka))->toBe($kata);
})->with([
    [0, 'nol'],
    [1, 'satu'],
    [10, 'sepuluh'],
    [11, 'sebelas'],
    [12, 'dua belas'],
    [18, 'delapan belas'],
    [19, 'sembilan belas'],
    [20, 'dua puluh'],
    [21, 'dua puluh satu'],
    [31, 'tiga puluh satu'],
    [99, 'sembilan puluh sembilan'],
    [100, 'seratus'],
    [101, 'seratus satu'],
    [111, 'seratus sebelas'],
    [200, 'dua ratus'],
    [1000, 'seribu'],
    [1001, 'seribu satu'],
    [1100, 'seribu seratus'],
    [2025, 'dua ribu dua puluh lima'],
    [2026, 'dua ribu dua puluh enam'],
    [20000, 'dua puluh ribu'],
    [115000, 'seratus lima belas ribu'],
    [1000000, 'satu juta'],
]);
