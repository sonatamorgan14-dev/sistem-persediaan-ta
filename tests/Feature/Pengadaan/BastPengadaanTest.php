<?php

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ProcurementDocumentType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Procurement;
use App\Models\ProcurementDocument;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentStorage;
use App\Support\ProcurementReceiptPoster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * BAST pengadaan dengan format resmi BPK (ADR 0042): nomornya diisi saat
 * cetak pertama, pihaknya Pelaksana Belanja dan Petugas Persediaan, rincian
 * barangnya di lampiran. Nama pembantu bersufiks `Bast` karena fungsi Pest
 * berbagi ruang nama global.
 */

/**
 * Realisasi dua barang yang sudah diterima: kertas datang penuh, tinta tidak
 * datang sama sekali. Nomor BAST-nya belum diisi.
 */
function realisasiDiterimaBast(User $pejabat, User $petugas): Procurement
{
    $kertas = Item::factory()->create(['item_name' => 'Kertas HVS A4']);
    $tinta = Item::factory()->create(['item_name' => 'Tinta Printer Hitam']);

    $usulan = setujuiUsulan($pejabat, buatUsulan($petugas, [
        ['item_id' => $kertas->item_id, 'amount_request' => 10],
        ['item_id' => $tinta->item_id, 'amount_request' => 4],
    ]));

    $procurement = buatRealisasiSiapDiterima(
        $pejabat, $usulan, Supplier::factory()->create(['supplier_name' => 'CV Bumi Lampung']),
    );

    $detail = $procurement->details->keyBy('item_id');

    ProcurementReceiptPoster::post(
        procurement: $procurement,
        diterima: [
            $detail[$kertas->item_id]->procurement_detail_id => 10,
            $detail[$tinta->item_id]->procurement_detail_id => 0,
        ],
        actor: $petugas,
        receivedAt: now(),
    );

    return $procurement->fresh();
}

function isiNomorBast(Procurement $procurement, string $nomor): \Illuminate\Testing\TestResponse
{
    return test()->patch("/pengadaan/{$procurement->procurement_id}/bast", ['bast_number' => $nomor]);
}

function htmlBast(Procurement $procurement): string
{
    $procurement->load(['supplier', 'details.item', 'receipt.receivedBy', 'purchasedBy', 'user']);

    return view('pdf.bast-pengadaan', [
        'procurement' => $procurement,
        'receipt' => $procurement->receipt,
    ])->render();
}

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);

    $this->pejabat = pejabatPengadaan([
        'fullname' => 'Ridwan Pelaksana',
        'nip' => '198506142003121001',
        'position' => 'Pengelola Pengadaan Barang/Jasa Ahli Pertama',
    ]);
    $this->petugas = pengelolaGudang([
        'fullname' => 'Yakub Persediaan',
        'nip' => '197409142023211005',
        'position' => 'Arsiparis Ahli Pertama (Pengelola Persediaan)',
    ]);
});

describe('hak akses nomor BAST', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        // Fixture dibangun lewat alur aplikasi, jadi sesinya masih masuk.
        auth()->logout();

        isiNomorBast($procurement, '001/UP_TUP/445237/2026')->assertRedirect('/login');
    });

    it('menolak peran di luar pengadaan', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create());
        isiNomorBast($procurement, '001/UP_TUP/445237/2026')->assertForbidden();
    });

    it('menolak Kasubbag Umum dan TI, yang hanya membaca', function () {
        // ADR 0041: Kasubbag mencetak BAST yang sudah bernomor, tetapi tidak
        // menomorinya.
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs(kasubbagUmumTi());
        isiNomorBast($procurement, '001/UP_TUP/445237/2026')->assertForbidden();

        expect($procurement->receipt->fresh()->bast_number)->toBeNull();
    });

    it('diizinkan bagi kedua penanda tangan dan Admin, lalu langsung mencetak', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);
        $cetak = "/pengadaan/{$procurement->procurement_id}/bast";
        $diterimaPada = $procurement->receipt->received_at;
        $this->travel(2)->days();

        foreach ([
            '001/UP_TUP/445237/2026' => $this->petugas,
            '002/UP_TUP/445237/2026' => $this->pejabat,
            '003/UP_TUP/445237/2026' => User::factory()->role(UserRole::Admin)->create(),
        ] as $nomor => $aktor) {
            $this->actingAs($aktor);
            isiNomorBast($procurement, $nomor)->assertRedirect($cetak);

            expect($procurement->receipt->fresh()->bast_number)->toBe($nomor);
        }

        // Tanggal penerimaan tercetak terbilang di BAST, jadi mengisi nomor
        // tidak boleh menggesernya. SQLite tidak meniru ON UPDATE milik
        // MariaDB — perbaikan aslinya ada di migrasi 2026_10_24_000003.
        expect($procurement->receipt->fresh()->received_at->equalTo($diterimaPada))->toBeTrue();
    });
});

describe('mengisi nomor BAST', function () {
    it('wajib diisi', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas);
        isiNomorBast($procurement, '')->assertSessionHasErrors('bast_number');
    });

    it('menolak nomor yang sudah dipakai penerimaan lain, kecuali miliknya sendiri', function () {
        $pertama = realisasiDiterimaBast($this->pejabat, $this->petugas);
        $kedua = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas);
        isiNomorBast($pertama, '007/UP_TUP/445237/2026')->assertSessionHasNoErrors();
        isiNomorBast($kedua, '007/UP_TUP/445237/2026')->assertSessionHasErrors('bast_number');
        isiNomorBast($pertama, '007/UP_TUP/445237/2026')->assertSessionHasNoErrors();

        expect($kedua->receipt->fresh()->bast_number)->toBeNull();
    });

    it('mencatat audit saat mengisi dan mengubah, tidak saat nomornya sama', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);
        $audit = fn () => AuditLog::query()
            ->where('module', AuditModule::Penerimaan)
            ->where('event', AuditEvent::Ubah)
            ->where('subject_id', $procurement->procurement_id);

        $this->actingAs($this->petugas);
        isiNomorBast($procurement, '001/UP_TUP/445237/2026');
        isiNomorBast($procurement, '001/UP_TUP/445237/2026');
        isiNomorBast($procurement, '002/UP_TUP/445237/2026');

        expect($audit()->count())->toBe(2)
            ->and($audit()->where('activity', 'like', 'Mengisi nomor BAST 001/UP_TUP/445237/2026%')->exists())->toBeTrue()
            ->and($audit()->where('activity', 'like', '%dari 001/UP_TUP/445237/2026 menjadi 002/UP_TUP/445237/2026')->exists())->toBeTrue();
    });

    it('terkunci setelah BAST bertanda tangan diunggah, dan terbuka lagi bila berkasnya dihapus', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas);
        isiNomorBast($procurement, '001/UP_TUP/445237/2026');
        $this->post("/pengadaan/{$procurement->procurement_id}/dokumen", [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast-ttd.pdf', 20, 'application/pdf'),
        ]);

        isiNomorBast($procurement, '009/UP_TUP/445237/2026')
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}")
            ->assertSessionHas('error');

        expect($procurement->receipt->fresh()->bast_number)->toBe('001/UP_TUP/445237/2026');

        $berkas = ProcurementDocument::where('procurement_id', $procurement->procurement_id)
            ->where('type', ProcurementDocumentType::Bast)
            ->sole();
        $this->delete("/pengadaan/{$procurement->procurement_id}/dokumen/{$berkas->procurement_document_id}");

        isiNomorBast($procurement, '009/UP_TUP/445237/2026')->assertSessionHasNoErrors();

        expect($procurement->receipt->fresh()->bast_number)->toBe('009/UP_TUP/445237/2026');
    });

    it('ditolak sebelum penerimaan dikonfirmasi', function () {
        $procurement = realisasiMenunggu($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas);
        isiNomorBast($procurement, '001/UP_TUP/445237/2026')
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}")
            ->assertSessionHas('error');
    });
});

describe('mencetak BAST', function () {
    it('belum dapat dicetak sebelum nomornya diisi', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas)
            ->get("/pengadaan/{$procurement->procurement_id}/bast")
            ->assertRedirect("/pengadaan/{$procurement->procurement_id}")
            ->assertSessionHas('error');
    });

    it('dicetak Kasubbag setelah bernomor, dengan nama berkas dari nomornya', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);
        $procurement->receipt->update(['bast_number' => '001/UP_TUP/445237/2026']);

        $response = $this->actingAs(kasubbagUmumTi())->get("/pengadaan/{$procurement->procurement_id}/bast");

        $response->assertOk();
        expect($response->getContent())->toStartWith('%PDF')
            ->and($response->headers->get('content-disposition'))->toContain('bast-001-up-tup-445237-2026.pdf');
    });
});

describe('isi BAST', function () {
    it('memuat tanggal terbilang, nomor, serta identitas Pelaksana Belanja dan Petugas Persediaan', function () {
        // Tanggal pada contoh format resmi: Kamis, 18 Desember 2025.
        $this->travelTo(now()->setDate(2025, 12, 18)->setTime(9, 0));

        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);
        $procurement->receipt->update(['bast_number' => '012/UP_TUP/445237/2025']);

        $html = htmlBast($procurement);

        expect($html)
            ->toContain('Kamis tanggal Delapan belas bulan Desember tahun Dua ribu dua puluh lima')
            ->toContain('(18-12-2025),')
            ->toContain('Nomor : 012/UP_TUP/445237/2025')
            ->toContain('198506142003121001')
            ->toContain('Pengelola Pengadaan Barang/Jasa Ahli Pertama')
            ->toContain('197409142023211005')
            ->toContain('Arsiparis Ahli Pertama (Pengelola Persediaan)')
            ->toContain('Lampiran Berita Acara Serah Terima Barang Persediaan')
            ->toContain('CV Bumi Lampung');

        // PIHAK PERTAMA adalah yang membelanjakan, PIHAK KEDUA yang menerima.
        expect(strpos($html, 'Ridwan Pelaksana'))->toBeLessThan(strpos($html, 'Yakub Persediaan'))
            ->and($procurement->purchased_by)->toBe($this->pejabat->user_id);
    });

    it('melampirkan hanya barang yang benar-benar diterima', function () {
        // ADR 0017: yang diserahterimakan adalah yang datang. Tinta yang tidak
        // datang hanya muncul di catatan kekurangan, bukan di tabel — maka
        // diperiksa lewat kodenya, yang hanya tercetak di tabel.
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);
        $procurement->receipt->update(['bast_number' => '001/UP_TUP/445237/2026']);

        $kode = $procurement->details->load('item')->mapWithKeys(
            fn ($detail) => [$detail->item->item_name => $detail->item->item_code],
        );

        $html = htmlBast($procurement);

        expect($html)->toContain($kode['Kertas HVS A4'])
            ->not->toContain($kode['Tinta Printer Hitam'])
            ->toContain('Tinta Printer Hitam kurang');
    });

    it('mencetak titik-titik bila NIP atau jabatan belum diisi Admin', function () {
        $petugas = pengelolaGudang(['nip' => null, 'position' => null]);
        $procurement = realisasiDiterimaBast($this->pejabat, $petugas);
        $procurement->receipt->update(['bast_number' => '001/UP_TUP/445237/2026']);

        expect(htmlBast($procurement))->toContain('NIP ..........');
    });
});

describe('kartu penerimaan', function () {
    it('menampilkan formulir nomor BAST kepada penanda tangan', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas)
            ->get("/pengadaan/{$procurement->procurement_id}")
            ->assertOk()
            ->assertSee('Belum diisi')
            ->assertSee('name="bast_number"', false);
    });

    it('tidak menampilkan formulir kepada Kasubbag', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs(kasubbagUmumTi())
            ->get("/pengadaan/{$procurement->procurement_id}")
            ->assertOk()
            ->assertSee('BAST dapat dicetak setelah nomornya diisi.')
            ->assertDontSee('name="bast_number"', false);
    });

    it('menyembunyikan pengubah nomor setelah BAST bertanda tangan diunggah', function () {
        $procurement = realisasiDiterimaBast($this->pejabat, $this->petugas);

        $this->actingAs($this->petugas);
        isiNomorBast($procurement, '001/UP_TUP/445237/2026');

        $this->get("/pengadaan/{$procurement->procurement_id}")->assertSee('Ubah Nomor BAST');

        $this->post("/pengadaan/{$procurement->procurement_id}/dokumen", [
            'type' => 'bast',
            'file' => UploadedFile::fake()->create('bast-ttd.pdf', 20, 'application/pdf'),
        ]);

        $this->get("/pengadaan/{$procurement->procurement_id}")
            ->assertOk()
            ->assertSee('001/UP_TUP/445237/2026')
            ->assertDontSee('Ubah Nomor BAST')
            ->assertDontSee('name="bast_number"', false);
    });
});
