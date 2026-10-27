<?php

use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\StockMutation;
use App\Models\User;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Fixture berkas ini bersufiks `Nd` supaya tidak bertabrakan dengan helper
 * global berkas uji lain. Generator dokumen di-mock supaya dompdf dan
 * template DOCX tidak ikut berjalan.
 */

const ND_UMUM_TI_51 = '51/B/ND/DJPKN-V.BLP.1.4/KEU.02.02/10/2026';

function permintaanNd(
    string $status = 'approved_by_head',
    array $attributes = [],
    UnitKerja $unit = UnitKerja::SubbagianUmumTi,
): ModelsRequest {
    $request = ModelsRequest::create([
        'user_id' => User::factory()->role(UserRole::Pengguna)->create(['unit' => $unit])->user_id,
        'date' => Carbon::parse('2026-10-15'),
        'type' => 'normal',
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        ...$attributes,
    ]);

    RequestDetail::insert([
        'request_id' => $request->request_id,
        'item_id' => Item::factory()->create(['stock_current' => 50])->item_id,
        'quantity_requested' => 5,
        'quantity_approved' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $request;
}

function ubahKuantitasNd(ModelsRequest $request, array $overrides = [])
{
    $detail = $request->item()->first();

    return test()
        ->from(route('request.edit', $request))
        ->put(route('request.update', $request), [
            'items' => [['source' => 'item', 'item_id' => $detail->item_id, 'qty' => 3]],
            'nd_sequence' => '51',
            ...$overrides,
        ]);
}

function setujuiNd(ModelsRequest $request, ?string $sequence)
{
    return test()
        ->from(route('request.show', $request))
        ->post(route('request.respond', ['request' => $request, 'action' => 'approve']), array_filter([
            'nd_sequence' => $sequence,
        ], fn ($value) => $value !== null));
}

beforeEach(function () {
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->generator = $this->mock(RequestDocumentGenerator::class);
});

describe('format nomor', function () {
    it('memakai kode naskah sesuai unit kerja', function (UnitKerja $unit, string $kode) {
        expect($unit->kodeNaskah())->toBe($kode);
    })->with([
        [UnitKerja::KepalaPerwakilan, 'BLP'],
        [UnitKerja::SekretariatPerwakilan, 'BLP.1'],
        [UnitKerja::BidangPemeriksaanLampungI, 'BLP.2'],
        [UnitKerja::BidangPemeriksaanLampungII, 'BLP.3'],
        [UnitKerja::SubbagianHumasTu, 'BLP.1.1'],
        [UnitKerja::SubbagianSdm, 'BLP.1.2'],
        [UnitKerja::SubbagianKeuangan, 'BLP.1.3'],
        [UnitKerja::SubbagianUmumTi, 'BLP.1.4'],
        [UnitKerja::SubbagianHukum, 'BLP.1.5'],
    ]);

    it('tidak menggandakan sebutan Kepala untuk Kepala Perwakilan', function () {
        expect(UnitKerja::KepalaPerwakilan->jabatanKepala())->toBe('Kepala Perwakilan')
            ->and(UnitKerja::SubbagianHukum->jabatanKepala())->toBe('Kepala Subbagian Hukum');
    });

    it('mengambil bulan dan tahun dari tanggal Nota Dinas', function () {
        $request = permintaanNd(unit: UnitKerja::BidangPemeriksaanLampungII);

        expect($request->documentNumber('ND'))->toBe('……/B/ND/DJPKN-V.BLP.3/KEU.02.02/10/2026')
            ->and($request->documentNumber('BAST', '7'))->toBe('7/B/BAST/DJPKN-V.BLP.3/KEU.02.02/10/2026');
    });
});

describe('ubah kuantitas', function () {
    it('menetapkan nomor Nota Dinas dan BAST dari nomor urut', function () {
        $this->generator->shouldReceive('renumberNotaDinas')->once();
        $request = permintaanNd();

        $this->actingAs($this->gudang);
        ubahKuantitasNd($request)->assertRedirect(route('request.index'));

        $request->refresh();
        expect($request->status)->toBe('processed_by_staff')
            ->and($request->nd_number)->toBe(ND_UMUM_TI_51)
            ->and($request->bast_number)->toBe('51/B/BAST/DJPKN-V.BLP.1.4/KEU.02.02/10/2026')
            ->and($request->ndSequence())->toBe('51');
    });

    it('menolak tanpa nomor urut atau bukan angka', function (string $sequence) {
        $this->generator->shouldNotReceive('renumberNotaDinas');
        $request = permintaanNd();

        $this->actingAs($this->gudang);
        ubahKuantitasNd($request, ['nd_sequence' => $sequence])->assertSessionHasErrors('nd_sequence');

        expect($request->refresh()->status)->toBe('approved_by_head')
            ->and($request->nd_number)->toBeNull();
    })->with(['kosong' => [''], 'huruf' => ['51a']]);

    it('menolak nomor yang sudah dipakai permintaan lain', function () {
        $this->generator->shouldNotReceive('renumberNotaDinas');
        permintaanNd('completed', ['nd_number' => ND_UMUM_TI_51]);
        $request = permintaanNd();

        $this->actingAs($this->gudang);
        ubahKuantitasNd($request)->assertSessionHasErrors('nd_sequence');

        expect($request->refresh()->status)->toBe('approved_by_head')
            ->and($request->item()->first()->quantity_approved)->toBe(0);
    });

    it('membolehkan nomor urut sama untuk unit lain', function () {
        $this->generator->shouldReceive('renumberNotaDinas')->once();
        permintaanNd('completed', ['nd_number' => ND_UMUM_TI_51]);
        $request = permintaanNd(unit: UnitKerja::SubbagianHukum);

        $this->actingAs($this->gudang);
        ubahKuantitasNd($request)->assertSessionHasNoErrors();

        expect($request->refresh()->nd_number)->toBe('51/B/ND/DJPKN-V.BLP.1.5/KEU.02.02/10/2026');
    });
});

describe('tombol setujui', function () {
    it('menolak menyetujui tanpa nomor urut', function () {
        $this->generator->shouldNotReceive('renumberNotaDinas');
        $request = permintaanNd();

        $this->actingAs($this->gudang);
        setujuiNd($request, null)
            ->assertRedirect(route('request.show', $request))
            ->assertSessionHas('error', 'Nomor urut Nota Dinas wajib diisi berupa angka, paling banyak 6 digit.');

        expect($request->refresh()->status)->toBe('approved_by_head');
    });

    it('memberi nomor saat Pengelola Gudang menyetujui', function () {
        $this->generator->shouldReceive('renumberNotaDinas')->once();
        $request = permintaanNd();

        $this->actingAs($this->gudang);
        setujuiNd($request, '12')->assertRedirect(route('request.index'));

        expect($request->refresh()->status)->toBe('processed_by_staff')
            ->and($request->nd_number)->toBe('12/B/ND/DJPKN-V.BLP.1.4/KEU.02.02/10/2026');
    });

    it('memakai nomor BAST saat permintaan darurat diselesaikan', function () {
        $bast = '9/B/BAST/DJPKN-V.BLP.1.2/KEU.02.02/10/2026';
        $this->generator->shouldReceive('renumberNotaDinas')->once();
        $this->generator->shouldReceive('generateBast')->once()
            ->withArgs(fn (ModelsRequest $request) => $request->bast_number === $bast);
        $request = permintaanNd(attributes: ['type' => 'emergency'], unit: UnitKerja::SubbagianSdm);

        $this->actingAs($this->gudang);
        setujuiNd($request, '9')->assertRedirect(route('request.index'));

        $nd = '9/B/ND/DJPKN-V.BLP.1.2/KEU.02.02/10/2026';
        expect($request->refresh()->status)->toBe('completed')
            ->and($request->bast_number)->toBe($bast)
            ->and(StockMutation::where('reference_number', $nd)->exists())->toBeTrue();
    });
});

describe('dokumen Nota Dinas', function () {
    it('mempertahankan persetujuan saat dinomori ulang, dengan QR baru', function () {
        Storage::fake('local');
        $generator = new RequestDocumentGenerator;
        $kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::KepalaPerwakilan]);
        $request = permintaanNd(unit: UnitKerja::KepalaPerwakilan);

        $this->travelTo(now()->subDay());
        $awal = $generator->approveNotaDinas($request, $kepala);
        $this->travelBack();
        $hashAwal = $awal->verification_token_hash;

        $request->update(['nd_number' => '3/B/ND/DJPKN-V.BLP/KEU.02.02/10/2026']);
        $dokumen = $generator->renumberNotaDinas($request->fresh());

        expect($dokumen->document_number)->toBe('3/B/ND/DJPKN-V.BLP/KEU.02.02/10/2026')
            ->and($dokumen->approved_by_user_id)->toBe($kepala->user_id)
            ->and($dokumen->approved_at->equalTo($awal->approved_at))->toBeTrue()
            ->and($dokumen->verification_token_hash)->not->toBe($hashAwal);
        Storage::disk('local')->assertExists($dokumen->file_path);
    });

    it('mencetak nomor dan jabatan penanda tangan', function () {
        $kepala = User::factory()->role(UserRole::KepalaUnit)->create(['unit' => UnitKerja::KepalaPerwakilan]);
        $request = permintaanNd(unit: UnitKerja::KepalaPerwakilan)->load(['user', 'item.itemProposal']);

        $html = fn (?User $approver) => view('pdf.notadinas-permintaan', [
            'request' => $request,
            'approvedBy' => $approver,
            'approvedAt' => null,
            'qrCodeDataUri' => null,
        ])->render();

        expect($html(null))->toContain('No. ……/B/ND/DJPKN-V.BLP/KEU.02.02/10/2026')
            ->toContain('Kepala Perwakilan,')
            ->not->toContain('Kepala Kepala');

        $request->nd_number = '3/B/ND/DJPKN-V.BLP/KEU.02.02/10/2026';
        expect($html($kepala))->toContain('No. 3/B/ND/DJPKN-V.BLP/KEU.02.02/10/2026');
    });
});
