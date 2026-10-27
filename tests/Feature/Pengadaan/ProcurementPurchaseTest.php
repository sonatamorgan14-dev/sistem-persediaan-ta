<?php

use App\Enums\AlasanPengadaan;
use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\NotificationType;
use App\Enums\ProcurementDocumentType;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Procurement;
use App\Models\ProcurementDocument;
use App\Models\StockMutation;
use App\Models\Supplier;
use App\Models\User;
use App\Support\DocumentStorage;
use App\Support\NotificationScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// Tahap belanja realisasi pengadaan (ADR 0035). Seluruh pembantu didefinisikan
// di berkas ini dengan akhiran `Belanja`, supaya berkas ini tetap lulus saat
// dijalankan sendirian — fungsi Pest hanya global ketika seluruh suite jalan.

function penggunaBelanja(UserRole $role): User
{
    return User::factory()->role($role)->create();
}

/**
 * Realisasi langsung (ADR 0031) yang dibuat lewat model lalu disetujui lewat
 * rute aplikasi, sehingga berhenti di ProsesBelanja persis seperti di lapangan.
 */
function realisasiDisetujuiBelanja(User $pejabat, User $kasubbag, int $jumlah = 10): Procurement
{
    $procurement = Procurement::create([
        'supplier_id' => Supplier::factory()->create()->supplier_id,
        'procurement_date' => now()->toDateString(),
        'basis_reason' => AlasanPengadaan::KebutuhanMendesak,
        'basis_note' => 'Kebutuhan rapat pekan ini.',
        'status' => ProcurementStatus::MenungguPersetujuan,
        'user_id' => $pejabat->user_id,
    ]);

    $procurement->details()->create([
        'item_id' => Item::factory()->create(['stock_current' => 0])->item_id,
        'amount' => $jumlah,
        'unit_price' => 12000,
    ]);

    test()->actingAs($kasubbag)->patch("/pengadaan/{$procurement->procurement_id}/setujui");

    return $procurement->refresh();
}

/**
 * @return array<string, mixed>
 */
function muatanBelanja(array $overrides = []): array
{
    return array_merge([
        'purchased_at' => now()->toDateString(),
        'photos' => [UploadedFile::fake()->image('belanja.jpg')],
        'purchase_note' => 'Dibeli di toko terdekat.',
    ], $overrides);
}

function notifikasiAktifBelanja(NotificationType $type, Procurement $procurement): int
{
    return Notification::query()
        ->where('type', $type)
        ->where('subject_id', $procurement->procurement_id)
        ->count();
}

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);

    $this->pejabat = penggunaBelanja(UserRole::PejabatPengadaan);
    $this->gudang = penggunaBelanja(UserRole::PengelolaGudang);
    $this->kasubbag = penggunaBelanja(UserRole::KasubbagUmumTi);
    $this->procurement = realisasiDisetujuiBelanja($this->pejabat, $this->kasubbag);
    $this->url = "/pengadaan/{$this->procurement->procurement_id}";
});

describe('hak akses tandai dibelanjakan', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        // beforeEach menyetujui lewat rute aplikasi, jadi sesinya masih masuk.
        auth()->logout();

        $this->patch("{$this->url}/dibelanjakan", muatanBelanja())->assertRedirect('/login');
    });

    it('menolak Pengelola Gudang, Kasubbag Umum dan TI, dan peran lain', function () {
        // Yang membelanjakan adalah Pejabat Pengadaan; penerima dan pemberi
        // persetujuan tidak mencatat belanja orang lain (ADR 0016).
        foreach ([$this->gudang, $this->kasubbag, penggunaBelanja(UserRole::KepalaUnit)] as $user) {
            $this->actingAs($user)->patch("{$this->url}/dibelanjakan", muatanBelanja())->assertForbidden();
        }

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja);
    });

    it('mengizinkan Pejabat Pengadaan', function () {
        $this->actingAs($this->pejabat)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja())
            ->assertRedirect($this->url);

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::MenungguPenerimaan);
    });

    it('mengizinkan Admin', function () {
        $admin = penggunaBelanja(UserRole::Admin);

        $this->actingAs($admin)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja())
            ->assertRedirect($this->url);

        expect($this->procurement->fresh()->purchased_by)->toBe($admin->user_id);
    });
});

describe('proses belanja sebelum ditandai', function () {
    it('menampilkan tombol tandai kepada Pejabat Pengadaan tanpa keterangan bagi gudang', function () {
        $this->actingAs($this->pejabat)
            ->get($this->url)
            ->assertOk()
            ->assertSee('Proses Belanja')
            ->assertSee('Tandai Sudah Dibelanjakan')
            ->assertDontSee('Konfirmasi penerimaan terbuka');
    });

    it('menjelaskan kepada gudang mengapa belum ada tombol konfirmasi penerimaan', function () {
        $this->actingAs($this->gudang)
            ->get($this->url)
            ->assertOk()
            ->assertSee('Konfirmasi penerimaan')
            ->assertDontSee('Tandai Sudah Dibelanjakan')
            ->assertDontSee(route('procurement-receipts.create', $this->procurement));
    });

    it('menolak gudang membuka formulir penerimaan', function () {
        $this->actingAs($this->gudang)
            ->get("{$this->url}/penerimaan/create")
            ->assertRedirect($this->url)
            ->assertSessionHas('error', fn (string $pesan) => str_contains($pesan, 'belum ditandai sudah dibelanjakan'));
    });

    it('tidak menambah stok ketika penerimaan dipaksakan lewat POST langsung', function () {
        $detail = $this->procurement->details->first();

        $this->actingAs($this->gudang)
            ->from($this->url)
            ->post("{$this->url}/penerimaan", [
                'received_at' => now()->toDateString(),
                'received' => [$detail->procurement_detail_id => 10],
            ])
            ->assertSessionHas('error');

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja)
            ->and($detail->item->refresh()->stock_current)->toBe(0)
            ->and(StockMutation::count())->toBe(0);
    });
});

describe('menandai sudah dibelanjakan', function () {
    it('menyimpan fakta belanja, fotonya, dan memindahkan status ke menunggu penerimaan', function () {
        $this->actingAs($this->pejabat)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja([
                'photos' => [
                    UploadedFile::fake()->image('barang.jpg'),
                    UploadedFile::fake()->image('kasir.png'),
                ],
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $segar = $this->procurement->fresh();
        $foto = ProcurementDocument::where('procurement_id', $segar->procurement_id)->get();

        expect($segar->status)->toBe(ProcurementStatus::MenungguPenerimaan)
            ->and($segar->sudahDibelanjakan())->toBeTrue()
            ->and($segar->purchased_at->toDateString())->toBe(now()->toDateString())
            ->and($segar->purchased_by)->toBe($this->pejabat->user_id)
            ->and($segar->purchase_note)->toBe('Dibeli di toko terdekat.')
            ->and($foto)->toHaveCount(2)
            ->and($foto->pluck('type')->unique()->all())->toBe([ProcurementDocumentType::FotoBelanja])
            ->and($foto->pluck('original_name')->sort()->values()->all())->toBe(['barang.jpg', 'kasir.png']);

        $foto->each(fn (ProcurementDocument $document) => Storage::disk(DocumentStorage::DISK)->assertExists($document->file_path));
    });

    it('mewajibkan minimal satu foto belanja', function () {
        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja(['photos' => []]))
            ->assertSessionHasErrors('photos');

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja)
            ->and($this->procurement->fresh()->purchased_at)->toBeNull();
    });

    it('menghitung foto belanja yang sudah diunggah lebih dulu', function () {
        $this->actingAs($this->pejabat)->post("{$this->url}/dokumen", [
            'type' => ProcurementDocumentType::FotoBelanja->value,
            'file' => UploadedFile::fake()->image('diunggah-duluan.jpg'),
        ]);

        $this->actingAs($this->pejabat)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja(['photos' => []]))
            ->assertSessionHasNoErrors();

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::MenungguPenerimaan);
    });

    it('tidak menghitung berkas jenis lain sebagai foto belanja', function () {
        $this->actingAs($this->pejabat)->post("{$this->url}/dokumen", [
            'type' => ProcurementDocumentType::SpkKontrak->value,
            'file' => UploadedFile::fake()->create('spk.pdf', 10, 'application/pdf'),
        ]);

        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja(['photos' => []]))
            ->assertSessionHasErrors('photos');
    });

    it('menolak tanggal belanja di masa depan maupun sebelum tanggal realisasi', function () {
        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja(['purchased_at' => now()->addDay()->toDateString()]))
            ->assertSessionHasErrors('purchased_at');

        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja(['purchased_at' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors('purchased_at');

        expect($this->procurement->fresh()->isProsesBelanja())->toBeTrue();
    });

    it('menolak berkas bukan gambar, gambar melebihi 10 MB, dan lebih dari lima foto', function () {
        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja([
                'photos' => [UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf')],
            ]))
            ->assertSessionHasErrors('photos.0');

        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja([
                'photos' => [UploadedFile::fake()->create('besar.jpg', 11000, 'image/jpeg')],
            ]))
            ->assertSessionHasErrors('photos.0');

        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja([
                'photos' => array_map(fn ($i) => UploadedFile::fake()->image("foto-{$i}.jpg"), range(1, 6)),
            ]))
            ->assertSessionHasErrors('photos');

        expect(ProcurementDocument::count())->toBe(0);
    });

    it('menerima foto HEIC dari iPhone', function () {
        $this->actingAs($this->pejabat)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja([
                'photos' => [UploadedFile::fake()->create('IMG_0001.heic', 300, 'image/heic')],
            ]))
            ->assertSessionHasNoErrors();

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::MenungguPenerimaan);
    });

    it('menolak penandaan ganda tanpa menambah foto', function () {
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());

        $this->actingAs($this->pejabat)
            ->from($this->url)
            ->patch("{$this->url}/dibelanjakan", muatanBelanja())
            ->assertSessionHas('error');

        expect(ProcurementDocument::count())->toBe(1);
    });

    it('menolak realisasi yang belum disetujui', function () {
        $baru = Procurement::create([
            'supplier_id' => Supplier::factory()->create()->supplier_id,
            'procurement_date' => now()->toDateString(),
            'basis_reason' => AlasanPengadaan::Lainnya,
            'basis_note' => 'Uji.',
            'status' => ProcurementStatus::MenungguPersetujuan,
            'user_id' => $this->pejabat->user_id,
        ]);

        $this->actingAs($this->pejabat)
            ->from("/pengadaan/{$baru->procurement_id}")
            ->patch("/pengadaan/{$baru->procurement_id}/dibelanjakan", muatanBelanja())
            ->assertSessionHas('error');

        expect($baru->fresh()->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and(ProcurementDocument::count())->toBe(0);
        Storage::disk(DocumentStorage::DISK)->assertDirectoryEmpty('/');
    });

    it('mencatat satu baris ubah status dan satu baris unggah per foto', function () {
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja([
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ]));

        expect(AuditLog::where('module', AuditModule::Pengadaan)
            ->where('event', AuditEvent::UbahStatus)
            ->where('activity', 'like', 'Menandai sudah dibelanjakan%')
            ->count())->toBe(1)
            ->and(AuditLog::where('module', AuditModule::DokumenPengadaan)
                ->where('event', AuditEvent::Unggah)
                ->where('activity', 'like', 'Mengunggah Foto Belanja%')
                ->count())->toBe(2);
    });

    it('menampilkan kartu belanja di halaman detail', function () {
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());

        $this->actingAs($this->gudang)
            ->get($this->url)
            ->assertOk()
            ->assertSee('Tanggal Belanja')
            ->assertSee('Dibeli di toko terdekat.')
            ->assertSee('1 foto')
            ->assertSee(route('procurement-receipts.create', $this->procurement));
    });

    it('meneruskan ke penerimaan yang menambah stok', function () {
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());
        $detail = $this->procurement->details->first();

        $this->actingAs($this->gudang)
            ->post("{$this->url}/penerimaan", [
                'received_at' => now()->toDateString(),
                'received' => [$detail->procurement_detail_id => 10],
            ])
            ->assertRedirect($this->url);

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::Diterima)
            ->and($detail->item->refresh()->stock_current)->toBe(10);
    });

    it('menahan akun penandanya dari penghapusan', function () {
        // FK `purchased_by` restrictOnDelete; tanpa relasi di
        // RESTRICTED_RELATIONS penghapusannya meledak jadi 500 (ADR 0003).
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());

        expect($this->pejabat->blockingRelations())->toContain('belanja realisasi pengadaan');
    });
});

describe('perubahan setelah dibelanjakan', function () {
    /**
     * @return array<string, mixed>
     */
    $ubah = function (Procurement $procurement, int $harga): array {
        $detail = $procurement->details->first();

        return [
            'supplier_id' => $procurement->supplier_id,
            'procurement_date' => $procurement->procurement_date->toDateString(),
            'basis_reason' => $procurement->basis_reason->value,
            'basis_note' => $procurement->basis_note,
            'items' => [[
                'procurement_detail_id' => $detail->procurement_detail_id,
                'amount' => $detail->amount,
                'unit_price' => $harga,
            ]],
        ];
    };

    it('mengulang persetujuan tanpa menghapus fakta belanjanya', function () use ($ubah) {
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());

        // Harga di toko ternyata berbeda dari yang disetujui.
        $this->actingAs($this->pejabat)
            ->put($this->url, $ubah($this->procurement->fresh('details'), 13500))
            ->assertSessionHasNoErrors();

        $segar = $this->procurement->fresh();

        expect($segar->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and($segar->reviewed_at)->toBeNull()
            ->and($segar->sudahDibelanjakan())->toBeTrue()
            ->and(notifikasiAktifBelanja(NotificationType::RealisasiMenungguPenerimaan, $segar))->toBe(0);

        $this->actingAs($this->pejabat)->get($this->url)->assertSee('Tanggal Belanja');
    });

    it('langsung kembali menunggu penerimaan begitu disetujui ulang', function () use ($ubah) {
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());
        $this->actingAs($this->pejabat)->put($this->url, $ubah($this->procurement->fresh('details'), 13500));

        $this->actingAs($this->kasubbag)
            ->patch("{$this->url}/setujui")
            ->assertSessionHas('status');

        $segar = $this->procurement->fresh();

        expect($segar->status)->toBe(ProcurementStatus::MenungguPenerimaan)
            ->and(notifikasiAktifBelanja(NotificationType::RealisasiMenungguPenerimaan, $segar))->toBeGreaterThan(0);

        $kabar = Notification::query()
            ->where('user_id', $this->pejabat->user_id)
            ->where('type', NotificationType::RealisasiDisetujui)
            ->latest('created_at')
            ->get()
            ->first(fn (Notification $n) => str_contains($n->body, 'sudah dibelanjakan sebelumnya'));

        expect($kabar)->not->toBeNull();
    });

    it('kembali ke proses belanja bila diubah sebelum dibelanjakan', function () use ($ubah) {
        $this->actingAs($this->pejabat)->put($this->url, $ubah($this->procurement->fresh('details'), 13500));
        $this->actingAs($this->kasubbag)->patch("{$this->url}/setujui");

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja);
    });
});

describe('notifikasi tahap belanja', function () {
    it('tidak menagih gudang sebelum barangnya dibelanjakan', function () {
        expect(notifikasiAktifBelanja(NotificationType::RealisasiMenungguPenerimaan, $this->procurement))->toBe(0);
    });

    it('menagih gudang begitu barangnya ditandai sudah dibelanjakan', function () {
        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());

        $kabar = Notification::query()
            ->where('user_id', $this->gudang->user_id)
            ->where('type', NotificationType::RealisasiMenungguPenerimaan)
            ->where('subject_id', $this->procurement->procurement_id)
            ->first();

        expect($kabar)->not->toBeNull()
            ->and($kabar->body)->toContain('sudah dibelanjakan');
    });

    it('belum menagih Pejabat Pengadaan selama jeda belanja masih wajar', function () {
        $this->travel(6)->days();
        NotificationScanner::periksaSeluruhRealisasi();

        expect(notifikasiAktifBelanja(NotificationType::RealisasiBelumDibelanjakan, $this->procurement))->toBe(0);
    });

    it('menagih Pejabat Pengadaan setelah tujuh hari belum dibelanjakan', function () {
        $this->travel(8)->days();
        NotificationScanner::periksaSeluruhRealisasi();

        $kabar = Notification::query()
            ->where('user_id', $this->pejabat->user_id)
            ->where('type', NotificationType::RealisasiBelumDibelanjakan)
            ->where('subject_id', $this->procurement->procurement_id)
            ->first();

        expect($kabar)->not->toBeNull()
            ->and($kabar->body)->toContain('Tandai Sudah Dibelanjakan')
            // Gudang tidak ikut ditagih atas belanja yang bukan tugasnya.
            ->and(Notification::query()
                ->where('user_id', $this->gudang->user_id)
                ->where('type', NotificationType::RealisasiBelumDibelanjakan)
                ->exists())->toBeFalse();
    });

    it('menutup tagihannya sendiri begitu ditandai sudah dibelanjakan', function () {
        $this->travel(8)->days();
        NotificationScanner::periksaSeluruhRealisasi();

        $this->actingAs($this->pejabat)->patch("{$this->url}/dibelanjakan", muatanBelanja());

        expect(notifikasiAktifBelanja(NotificationType::RealisasiBelumDibelanjakan, $this->procurement))->toBe(0);
    });

    it('menutup tagihannya sendiri begitu realisasinya dibatalkan', function () {
        $this->travel(8)->days();
        NotificationScanner::periksaSeluruhRealisasi();

        $this->actingAs($this->pejabat)->patch("{$this->url}/batal", [
            'cancel_reason' => 'penyedia_gagal_kirim',
            'cancel_note' => 'Toko tutup permanen.',
        ]);

        expect(notifikasiAktifBelanja(NotificationType::RealisasiBelumDibelanjakan, $this->procurement))->toBe(0);
    });
});
