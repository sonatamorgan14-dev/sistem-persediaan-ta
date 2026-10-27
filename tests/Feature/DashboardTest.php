<?php

use App\Enums\AlasanPengadaan;
use App\Enums\ItemProposalStatus;
use App\Enums\MutationType;
use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Enums\ProcurementStatus;
use App\Enums\SatuanBarang;
use App\Enums\StockStatus;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\Procurement;
use App\Models\ProcurementDetail;
use App\Models\ProcurementReceipt;
use App\Models\ProcurementRequest;
use App\Models\Request as ModelsRequest;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Support\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * Fixture dasbor. Semuanya didefinisikan di berkas ini dan bersufiks `Dasbor`,
 * supaya berkas ini bisa dijalankan sendiri dan tidak bertabrakan dengan
 * helper global berkas uji lain.
 */

function penggunaDasbor(UserRole $role, array $attributes = []): User
{
    return User::factory()->role($role)->create($attributes);
}

function realisasiDasbor(ProcurementStatus $status): Procurement
{
    return Procurement::create([
        'supplier_id' => Supplier::factory()->create()->supplier_id,
        'procurement_date' => now(),
        'basis_reason' => AlasanPengadaan::KebutuhanMendesak,
        'basis_note' => 'Kebutuhan uji dasbor.',
        'status' => $status,
        'user_id' => penggunaDasbor(UserRole::PejabatPengadaan)->user_id,
    ]);
}

/**
 * Realisasi Diterima dengan satu baris barang dan tanda terimanya.
 */
function realisasiDiterimaDasbor(Carbon $diterima, int $dipesan, int $datang, int $harga, ProcurementStatus $status = ProcurementStatus::Diterima): Procurement
{
    $procurement = realisasiDasbor($status);

    ProcurementDetail::create([
        'procurement_id' => $procurement->procurement_id,
        'item_id' => Item::factory()->create()->item_id,
        'amount' => $dipesan,
        'unit_price' => $harga,
        'amount_received' => $datang,
    ]);

    ProcurementReceipt::create([
        'procurement_id' => $procurement->procurement_id,
        'bast_number' => 'BAST/'.Str::random(6),
        'received_by' => penggunaDasbor(UserRole::PengelolaGudang)->user_id,
        'received_at' => $diterima,
    ]);

    return $procurement;
}

function usulanDasbor(ProcurementRequestStatus $status): ProcurementRequest
{
    return ProcurementRequest::create([
        'user_id' => penggunaDasbor(UserRole::PengelolaGudang)->user_id,
        'source' => ProcurementRequestSource::Manual,
        'status' => $status,
    ]);
}

function permintaanDasbor(User $peminta, string $status, string $type = 'normal'): ModelsRequest
{
    return ModelsRequest::create([
        'user_id' => $peminta->user_id,
        'date' => now(),
        'type' => $type,
        'status' => $status,
        'request_number' => 'REQ/'.Str::random(8),
    ]);
}

/**
 * Baris antrean "Perlu Ditindaklanjuti" yang dilihat pengguna, label => jumlah.
 *
 * @return array<string, int>
 */
function tugasDasbor(User $user): array
{
    return collect(test()->actingAs($user)->get('/dasbor')->assertOk()->viewData('tugas'))
        ->mapWithKeys(fn (array $baris) => [$baris['label'] => $baris['jumlah']])
        ->all();
}

describe('hak akses dasbor', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/dasbor')->assertRedirect('/login');
    });

    it('terbuka untuk seluruh peran, termasuk Admin', function () {
        foreach (UserRole::cases() as $role) {
            $this->actingAs(penggunaDasbor($role))->get('/dasbor')->assertOk();
        }
    });
});

describe('tampilan bersih', function () {
    it('tidak lagi memuat data contoh maupun kalimat penjelas peran', function () {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(penggunaDasbor($role))->get('/dasbor');

            $response->assertDontSee('dummy')->assertDontSee('data contoh');

            foreach (UserRole::cases() as $peran) {
                $response->assertDontSee($peran->description());
            }
        }
    });

    it('menyapa sesuai jam setempat', function () {
        $user = penggunaDasbor(UserRole::PengelolaGudang, ['fullname' => 'Budi Santoso']);

        $this->travelTo(Carbon::parse('2026-10-05 08:00'));
        $this->actingAs($user)->get('/dasbor')->assertSee('Selamat pagi, Budi Santoso');

        $this->travelTo(Carbon::parse('2026-10-05 19:30'));
        $this->actingAs($user)->get('/dasbor')->assertSee('Selamat malam, Budi Santoso');
    });
});

describe('akses cepat', function () {
    it('hanya menampilkan modul yang gate-nya dilewati', function (UserRole $role, array $terlihat, array $tersembunyi) {
        $label = collect($this->actingAs(penggunaDasbor($role))->get('/dasbor')->viewData('aksesCepat'))
            ->pluck('label');

        foreach ($terlihat as $modul) {
            expect($label)->toContain($modul);
        }

        foreach ($tersembunyi as $modul) {
            expect($label)->not->toContain($modul);
        }
    })->with([
        'Pengelola Gudang' => [UserRole::PengelolaGudang, ['Master Barang', 'Stok Opname', 'Realisasi Pengadaan'], ['Penyedia Barang', 'Manajemen Pengguna']],
        'Pejabat Pengadaan' => [UserRole::PejabatPengadaan, ['Usulan Pengadaan', 'Penyedia Barang'], ['Master Barang', 'Stok Opname', 'Log Aktivitas']],
        // Pengawas baca-saja (ADR 0041): seluruh modul subbagiannya, tanpa administrasi sistem.
        'Kasubbag' => [UserRole::KasubbagUmumTi, ['Realisasi Pengadaan', 'Master Barang', 'Stok Opname', 'Penyedia Barang'], ['Manajemen Pengguna', 'Log Aktivitas']],
        'Pengguna' => [UserRole::Pengguna, ['Permintaan Barang'], ['Master Barang', 'Realisasi Pengadaan']],
    ]);

    it('menampilkan seluruh modul bagi Admin', function () {
        $this->actingAs(penggunaDasbor(UserRole::Admin))->get('/dasbor')
            ->assertViewHas('aksesCepat', fn (array $ubin) => count($ubin) === 10);
    });

    it('tidak menautkan halaman yang akan ditolak', function () {
        $this->actingAs(penggunaDasbor(UserRole::PejabatPengadaan))->get('/dasbor')
            ->assertSee(route('suppliers.index'), false)
            ->assertDontSee(route('items.index'), false);
    });
});

describe('perlu ditindaklanjuti', function () {
    it('menampilkan antrean persetujuan bagi Kasubbag', function () {
        realisasiDasbor(ProcurementStatus::MenungguPersetujuan);
        realisasiDasbor(ProcurementStatus::MenungguPersetujuan);
        realisasiDasbor(ProcurementStatus::MenungguPenerimaan);

        expect(tugasDasbor(penggunaDasbor(UserRole::KasubbagUmumTi)))
            ->toBe(['Realisasi menunggu persetujuan' => 2]);

        $this->actingAs(penggunaDasbor(UserRole::KasubbagUmumTi))->get('/dasbor')
            ->assertSee(route('procurements.index', ['status' => 'menunggu_persetujuan']), false);
    });

    it('menampilkan antrean pengadaan bagi Pejabat Pengadaan', function () {
        usulanDasbor(ProcurementRequestStatus::Diajukan);
        usulanDasbor(ProcurementRequestStatus::DisetujuiMenungguRealisasi);
        usulanDasbor(ProcurementRequestStatus::DisetujuiSelesai);
        realisasiDasbor(ProcurementStatus::Ditolak);
        realisasiDasbor(ProcurementStatus::ProsesBelanja);
        realisasiDasbor(ProcurementStatus::ProsesBelanja);

        expect(tugasDasbor(penggunaDasbor(UserRole::PejabatPengadaan)))->toBe([
            'Usulan menunggu keputusan' => 1,
            'Usulan siap direalisasi' => 1,
            'Realisasi ditolak' => 1,
            'Belum dibelanjakan' => 2,
        ]);
    });

    it('menampilkan antrean gudang bagi Pengelola Gudang', function () {
        $petugas = penggunaDasbor(UserRole::PengelolaGudang);

        realisasiDasbor(ProcurementStatus::MenungguPenerimaan);
        realisasiDasbor(ProcurementStatus::MenungguPersetujuan);

        ItemProposal::create([
            'item_name' => 'Stapler besar',
            'unit' => SatuanBarang::Buah,
            'purpose_note' => 'Kebutuhan uji dasbor.',
            'proposed_by' => $petugas->user_id,
            'status' => ItemProposalStatus::Diajukan,
        ]);

        $draf = ProcurementRequest::create([
            'user_id' => null,
            'source' => ProcurementRequestSource::PemantauanStok,
            'status' => ProcurementRequestStatus::Draf,
        ]);
        foreach (Item::factory()->habis()->count(3)->create() as $item) {
            $draf->details()->create(['item_id' => $item->item_id, 'amount_request' => 5, 'is_auto' => true]);
        }

        StockOpname::create([
            'opname_date' => now(),
            'user_id' => $petugas->user_id,
            'status' => OpnameStatus::Draf,
            'period_type' => PeriodeOpname::TriwulanIII,
            'period_year' => now()->year,
        ]);

        permintaanDasbor(penggunaDasbor(UserRole::Pengguna), 'approved_by_head');
        permintaanDasbor(penggunaDasbor(UserRole::Pengguna), 'submitted');

        // Draf dihitung per barang: draf selalu tunggal (ADR 0027).
        expect(tugasDasbor($petugas))->toBe([
            'Barang menunggu penerimaan' => 1,
            'Usulan barang baru' => 1,
            'Draf usulan pengadaan' => 3,
            'Opname belum selesai' => 1,
            'Permintaan perlu diproses' => 1,
        ]);
    });

    it('menampilkan keadaan kosong bila tidak ada antrean', function () {
        $this->actingAs(penggunaDasbor(UserRole::PengelolaGudang))->get('/dasbor')
            ->assertViewHas('tugas', [])
            ->assertSee('Tidak ada yang perlu ditindaklanjuti.');
    });

    it('menghitung permintaan sesuai cakupan peran, tanpa yang selesai atau ditolak', function () {
        $kepalaUnit = penggunaDasbor(UserRole::KepalaUnit, ['unit' => UnitKerja::BidangPemeriksaanLampungI]);
        $rekanSeunit = penggunaDasbor(UserRole::Pengguna, ['unit' => UnitKerja::BidangPemeriksaanLampungI]);
        $unitLain = penggunaDasbor(UserRole::Pengguna, ['unit' => UnitKerja::SekretariatPerwakilan]);

        permintaanDasbor($rekanSeunit, 'submitted');
        permintaanDasbor($rekanSeunit, 'approved_by_head');
        permintaanDasbor($rekanSeunit, 'completed');
        permintaanDasbor($rekanSeunit, 'rejected');
        permintaanDasbor($unitLain, 'submitted');
        permintaanDasbor($unitLain, 'processed_by_staff');
        // Permintaan umum punya daftarnya sendiri, jadi tidak ikut dihitung.
        permintaanDasbor($unitLain, 'submitted', 'umum');

        expect(tugasDasbor($kepalaUnit))->toBe(['Permintaan menunggu persetujuan' => 1])
            ->and(tugasDasbor($rekanSeunit))->toBe(['Permintaan saya yang berjalan' => 2])
            ->and(tugasDasbor(penggunaDasbor(UserRole::Admin)))->toBe([
                'Permintaan menunggu persetujuan' => 2,
                'Permintaan perlu diproses' => 1,
                'Permintaan menunggu penyelesaian' => 1,
            ]);
    });
});

describe('kondisi stok', function () {
    it('menghitung sebaran status barang aktif saja', function () {
        Item::factory()->count(2)->create();
        Item::factory()->menipis()->create();
        Item::factory()->habis()->create();
        Item::factory()->create(['stock_min' => 5, 'stock_max' => 10, 'stock_current' => 50]);
        Item::factory()->habis()->arsip()->create();

        $stok = $this->actingAs(penggunaDasbor(UserRole::PengelolaGudang))->get('/dasbor')->viewData('stok');

        expect($stok['total'])->toBe(5)
            ->and(collect($stok['sebaran'])->mapWithKeys(fn ($s) => [$s['status']->value => $s['jumlah']])->all())
            ->toBe([
                StockStatus::Habis->value => 1,
                StockStatus::Menipis->value => 1,
                StockStatus::Aman->value => 2,
                StockStatus::Berlebih->value => 1,
            ]);
    });

    it('mengurutkan barang yang perlu diisi ulang dari yang paling mendesak', function () {
        $setengah = Item::factory()->create(['stock_min' => 10, 'stock_current' => 5]);
        $habis = Item::factory()->create(['stock_min' => 10, 'stock_current' => 0]);
        $sepersepuluh = Item::factory()->create(['stock_min' => 100, 'stock_current' => 10]);
        Item::factory()->create(['stock_min' => 10, 'stock_current' => 50]);
        Item::factory()->habis()->arsip()->create();

        $kritis = $this->actingAs(penggunaDasbor(UserRole::PengelolaGudang))->get('/dasbor')->viewData('stok')['kritis'];

        expect($kritis->pluck('item_id')->all())
            ->toBe([$habis->item_id, $sepersepuluh->item_id, $setengah->item_id]);
    });

    it('menautkan barang hanya bagi peran yang boleh membuka halamannya', function () {
        $item = Item::factory()->habis()->create(['item_name' => 'Kertas HVS A4']);

        $this->actingAs(penggunaDasbor(UserRole::PengelolaGudang))->get('/dasbor')
            ->assertSee(route('items.show', $item), false);

        $this->actingAs(penggunaDasbor(UserRole::PejabatPengadaan))->get('/dasbor')
            ->assertSee('Kertas HVS A4')
            ->assertDontSee(route('items.show', $item), false);
    });

    it('tidak ditampilkan bagi peran sub sistem permintaan', function () {
        $this->actingAs(penggunaDasbor(UserRole::Pengguna))->get('/dasbor')
            ->assertViewHas('stok', null)
            ->assertViewHas('alur', null);
    });
});

describe('alur permintaan', function () {
    beforeEach(function () {
        $this->pemohon = penggunaDasbor(UserRole::Pengguna, ['unit' => UnitKerja::BidangPemeriksaanLampungI]);
        $rekanSeunit = penggunaDasbor(UserRole::Pengguna, ['unit' => UnitKerja::BidangPemeriksaanLampungI]);
        $unitLain = penggunaDasbor(UserRole::Pengguna, ['unit' => UnitKerja::SekretariatPerwakilan]);

        permintaanDasbor($this->pemohon, 'submitted');
        permintaanDasbor($this->pemohon, 'completed');
        permintaanDasbor($this->pemohon, 'rejected');
        permintaanDasbor($rekanSeunit, 'approved_by_head');
        permintaanDasbor($unitLain, 'processed_by_staff');
        permintaanDasbor($unitLain, 'in_issuance');
        permintaanDasbor($unitLain, 'rejected');
        // Permintaan umum punya daftarnya sendiri, jadi tidak ikut dihitung.
        permintaanDasbor($unitLain, 'submitted', 'umum');
    });

    it('menghitung tiap tahap sesuai cakupan peran, dengan penolakan di tahap selesai', function () {
        $alur = fn (User $user) => $this->actingAs($user)->get('/dasbor')->assertOk()->viewData('alurPermintaan');
        $kepalaUnit = penggunaDasbor(UserRole::KepalaUnit, ['unit' => UnitKerja::BidangPemeriksaanLampungI]);
        $admin = penggunaDasbor(UserRole::Admin);

        expect(collect($alur($this->pemohon))->pluck('jumlah')->all())->toBe([1, 0, 0, 0, 1])
            ->and($alur($this->pemohon)[4]['ditolak']['jumlah'])->toBe(1)
            ->and(collect($alur($kepalaUnit))->pluck('jumlah')->all())->toBe([1, 1, 0, 0, 1])
            ->and(collect($alur($admin))->pluck('jumlah')->all())->toBe([1, 1, 1, 1, 1])
            ->and($alur($admin)[4]['ditolak'])->toBe(['jumlah' => 2, 'url' => route('request.index', ['status' => 'rejected'])]);
    });

    it('menautkan tiap tahap ke daftar permintaan dengan angka yang sama', function () {
        $alur = $this->actingAs($this->pemohon)->get('/dasbor')->viewData('alurPermintaan');
        $tautan = [...$alur, $alur[4]['ditolak']];

        foreach ($tautan as $tahap) {
            expect($this->get($tahap['url'])->assertOk()->viewData('requests')->total())->toBe($tahap['jumlah']);
        }
    });

    it('ditampilkan bagi Pengguna', function () {
        $this->actingAs($this->pemohon)->get('/dasbor')
            ->assertSee('Alur Permintaan')
            ->assertDontSee('Alur Pengadaan');
    });
});

describe('alur pengadaan', function () {
    it('menghitung tiap tahap tanpa draf, dengan penolakan terpisah', function () {
        ProcurementRequest::create([
            'user_id' => null,
            'source' => ProcurementRequestSource::PemantauanStok,
            'status' => ProcurementRequestStatus::Draf,
        ]);
        usulanDasbor(ProcurementRequestStatus::Diajukan);
        usulanDasbor(ProcurementRequestStatus::DisetujuiMenungguRealisasi);
        realisasiDasbor(ProcurementStatus::MenungguPersetujuan);
        realisasiDasbor(ProcurementStatus::Ditolak);
        realisasiDasbor(ProcurementStatus::Ditolak);
        realisasiDasbor(ProcurementStatus::MenungguPenerimaan);
        realisasiDasbor(ProcurementStatus::Diterima);
        realisasiDasbor(ProcurementStatus::Dibatalkan);

        $alur = $this->actingAs(penggunaDasbor(UserRole::KasubbagUmumTi))->get('/dasbor')->viewData('alur');

        expect(collect($alur)->pluck('jumlah')->all())->toBe([1, 1, 1, 0, 1, 1])
            ->and($alur[2]['ditolak']['jumlah'])->toBe(2)
            ->and($alur[2]['ditolak']['url'])->toBe(route('procurements.index', ['status' => 'ditolak']));
    });
});

describe('grafik', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-10-15 10:00'));
    });

    it('menghitung transaksi masuk dan keluar per bulan tanpa saldo awal', function () {
        $petugas = penggunaDasbor(UserRole::PengelolaGudang);
        $item = Item::factory()->create(['stock_current' => 0, 'stock_min' => 5, 'stock_max' => 0]);
        $catat = fn (MutationType $type, int $amount, string $tanggal) => StockLedger::record(
            item: $item->fresh(),
            type: $type,
            amount: $amount,
            recordedBy: $petugas,
            notes: 'Uji dasbor',
            date: Carbon::parse($tanggal),
        );

        $catat(MutationType::SaldoAwal, 100, '2026-10-01');
        $catat(MutationType::Masuk, 10, '2026-10-02');
        $catat(MutationType::Masuk, 10, '2026-10-03');
        $catat(MutationType::Keluar, 5, '2026-10-04');
        $catat(MutationType::Penyesuaian, -2, '2026-10-05');
        $catat(MutationType::Masuk, 10, '2026-08-20');
        // Di luar jendela enam bulan.
        $catat(MutationType::Masuk, 10, '2026-04-30');

        $grafik = $this->actingAs($petugas)->get('/dasbor')->assertOk()->viewData('grafikMutasi');

        expect($grafik['bulan'])->toBe(['Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt'])
            ->and($grafik['masuk'])->toBe([0, 0, 0, 1, 0, 2])
            ->and($grafik['keluar'])->toBe([0, 0, 0, 0, 0, 2])
            ->and($grafik['total'])->toBe(5);
    });

    it('menjumlahkan nilai yang benar-benar diterima, bukan yang dipesan', function () {
        realisasiDiterimaDasbor(Carbon::parse('2026-10-03'), dipesan: 10, datang: 8, harga: 1_000);
        realisasiDiterimaDasbor(Carbon::parse('2026-09-10'), dipesan: 2, datang: 2, harga: 50_000);
        // Penerimaan di luar jendela, dan realisasi yang tidak pernah jadi transaksi.
        realisasiDiterimaDasbor(Carbon::parse('2026-03-31'), dipesan: 1, datang: 1, harga: 999_000);
        realisasiDiterimaDasbor(Carbon::parse('2026-10-03'), dipesan: 1, datang: 1, harga: 777_000, status: ProcurementStatus::Dibatalkan);

        $grafik = $this->actingAs(penggunaDasbor(UserRole::KasubbagUmumTi))->get('/dasbor')->viewData('grafikNilai');

        expect($grafik['nilai'])->toBe([0, 0, 0, 0, 100_000, 8_000])
            ->and($grafik['total'])->toBe(108_000);
    });

    it('menampilkan grafik sesuai peran', function (UserRole $role, bool $mutasi, bool $nilai) {
        $response = $this->actingAs(penggunaDasbor($role))->get('/dasbor');

        expect($response->viewData('grafikMutasi') !== null)->toBe($mutasi)
            ->and($response->viewData('grafikNilai') !== null)->toBe($nilai);
    })->with([
        'Pengelola Gudang' => [UserRole::PengelolaGudang, true, false],
        'Pejabat Pengadaan' => [UserRole::PejabatPengadaan, false, true],
        'Kasubbag' => [UserRole::KasubbagUmumTi, true, true],
        'Admin' => [UserRole::Admin, true, true],
        'Kepala Unit' => [UserRole::KepalaUnit, false, false],
    ]);
});
