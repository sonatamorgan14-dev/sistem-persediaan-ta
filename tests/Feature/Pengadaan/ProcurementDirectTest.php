<?php

use App\Enums\AlasanPengadaan;
use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\StockMutation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Realisasi pengadaan langsung — dibuat Pejabat Pengadaan tanpa usulan sama
 * sekali, dengan dasar pengadaan wajib sebagai gantinya. Lihat ADR 0031.
 *
 * Helper di berkas Pest bersifat global lintas berkas, jadi nama di sini
 * sengaja dibedakan dari `realisasiPayload()`/`buatRealisasi()` milik
 * ProcurementTest.php yang keduanya menuntut sebuah usulan.
 */

/**
 * @return array<string, mixed>
 */
function payloadLangsung(Supplier $supplier, array $items, array $overrides = []): array
{
    return array_merge([
        'supplier_id' => $supplier->supplier_id,
        'procurement_date' => now()->toDateString(),
        'basis_reason' => AlasanPengadaan::KebutuhanMendesak->value,
        'basis_note' => 'Spidol untuk rapat pleno pekan depan, stok habis dan belum masuk usulan.',
        'items' => $items,
    ], $overrides);
}

/**
 * Satu baris barang siap pakai untuk payload realisasi langsung.
 *
 * @return array<string, mixed>
 */
function barisLangsung(Item $item, int $amount = 10, int $unitPrice = 15000): array
{
    return ['item_id' => $item->item_id, 'amount' => $amount, 'unit_price' => $unitPrice];
}

function buatRealisasiLangsung(User $pejabat, Supplier $supplier, array $items, array $overrides = []): Procurement
{
    $response = test()->actingAs($pejabat)->post('/pengadaan', payloadLangsung($supplier, $items, $overrides));

    $response->assertRedirect();

    // Id dibaca dari header Location, bukan `latest('created_at')`: dua baris
    // bisa lahir pada detik yang sama.
    $id = (string) str($response->headers->get('Location'))->match('#/pengadaan/([^/]+)#');

    return Procurement::findOrFail($id);
}

describe('hak akses realisasi langsung', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/pengadaan/create')->assertRedirect('/login');
    });

    it('mengizinkan Pejabat Pengadaan membuka formulir langsung', function () {
        $this->actingAs(pejabatPengadaan())
            ->get('/pengadaan/create')
            ->assertOk()
            ->assertSee('Realisasi Pengadaan Langsung')
            ->assertSee('Dasar Pengadaan');
    });

    it('menolak Pengelola Gudang membuka formulir langsung', function () {
        $this->actingAs(pengelolaGudang())
            ->get('/pengadaan/create')
            ->assertForbidden();
    });

    // Menangkap jebakan middleware: `role:pejabat_pengadaan` akan menolak Admin,
    // sedangkan gate `kelola-pengadaan` meloloskannya lewat Gate::before.
    it('mengizinkan Admin membuka formulir langsung', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/pengadaan/create')
            ->assertOk();
    });

    it('menolak Kasubbag Umum dan TI membuat realisasi langsung', function () {
        $item = Item::factory()->create();

        $this->actingAs(kasubbagUmumTi())
            ->post('/pengadaan', payloadLangsung(Supplier::factory()->create(), [barisLangsung($item)]))
            ->assertForbidden();
    });

    it('menampilkan tombol Buat Realisasi Langsung hanya kepada pemegang kelola-pengadaan', function () {
        $this->actingAs(pejabatPengadaan())
            ->get('/pengadaan')
            ->assertOk()
            ->assertSee('Buat Realisasi Langsung');

        $this->actingAs(pengelolaGudang())
            ->get('/pengadaan')
            ->assertOk()
            ->assertDontSee('Buat Realisasi Langsung');
    });
});

describe('membuat realisasi langsung', function () {
    it('menyimpan realisasi tanpa usulan beserta dasarnya', function () {
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create();

        $procurement = buatRealisasiLangsung($pejabat, $supplier, [barisLangsung($item, 12, 20000)]);

        expect($procurement->procurement_request_id)->toBeNull()
            ->and($procurement->tanpaUsulan())->toBeTrue()
            ->and($procurement->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and($procurement->basis_reason)->toBe(AlasanPengadaan::KebutuhanMendesak)
            ->and($procurement->basis_note)->toContain('rapat pleno')
            ->and($procurement->details)->toHaveCount(1)
            ->and($procurement->details->first()->item_id)->toBe($item->item_id)
            ->and($procurement->details->first()->amount)->toBe(12)
            ->and($procurement->details->first()->unit_price)->toBe(20000);

        expect(AuditLog::where('activity', 'like', '%realisasi pengadaan langsung%')->exists())->toBeTrue();
    });

    it('memberi tahu Kasubbag Umum dan TI beserta dasarnya', function () {
        $kasubbag = kasubbagUmumTi();
        $item = Item::factory()->create();

        buatRealisasiLangsung(pejabatPengadaan(), Supplier::factory()->create(), [barisLangsung($item)]);

        $notifikasi = Notification::where('user_id', $kasubbag->user_id)
            ->where('type', NotificationType::RealisasiMenungguPersetujuan)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('tanpa usulan')
            ->and($notifikasi->body)->toContain('kebutuhan mendesak');
    });

    /**
     * Inti migrasi `allow_procurements_without_request`: indeks uniknya
     * dipertahankan, dan banyak baris NULL memang diizinkan di atasnya.
     */
    it('mengizinkan lebih dari satu realisasi langsung berdiri bersama', function () {
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();

        $pertama = buatRealisasiLangsung($pejabat, $supplier, [barisLangsung(Item::factory()->create())]);
        $kedua = buatRealisasiLangsung($pejabat, $supplier, [barisLangsung(Item::factory()->create())]);

        expect($pertama->procurement_id)->not->toBe($kedua->procurement_id)
            ->and(Procurement::whereNull('procurement_request_id')->count())->toBe(2);
    });

    it('mewajibkan dasar pengadaan bila tidak ada usulan', function () {
        $item = Item::factory()->create();

        $this->actingAs(pejabatPengadaan())
            ->post('/pengadaan', payloadLangsung(
                Supplier::factory()->create(),
                [barisLangsung($item)],
                ['basis_reason' => '', 'basis_note' => ''],
            ))
            ->assertSessionHasErrors(['basis_reason', 'basis_note']);

        expect(Procurement::count())->toBe(0);
    });

    it('tidak mewajibkan dasar pengadaan pada realisasi dari usulan', function () {
        $gudang = pengelolaGudang();
        $pejabat = pejabatPengadaan();
        $item = Item::factory()->create();
        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => $item->item_id, 'amount_request' => 5],
        ]));

        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        expect($procurement->tanpaUsulan())->toBeFalse()
            ->and($procurement->basis_reason)->toBeNull()
            ->and($procurement->basis_note)->toBeNull();
    });

    it('menolak baris yang mengaku berasal dari usulan pada realisasi langsung', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/pengadaan', payloadLangsung(Supplier::factory()->create(), [[
                'procurement_request_detail_id' => (string) Str::uuid(),
                'amount' => 5,
                'unit_price' => 1000,
            ]]))
            ->assertSessionHasErrors('items.0.procurement_request_detail_id');

        expect(Procurement::count())->toBe(0);
    });

    it('menolak barang ganda pada satu realisasi langsung', function () {
        $item = Item::factory()->create();

        $this->actingAs(pejabatPengadaan())
            ->post('/pengadaan', payloadLangsung(Supplier::factory()->create(), [
                barisLangsung($item, 5),
                barisLangsung($item, 7),
            ]))
            ->assertSessionHasErrors();

        expect(Procurement::count())->toBe(0);
    });

    it('menolak realisasi langsung tanpa satu pun barang', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/pengadaan', payloadLangsung(Supplier::factory()->create(), []))
            ->assertSessionHasErrors('items');

        expect(Procurement::count())->toBe(0);
    });

    it('menawarkan usulan barang baru dari pencarian barang yang tidak ditemukan', function () {
        // Pengganti kalimat penjelasan yang dulu selalu tampil di bawah daftar.
        $this->actingAs(pejabatPengadaan())
            ->get('/pengadaan/create')
            ->assertOk()
            ->assertSee('href="'.route('item-proposals.create').'"', false)
            ->assertSee('Usulkan barang baru');
    });

    it('tidak menyebut usulan asal di dialog pembatalan realisasi langsung', function () {
        // Realisasi langsung tidak punya usulan; kalimat lama menyebutnya juga.
        $pejabat = pejabatPengadaan();
        $procurement = buatRealisasiLangsung($pejabat, Supplier::factory()->create(), [barisLangsung(Item::factory()->create())]);

        $this->actingAs($pejabat)
            ->get("/pengadaan/{$procurement->procurement_id}")
            ->assertOk()
            ->assertSee('Pembatalan tidak dapat diurungkan.')
            ->assertDontSee('Usulan asalnya');
    });
});

describe('mengubah realisasi langsung', function () {
    it('mengizinkan menghapus baris mana pun selama tersisa satu', function () {
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();
        $pertama = Item::factory()->create();
        $kedua = Item::factory()->create();

        $procurement = buatRealisasiLangsung($pejabat, $supplier, [
            barisLangsung($pertama, 10),
            barisLangsung($kedua, 20),
        ]);

        $sisa = $procurement->details->firstWhere('item_id', $pertama->item_id);

        $this->actingAs($pejabat)
            ->put("/pengadaan/{$procurement->procurement_id}", [
                'supplier_id' => $supplier->supplier_id,
                'procurement_date' => now()->toDateString(),
                'basis_reason' => AlasanPengadaan::ArahanPimpinan->value,
                'basis_note' => 'Arahan pimpinan pada rapat 15 September.',
                'items' => [[
                    'procurement_detail_id' => $sisa->procurement_detail_id,
                    'amount' => 8,
                    'unit_price' => 15000,
                ]],
            ])
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}");

        $procurement->refresh()->load('details');

        expect($procurement->details)->toHaveCount(1)
            ->and($procurement->details->first()->item_id)->toBe($pertama->item_id)
            ->and($procurement->basis_reason)->toBe(AlasanPengadaan::ArahanPimpinan);
    });

    /**
     * Lubang tata kelola yang ditutup ADR 0030 harus tetap tertutup di pintu
     * baru ini: mintakan persetujuan atas dasar yang wajar, lalu tukar dasarnya
     * setelah disetujui.
     */
    it('menggugurkan persetujuan begitu dasarnya diubah', function () {
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create();

        $procurement = buatRealisasiLangsung($pejabat, $supplier, [barisLangsung($item)]);
        setujuiRealisasi($procurement);

        expect($procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja);

        $detail = $procurement->details->first();

        $this->actingAs($pejabat)->put("/pengadaan/{$procurement->procurement_id}", [
            'supplier_id' => $supplier->supplier_id,
            'procurement_date' => now()->toDateString(),
            'basis_reason' => AlasanPengadaan::BelanjaRutinNilaiKecil->value,
            'basis_note' => 'Belanja rutin bulanan.',
            'items' => [[
                'procurement_detail_id' => $detail->procurement_detail_id,
                'amount' => $detail->amount,
                'unit_price' => $detail->unit_price,
            ]],
        ]);

        $procurement->refresh();

        expect($procurement->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and($procurement->reviewed_at)->toBeNull()
            ->and($procurement->reviewed_by)->toBeNull()
            ->and($procurement->review_note)->toBeNull();
    });

    it('tetap mewajibkan dasar pengadaan saat diubah', function () {
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();
        $item = Item::factory()->create();

        $procurement = buatRealisasiLangsung($pejabat, $supplier, [barisLangsung($item)]);
        $detail = $procurement->details->first();

        $this->actingAs($pejabat)
            ->put("/pengadaan/{$procurement->procurement_id}", [
                'supplier_id' => $supplier->supplier_id,
                'procurement_date' => now()->toDateString(),
                'basis_reason' => '',
                'basis_note' => '',
                'items' => [[
                    'procurement_detail_id' => $detail->procurement_detail_id,
                    'amount' => $detail->amount,
                    'unit_price' => $detail->unit_price,
                ]],
            ])
            ->assertSessionHasErrors(['basis_reason', 'basis_note']);

        expect($procurement->fresh()->basis_reason)->toBe(AlasanPengadaan::KebutuhanMendesak);
    });
});

describe('penerimaan realisasi langsung', function () {
    it('menolak penerimaan sebelum realisasi langsung disetujui', function () {
        $item = Item::factory()->create();
        $procurement = buatRealisasiLangsung(pejabatPengadaan(), Supplier::factory()->create(), [
            barisLangsung($item, 10),
        ]);

        $this->actingAs(pengelolaGudang())
            ->get("/pengadaan/{$procurement->procurement_id}/penerimaan/create")
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}");

        expect(StockMutation::count())->toBe(0);
    });

    it('menambah stok lewat StockLedger setelah disetujui dan diterima', function () {
        $item = Item::factory()->create();
        $stokAwal = $item->stock_current;

        $procurement = buatRealisasiLangsung(pejabatPengadaan(), Supplier::factory()->create(), [
            barisLangsung($item, 10),
        ]);
        belanjakanRealisasi(setujuiRealisasi($procurement));

        $detail = $procurement->details->first();

        $this->actingAs(pengelolaGudang())
            ->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
                'received_at' => now()->toDateString(),
                'received' => [$detail->procurement_detail_id => 10],
            ])
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}");

        $mutasi = StockMutation::where('item_id', $item->item_id)
            ->where('mutation_type', MutationType::Masuk)
            ->first();

        expect($item->refresh()->stock_current)->toBe($stokAwal + 10)
            ->and($mutasi)->not->toBeNull()
            ->and($mutasi->reference_id)->toBe($procurement->procurement_id)
            ->and($procurement->fresh()->status)->toBe(ProcurementStatus::Diterima);
    });
});

describe('rekap dan penyaring dasar pengadaan', function () {
    it('menghitung realisasi langsung sebagai irisan, bukan penjumlah', function () {
        $gudang = pengelolaGudang();
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 5],
        ]));
        buatRealisasi($pejabat, $usulan, $supplier);
        buatRealisasiLangsung($pejabat, $supplier, [barisLangsung(Item::factory()->create())]);

        $this->actingAs($pejabat)
            ->get('/pengadaan')
            ->assertOk()
            ->assertSee('1 tanpa usulan');
    });

    it('menyaring hanya realisasi langsung', function () {
        $gudang = pengelolaGudang();
        $pejabat = pejabatPengadaan();
        $supplier = Supplier::factory()->create();

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 5],
        ]));
        $berusulan = buatRealisasi($pejabat, $usulan, $supplier);
        $langsung = buatRealisasiLangsung($pejabat, $supplier, [barisLangsung(Item::factory()->create())]);

        // Dibuktikan dengan kunci penuh, bukan judul(): dua baris yang lahir
        // pada milidetik yang sama berbagi prefiks UUID sehingga judulnya
        // terbaca identik walau dokumennya berbeda.
        $this->actingAs($pejabat)
            ->get('/pengadaan?sumber=langsung')
            ->assertOk()
            ->assertSee($langsung->procurement_id)
            ->assertDontSee($berusulan->procurement_id);

        $this->actingAs($pejabat)
            ->get('/pengadaan?sumber=usulan')
            ->assertOk()
            ->assertSee($berusulan->procurement_id)
            ->assertDontSee($langsung->procurement_id);
    });

    it('menyebut dasar pengadaan pada ekspor CSV', function () {
        buatRealisasiLangsung(pejabatPengadaan(), Supplier::factory()->create(), [
            barisLangsung(Item::factory()->create()),
        ]);

        $isi = $this->actingAs(pejabatPengadaan())
            ->get('/pengadaan/laporan/csv')
            ->streamedContent();

        expect($isi)->toContain('Dasar')
            ->and($isi)->toContain('Langsung');
    });
});
