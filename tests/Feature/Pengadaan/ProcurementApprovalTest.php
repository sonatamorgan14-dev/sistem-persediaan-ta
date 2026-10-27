<?php

use App\Enums\NotificationType;
use App\Enums\ProcurementStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Notification;
use App\Models\StockMutation;
use App\Models\Supplier;
use App\Models\User;

// Nama pembantu di berkas Pest bersifat global: `buatUsulan`, `setujuiUsulan`,
// `buatRealisasi`, `buatRealisasiSiapDiterima`, `kasubbagUmumTi`, `pengelolaGudang`,
// dan `pejabatPengadaan` dipakai ulang dari berkas uji Tahap 2, 3, dan 4.

/**
 * Realisasi segar yang berhenti di MenungguPersetujuan.
 */
function realisasiMenungguPersetujuan(User $pejabat, User $petugas): App\Models\Procurement
{
    $usulan = setujuiUsulan($pejabat, buatUsulan($petugas, [
        ['item_id' => Item::factory()->create(['stock_current' => 0])->item_id, 'amount_request' => 10],
    ]));

    return buatRealisasi($pejabat, $usulan, Supplier::factory()->create());
}

beforeEach(function () {
    $this->petugas = pengelolaGudang();
    $this->pejabat = pejabatPengadaan();
    $this->kasubbag = kasubbagUmumTi();
    $this->procurement = realisasiMenungguPersetujuan($this->pejabat, $this->petugas);
    $this->url = "/pengadaan/{$this->procurement->procurement_id}";
});

describe('hak akses persetujuan', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        // beforeEach membangun realisasinya lewat alur aplikasi, jadi sesi uji
        // masih membawa pengguna terakhir yang dipakai.
        auth()->logout();

        $this->patch("{$this->url}/setujui")->assertRedirect('/login');
        $this->patch("{$this->url}/tolak", ['review_note' => 'x'])->assertRedirect('/login');
    });

    it('menolak Pejabat Pengadaan menyetujui realisasinya sendiri', function () {
        // Inti pemisahan tugas ADR 0016: pembuat dokumen bukan pemutusnya.
        $this->actingAs($this->pejabat)->patch("{$this->url}/setujui")->assertForbidden();
        $this->actingAs($this->pejabat)
            ->patch("{$this->url}/tolak", ['review_note' => 'Coba tolak sendiri.'])
            ->assertForbidden();

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::MenungguPersetujuan);
    });

    it('menolak Pengelola Gudang menyetujui', function () {
        $this->actingAs($this->petugas)->patch("{$this->url}/setujui")->assertForbidden();
    });

    it('menolak peran di luar pengadaan', function () {
        $this->actingAs(User::factory()->role(UserRole::KepalaUnit)->create())
            ->patch("{$this->url}/setujui")
            ->assertForbidden();
    });

    it('mengizinkan Kasubbag Umum dan TI menyetujui', function () {
        $this->actingAs($this->kasubbag)
            ->patch("{$this->url}/setujui")
            ->assertRedirect($this->url);
    });

    it('mengizinkan Admin menyetujui', function () {
        // Menangkap jebakan middleware sekaligus mengunci keputusan ADR 0030:
        // Gate::before sengaja tetap meloloskan Admin pada gate persetujuan.
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->patch("{$this->url}/setujui")
            ->assertRedirect($this->url);

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja);
    });

    it('mengizinkan Kasubbag Umum dan TI membaca daftar dan detail realisasi', function () {
        $this->actingAs($this->kasubbag)->get('/pengadaan')->assertOk();
        $this->actingAs($this->kasubbag)->get($this->url)->assertOk();
    });
});

describe('menyetujui realisasi', function () {
    it('memindahkan status ke proses belanja beserta jejak peninjaunya', function () {
        // ADR 0035: persetujuan membuka tahap belanja, bukan langsung
        // menunggu penerimaan — barangnya belum dibeli siapa pun.
        $this->actingAs($this->kasubbag)
            ->patch("{$this->url}/setujui", ['review_note' => 'Harga sudah wajar.'])
            ->assertSessionHas('status');

        $segar = $this->procurement->fresh();

        expect($segar->status)->toBe(ProcurementStatus::ProsesBelanja)
            ->and($segar->sudahDibelanjakan())->toBeFalse()
            ->and($segar->reviewed_by)->toBe($this->kasubbag->user_id)
            ->and($segar->reviewed_at)->not->toBeNull()
            ->and($segar->review_note)->toBe('Harga sudah wajar.')
            ->and($segar->sudahDireview())->toBeTrue();
    });

    it('tidak mewajibkan catatan', function () {
        $this->actingAs($this->kasubbag)
            ->patch("{$this->url}/setujui")
            ->assertSessionHasNoErrors();

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja);
    });

    it('mencatat jejak audit', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/setujui");

        expect(AuditLog::where('user_id', $this->kasubbag->user_id)
            ->where('activity', 'like', 'Menyetujui realisasi pengadaan%')
            ->exists())->toBeTrue();
    });

    it('menolak keputusan ganda', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/setujui");

        $this->actingAs($this->kasubbag)
            ->from($this->url)
            ->patch("{$this->url}/tolak", ['review_note' => 'Berubah pikiran.'])
            ->assertSessionHas('error');

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja);
    });
});

describe('menolak realisasi', function () {
    it('mewajibkan alasan penolakan diisi', function () {
        $this->actingAs($this->kasubbag)
            ->from($this->url)
            ->patch("{$this->url}/tolak", ['review_note' => ''])
            ->assertSessionHasErrors('review_note');

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::MenungguPersetujuan);
    });

    it('menyimpan alasannya beserta jejak peninjaunya', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", [
            'review_note' => 'Harga satuan di atas harga pasar, mintakan penawaran pembanding.',
        ]);

        $segar = $this->procurement->fresh();

        expect($segar->status)->toBe(ProcurementStatus::Ditolak)
            ->and($segar->isDitolak())->toBeTrue()
            ->and($segar->reviewed_by)->toBe($this->kasubbag->user_id)
            ->and($segar->review_note)->toContain('harga pasar');

        expect(AuditLog::where('user_id', $this->kasubbag->user_id)
            ->where('activity', 'like', 'Menolak realisasi pengadaan%')
            ->exists())->toBeTrue();
    });

    it('menampilkan alasannya beserta penjelasan bahwa penolakan bukan akhir', function () {
        // Tanpa kalimat ini, halaman yang ditolak dan tidak punya tombol
        // "ajukan ulang" terbaca seperti bug (pelajaran ADR 0024).
        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", [
            'review_note' => 'Penyedia belum punya NPWP aktif.',
        ]);

        $this->actingAs($this->pejabat)
            ->get($this->url)
            ->assertOk()
            ->assertSee('Penyedia belum punya NPWP aktif.')
            ->assertSee('Perbaiki &amp; Ajukan Ulang', false)
            ->assertSee('Perbaiki lalu simpan untuk mengajukannya ulang');
    });
});

describe('penerimaan menunggu persetujuan lebih dulu', function () {
    it('menolak gudang membuka formulir penerimaan sebelum disetujui', function () {
        $this->actingAs($this->petugas)
            ->get("{$this->url}/penerimaan/create")
            ->assertRedirect($this->url)
            ->assertSessionHas('error');
    });

    it('tidak menambah stok ketika penerimaan dipaksakan lewat POST langsung', function () {
        $detail = $this->procurement->details->first();

        $this->actingAs($this->petugas)
            ->from($this->url)
            ->post("{$this->url}/penerimaan", [
                'received_at' => now()->toDateString(),
                'received' => [$detail->procurement_detail_id => 10],
            ])
            ->assertSessionHas('error');

        // Penjaga status adalah yang sesungguhnya melindungi stok di sini.
        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and($detail->item->refresh()->stock_current)->toBe(0)
            ->and(StockMutation::count())->toBe(0);
    });

    it('menolak penerimaan atas realisasi yang ditolak', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", ['review_note' => 'Revisi dulu.']);

        $this->actingAs($this->petugas)
            ->get("{$this->url}/penerimaan/create")
            ->assertRedirect($this->url)
            ->assertSessionHas('error');
    });
});

describe('perbaikan setelah ditolak', function () {
    it('mengembalikan realisasi ke antrean persetujuan begitu perubahannya disimpan', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", ['review_note' => 'Harga kemahalan.']);
        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::Ditolak);

        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement->fresh('details'), [
                'items' => [[
                    'procurement_detail_id' => $this->procurement->details->first()->procurement_detail_id,
                    'amount' => 10,
                    'unit_price' => 7500,
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $segar = $this->procurement->fresh('details');

        // Jejak penolakan ikut dikosongkan: keputusan lama tidak lagi berlaku
        // atas dokumen yang isinya sudah berbeda.
        expect($segar->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and($segar->reviewed_at)->toBeNull()
            ->and($segar->reviewed_by)->toBeNull()
            ->and($segar->review_note)->toBeNull()
            ->and($segar->sudahDireview())->toBeFalse()
            ->and($segar->details->first()->unit_price)->toBe(7500);
    });

    it('membolehkan realisasi yang ditolak dibatalkan sebagai jalan keluar', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", ['review_note' => 'Tidak perlu lagi.']);

        $this->actingAs($this->pejabat)->patch("{$this->url}/batal", pembatalanPayload());

        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::Dibatalkan);
    });
});

describe('persetujuan gugur setelah realisasi diubah', function () {
    it('menarik kembali realisasi yang sudah disetujui ke antrean persetujuan', function () {
        // Lubang tata kelola yang ditutup ADR 0030: tanpa aturan ini, harga
        // dapat diubah setelah persetujuan terbit dan barang tetap masuk.
        $this->actingAs($this->kasubbag)->patch("{$this->url}/setujui");
        expect($this->procurement->fresh()->status)->toBe(ProcurementStatus::ProsesBelanja);

        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement->fresh('details'), [
                'items' => [[
                    'procurement_detail_id' => $this->procurement->details->first()->procurement_detail_id,
                    'amount' => 10,
                    'unit_price' => 99000,
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $segar = $this->procurement->fresh();

        expect($segar->status)->toBe(ProcurementStatus::MenungguPersetujuan)
            ->and($segar->reviewed_at)->toBeNull();

        // Gudang tidak boleh lagi menerima barang atas dokumen yang berubah.
        $this->actingAs($this->petugas)
            ->get("{$this->url}/penerimaan/create")
            ->assertRedirect($this->url);
    });

    it('menolak mengubah realisasi yang sudah diterima', function () {
        $diterima = realisasiDiterima($this->pejabat, $this->petugas);

        $this->actingAs($this->pejabat)
            ->get("/pengadaan/{$diterima->procurement_id}/edit")
            ->assertRedirect("/pengadaan/{$diterima->procurement_id}")
            ->assertSessionHas('error');
    });
});

describe('notifikasi persetujuan', function () {
    it('menagih Kasubbag Umum dan TI selama realisasi belum diputuskan', function () {
        $kabar = Notification::query()
            ->where('user_id', $this->kasubbag->user_id)
            ->where('type', NotificationType::RealisasiMenungguPersetujuan)
            ->where('subject_id', $this->procurement->procurement_id)
            ->first();

        expect($kabar)->not->toBeNull()
            ->and($kabar->body)->toContain('menunggu keputusan Anda');
    });

    it('menutup tagihannya dan memberi tahu perealisasi ketika disetujui', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/setujui");

        expect(notifikasiAktifUntuk(
            NotificationType::RealisasiMenungguPersetujuan,
            $this->procurement->procurement_id,
        ))->toBe(0);

        // ADR 0035: gudang belum ditagih — barangnya belum dibelanjakan.
        // Tagihan penerimaan baru terbit setelah ditandai sudah dibelanjakan.
        expect(notifikasiAktifUntuk(
            NotificationType::RealisasiMenungguPenerimaan,
            $this->procurement->procurement_id,
        ))->toBe(0);

        // Regresi: `catatRealisasiDiputuskan()` dulu menilai "disetujui" dari
        // status MenungguPenerimaan, sehingga persetujuan yang kini berujung
        // di ProsesBelanja akan terkirim sebagai kabar penolakan.
        $kabar = Notification::query()
            ->where('user_id', $this->pejabat->user_id)
            ->where('type', NotificationType::RealisasiDisetujui)
            ->first();

        expect($kabar)->not->toBeNull()
            ->and($kabar->body)->toContain('boleh dibelanjakan')
            ->and(Notification::query()
                ->where('user_id', $this->pejabat->user_id)
                ->where('type', NotificationType::RealisasiDitolak)
                ->exists())->toBeFalse();
    });

    it('memberi tahu perealisasi beserta alasannya ketika ditolak', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", [
            'review_note' => 'Spesifikasi tidak sesuai usulan.',
        ]);

        $kabar = Notification::query()
            ->where('user_id', $this->pejabat->user_id)
            ->where('type', NotificationType::RealisasiDitolak)
            ->first();

        expect($kabar)->not->toBeNull()
            ->and($kabar->body)->toContain('Spesifikasi tidak sesuai usulan.')
            ->and($kabar->body)->toContain('mengajukannya ulang');

        // Gudang tidak pernah ditagih menunggu barang yang tidak jadi dipesan.
        expect(notifikasiAktifUntuk(
            NotificationType::RealisasiMenungguPenerimaan,
            $this->procurement->procurement_id,
        ))->toBe(0);
    });

    it('memberi tahu perealisasi lagi ketika ditolak untuk kedua kalinya', function () {
        // ADR 0037: penolakan kedua memakai baris notifikasi yang sama, tetapi
        // harus terdengar lagi walau penolakan pertama sudah dibaca.
        $kabar = fn () => Notification::query()
            ->where('user_id', $this->pejabat->user_id)
            ->where('type', NotificationType::RealisasiDitolak)
            ->sole();

        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", ['review_note' => 'Harga kemahalan.']);
        $pertama = $kabar();

        $this->actingAs($this->pejabat)->patch("/notifikasi/{$pertama->notification_id}/baca");
        expect($kabar()->sudahDibaca())->toBeTrue();

        $this->actingAs($this->pejabat)
            ->put($this->url, ubahPayload($this->procurement->fresh('details'), [
                'items' => [[
                    'procurement_detail_id' => $this->procurement->details->first()->procurement_detail_id,
                    'amount' => 10,
                    'unit_price' => 7500,
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", [
            'review_note' => 'Penyedianya belum terdaftar di katalog elektronik.',
        ]);

        $kedua = $kabar();

        expect($kedua->notification_id)->toBe($pertama->notification_id)
            ->and($kedua->sudahDibaca())->toBeFalse()
            ->and($kedua->body)->toContain('katalog elektronik');
    });

    it('menagih ulang persetujuan setelah realisasi yang sudah disetujui diubah', function () {
        $this->actingAs($this->kasubbag)->patch("{$this->url}/setujui");

        $this->actingAs($this->pejabat)->put($this->url, ubahPayload($this->procurement->fresh('details'), [
            'items' => [[
                'procurement_detail_id' => $this->procurement->details->first()->procurement_detail_id,
                'amount' => 10,
                'unit_price' => 45000,
            ]],
        ]));

        expect(notifikasiAktifUntuk(
            NotificationType::RealisasiMenungguPersetujuan,
            $this->procurement->procurement_id,
        ))->toBeGreaterThan(0)
            ->and(notifikasiAktifUntuk(
                NotificationType::RealisasiMenungguPenerimaan,
                $this->procurement->procurement_id,
            ))->toBe(0);
    });
});

describe('rekap tidak menghitung realisasi yang ditolak', function () {
    it('memisahkan cacahnya dan mengeluarkannya dari nilai', function () {
        // Perluasan ADR 0026: dokumen yang tidak pernah menjadi transaksi
        // ditampilkan, tetapi tidak pernah dihitung.
        $this->actingAs($this->kasubbag)->patch("{$this->url}/tolak", ['review_note' => 'Revisi harga.']);

        $this->actingAs($this->pejabat)
            ->get('/pengadaan')
            ->assertOk()
            // Barisnya tetap tampil lengkap dengan badge statusnya.
            ->assertSee('Ditolak')
            ->assertSee('1 ditolak')
            ->assertSee('Rp0');
    });
});
