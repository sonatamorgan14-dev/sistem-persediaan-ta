<?php

use App\Enums\UserRole;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\User;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Str;

/*
 * Fixture berkas ini bersufiks `Umum` supaya tidak bertabrakan dengan helper
 * global berkas uji lain.
 */

function permintaanUmum(User $peminta, Item $item, int $qty): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => $peminta->user_id,
        'date' => now(),
        'type' => 'umum',
        'status' => 'submitted',
        'request_number' => 'REQ-UMUM-'.Str::upper(Str::random(8)),
    ]);

    RequestDetail::insert([
        'request_id' => $request->request_id,
        'item_id' => $item->item_id,
        'quantity_requested' => $qty,
        'quantity_approved' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $request;
}

function gabungkanUmum(User $gudang, array $requests): ModelsRequest
{
    test()->mock(RequestDocumentGenerator::class)->shouldReceive('generateNotaDinas')->once();

    test()->actingAs($gudang)
        ->post(route('request.umum.merge'), [
            'request_ids' => collect($requests)->pluck('request_id')->all(),
        ])
        ->assertRedirect(route('request.index'));

    return ModelsRequest::where('type', 'emergency')->sole();
}

beforeEach(function () {
    User::factory()->role(UserRole::KasubbagUmumTi)->create();
    $this->gudang = User::factory()->role(UserRole::PengelolaGudang)->create();
    $this->item = Item::factory()->create();
});

test('permintaan umum yang digabung disimpan sebagai histori, bukan dihapus', function () {
    $peminta = User::factory()->role(UserRole::Pengguna)->create();
    $pertama = permintaanUmum($peminta, $this->item, 2);
    $kedua = permintaanUmum($peminta, $this->item, 3);

    $gabungan = gabungkanUmum($this->gudang, [$pertama, $kedua]);

    expect($gabungan->item()->sole()->quantity_requested)->toBe(5);

    foreach ([$pertama, $kedua] as $asal) {
        $asal->refresh();
        expect($asal->status)->toBe('merged')
            ->and($asal->merged_into_request_id)->toBe($gabungan->request_id)
            ->and($asal->item()->count())->toBe(1);
    }
});

test('antrean hanya menampilkan permintaan yang belum digabung', function () {
    $peminta = User::factory()->role(UserRole::Pengguna)->create();
    $digabung = permintaanUmum($peminta, $this->item, 2);
    $menunggu = permintaanUmum($peminta, $this->item, 1);

    $gabungan = gabungkanUmum($this->gudang, [$digabung]);

    $this->get(route('request.umum.index'))
        ->assertOk()
        ->assertSee($menunggu->request_number)
        ->assertDontSee($digabung->request_number);

    $this->get(route('request.umum.index', ['view' => 'histori']))
        ->assertOk()
        ->assertSee('Histori Permintaan Umum')
        ->assertSee($digabung->request_number)
        ->assertSee($gabungan->request_number)
        ->assertDontSee($menunggu->request_number);
});

test('permintaan yang sudah digabung tidak bisa diubah, ditolak, atau digabung ulang', function () {
    $peminta = User::factory()->role(UserRole::Pengguna)->create();
    $digabung = permintaanUmum($peminta, $this->item, 2);

    gabungkanUmum($this->gudang, [$digabung]);

    $this->get(route('request.umum.edit', $digabung))->assertNotFound();
    $this->post(route('request.umum.reject', $digabung))->assertStatus(409);
    $this->post(route('request.umum.merge'), ['request_ids' => [$digabung->request_id]])
        ->assertSessionHasErrors('request_ids.0');

    expect($digabung->refresh()->status)->toBe('merged');
});
