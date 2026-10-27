<?php

use App\Enums\MutationType;
use App\Enums\SatuanBarang;
use App\Enums\StockStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\User;
use App\Support\StockLedger;

function pengelolaGudang(array $attributes = []): User
{
    return User::factory()->role(UserRole::PengelolaGudang)->create($attributes);
}

function itemPayload(array $overrides = []): array
{
    return array_merge([
        'item_code' => 'ATK-001',
        'item_name' => 'Kertas HVS A4 70 gram',
        'unit' => SatuanBarang::Rim->value,
        'stock_awal' => 50,
        'stock_min' => 10,
        'stock_max' => 200,
        'is_active' => 1,
    ], $overrides);
}

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/barang')->assertRedirect('/login');
    });

    it('menolak Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/barang')
            ->assertForbidden();
    });

    it('mengizinkan Pengelola Gudang', function () {
        $this->actingAs(pengelolaGudang())->get('/barang')->assertOk()->assertSee('Master Barang');
    });

    // Menangkap jebakan middleware: `role:pengelola_gudang` akan menolak Admin,
    // sedangkan gate `kelola-gudang` meloloskannya lewat Gate::before.
    it('mengizinkan Admin', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/barang')
            ->assertOk();
    });

    it('menolak Pejabat Pengadaan membuat barang', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->post('/barang', itemPayload())
            ->assertForbidden();

        expect(Item::where('item_code', 'ATK-001')->exists())->toBeFalse();
    });

    it('menyembunyikan menu gudang dari Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/dasbor')
            ->assertOk()
            ->assertDontSee('Master Barang');
    });
});

describe('membuat barang', function () {
    it('menyimpan barang dan mencatat mutasi saldo awal', function () {
        $actor = pengelolaGudang();

        $this->actingAs($actor)
            ->post('/barang', itemPayload())
            ->assertRedirect('/barang')
            ->assertSessionHas('status');

        $item = Item::where('item_code', 'ATK-001')->first();

        expect($item)->not->toBeNull()
            ->and($item->stock_current)->toBe(50)
            ->and($item->unit)->toBe(SatuanBarang::Rim)
            ->and($item->is_active)->toBeTrue();

        $mutations = $item->stockMutations()->get();

        expect($mutations)->toHaveCount(1)
            ->and($mutations->first()->mutation_type)->toBe(MutationType::SaldoAwal)
            ->and($mutations->first()->amount)->toBe(50);

        expect(AuditLog::where('user_id', $actor->user_id)
            ->where('activity', 'like', 'Menambah barang: ATK-001%')
            ->exists())->toBeTrue();
    });

    it('tidak membuat mutasi bila stok awal nol', function () {
        $this->actingAs(pengelolaGudang())->post('/barang', itemPayload(['stock_awal' => 0]));

        $item = Item::where('item_code', 'ATK-001')->first();

        expect($item->stock_current)->toBe(0)
            ->and($item->stockMutations()->count())->toBe(0);
    });

    it('menormalkan kode barang menjadi huruf besar', function () {
        $this->actingAs(pengelolaGudang())->post('/barang', itemPayload(['item_code' => 'atk-009']));

        expect(Item::where('item_code', 'ATK-009')->exists())->toBeTrue();
    });

    it('menolak kode barang ganda', function () {
        Item::factory()->create(['item_code' => 'ATK-001']);

        $this->actingAs(pengelolaGudang())
            ->post('/barang', itemPayload())
            ->assertSessionHasErrors('item_code');
    });

    it('menolak kode barang dengan karakter tidak sah', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/barang', itemPayload(['item_code' => 'ATK 001!']))
            ->assertSessionHasErrors('item_code');
    });

    it('menolak satuan di luar daftar', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/barang', itemPayload(['unit' => 'ember']))
            ->assertSessionHasErrors('unit');
    });

    it('menolak stok maksimum lebih kecil dari stok minimum', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/barang', itemPayload(['stock_min' => 100, 'stock_max' => 50]))
            ->assertSessionHasErrors('stock_max');
    });

    it('mengizinkan stok maksimum nol sebagai tanpa batas', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/barang', itemPayload(['stock_min' => 0, 'stock_max' => 0]))
            ->assertSessionHasNoErrors();

        expect(Item::where('item_code', 'ATK-001')->first()->stock_max)->toBe(0);
    });

    it('menolak stok negatif', function () {
        $this->actingAs(pengelolaGudang())
            ->post('/barang', itemPayload(['stock_awal' => -5]))
            ->assertSessionHasErrors('stock_awal');
    });
});

describe('mengubah barang', function () {
    it('memperbarui data induk', function () {
        $item = Item::factory()->create(['item_code' => 'ATK-001', 'stock_current' => 40]);

        $this->actingAs(pengelolaGudang())
            ->put("/barang/{$item->item_id}", [
                'item_code' => 'ATK-001',
                'item_name' => 'Kertas HVS A4 80 gram',
                'unit' => SatuanBarang::Rim->value,
                'stock_min' => 25,
                'stock_max' => 400,
                'is_active' => 1,
            ])
            ->assertRedirect('/barang');

        $item->refresh();

        expect($item->item_name)->toBe('Kertas HVS A4 80 gram')
            ->and($item->stock_min)->toBe(25)
            ->and($item->stock_max)->toBe(400);
    });

    // Inti dari keputusan desain: stok hanya berubah lewat StockLedger.
    it('tidak mengubah stok walaupun stock_current dikirim di request', function () {
        $item = Item::factory()->create(['item_code' => 'ATK-001', 'stock_current' => 40]);

        $this->actingAs(pengelolaGudang())->put("/barang/{$item->item_id}", [
            'item_code' => 'ATK-001',
            'item_name' => $item->item_name,
            'unit' => $item->unit->value,
            'stock_min' => 10,
            'stock_max' => 200,
            'is_active' => 1,
            'stock_current' => 9999,
            'stock_awal' => 9999,
        ]);

        expect($item->refresh()->stock_current)->toBe(40)
            ->and($item->stockMutations()->count())->toBe(0);
    });
});

describe('status barang', function () {
    it('mengarsipkan dan mengaktifkan kembali', function () {
        $actor = pengelolaGudang();
        $item = Item::factory()->create(['is_active' => true]);

        $this->actingAs($actor)->patch("/barang/{$item->item_id}/status");
        expect($item->refresh()->is_active)->toBeFalse();

        $this->actingAs($actor)->patch("/barang/{$item->item_id}/status");
        expect($item->refresh()->is_active)->toBeTrue();
    });
});

describe('menghapus barang', function () {
    it('menghapus barang yang belum bertransaksi', function () {
        $actor = pengelolaGudang();
        $item = Item::factory()->create(['item_code' => 'ATK-777']);

        $this->actingAs($actor)
            ->delete("/barang/{$item->item_id}")
            ->assertRedirect('/barang')
            ->assertSessionHas('status');

        expect(Item::find($item->item_id))->toBeNull();
    });

    it('menolak menghapus barang yang punya riwayat mutasi', function () {
        $item = Item::factory()->create(['stock_current' => 0]);
        StockLedger::record($item, MutationType::SaldoAwal, 10);

        $this->actingAs(pengelolaGudang())
            ->delete("/barang/{$item->item_id}")
            ->assertSessionHas('error');

        expect(Item::find($item->item_id))->not->toBeNull();
    });
});

describe('daftar dan penyaringan', function () {
    it('menyaring berdasarkan kata kunci, satuan, dan status barang', function () {
        $actor = pengelolaGudang();
        Item::factory()->create(['item_code' => 'ATK-001', 'item_name' => 'Kertas HVS A4', 'unit' => SatuanBarang::Rim]);
        Item::factory()->create(['item_code' => 'ATK-002', 'item_name' => 'Tinta Printer', 'unit' => SatuanBarang::Botol]);
        Item::factory()->arsip()->create(['item_code' => 'ATK-003', 'item_name' => 'Ordner Folio', 'unit' => SatuanBarang::Buah]);

        $this->actingAs($actor)->get('/barang?q=Kertas')
            ->assertSee('Kertas HVS A4')->assertDontSee('Tinta Printer');

        $this->actingAs($actor)->get('/barang?unit='.SatuanBarang::Botol->value)
            ->assertSee('Tinta Printer')->assertDontSee('Kertas HVS A4');

        $this->actingAs($actor)->get('/barang?status=arsip')
            ->assertSee('Ordner Folio')->assertDontSee('Kertas HVS A4');
    });

    it('menyaring berdasarkan status stok', function () {
        $actor = pengelolaGudang();
        Item::factory()->create(['item_name' => 'Barang Aman', 'stock_current' => 100, 'stock_min' => 10, 'stock_max' => 200]);
        Item::factory()->create(['item_name' => 'Barang Menipis', 'stock_current' => 5, 'stock_min' => 10, 'stock_max' => 200]);
        Item::factory()->create(['item_name' => 'Barang Habis', 'stock_current' => 0, 'stock_min' => 10, 'stock_max' => 200]);
        Item::factory()->create(['item_name' => 'Barang Berlebih', 'stock_current' => 500, 'stock_min' => 10, 'stock_max' => 200]);

        $this->actingAs($actor)->get('/barang?stock='.StockStatus::Menipis->value)
            ->assertSee('Barang Menipis')
            ->assertDontSee('Barang Aman')
            ->assertDontSee('Barang Habis');

        $this->actingAs($actor)->get('/barang?stock='.StockStatus::Habis->value)
            ->assertSee('Barang Habis')->assertDontSee('Barang Menipis');

        $this->actingAs($actor)->get('/barang?stock='.StockStatus::Berlebih->value)
            ->assertSee('Barang Berlebih')->assertDontSee('Barang Aman');

        $this->actingAs($actor)->get('/barang?stock='.StockStatus::Aman->value)
            ->assertSee('Barang Aman')
            ->assertDontSee('Barang Menipis')
            ->assertDontSee('Barang Berlebih');
    });
});

describe('halaman detail', function () {
    it('menampilkan riwayat mutasi', function () {
        $item = Item::factory()->create(['item_name' => 'Kertas HVS A4', 'stock_current' => 0]);
        StockLedger::record($item, MutationType::SaldoAwal, 50);
        StockLedger::record($item, MutationType::Keluar, 20);

        $this->actingAs(pengelolaGudang())
            ->get("/barang/{$item->item_id}")
            ->assertOk()
            ->assertSee('Kertas HVS A4')
            ->assertSee('Saldo Awal')
            ->assertSee('Barang Keluar')
            ->assertSee('+50')
            ->assertSee('-20');
    });
});

describe('status stok pada model', function () {
    it('menghitung status dari batas minimum dan maksimum', function () {
        expect(Item::factory()->make(['stock_current' => 0, 'stock_min' => 10, 'stock_max' => 100])->stockStatus())
            ->toBe(StockStatus::Habis)
            ->and(Item::factory()->make(['stock_current' => 10, 'stock_min' => 10, 'stock_max' => 100])->stockStatus())
            ->toBe(StockStatus::Menipis)
            ->and(Item::factory()->make(['stock_current' => 50, 'stock_min' => 10, 'stock_max' => 100])->stockStatus())
            ->toBe(StockStatus::Aman)
            ->and(Item::factory()->make(['stock_current' => 150, 'stock_min' => 10, 'stock_max' => 100])->stockStatus())
            ->toBe(StockStatus::Berlebih)
            // stock_max 0 berarti tanpa batas atas, jadi tidak pernah berlebih.
            ->and(Item::factory()->make(['stock_current' => 9999, 'stock_min' => 10, 'stock_max' => 0])->stockStatus())
            ->toBe(StockStatus::Aman);
    });
});
