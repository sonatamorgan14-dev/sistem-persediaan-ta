<?php

use App\Enums\AuditEvent;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\User;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Str;

/*
 * Fixture berkas ini bersufiks `Kembali` supaya tidak bertabrakan dengan
 * helper global berkas uji lain.
 */

function permintaanKembali(string $status, array $attributes = []): ModelsRequest
{
    $request = ModelsRequest::create([
        'user_id' => User::factory()->role(UserRole::Pengguna)->create()->user_id,
        'date' => now(),
        'type' => 'normal',
        'status' => $status,
        'request_number' => 'REQ-'.Str::upper(Str::random(8)),
        ...$attributes,
    ]);

    RequestDetail::insert([
        'request_id' => $request->request_id,
        'item_id' => Item::factory()->create()->item_id,
        'quantity_requested' => 5,
        'quantity_approved' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $request;
}

function kembalikanStatus(ModelsRequest $request, string $reason = 'Salah klik oleh staf.')
{
    return test()
        ->from(route('request.show', $request))
        ->post(route('request.revert-status', $request), ['reason' => $reason]);
}

beforeEach(function () {
    $this->admin = User::factory()->role(UserRole::Admin)->create();
});

test('admin mengembalikan permintaan satu langkah ke status sebelumnya', function (string $dari, string $ke) {
    $request = permintaanKembali($dari);

    $this->actingAs($this->admin);
    kembalikanStatus($request)->assertRedirect(route('request.show', $request));

    expect($request->refresh()->status)->toBe($ke);
})->with([
    'diproses staf ke disetujui kepala unit' => ['processed_by_staff', 'approved_by_head'],
    'dalam pengeluaran ke diproses staf' => ['in_issuance', 'processed_by_staff'],
]);

test('mengembalikan dari disetujui kepala unit mencabut persetujuan nota dinas', function () {
    $request = permintaanKembali('approved_by_head');
    $this->mock(RequestDocumentGenerator::class)
        ->shouldReceive('generateNotaDinas')
        ->once()
        ->withArgs(fn (ModelsRequest $argument) => $argument->is($request));

    $this->actingAs($this->admin);
    kembalikanStatus($request)->assertRedirect(route('request.show', $request));

    expect($request->refresh()->status)->toBe('submitted');
});

test('mengembalikan dari diproses staf mengosongkan jumlah disetujui dan membuang barang tambahan', function () {
    $request = permintaanKembali('processed_by_staff');
    $request->item()->update(['quantity_approved' => 4]);
    RequestDetail::insert([
        'request_id' => $request->request_id,
        'item_id' => Item::factory()->create()->item_id,
        'quantity_requested' => 0,
        'quantity_approved' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($this->admin);
    kembalikanStatus($request);

    $details = $request->item()->get();

    expect($details)->toHaveCount(1)
        ->and($details->first()->quantity_requested)->toEqual(5)
        ->and($details->first()->quantity_approved)->toEqual(0);
});

test('penolakan mencatat status asal dan admin bisa memulihkannya', function () {
    $request = permintaanKembali('processed_by_staff');

    $this->actingAs($this->admin)
        ->post(route('request.respond', ['request' => $request, 'action' => 'reject']));

    expect($request->refresh())
        ->status->toBe('rejected')
        ->rejected_from_status->toBe('processed_by_staff');

    kembalikanStatus($request);

    expect($request->refresh())
        ->status->toBe('processed_by_staff')
        ->rejected_from_status->toBeNull();
});

test('status yang tidak punya langkah sebelumnya tidak bisa dikembalikan', function (string $status, array $attributes = []) {
    $request = permintaanKembali($status, $attributes);

    $this->actingAs($this->admin);
    kembalikanStatus($request)
        ->assertRedirect(route('request.show', $request))
        ->assertSessionHas('error');

    expect($request->refresh()->status)->toBe($status);
})->with([
    'selesai' => ['completed'],
    'terkirim' => ['submitted'],
    'ditolak sebelum status asal dicatat' => ['rejected'],
    'permintaan umum' => ['rejected', ['type' => 'umum', 'rejected_from_status' => 'submitted']],
]);

test('hanya admin yang bisa mengembalikan status', function (UserRole $role) {
    $request = permintaanKembali('in_issuance');

    $this->actingAs(User::factory()->role($role)->create());
    kembalikanStatus($request)->assertForbidden();

    expect($request->refresh()->status)->toBe('in_issuance');
})->with([UserRole::PengelolaGudang, UserRole::KasubbagUmumTi, UserRole::KepalaUnit]);

test('alasan wajib diisi dan dicatat di log aktivitas', function () {
    $request = permintaanKembali('in_issuance');

    $this->actingAs($this->admin);
    kembalikanStatus($request, '')->assertSessionHasErrors('reason');
    expect($request->refresh()->status)->toBe('in_issuance');

    kembalikanStatus($request, 'Staf salah menekan tombol.');

    expect(AuditLog::where('event', AuditEvent::UbahStatus)->sole()->activity)
        ->toContain($request->request_number)
        ->toContain('Staf salah menekan tombol.');
});

test('tombol kembalikan status di detail dan tabel permintaan hanya tampil untuk admin', function (string $halaman) {
    $request = permintaanKembali('in_issuance');
    $url = $halaman === 'detail' ? route('request.show', $request) : route('request.index');

    $this->actingAs($this->admin)
        ->get($url)
        ->assertSee('Kembalikan Status');

    $this->actingAs(User::factory()->role(UserRole::PengelolaGudang)->create())
        ->get($url)
        ->assertDontSee('Kembalikan Status');
})->with(['detail', 'tabel']);

test('tombol kembalikan status tidak tampil di tabel untuk permintaan selesai', function () {
    permintaanKembali('completed');

    $this->actingAs($this->admin)
        ->get(route('request.index'))
        ->assertDontSee('Kembalikan Status');
});

test('mengembalikan status dari tabel kembali ke tabel dengan penyaringnya', function () {
    $request = permintaanKembali('in_issuance');
    $tabel = route('request.index', ['status' => 'in_issuance']);

    $this->actingAs($this->admin)
        ->from($tabel)
        ->post(route('request.revert-status', $request), ['reason' => 'Salah klik.'])
        ->assertRedirect($tabel)
        ->assertSessionHas('status');

    expect($request->refresh()->status)->toBe('processed_by_staff');
});

test('modal terbuka lagi dengan konteks yang sama saat alasan tidak valid', function () {
    $request = permintaanKembali('in_issuance');
    $konteks = json_encode(['id' => $request->request_id, 'number' => $request->request_number, 'from' => 'in_issuance', 'to' => 'processed_by_staff']);

    $this->actingAs($this->admin)
        ->from(route('request.index'))
        ->post(route('request.revert-status', $request), ['reason' => '   ', 'revert_context' => $konteks])
        ->assertSessionHasErrors('reason');

    $this->get(route('request.index'))
        ->assertSee($request->request_number)
        ->assertSee('open: true', false);
});
