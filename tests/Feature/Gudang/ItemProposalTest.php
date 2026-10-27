<?php

use App\Enums\ItemProposalStatus;
use App\Enums\NotificationType;
use App\Enums\SatuanBarang;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\Notification;
use App\Models\StockMutation;
use App\Models\User;

/**
 * Usulan barang baru — pintu depan katalog (ADR 0032).
 *
 * Helper di berkas Pest bersifat global lintas berkas, jadi namanya dibedakan
 * dari helper modul lain.
 */

/**
 * @return array<string, mixed>
 */
function payloadUsulanBarang(array $overrides = []): array
{
    return array_merge([
        'item_name' => 'Spidol Whiteboard Warna Emas',
        'unit' => SatuanBarang::Buah->value,
        'purpose_note' => 'Untuk menandai berkas pemeriksaan pada rapat pleno; spidol yang ada hanya hitam dan merah.',
    ], $overrides);
}

function buatUsulanBarang(?User $pengusul = null, array $overrides = []): ItemProposal
{
    $response = test()->actingAs($pengusul ?? pejabatPengadaan())
        ->post('/usulan-barang', payloadUsulanBarang($overrides));

    $response->assertRedirect();

    $id = (string) str($response->headers->get('Location'))->match('#/usulan-barang/([^/]+)#');

    return ItemProposal::findOrFail($id);
}

/**
 * Isian formulir barang yang lengkap, untuk menindaklanjuti sebuah usulan.
 *
 * @return array<string, mixed>
 */
function payloadBarangDariUsulan(ItemProposal $proposal, array $overrides = []): array
{
    return array_merge([
        'item_proposal_id' => $proposal->item_proposal_id,
        'item_code' => 'ATK-900',
        'item_name' => $proposal->item_name,
        'unit' => $proposal->unit->value,
        'stock_min' => 5,
        'stock_max' => 50,
        'stock_awal' => 0,
        'is_active' => 1,
    ], $overrides);
}

describe('hak akses usulan barang', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/usulan-barang')->assertRedirect('/login');
        $this->get('/usulan-barang/create')->assertRedirect('/login');
    });

    it('mengizinkan Pejabat Pengadaan membuka formulir usulan', function () {
        $this->actingAs(pejabatPengadaan())
            ->get('/usulan-barang/create')
            ->assertOk()
            ->assertSee('Usulkan Barang Baru');
    });

    it('menolak Pengelola Gudang membuka formulir usulan', function () {
        $this->actingAs(pengelolaGudang())
            ->get('/usulan-barang/create')
            ->assertForbidden();
    });

    // Menangkap jebakan middleware: `role:` akan menolak Admin, sedangkan gate
    // meloloskannya lewat Gate::before.
    it('mengizinkan Admin membuka formulir usulan', function () {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/usulan-barang/create')
            ->assertOk();
    });

    it('mengizinkan kedua peran pengadaan membaca daftar usulan barang', function () {
        $this->actingAs(pengelolaGudang())->get('/usulan-barang')->assertOk();
        $this->actingAs(pejabatPengadaan())->get('/usulan-barang')->assertOk();
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->get('/usulan-barang')->assertOk();
    });

    it('menolak peran sub sistem permintaan membaca daftar usulan barang', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->get('/usulan-barang')
            ->assertForbidden();
    });

    it('menolak Pejabat Pengadaan memutuskan usulannya sendiri', function () {
        $proposal = buatUsulanBarang();

        $this->actingAs(pejabatPengadaan())
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", ['decision_note' => 'Tidak perlu.'])
            ->assertForbidden();

        expect($proposal->fresh()->isDiajukan())->toBeTrue();
    });
});

describe('mengusulkan barang baru', function () {
    it('menyimpan usulan, mencatat audit, dan menagih Pengelola Gudang', function () {
        $gudang = pengelolaGudang();
        $pengusul = pejabatPengadaan();

        $proposal = buatUsulanBarang($pengusul);

        expect($proposal->item_name)->toBe('Spidol Whiteboard Warna Emas')
            ->and($proposal->unit)->toBe(SatuanBarang::Buah)
            ->and($proposal->status)->toBe(ItemProposalStatus::Diajukan)
            ->and($proposal->proposed_by)->toBe($pengusul->user_id)
            ->and($proposal->item_id)->toBeNull()
            ->and($proposal->decided_at)->toBeNull();

        expect(AuditLog::where('activity', 'like', '%Mengusulkan barang baru%')->exists())->toBeTrue();

        $notifikasi = Notification::where('user_id', $gudang->user_id)
            ->where('type', NotificationType::UsulanBarangMenungguKeputusan)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('Spidol Whiteboard Warna Emas');
    });

    it('mewajibkan nama, satuan, dan kegunaan', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/usulan-barang', ['item_name' => '', 'unit' => '', 'purpose_note' => ''])
            ->assertSessionHasErrors(['item_name', 'unit', 'purpose_note']);

        expect(ItemProposal::count())->toBe(0);
    });

    it('menolak satuan di luar daftar', function () {
        $this->actingAs(pejabatPengadaan())
            ->post('/usulan-barang', payloadUsulanBarang(['unit' => 'ember']))
            ->assertSessionHasErrors('unit');

        expect(ItemProposal::count())->toBe(0);
    });
});

describe('menyetujui usulan dengan mendaftarkan barangnya', function () {
    it('mengisi formulir barang dari usulan', function () {
        $proposal = buatUsulanBarang();

        $this->actingAs(pengelolaGudang())
            ->get("/barang/create?item_proposal_id={$proposal->item_proposal_id}")
            ->assertOk()
            ->assertSee('Spidol Whiteboard Warna Emas')
            ->assertSee('Mendaftarkan barang dari usulan', false);
    });

    /**
     * Inti ADR 0032: menyetujui usulan *adalah* mendaftarkan barangnya. Tidak
     * ada jalur pembuatan barang kedua, dan tidak ada persetujuan yang berdiri
     * tanpa baris `items`.
     */
    it('mendaftarkan barang berstok nol dan menutup usulannya sekaligus', function () {
        $gudang = pengelolaGudang();
        $pengusul = pejabatPengadaan();
        $proposal = buatUsulanBarang($pengusul);

        $this->actingAs($gudang)
            ->post('/barang', payloadBarangDariUsulan($proposal))
            ->assertRedirect("/usulan-barang/{$proposal->item_proposal_id}");

        $item = Item::where('item_code', 'ATK-900')->first();
        $proposal->refresh();

        expect($item)->not->toBeNull()
            ->and($item->stock_current)->toBe(0)
            ->and($item->item_name)->toBe('Spidol Whiteboard Warna Emas')
            ->and($proposal->status)->toBe(ItemProposalStatus::Disetujui)
            ->and($proposal->item_id)->toBe($item->item_id)
            ->and($proposal->decided_by)->toBe($gudang->user_id)
            ->and($proposal->decided_at)->not->toBeNull();

        // Stok awal nol tidak menghasilkan mutasi apa pun.
        expect(StockMutation::where('item_id', $item->item_id)->count())->toBe(0);

        expect(AuditLog::where('activity', 'like', '%Menyetujui usulan barang%')->exists())->toBeTrue();
    });

    it('memberi tahu pengusul bahwa barangnya sudah terdaftar', function () {
        $pengusul = pejabatPengadaan();
        $proposal = buatUsulanBarang($pengusul);

        $this->actingAs(pengelolaGudang())->post('/barang', payloadBarangDariUsulan($proposal));

        $notifikasi = Notification::where('user_id', $pengusul->user_id)
            ->where('type', NotificationType::UsulanBarangDisetujui)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('ATK-900');
    });

    it('menutup tagihan keputusan begitu usulannya diputuskan', function () {
        $gudang = pengelolaGudang();
        $proposal = buatUsulanBarang();

        $sebelum = Notification::where('user_id', $gudang->user_id)
            ->where('type', NotificationType::UsulanBarangMenungguKeputusan)
            ->first();

        expect($sebelum)->not->toBeNull();

        $this->actingAs($gudang)->post('/barang', payloadBarangDariUsulan($proposal));

        // Keadaan yang selesai dihapus, bukan ditandai (ADR 0036).
        expect($sebelum->fresh())->toBeNull();
    });

    it('menolak formulir barang yang menunjuk usulan yang sudah diputuskan', function () {
        $gudang = pengelolaGudang();
        $proposal = buatUsulanBarang();

        $this->actingAs($gudang)->post('/barang', payloadBarangDariUsulan($proposal));

        // Kiriman kedua dari tab lama yang masih terbuka.
        $this->actingAs($gudang)
            ->post('/barang', payloadBarangDariUsulan($proposal, ['item_code' => 'ATK-901']))
            ->assertSessionHasErrors('item_proposal_id');

        expect(Item::where('item_code', 'ATK-901')->exists())->toBeFalse();
    });

    it('mengalihkan formulir barang bila usulannya sudah diputuskan', function () {
        $gudang = pengelolaGudang();
        $proposal = buatUsulanBarang();

        $this->actingAs($gudang)->post('/barang', payloadBarangDariUsulan($proposal));

        $this->actingAs($gudang)
            ->get("/barang/create?item_proposal_id={$proposal->item_proposal_id}")
            ->assertRedirect("/usulan-barang/{$proposal->item_proposal_id}");
    });
});

describe('menolak usulan barang', function () {
    it('menyimpan penolakan beserta keterangannya dan memberi tahu pengusul', function () {
        $gudang = pengelolaGudang();
        $pengusul = pejabatPengadaan();
        $proposal = buatUsulanBarang($pengusul);

        $this->actingAs($gudang)
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
                'decision_note' => 'Barangnya sudah terdaftar sebagai ATK-014 Spidol Permanen Emas.',
            ])
            ->assertRedirect("/usulan-barang/{$proposal->item_proposal_id}");

        $proposal->refresh();

        expect($proposal->status)->toBe(ItemProposalStatus::Ditolak)
            ->and($proposal->item_id)->toBeNull()
            ->and($proposal->decided_by)->toBe($gudang->user_id)
            ->and($proposal->decision_note)->toContain('ATK-014');

        $notifikasi = Notification::where('user_id', $pengusul->user_id)
            ->where('type', NotificationType::UsulanBarangDitolak)
            ->first();

        expect($notifikasi)->not->toBeNull()
            ->and($notifikasi->body)->toContain('ATK-014');
    });

    it('mewajibkan keterangan penolakan', function () {
        $proposal = buatUsulanBarang();

        $this->actingAs(pengelolaGudang())
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", ['decision_note' => ''])
            ->assertSessionHasErrors('decision_note');

        expect($proposal->fresh()->isDiajukan())->toBeTrue();
    });

    it('menolak keputusan ganda dengan pesan, bukan galat 500', function () {
        $gudang = pengelolaGudang();
        $proposal = buatUsulanBarang();

        $this->actingAs($gudang)->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
            'decision_note' => 'Sudah ada sebagai ATK-014.',
        ]);

        $this->actingAs($gudang)
            ->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
                'decision_note' => 'Keputusan kedua.',
            ])
            ->assertSessionHas('error');

        expect($proposal->fresh()->decision_note)->toContain('ATK-014');
    });

    it('menutup tagihan keputusan setelah ditolak', function () {
        $gudang = pengelolaGudang();
        $proposal = buatUsulanBarang();

        $this->actingAs($gudang)->patch("/usulan-barang/{$proposal->item_proposal_id}/tolak", [
            'decision_note' => 'Sudah ada sebagai ATK-014.',
        ]);

        $tagihan = Notification::where('user_id', $gudang->user_id)
            ->where('type', NotificationType::UsulanBarangMenungguKeputusan)
            ->first();

        expect($tagihan)->toBeNull();
    });
});

describe('jahitan ke realisasi pengadaan langsung', function () {
    /**
     * Skenario asli yang diminta Kasubbag Umum dan TI, ujung ke ujung:
     * barang belum ada di master, diusulkan, didaftarkan, lalu langsung
     * diadakan tanpa usulan pengadaan.
     */
    it('mengizinkan barang hasil usulan dipakai pada realisasi langsung', function () {
        $pengusul = pejabatPengadaan();
        $proposal = buatUsulanBarang($pengusul);

        $this->actingAs(pengelolaGudang())->post('/barang', payloadBarangDariUsulan($proposal));

        $item = $proposal->fresh()->item;

        $procurement = buatRealisasiLangsung(
            $pengusul,
            \App\Models\Supplier::factory()->create(),
            [barisLangsung($item, 24, 12000)],
        );

        expect($procurement->tanpaUsulan())->toBeTrue()
            ->and($procurement->details->first()->item_id)->toBe($item->item_id);
    });
});

describe('daftar dan penyaringan usulan barang', function () {
    it('menyaring berdasarkan status dan kata kunci', function () {
        $gudang = pengelolaGudang();
        $ditolak = buatUsulanBarang(null, ['item_name' => 'Tinta Printer Warna Emas']);
        $menunggu = buatUsulanBarang(null, ['item_name' => 'Papan Tulis Kaca']);

        $this->actingAs($gudang)->patch("/usulan-barang/{$ditolak->item_proposal_id}/tolak", [
            'decision_note' => 'Sudah ada sebagai ATK-020.',
        ]);

        // Dibuktikan dengan kunci barisnya, bukan nama barangnya: pesan flash
        // dari penolakan tadi masih ikut terbawa ke permintaan berikutnya dan
        // memuat nama itu, sehingga assertDontSee atas nama akan meleset.
        $this->actingAs($gudang)
            ->get('/usulan-barang?status='.ItemProposalStatus::Diajukan->value)
            ->assertOk()
            ->assertSee($menunggu->item_proposal_id)
            ->assertDontSee($ditolak->item_proposal_id);

        $this->actingAs($gudang)
            ->get('/usulan-barang?q=Tinta')
            ->assertOk()
            ->assertSee($ditolak->item_proposal_id)
            ->assertDontSee($menunggu->item_proposal_id);
    });

    it('menampilkan tombol keputusan hanya kepada Pengelola Gudang', function () {
        $proposal = buatUsulanBarang();

        $this->actingAs(pengelolaGudang())
            ->get("/usulan-barang/{$proposal->item_proposal_id}")
            ->assertOk()
            ->assertSee('Setujui &amp; Buat Barang', false);

        $this->actingAs(pejabatPengadaan())
            ->get("/usulan-barang/{$proposal->item_proposal_id}")
            ->assertOk()
            ->assertDontSee('Setujui &amp; Buat Barang', false);
    });
});
