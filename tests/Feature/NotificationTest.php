<?php

use App\Enums\MutationType;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationScanner;
use App\Support\Notifier;
use App\Support\StockLedger;
use App\View\Components\Header\NotificationDropdown;

// Nama fungsi pembantu di Pest bersifat global lintas berkas; `gudang()` dan
// `petugasGudang()` sudah dipakai berkas uji lain.
function petugasNotifikasi(array $attributes = []): User
{
    return User::factory()->role(UserRole::PengelolaGudang)->create($attributes);
}

function adminNotifikasi(array $attributes = []): User
{
    return User::factory()->role(UserRole::Admin)->create($attributes);
}

/**
 * Satu peristiwa tanpa tautan tindak lanjut, dengan judul yang unik per kunci.
 */
function peristiwaNotifikasi(
    User $penerima,
    string $kunci,
    NotificationType $type = NotificationType::OpnameDiposting,
): Notification {
    $dedupKey = $type->dedupKey($kunci);

    Notifier::catatPeristiwa(
        penerima: [$penerima],
        type: $type,
        dedupKey: $dedupKey,
        title: "Peristiwa uji {$kunci}",
        body: "Kalimat lengkap peristiwa uji {$kunci}.",
    );

    return Notification::query()->untuk($penerima)->where('dedup_key', $dedupKey)->firstOrFail();
}

/**
 * Satu-satunya baris keadaan bertipe tertentu milik sebuah barang.
 */
function keadaanBarang(NotificationType $type, Item $item): Notification
{
    return Notification::query()
        ->where('type', $type)
        ->where('subject_id', $item->item_id)
        ->sole();
}

function bacaKeadaanBarangPada(NotificationType $type, Item $item, DateTimeInterface $waktu): void
{
    keadaanBarang($type, $item)->forceFill(['read_at' => $waktu])->save();
}

function hitungNotifikasi(NotificationType $type, ?User $user = null): int
{
    return Notification::query()
        ->where('type', $type)
        ->when($user !== null, fn ($query) => $query->where('user_id', $user->user_id))
        ->count();
}

describe('hak akses', function () {
    // Halaman ini sengaja tidak memakai gate peran: setiap pengguna berhak atas
    // notifikasinya sendiri. Karena itu matriks peran yang biasa dipakai modul
    // gudang diganti — ketiga peran boleh masuk, dan yang dijaga adalah
    // kepemilikan tiap baris (diuji di blok "kepemilikan").
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/notifikasi')->assertRedirect('/login');
    });

    it('mengizinkan Pengelola Gudang', function () {
        $this->actingAs(petugasNotifikasi())->get('/notifikasi')->assertOk()->assertSee('Notifikasi');
    });

    it('mengizinkan Pejabat Pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::PejabatPengadaan)->create())
            ->get('/notifikasi')->assertOk();
    });

    it('mengizinkan Admin', function () {
        $this->actingAs(adminNotifikasi())->get('/notifikasi')->assertOk();
    });
});

describe('keadaan stok', function () {
    it('memberi tahu Pengelola Gudang dan Admin saat stok habis', function () {
        $petugas = petugasNotifikasi();
        $admin = adminNotifikasi();
        $item = Item::factory()->habis()->create(['item_name' => 'Kertas HVS A4']);

        NotificationScanner::periksaBarang($item);

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(2)
            ->and(hitungNotifikasi(NotificationType::StokHabis, $petugas))->toBe(1)
            ->and(hitungNotifikasi(NotificationType::StokHabis, $admin))->toBe(1);

        $notification = Notification::query()->where('user_id', $petugas->user_id)->first();

        expect($notification->title)->toContain('Kertas HVS A4')
            ->and($notification->body)->toContain('usulan pengadaan')
            ->and($notification->sudahDibaca())->toBeFalse();
    });

    it('tidak menumpuk baris meski diperiksa berkali-kali', function () {
        petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        NotificationScanner::periksaBarang($item);
        NotificationScanner::periksaBarang($item);

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(1);
    });

    it('menghapus notifikasi sendiri begitu stok terisi kembali', function () {
        $petugas = petugasNotifikasi();

        // Batas atas dimatikan (0 = tanpa batas) supaya pengisian ulang tidak
        // justru memicu peringatan stok berlebih dan mengaburkan yang diuji.
        $item = Item::factory()->create(['stock_current' => 0, 'stock_min' => 5, 'stock_max' => 0]);

        NotificationScanner::periksaBarang($item);
        expect(Notification::query()->count())->toBe(1);

        StockLedger::record(
            item: $item,
            type: MutationType::HibahMasuk,
            amount: 100,
            recordedBy: $petugas,
        );

        NotificationScanner::periksaBarang($item);

        // Barisnya dihapus, bukan ditandai selesai (ADR 0036).
        expect(Notification::query()->count())->toBe(0);
    });

    it('melahirkan baris baru yang belum dibaca saat keadaan kambuh', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->create(['stock_current' => 0, 'stock_min' => 5, 'stock_max' => 0]);

        NotificationScanner::periksaBarang($item);
        $pertama = Notification::query()->where('type', NotificationType::StokHabis)->firstOrFail();
        Notifier::tandaiSemuaDibaca($petugas);

        // Terisi lalu habis lagi.
        StockLedger::record(item: $item, type: MutationType::HibahMasuk, amount: 100, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        StockLedger::record(item: $item, type: MutationType::BarangRusak, amount: 100, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        $kambuh = Notification::query()->where('type', NotificationType::StokHabis)->firstOrFail();

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(1)
            ->and($kambuh->notification_id)->not->toBe($pertama->notification_id)
            // Ditagih ulang: keadaan yang kambuh bukan kabar lama.
            ->and($kambuh->sudahDibaca())->toBeFalse();
    });

    it('memisahkan stok habis dari stok menipis', function () {
        // Satu petugas saja: tiap pemanggilan factory menambah penerima baru.
        $petugas = petugasNotifikasi();
        $item = Item::factory()->create(['stock_current' => 10, 'stock_min' => 3, 'stock_max' => 0]);

        NotificationScanner::periksaBarang($item);
        expect(Notification::query()->count())->toBe(0);

        StockLedger::record(item: $item, type: MutationType::BarangRusak, amount: 7, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        expect(hitungNotifikasi(NotificationType::StokMenipis))->toBe(1)
            ->and(hitungNotifikasi(NotificationType::StokHabis))->toBe(0);

        StockLedger::record(item: $item, type: MutationType::BarangRusak, amount: 3, recordedBy: $petugas);
        NotificationScanner::periksaBarang($item);

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(1)
            ->and(hitungNotifikasi(NotificationType::StokMenipis))->toBe(0);
    });

    it('menutup peringatan stok saat barang diarsipkan', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        expect(Notification::query()->count())->toBe(1);

        $this->actingAs($petugas)
            ->patch("/barang/{$item->item_id}/status")
            ->assertSessionHas('status');

        expect(Notification::query()->count())->toBe(0);
    });
});

describe('pemicu dari alur aplikasi', function () {
    it('memunculkan peringatan setelah mutasi manual menghabiskan stok', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->create(['stock_current' => 5, 'stock_min' => 1, 'stock_max' => 0]);

        $this->actingAs($petugas)->post('/mutasi', [
            'item_id' => $item->item_id,
            'mutation_type' => MutationType::BarangRusak->value,
            'amount' => 5,
            'mutation_date' => now()->format('Y-m-d'),
            'notes' => 'Rusak terkena air saat gudang bocor.',
        ])->assertRedirect('/mutasi');

        expect(hitungNotifikasi(NotificationType::StokHabis, $petugas))->toBe(1);
    });

    it('melaporkan mutasi manual sensitif kepada Admin tanpa memberi tahu pelakunya', function () {
        $petugas = petugasNotifikasi();
        $admin = adminNotifikasi();
        $item = Item::factory()->create(['stock_current' => 50, 'stock_min' => 1, 'stock_max' => 0]);

        $this->actingAs($petugas)->post('/mutasi', [
            'item_id' => $item->item_id,
            'mutation_type' => MutationType::BarangHilang->value,
            'amount' => 10,
            'mutation_date' => now()->format('Y-m-d'),
            'notes' => 'Tidak ditemukan saat penataan ulang rak.',
        ])->assertRedirect('/mutasi');

        expect(hitungNotifikasi(NotificationType::MutasiManualSensitif, $admin))->toBe(1)
            // Pelakunya baru saja melakukannya sendiri.
            ->and(hitungNotifikasi(NotificationType::MutasiManualSensitif, $petugas))->toBe(0);
    });

    it('tidak melaporkan mutasi manual yang menambah stok', function () {
        $petugas = petugasNotifikasi();
        adminNotifikasi();
        $item = Item::factory()->create(['stock_current' => 50, 'stock_min' => 1, 'stock_max' => 0]);

        $this->actingAs($petugas)->post('/mutasi', [
            'item_id' => $item->item_id,
            'mutation_type' => MutationType::HibahMasuk->value,
            'amount' => 10,
            'mutation_date' => now()->format('Y-m-d'),
            'notes' => 'Hibah dari unit lain.',
        ])->assertRedirect('/mutasi');

        expect(hitungNotifikasi(NotificationType::MutasiManualSensitif))->toBe(0);
    });
});

describe('membaca notifikasi', function () {
    it('menandai dibaca lalu mengantar ke tujuan tindak lanjut', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        $notification = Notification::query()->where('user_id', $petugas->user_id)->firstOrFail();

        $this->actingAs($petugas)
            ->patch("/notifikasi/{$notification->notification_id}/baca")
            ->assertRedirect("/barang/{$item->item_id}");

        expect($notification->fresh()->sudahDibaca())->toBeTrue();
    });

    it('menandai seluruh notifikasi dibaca', function () {
        $petugas = petugasNotifikasi();
        Item::factory()->count(3)->habis()->create()
            ->each(fn (Item $item) => NotificationScanner::periksaBarang($item));

        expect(Notification::query()->untuk($petugas)->belumDibaca()->count())->toBe(3);

        $this->actingAs($petugas)
            ->post('/notifikasi/baca-semua')
            ->assertSessionHas('status');

        expect(Notification::query()->untuk($petugas)->belumDibaca()->count())->toBe(0);
    });
});

describe('kepemilikan', function () {
    it('menolak membaca notifikasi milik pengguna lain', function () {
        $pemilik = petugasNotifikasi();
        $orangLain = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        NotificationScanner::periksaBarang($item);
        $notification = Notification::query()->where('user_id', $pemilik->user_id)->firstOrFail();

        $this->actingAs($orangLain)
            ->patch("/notifikasi/{$notification->notification_id}/baca")
            ->assertNotFound();

        expect($notification->fresh()->sudahDibaca())->toBeFalse();
    });

    it('hanya menampilkan notifikasi milik sendiri', function () {
        $pemilik = petugasNotifikasi(['fullname' => 'Pemilik Notifikasi']);
        $orangLain = petugasNotifikasi();
        Item::factory()->habis()->create(['item_name' => 'Tinta Printer Hitam']);

        NotificationScanner::periksaSeluruhBarang();
        Notification::query()->where('user_id', '!=', $pemilik->user_id)->delete();

        $this->actingAs($pemilik)->get('/notifikasi')->assertSee('Tinta Printer Hitam');
        $this->actingAs($orangLain)->get('/notifikasi')->assertDontSee('Tinta Printer Hitam');
    });
});

describe('perintah terjadwal', function () {
    it('berjalan dan bersifat idempoten', function () {
        petugasNotifikasi();
        Item::factory()->count(2)->habis()->create();

        $this->artisan('notifikasi:periksa')->assertSuccessful();
        $pertama = hitungNotifikasi(NotificationType::StokHabis);

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect($pertama)->toBe(2)
            ->and(hitungNotifikasi(NotificationType::StokHabis))->toBe(2);
    });

    it('menghapus keadaan yang sudah pulih pada pemeriksaan berikutnya', function () {
        $petugas = petugasNotifikasi();
        $item = Item::factory()->habis()->create();

        $this->artisan('notifikasi:periksa')->assertSuccessful();
        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(1);

        StockLedger::record(item: $item, type: MutationType::HibahMasuk, amount: 500, recordedBy: $petugas);

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect(hitungNotifikasi(NotificationType::StokHabis))->toBe(0);
    });

    it('membuang peristiwa berumur lebih dari tujuh hari, dibaca atau belum', function () {
        $admin = adminNotifikasi();
        $keadaanLama = Item::factory()->habis()->create();
        NotificationScanner::periksaBarang($keadaanLama);

        $baru = peristiwaNotifikasi($admin, 'baru');
        $lamaBelumDibaca = peristiwaNotifikasi($admin, 'lama-belum-dibaca');
        $lamaSudahDibaca = peristiwaNotifikasi($admin, 'lama-sudah-dibaca');
        Notifier::tandaiDibaca($lamaSudahDibaca);

        // Keadaan yang sudah lama berlangsung ikut dimundurkan: umur tidak
        // pernah menjadi alasan membuang keadaan, hanya kepulihannya.
        Notification::query()
            ->whereKey([$lamaBelumDibaca->getKey(), $lamaSudahDibaca->getKey()])
            ->orWhere('type', NotificationType::StokHabis)
            ->update(['created_at' => now()->subDays(8)]);

        $this->artisan('notifikasi:periksa --bersihkan')->assertSuccessful();

        expect($baru->fresh())->not->toBeNull()
            ->and($lamaBelumDibaca->fresh())->toBeNull()
            ->and($lamaSudahDibaca->fresh())->toBeNull()
            ->and(hitungNotifikasi(NotificationType::StokHabis, $admin))->toBe(1);
    });

    it('tidak membuang apa pun tanpa opsi bersihkan', function () {
        $admin = adminNotifikasi();
        $lama = peristiwaNotifikasi($admin, 'lama');
        $lama->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect($lama->fresh())->not->toBeNull();
    });
});

describe('lonceng', function () {
    it('hanya menampilkan judul, tanpa kalimat lengkapnya', function () {
        $petugas = petugasNotifikasi();

        // Kalimatnya sengaja pendek: lonceng lama memotong isi di 90 karakter,
        // jadi kalimat panjang akan lolos uji ini meski isinya masih tampil.
        $notification = peristiwaNotifikasi($petugas, 'judul');

        // Halaman lain dipakai supaya yang diperiksa memang lonceng, bukan
        // halaman notifikasi yang memang memuat kalimat lengkapnya.
        $this->actingAs($petugas)->get('/dasbor')
            ->assertOk()
            ->assertSee($notification->title)
            ->assertDontSee($notification->body);

        $this->actingAs($petugas)->get('/notifikasi')->assertSee($notification->body);
    });

    it('bersih setelah tandai semua dibaca, tetapi halaman notifikasi tetap memuatnya', function () {
        $admin = adminNotifikasi();
        Item::factory()->habis()->create();
        NotificationScanner::periksaSeluruhBarang();
        $peristiwa = peristiwaNotifikasi($admin, 'kabar');

        // Komponen lonceng membaca pengguna yang sedang masuk.
        $this->actingAs($admin);

        $lonceng = new NotificationDropdown;
        expect($lonceng->perluTindakan)->toHaveCount(1)
            ->and($lonceng->aktivitas)->toHaveCount(1);

        $this->actingAs($admin)->post('/notifikasi/baca-semua')->assertSessionHas('status');

        $lonceng = new NotificationDropdown;
        expect($lonceng->kosong())->toBeTrue()
            ->and($lonceng->belumDibaca)->toBe(0);

        // Masih ada di halaman notifikasi, termasuk keadaan yang kondisinya
        // belum pulih.
        $this->actingAs($admin)->get('/notifikasi')
            ->assertSee($peristiwa->title)
            ->assertSee('Stok habis');
    });

    it('menyingkirkan peristiwa yang diklik, tetapi menyimpannya di halaman notifikasi', function () {
        $admin = adminNotifikasi();
        $peristiwa = peristiwaNotifikasi($admin, 'kabar');

        $this->actingAs($admin)
            ->patch("/notifikasi/{$peristiwa->notification_id}/baca")
            ->assertRedirect('/notifikasi');

        expect((new NotificationDropdown)->aktivitas)->toBeEmpty()
            ->and($peristiwa->fresh())->not->toBeNull();

        $this->actingAs($admin)->get('/notifikasi?status=sudah_dibaca')->assertSee($peristiwa->title);
        $this->actingAs($admin)->get('/notifikasi?status=belum_dibaca')->assertDontSee($peristiwa->title);
    });

    it('tetap menampilkan aktivitas meski keadaan memenuhi kuota', function () {
        $admin = adminNotifikasi();
        Item::factory()->count(11)->habis()->create();
        NotificationScanner::periksaSeluruhBarang();
        $peristiwa = peristiwaNotifikasi($admin, 'kabar');

        $this->actingAs($admin);
        $lonceng = new NotificationDropdown;

        // Dulu sepuluh baris diambil lebih dulu lalu dibagi, sehingga sebelas
        // keadaan menyisakan nol tempat bagi peristiwa (ADR 0037).
        expect($lonceng->perluTindakan)->toHaveCount(5)
            ->and($lonceng->jumlahPerluTindakan)->toBe(11)
            ->and($lonceng->aktivitas->pluck('notification_id')->all())->toBe([$peristiwa->notification_id])
            ->and($lonceng->jumlahAktivitas)->toBe(1)
            ->and($lonceng->belumDibaca)->toBe(12);

        $this->get('/dasbor')->assertSee('Lihat 6 lainnya');
    });

    it('mengurutkan aktivitas menurut waktu, bukan menurut tingkat', function () {
        $admin = adminNotifikasi();

        // Perlu Perhatian, tetapi lebih lama.
        $lama = peristiwaNotifikasi($admin, 'lama', NotificationType::RealisasiDitolak);
        $lama->forceFill(['created_at' => now()->subHour()])->save();

        // Informasi, tetapi lebih baru.
        $baru = peristiwaNotifikasi($admin, 'baru', NotificationType::OpnameDiposting);

        $this->actingAs($admin);

        expect((new NotificationDropdown)->aktivitas->pluck('notification_id')->all())
            ->toBe([$baru->notification_id, $lama->notification_id]);
    });
});

describe('halaman notifikasi', function () {
    it('menyaring menurut sifat', function () {
        $petugas = petugasNotifikasi();
        Item::factory()->habis()->create(['item_name' => 'Map Plastik Biru']);
        NotificationScanner::periksaSeluruhBarang();
        $peristiwa = peristiwaNotifikasi($petugas, 'sifat');

        // Lonceng dikosongkan lebih dulu: ia tampil di halaman yang sama dan
        // akan ikut memuat judul yang seharusnya tersaring.
        Notifier::tandaiSemuaDibaca($petugas);

        $this->actingAs($petugas)->get('/notifikasi?sifat=keadaan')
            ->assertSee('Map Plastik Biru')
            ->assertDontSee($peristiwa->title);

        $this->actingAs($petugas)->get('/notifikasi?sifat=peristiwa')
            ->assertSee($peristiwa->title)
            ->assertDontSee('Map Plastik Biru');
    });
});

describe('pengingat ulang', function () {
    it('menagih ulang keadaan mendesak yang dibaca kemarin, bukan yang dibaca hari ini', function () {
        petugasNotifikasi();
        [$kemarin, $hariIni] = Item::factory()->count(2)->habis()->create()->all();
        NotificationScanner::periksaSeluruhBarang();

        // Batasnya hari kalender, bukan 24 jam: yang dibaca kemarin menjelang
        // tengah malam — belum 24 jam lalu — tetap ditagih pagi ini, sedangkan
        // yang dibaca tepat pukul 00.00 hari ini belum.
        bacaKeadaanBarangPada(NotificationType::StokHabis, $kemarin, now()->subDay()->endOfDay());
        bacaKeadaanBarangPada(NotificationType::StokHabis, $hariIni, now()->startOfDay());

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect(keadaanBarang(NotificationType::StokHabis, $kemarin)->sudahDibaca())->toBeFalse()
            ->and(keadaanBarang(NotificationType::StokHabis, $hariIni)->sudahDibaca())->toBeTrue();
    });

    it('menagih ulang keadaan perlu perhatian setelah tujuh hari', function () {
        petugasNotifikasi();
        [$tujuhHari, $enamHari] = Item::factory()->count(2)
            ->create(['stock_current' => 2, 'stock_min' => 5, 'stock_max' => 0])
            ->all();
        NotificationScanner::periksaSeluruhBarang();

        // Kedua sisi batasnya: akhir hari ketujuh ditagih, awal hari keenam belum.
        bacaKeadaanBarangPada(NotificationType::StokMenipis, $tujuhHari, now()->subDays(7)->endOfDay());
        bacaKeadaanBarangPada(NotificationType::StokMenipis, $enamHari, now()->subDays(6)->startOfDay());

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect(keadaanBarang(NotificationType::StokMenipis, $tujuhHari)->sudahDibaca())->toBeFalse()
            ->and(keadaanBarang(NotificationType::StokMenipis, $enamHari)->sudahDibaca())->toBeTrue();
    });

    it('tidak pernah menagih ulang keadaan informasi maupun peristiwa', function () {
        $petugas = petugasNotifikasi();
        $berlebih = Item::factory()->create(['stock_current' => 100, 'stock_min' => 1, 'stock_max' => 10]);
        NotificationScanner::periksaBarang($berlebih);
        $peristiwa = peristiwaNotifikasi($petugas, 'kabar');

        bacaKeadaanBarangPada(NotificationType::StokBerlebih, $berlebih, now()->subDays(30));
        $peristiwa->forceFill(['read_at' => now()->subDays(3)])->save();

        $this->artisan('notifikasi:periksa')->assertSuccessful();

        expect(keadaanBarang(NotificationType::StokBerlebih, $berlebih)->sudahDibaca())->toBeTrue()
            ->and($peristiwa->fresh()->sudahDibaca())->toBeTrue();
    });
});

describe('peristiwa berulang', function () {
    it('mengembalikan peristiwa yang terulang ke lonceng sebagai kabar baru', function () {
        $admin = adminNotifikasi();
        $pertama = peristiwaNotifikasi($admin, 'ulang');
        $pertama->forceFill(['read_at' => now(), 'created_at' => now()->subDays(3)])->save();

        $kedua = peristiwaNotifikasi($admin, 'ulang');

        expect($kedua->notification_id)->toBe($pertama->notification_id)
            ->and($kedua->sudahDibaca())->toBeFalse()
            // Umur tujuh harinya dihitung ulang dari kejadian terakhir.
            ->and($kedua->created_at->gt(now()->subMinute()))->toBeTrue();
    });

    it('tidak menagih ulang keadaan yang sekadar diperiksa ulang', function () {
        // Pengaman bagi pembeda di atas: pemeriksaan terjadwal menulis ulang
        // setiap keadaan tiap pagi, dan itu bukan kejadian baru.
        $petugas = petugasNotifikasi();
        $item = Item::factory()->habis()->create();
        NotificationScanner::periksaBarang($item);
        Notifier::tandaiSemuaDibaca($petugas);

        NotificationScanner::periksaBarang($item);

        expect(Notification::query()->untuk($petugas)->sole()->sudahDibaca())->toBeTrue();
    });
});
