<?php

use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ProcurementReceiptPoster;

/**
 * Realisasi satu barang dengan angka yang ditentukan, supaya isi laporan dapat
 * diperiksa persis. Nama pembantu unik: berkas Pest berbagi ruang nama global.
 *
 * `diterima` yang null berarti penerimaannya belum dikonfirmasi.
 */
function realisasiLaporan(
    User $pejabat,
    User $petugas,
    Item $item,
    Supplier $supplier,
    int $dipesan = 10,
    int $harga = 12500,
    ?int $diterima = null,
    ?string $tanggal = null,
    string $bastNumber = '007/BAST/2026',
): Procurement {
    $usulan = setujuiUsulan($pejabat, buatUsulan($petugas, [
        ['item_id' => $item->item_id, 'amount_request' => $dipesan],
    ]));

    // Disetujui Kasubbag Umum dan TI supaya realisasinya benar-benar berjalan;
    // laporan dan BAST baru bermakna setelah tahap itu (ADR 0030).
    $procurement = buatRealisasiSiapDiterima($pejabat, $usulan, $supplier, [
        'procurement_date' => $tanggal ?? now()->toDateString(),
        'items' => [[
            'procurement_request_detail_id' => $usulan->details->first()->procurement_request_detail_id,
            'amount' => $dipesan,
            'unit_price' => $harga,
        ]],
    ]);

    if ($diterima !== null) {
        ProcurementReceiptPoster::post(
            procurement: $procurement,
            diterima: [$procurement->details->first()->procurement_detail_id => $diterima],
            actor: $petugas,
            receivedAt: now(),
        );

        // Nomor BAST diisi saat cetak pertama (ADR 0042); laporan dan BAST di
        // berkas ini mengandaikan nomornya sudah ada.
        $procurement->fresh()->receipt->update(['bast_number' => $bastNumber]);
    }

    return $procurement->fresh(['details', 'receipt']);
}

beforeEach(function () {
    $this->petugas = pengelolaGudang();
    $this->pejabat = pejabatPengadaan();

    $this->item = Item::factory()->create([
        'item_code' => 'ATK-777', 'item_name' => 'Kertas HVS A4',
    ]);
    $this->supplier = Supplier::factory()->create(['supplier_name' => 'CV Bumi Lampung']);
});

describe('BAST pengadaan', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $procurement = realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, diterima: 10);

        auth()->logout();
        $this->get("/pengadaan/{$procurement->procurement_id}/bast")->assertRedirect('/login');
    });

    it('menolak peran di luar pengadaan', function () {
        $procurement = realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, diterima: 10);

        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->get("/pengadaan/{$procurement->procurement_id}/bast")
            ->assertForbidden();
    });

    it('dicetak oleh kedua peran pengadaan dan Admin', function () {
        $procurement = realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, diterima: 10);
        $url = "/pengadaan/{$procurement->procurement_id}/bast";

        foreach ([$this->petugas, $this->pejabat, User::factory()->role(UserRole::Admin)->create()] as $aktor) {
            $response = $this->actingAs($aktor)->get($url);

            $response->assertOk();
            expect($response->headers->get('content-type'))->toContain('application/pdf')
                ->and($response->getContent())->toStartWith('%PDF');
        }
    });

    it('belum dapat dicetak sebelum penerimaan dikonfirmasi', function () {
        $procurement = realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier);

        $this->actingAs($this->petugas)
            ->get("/pengadaan/{$procurement->procurement_id}/bast")
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}")
            ->assertSessionHas('error');
    });

    it('tetap terbit untuk kiriman yang kurang dari pesanan', function () {
        // ADR 0017: yang diserahterimakan adalah yang benar-benar datang.
        $procurement = realisasiLaporan(
            $this->pejabat, $this->petugas, $this->item, $this->supplier,
            dipesan: 10, diterima: 7,
        );

        expect($procurement->nilaiDiterima())->toBe(7 * 12500)
            ->and($procurement->nilaiTotal())->toBe(10 * 12500);

        $this->actingAs($this->petugas)
            ->get("/pengadaan/{$procurement->procurement_id}/bast")
            ->assertOk();
    });
});

describe('hak akses laporan', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/pengadaan/laporan/pdf')->assertRedirect('/login');
        $this->get('/pengadaan/laporan/csv')->assertRedirect('/login');
    });

    it('menolak peran di luar pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->get('/pengadaan/laporan/pdf')
            ->assertForbidden();
    });

    it('mengizinkan kedua peran pengadaan dan Admin', function () {
        foreach ([$this->petugas, $this->pejabat, User::factory()->role(UserRole::Admin)->create()] as $aktor) {
            $this->actingAs($aktor)->get('/pengadaan/laporan/pdf')->assertOk();
            $this->actingAs($aktor)->get('/pengadaan/laporan/csv')->assertOk();
        }
    });
});

describe('laporan PDF', function () {
    it('menghasilkan PDF berisi pengadaan yang tersaring', function () {
        realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, diterima: 10);

        $response = $this->actingAs($this->pejabat)->get('/pengadaan/laporan/pdf');

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('application/pdf')
            ->and($response->getContent())->toStartWith('%PDF');
    });

    it('tetap terbit ketika tidak ada data yang cocok', function () {
        $response = $this->actingAs($this->pejabat)->get('/pengadaan/laporan/pdf?from=2020-01-01&to=2020-12-31');

        $response->assertOk();
        expect($response->getContent())->toStartWith('%PDF');
    });
});

describe('ekspor CSV', function () {
    it('memuat rincian barang, harga, dan nomor BAST', function () {
        realisasiLaporan(
            $this->pejabat, $this->petugas, $this->item, $this->supplier,
            dipesan: 10, harga: 12500, diterima: 7, bastNumber: '021/BAST/2026',
        );

        $response = $this->actingAs($this->pejabat)->get('/pengadaan/laporan/csv');
        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('text/csv');

        $csv = bodyOf($response);

        expect($csv)->toContain('ATK-777')
            ->and($csv)->toContain('021/BAST/2026')
            // Dipesan 10, diterima 7 — dua angka yang tidak boleh dilebur.
            ->and($csv)->toContain(';10;12500;125000;7;3;87500;')
            ->and($csv)->toContain('Nomor BAST');
    });

    it('menandai realisasi yang belum diterima dengan kolom kosong', function () {
        realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, dipesan: 4, harga: 1000);

        $csv = bodyOf($this->actingAs($this->pejabat)->get('/pengadaan/laporan/csv'));

        expect($csv)->toContain('ATK-777')
            ->and($csv)->toContain(';4;1000;4000;;0;0;;');
    });

    it('menghormati penyaring status', function () {
        realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, diterima: 10);

        $lain = Item::factory()->create(['item_code' => 'ATK-888', 'item_name' => 'Tinta Printer']);
        realisasiLaporan($this->pejabat, $this->petugas, $lain, $this->supplier);

        $csv = bodyOf($this->actingAs($this->pejabat)->get('/pengadaan/laporan/csv?status=diterima'));

        expect($csv)->toContain('ATK-777')->and($csv)->not->toContain('ATK-888');
    });

    it('menghormati penyaring penyedia', function () {
        realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier);

        $penyediaLain = Supplier::factory()->create(['supplier_name' => 'PT Sinar Abadi']);
        $itemLain = Item::factory()->create(['item_code' => 'ATK-888', 'item_name' => 'Tinta Printer']);
        realisasiLaporan($this->pejabat, $this->petugas, $itemLain, $penyediaLain);

        $csv = bodyOf($this->actingAs($this->pejabat)
            ->get('/pengadaan/laporan/csv?supplier='.$penyediaLain->supplier_id));

        expect($csv)->toContain('ATK-888')->and($csv)->not->toContain('ATK-777');
    });

    it('menghormati rentang tanggal realisasi', function () {
        realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, tanggal: '2026-01-15');

        $itemLain = Item::factory()->create(['item_code' => 'ATK-888', 'item_name' => 'Tinta Printer']);
        realisasiLaporan($this->pejabat, $this->petugas, $itemLain, $this->supplier, tanggal: '2026-06-20');

        $csv = bodyOf($this->actingAs($this->pejabat)
            ->get('/pengadaan/laporan/csv?from=2026-01-01&to=2026-03-31'));

        expect($csv)->toContain('ATK-777')->and($csv)->not->toContain('ATK-888');
    });
});

describe('rekap di layar', function () {
    it('memisahkan nilai dipesan dari nilai diterima', function () {
        realisasiLaporan(
            $this->pejabat, $this->petugas, $this->item, $this->supplier,
            dipesan: 10, harga: 12500, diterima: 7,
        );

        $this->actingAs($this->pejabat)
            ->get('/pengadaan')
            ->assertOk()
            ->assertSee('Nilai Dipesan')
            ->assertSee('Rp125.000')
            ->assertSee('Nilai Diterima')
            ->assertSee('Rp87.500');
    });

    it('mengikuti penyaring, bukan seluruh basis data', function () {
        realisasiLaporan($this->pejabat, $this->petugas, $this->item, $this->supplier, dipesan: 10, harga: 12500);

        $this->actingAs($this->pejabat)
            ->get('/pengadaan?status=diterima')
            ->assertOk()
            ->assertDontSee('Rp125.000');
    });
});

describe('realisasi yang dibatalkan tidak dihitung', function () {
    /**
     * Satu realisasi berjalan (Rp125.000) dan satu dibatalkan (Rp900.000).
     * Kalau yang dibatalkan ikut terhitung, rekapnya menjadi 2 dan Rp1.025.000.
     */
    $duaRealisasi = function (User $pejabat, User $petugas, Item $item, Supplier $supplier): Procurement {
        realisasiLaporan($pejabat, $petugas, $item, $supplier, dipesan: 10, harga: 12500);

        $lain = Item::factory()->create(['item_code' => 'ATK-888', 'item_name' => 'Tinta Printer']);
        $batal = realisasiLaporan($pejabat, $petugas, $lain, $supplier, dipesan: 9, harga: 100000);

        test()->actingAs($pejabat)
            ->patch("/pengadaan/{$batal->procurement_id}/batal", pembatalanPayload());

        return $batal->fresh();
    };

    it('mengeluarkannya dari jumlah realisasi dan nilai dipesan', function () use ($duaRealisasi) {
        $duaRealisasi($this->pejabat, $this->petugas, $this->item, $this->supplier);

        $this->actingAs($this->pejabat)
            ->get('/pengadaan')
            ->assertOk()
            // Rekap hanya memuat yang berjalan…
            ->assertSee('Rp125.000')
            ->assertDontSee('Rp1.025.000')
            ->assertSee('1 dibatalkan')
            // …sementara barisnya sendiri tetap tampil apa adanya, lengkap
            // dengan nilai dan status: ditampilkan, tidak dihitung.
            ->assertSee('Rp900.000')
            ->assertSee('Dibatalkan');
    });

    it('tetap menampilkan barisnya di CSV laporan', function () use ($duaRealisasi) {
        // Ditampilkan, tidak dihitung — menyembunyikan barisnya adalah bentuk
        // lain dari berbohong (ADR 0026).
        $duaRealisasi($this->pejabat, $this->petugas, $this->item, $this->supplier);

        $csv = bodyOf($this->actingAs($this->pejabat)->get('/pengadaan/laporan/csv'));

        expect($csv)->toContain('ATK-777')
            ->and($csv)->toContain('ATK-888')
            ->and($csv)->toContain('Dibatalkan');
    });

    it('tetap menerbitkan laporan PDF', function () use ($duaRealisasi) {
        $duaRealisasi($this->pejabat, $this->petugas, $this->item, $this->supplier);

        $response = $this->actingAs($this->pejabat)->get('/pengadaan/laporan/pdf');

        $response->assertOk();
        expect($response->getContent())->toStartWith('%PDF');
    });

    it('masih dapat disaring sendiri lewat status', function () use ($duaRealisasi) {
        $duaRealisasi($this->pejabat, $this->petugas, $this->item, $this->supplier);

        $csv = bodyOf($this->actingAs($this->pejabat)->get('/pengadaan/laporan/csv?status=dibatalkan'));

        expect($csv)->toContain('ATK-888')->and($csv)->not->toContain('ATK-777');
    });
});
