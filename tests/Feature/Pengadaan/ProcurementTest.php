<?php

use App\Enums\NotificationType;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\ProcurementRequest;
use App\Models\StockMutation;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Setujui seluruh rincian sebuah usulan lewat alur aplikasi, mengembalikan
 * usulan yang sudah Disetujui.
 */
function setujuiUsulan(User $pejabat, ProcurementRequest $usulan): ProcurementRequest
{
    $granted = $usulan->details->mapWithKeys(
        fn ($detail) => [$detail->procurement_request_detail_id => $detail->amount_request],
    )->all();

    test()->actingAs($pejabat)
        ->patch("/usulan-pengadaan/{$usulan->procurement_request_id}/setujui", ['granted' => $granted]);

    return $usulan->refresh();
}

/**
 * @return array<string, mixed>
 */
function realisasiPayload(ProcurementRequest $usulan, Supplier $supplier, array $overrides = []): array
{
    $items = $usulan->details->map(fn ($detail) => [
        'procurement_request_detail_id' => $detail->procurement_request_detail_id,
        'amount' => $detail->amount_granted,
        'unit_price' => 10000,
    ])->all();

    return array_merge([
        'procurement_request_id' => $usulan->procurement_request_id,
        'supplier_id' => $supplier->supplier_id,
        'procurement_date' => now()->toDateString(),
        'items' => $items,
    ], $overrides);
}

/**
 * Buat realisasi lewat alur aplikasi, mengembalikan model Procurement.
 *
 * Realisasinya berhenti di MenungguPersetujuan — sejak ADR 0030 itulah keadaan
 * kelahirannya. Fixture yang membutuhkan realisasi berjalan harus melanjutkan
 * dengan `setujuiRealisasi()`.
 */
function buatRealisasi(User $pejabat, ProcurementRequest $usulan, Supplier $supplier, array $overrides = []): Procurement
{
    $response = test()->actingAs($pejabat)->post('/pengadaan', realisasiPayload($usulan, $supplier, $overrides));

    $id = (string) str($response->headers->get('Location'))->match('#/pengadaan/([^/]+)#');

    return Procurement::findOrFail($id);
}

/**
 * Akun Kasubbag Umum dan TI, pemberi persetujuan realisasi (ADR 0030).
 *
 * Namanya unik lintas berkas karena fungsi di berkas Pest bersifat global.
 */
function kasubbagUmumTi(array $attributes = []): User
{
    return User::factory()->role(UserRole::KasubbagUmumTi)->create($attributes);
}

/**
 * Setujui realisasi lewat alur aplikasi. Sejak ADR 0035 statusnya berpindah
 * ke ProsesBelanja, bukan langsung MenungguPenerimaan — fixture yang
 * meneruskan ke penerimaan melanjutkan dengan `belanjakanRealisasi()`.
 */
function setujuiRealisasi(Procurement $procurement, ?User $kasubbag = null): Procurement
{
    test()->actingAs($kasubbag ?? kasubbagUmumTi())
        ->patch("/pengadaan/{$procurement->procurement_id}/setujui");

    return $procurement->refresh();
}

/**
 * Tandai realisasi sudah dibelanjakan lewat alur aplikasi, beserta satu foto
 * belanja palsu, sehingga statusnya berpindah ke MenungguPenerimaan (ADR 0035).
 *
 * Fotonya wajib, jadi fixture ini menulis berkas. Disk palsu dipasang di sini
 * bila berkas uji belum memasangnya sendiri — supaya tidak ada foto yang
 * mendarat di storage/app/private sungguhan — dan tidak dipasang ulang bila
 * sudah, supaya berkas yang diunggah uji lebih dulu tidak ikut tersapu.
 */
function belanjakanRealisasi(Procurement $procurement, ?User $pejabat = null): Procurement
{
    $akar = str_replace('\\', '/', Storage::disk(DocumentStorage::DISK)->path(''));

    if (! str_contains($akar, 'framework/testing/disks')) {
        Storage::fake(DocumentStorage::DISK);
    }

    test()->actingAs($pejabat ?? pejabatPengadaan())
        ->patch("/pengadaan/{$procurement->procurement_id}/dibelanjakan", [
            'purchased_at' => now()->toDateString(),
            'photos' => [UploadedFile::fake()->image('belanja.jpg')],
        ]);

    return $procurement->refresh();
}

/**
 * Jalan pintas fixture: buat realisasi, setujui, lalu tandai sudah
 * dibelanjakan — realisasi yang siap dikonfirmasi diterima gudang.
 */
function buatRealisasiSiapDiterima(User $pejabat, ProcurementRequest $usulan, Supplier $supplier, array $overrides = []): Procurement
{
    return belanjakanRealisasi(setujuiRealisasi(buatRealisasi($pejabat, $usulan, $supplier, $overrides)), $pejabat);
}

describe('hak akses realisasi', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/pengadaan')->assertRedirect('/login');
    });

    it('mengizinkan kedua peran pengadaan membaca daftar dan detail realisasi', function () {
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi(pejabatPengadaan(), $usulan, Supplier::factory()->create());

        $this->actingAs(pengelolaGudang())->get('/pengadaan')->assertOk();
        $this->actingAs(pejabatPengadaan())->get("/pengadaan/{$procurement->procurement_id}")->assertOk();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->get('/pengadaan')->assertOk();
    });

    it('menolak peran lain membaca realisasi', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->get('/pengadaan')
            ->assertForbidden();
    });

    it('mengizinkan Pejabat Pengadaan membuka formulir realisasi', function () {
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->actingAs(pejabatPengadaan())
            ->get("/pengadaan/create?procurement_request_id={$usulan->procurement_request_id}")
            ->assertOk()
            ->assertSee('Realisasi Pengadaan');
    });

    it('menolak Pengelola Gudang membuka formulir realisasi', function () {
        $usulan = setujuiUsulan(pejabatPengadaan(), buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->actingAs(pengelolaGudang())
            ->get("/pengadaan/create?procurement_request_id={$usulan->procurement_request_id}")
            ->assertForbidden();
    });
});

describe('membuat realisasi', function () {
    it('menyimpan realisasi beserta rincian, mencatat audit, dan memberi tahu Kasubbag Umum dan TI', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $kasubbag = kasubbagUmumTi();
        $item = Item::factory()->create(['item_name' => 'Tinta Printer Hitam']);
        $supplier = Supplier::factory()->create();

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => $item->item_id, 'amount_request' => 40],
        ]));

        $procurement = buatRealisasi($pejabat, $usulan, $supplier);

        // ADR 0030: realisasi lahir menunggu persetujuan, bukan langsung
        // menunggu penerimaan.
        expect($procurement->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and($procurement->user_id)->toBe($pejabat->user_id)
            ->and($procurement->reviewed_at)->toBeNull()
            ->and($procurement->details)->toHaveCount(1)
            ->and($procurement->details->first()->amount)->toBe(40)
            ->and($procurement->details->first()->unit_price)->toBe(10000)
            ->and($procurement->details->first()->amount_received)->toBeNull();

        expect(AuditLog::where('user_id', $pejabat->user_id)
            ->where('activity', 'like', 'Membuat realisasi pengadaan%')
            ->exists())->toBeTrue();

        expect(Notification::query()
            ->where('user_id', $kasubbag->user_id)
            ->where('type', NotificationType::RealisasiMenungguPersetujuan)
            ->exists())->toBeTrue();

        // Gudang belum ditagih apa pun: barangnya belum tentu jadi dipesan.
        expect(Notification::query()
            ->where('user_id', $gudang->user_id)
            ->where('type', NotificationType::RealisasiMenungguPenerimaan)
            ->exists())->toBeFalse();
    });

    it('memindahkan status usulan asalnya dari menunggu realisasi menjadi selesai', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        expect($usulan->status)->toBe(ProcurementRequestStatus::DisetujuiMenungguRealisasi);

        buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        expect($usulan->fresh()->status)->toBe(ProcurementRequestStatus::DisetujuiSelesai);
    });

    it('menolak realisasi dari usulan yang belum disetujui', function () {
        $item = Item::factory()->create();
        $usulan = buatUsulan(pengelolaGudang(), [['item_id' => $item->item_id, 'amount_request' => 10]]);

        $this->actingAs(pejabatPengadaan())
            ->post('/pengadaan', realisasiPayload($usulan, Supplier::factory()->create()))
            ->assertSessionHasErrors('procurement_request_id');

        expect(Procurement::count())->toBe(0);
    });

    it('menolak realisasi kedua dari usulan yang sudah punya realisasi', function () {
        $pejabat = pejabatPengadaan();
        $item = Item::factory()->create();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $supplier = Supplier::factory()->create();

        buatRealisasi($pejabat, $usulan, $supplier);

        $this->actingAs($pejabat)
            ->post('/pengadaan', realisasiPayload($usulan->refresh(), $supplier))
            ->assertSessionHasErrors('procurement_request_id');

        expect(Procurement::count())->toBe(1);
    });

    it('mengizinkan jumlah pesan melebihi jumlah disetujui usulan', function () {
        // ADR 0029: Pejabat Pengadaan boleh memesan lebih banyak dari yang
        // disetujui usulan — keputusan pembelian akhir ada di tangannya.
        $pejabat = pejabatPengadaan();
        $item = Item::factory()->create();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $detail = $usulan->details->first();

        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create(), [
            'items' => [[
                'procurement_request_detail_id' => $detail->procurement_request_detail_id,
                'amount' => 999,
                'unit_price' => 5000,
            ]],
        ]);

        expect($procurement->details->first()->amount)->toBe(999);
    });

    it('mengizinkan menambah barang di luar usulan sekaligus melebihi jumlah usulan', function () {
        $pejabat = pejabatPengadaan();
        $itemUsulan = Item::factory()->create();
        $itemTambahan = Item::factory()->create();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $itemUsulan->item_id, 'amount_request' => 10],
        ]));
        $detail = $usulan->details->first();

        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create(), [
            'items' => [
                [
                    'procurement_request_detail_id' => $detail->procurement_request_detail_id,
                    'amount' => 10,
                    'unit_price' => 5000,
                ],
                [
                    'item_id' => $itemTambahan->item_id,
                    'amount' => 3,
                    'unit_price' => 7000,
                ],
            ],
        ]);

        expect($procurement->details)->toHaveCount(2)
            ->and($procurement->details->firstWhere('item_id', $itemTambahan->item_id)->amount)->toBe(3);
    });

    it('menolak realisasi yang tidak mencakup seluruh barang usulan yang disetujui', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $kertas = Item::factory()->create(['item_name' => 'Kertas HVS A4']);
        $tinta = Item::factory()->create(['item_name' => 'Tinta Printer']);

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => $kertas->item_id, 'amount_request' => 10],
            ['item_id' => $tinta->item_id, 'amount_request' => 5],
        ]));

        $this->actingAs($pejabat)
            ->post('/pengadaan', realisasiPayload($usulan, Supplier::factory()->create(), [
                'items' => [[
                    'procurement_request_detail_id' => $usulan->details->first()->procurement_request_detail_id,
                    'amount' => 10,
                    'unit_price' => 5000,
                ]],
            ]))
            ->assertSessionHasErrors('items');

        expect(Procurement::count())->toBe(0);
    });

    it('menolak barang tambahan yang sama dengan barang usulan', function () {
        $pejabat = pejabatPengadaan();
        $item = Item::factory()->create();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $detail = $usulan->details->first();

        $this->actingAs($pejabat)
            ->post('/pengadaan', realisasiPayload($usulan, Supplier::factory()->create(), [
                'items' => [
                    [
                        'procurement_request_detail_id' => $detail->procurement_request_detail_id,
                        'amount' => 10,
                        'unit_price' => 5000,
                    ],
                    [
                        'item_id' => $item->item_id,
                        'amount' => 3,
                        'unit_price' => 7000,
                    ],
                ],
            ]))
            ->assertSessionHasErrors('items.1.item_id');
    });

    it('mengarahkan kembali dengan galat bila usulan pada tautan tidak ditemukan', function () {
        $this->actingAs(pejabatPengadaan())
            ->get('/pengadaan/create?procurement_request_id=tidak-ada')
            ->assertRedirect('/usulan-pengadaan')
            ->assertSessionHas('error');
    });
});

describe('jalur dari halaman usulan', function () {
    it('menampilkan tombol Realisasikan pada usulan yang disetujui dan belum direalisasikan', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));

        $this->actingAs($pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertOk()
            ->assertSee('Realisasikan');
    });

    it('menampilkan tautan Lihat Realisasi begitu usulan sudah direalisasikan', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($pejabat)
            ->get("/usulan-pengadaan/{$usulan->procurement_request_id}")
            ->assertOk()
            ->assertSee('Lihat Realisasi')
            ->assertDontSee('Realisasikan');
    });
});

describe('konfirmasi penerimaan', function () {
    it('menambah stok sebanyak jumlah diterima, bukan jumlah dipesan, dan mengonfirmasi lewat Pengelola Gudang', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $item = Item::factory()->create(['stock_current' => 5]);
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 40],
        ]));
        $procurement = buatRealisasiSiapDiterima($pejabat, $usulan, Supplier::factory()->create());
        $detail = $procurement->details->first();

        $response = $this->actingAs($gudang)->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
            'received_at' => now()->toDateString(),
            'received' => [$detail->procurement_detail_id => 30],
        ]);

        $response->assertRedirect("/pengadaan/{$procurement->procurement_id}");

        expect($item->refresh()->stock_current)->toBe(35)
            ->and($detail->refresh()->amount_received)->toBe(30)
            ->and($detail->kurangKirim())->toBe(10)
            ->and($procurement->refresh()->status)->toBe(ProcurementStatus::Diterima)
            ->and($procurement->receipt->received_by)->toBe($gudang->user_id);

        // Nomor BAST baru diisi saat cetak pertama, sehingga mutasinya merujuk
        // judul realisasi — baris mutasi tidak dapat diubah sesudahnya (ADR 0042).
        expect($procurement->receipt->bast_number)->toBeNull()
            ->and(StockMutation::where('item_id', $item->item_id)->sole()->reference_number)
            ->toBe($procurement->judul());

        expect(Notification::query()
            ->where('user_id', $pejabat->user_id)
            ->where('type', NotificationType::PenerimaanDikonfirmasi)
            ->exists())->toBeTrue();

        // Keadaan "menunggu penerimaan" untuk Pengelola Gudang harus tertutup.
        expect(Notification::query()
            ->where('user_id', $gudang->user_id)
            ->where('type', NotificationType::RealisasiMenungguPenerimaan)
            ->exists())->toBeFalse();
    });

    it('menolak Pejabat Pengadaan mengonfirmasi penerimaan', function () {
        $pejabat = pejabatPengadaan();
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => Item::factory()->create()->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasi($pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($pejabat)
            ->get("/pengadaan/{$procurement->procurement_id}/penerimaan/create")
            ->assertForbidden();
    });

    it('menolak konfirmasi ulang pada realisasi yang sudah diterima', function () {
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $item = Item::factory()->create(['stock_current' => 0]);
        $usulan = setujuiUsulan($pejabat, buatUsulan(pengelolaGudang(), [
            ['item_id' => $item->item_id, 'amount_request' => 10],
        ]));
        $procurement = buatRealisasiSiapDiterima($pejabat, $usulan, Supplier::factory()->create());
        $detail = $procurement->details->first();

        $this->actingAs($gudang)->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
            'received_at' => now()->toDateString(),
            'received' => [$detail->procurement_detail_id => 10],
        ]);

        $this->actingAs($gudang)
            ->get("/pengadaan/{$procurement->procurement_id}/penerimaan/create")
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}")
            ->assertSessionHas('error');

        expect($detail->item->refresh()->stock_current)->toBe(10);
    });

    it('menolak konfirmasi yang tidak mencakup seluruh baris barang', function () {
        // Penerimaan adalah peristiwa sekali jadi tanpa rute ubah, jadi baris
        // yang hilang dari kiriman akan terbaca "nol diterima" secara permanen.
        // Aturannya sama dengan formulir realisasi pada ADR 0021.
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $kertas = Item::factory()->create(['item_name' => 'Kertas HVS A4', 'stock_current' => 0]);
        $tinta = Item::factory()->create(['item_name' => 'Tinta Printer', 'stock_current' => 0]);

        $usulan = setujuiUsulan($pejabat, buatUsulan($gudang, [
            ['item_id' => $kertas->item_id, 'amount_request' => 10],
            ['item_id' => $tinta->item_id, 'amount_request' => 5],
        ]));
        $procurement = buatRealisasiSiapDiterima($pejabat, $usulan, Supplier::factory()->create());

        $this->actingAs($gudang)
            ->post("/pengadaan/{$procurement->procurement_id}/penerimaan", [
                'received_at' => now()->toDateString(),
                // Hanya satu dari dua barang yang diisi.
                'received' => [$procurement->details->first()->procurement_detail_id => 10],
            ])
            ->assertSessionHasErrors('received');

        expect($procurement->fresh()->isMenungguPenerimaan())->toBeTrue()
            ->and($kertas->refresh()->stock_current)->toBe(0)
            ->and($tinta->refresh()->stock_current)->toBe(0);
    });
});

describe('nomor BAST pada konfirmasi penerimaan', function () {
    it('tidak diminta, karena diisi saat BAST dicetak pertama kali', function () {
        // ADR 0042: nomornya berasal dari agenda kantor dan sering belum ada
        // ketika barang datang.
        $pejabat = pejabatPengadaan();
        $gudang = pengelolaGudang();
        $procurement = realisasiMenunggu($pejabat, $gudang);

        $this->actingAs($gudang)
            ->get("/pengadaan/{$procurement->procurement_id}/penerimaan/create")
            ->assertOk()
            ->assertDontSee('name="bast_number"', false);
    });
});
