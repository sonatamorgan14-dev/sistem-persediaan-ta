<?php

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

// Kata sandi bawaan UserFactory.
const SANDI_LAMA_PROFIL = 'password';
const SANDI_BARU_PROFIL = 'SandiBaru#2026x';

beforeEach(fn () => Storage::fake('local'));

function penggunaProfil(UserRole $role = UserRole::PengelolaGudang, array $attributes = []): User
{
    return User::factory()->role($role)->create($attributes);
}

/**
 * Pasang foto langsung ke disk dan baris, tanpa lewat rute — supaya akunnya
 * tidak mendapat baris audit (yang akan menghalangi penghapusan akun).
 */
function pasangFotoProfil(User $user): string
{
    $path = 'avatars/'.fake()->uuid().'.jpg';
    Storage::disk('local')->put($path, 'isi-foto');
    $user->forceFill(['avatar_path' => $path])->save();

    return $path;
}

function auditProfil(User $user, AuditEvent $event, AuditModule $module = AuditModule::Profil): int
{
    return AuditLog::where('user_id', $user->user_id)
        ->where('module', $module->value)
        ->where('event', $event->value)
        ->count();
}

describe('hak akses', function () {
    it('mengarahkan tamu ke halaman masuk', function () {
        $this->get('/profil')->assertRedirect('/login');
        $this->patch('/profil/email')->assertRedirect('/login');
        $this->put('/profil/kata-sandi')->assertRedirect('/login');
        $this->post('/profil/foto')->assertRedirect('/login');
        $this->delete('/profil/foto')->assertRedirect('/login');
    });

    // Tidak ada kasus "peran salah → 403": profil milik setiap akun, termasuk
    // peran sub sistem Permintaan. Admin ikut dalam perulangan ini.
    it('membuka halaman profil untuk setiap peran', function () {
        foreach (UserRole::cases() as $role) {
            $user = penggunaProfil($role);

            $this->actingAs($user)
                ->get('/profil')
                ->assertOk()
                ->assertSee($user->fullname)
                ->assertSee($role->label());
        }
    });

    it('menampilkan data wewenang Admin tanpa isian untuk mengubahnya', function () {
        $user = penggunaProfil(attributes: [
            'unit' => UnitKerja::SubbagianUmumTi,
            'nip' => '198506142003121001',
            'position' => 'Pengelola Pengadaan Barang/Jasa Ahli Pertama',
        ]);

        $this->actingAs($user)
            ->get('/profil')
            ->assertOk()
            ->assertSee($user->username)
            ->assertSee('Subbagian Umum dan TI')
            ->assertSee('198506142003121001')
            ->assertSee('Pengelola Pengadaan Barang/Jasa Ahli Pertama')
            ->assertDontSee('name="nip"', false)
            ->assertDontSee('name="position"', false)
            ->assertDontSee('name="fullname"', false)
            ->assertDontSee('name="username"', false)
            ->assertDontSee('name="role"', false)
            ->assertDontSee('name="unit"', false);
    });
});

describe('mengganti email', function () {
    it('menyimpan email baru dan mencatat audit beserta email lamanya', function () {
        $user = penggunaProfil(attributes: ['email' => 'lama@bpk.go.id']);

        $this->actingAs($user)
            ->patch('/profil/email', ['email' => 'baru@bpk.go.id', 'email_password' => SANDI_LAMA_PROFIL])
            ->assertRedirect()
            ->assertSessionHas('status', 'Email berhasil diperbarui.');

        expect($user->fresh()->email)->toBe('baru@bpk.go.id')
            ->and(auditProfil($user, AuditEvent::Ubah))->toBe(1)
            ->and(AuditLog::where('user_id', $user->user_id)->value('activity'))->toContain('lama@bpk.go.id');
    });

    it('menolak kata sandi saat ini yang salah', function () {
        $user = penggunaProfil(attributes: ['email' => 'lama@bpk.go.id']);

        $this->actingAs($user)
            ->patch('/profil/email', ['email' => 'baru@bpk.go.id', 'email_password' => 'salah'])
            ->assertSessionHasErrors(['email_password' => 'Kata sandi saat ini salah.']);

        expect($user->fresh()->email)->toBe('lama@bpk.go.id');
    });

    it('menolak email yang dipakai akun lain', function () {
        penggunaProfil(attributes: ['email' => 'dipakai@bpk.go.id']);
        $user = penggunaProfil();

        $this->actingAs($user)
            ->patch('/profil/email', ['email' => 'dipakai@bpk.go.id', 'email_password' => SANDI_LAMA_PROFIL])
            ->assertSessionHasErrors(['email' => 'Email sudah dipakai akun lain.']);
    });

    it('tidak mencatat apa pun bila email tidak berubah', function () {
        $user = penggunaProfil(attributes: ['email' => 'tetap@bpk.go.id']);

        $this->actingAs($user)
            ->patch('/profil/email', ['email' => 'tetap@bpk.go.id', 'email_password' => SANDI_LAMA_PROFIL])
            ->assertSessionHas('status', 'Email tidak berubah.');

        expect(auditProfil($user, AuditEvent::Ubah))->toBe(0);
    });

    it('mengabaikan isian selundupan untuk kolom wewenang Admin', function () {
        $user = penggunaProfil(UserRole::PengelolaGudang, [
            'fullname' => 'Nama Asli',
            'username' => 'nama.asli',
            'unit' => UnitKerja::SubbagianHukum,
            'nip' => '198506142003121001',
            'position' => 'Jabatan Asli',
        ]);

        $this->actingAs($user)->patch('/profil/email', [
            'email' => 'baru@bpk.go.id',
            'email_password' => SANDI_LAMA_PROFIL,
            'fullname' => 'Nama Palsu',
            'nip' => '199999999999999999',
            'position' => 'Jabatan Palsu',
            'username' => 'palsu',
            'role' => UserRole::Admin->value,
            'unit' => UnitKerja::SubbagianSdm->value,
            'is_active' => 0,
            'avatar_path' => 'avatars/../../.env',
        ]);

        $user->refresh();

        expect($user->email)->toBe('baru@bpk.go.id')
            ->and($user->fullname)->toBe('Nama Asli')
            ->and($user->username)->toBe('nama.asli')
            ->and($user->role)->toBe(UserRole::PengelolaGudang)
            ->and($user->unit)->toBe(UnitKerja::SubbagianHukum)
            ->and($user->nip)->toBe('198506142003121001')
            ->and($user->position)->toBe('Jabatan Asli')
            ->and($user->is_active)->toBeTrue()
            ->and($user->avatar_path)->toBeNull();
    });
});

describe('mengganti kata sandi', function () {
    it('menyimpan kata sandi baru, mencatat audit, dan sesi ini tetap masuk', function () {
        $user = penggunaProfil();

        $this->actingAs($user)
            ->put('/profil/kata-sandi', [
                'current_password' => SANDI_LAMA_PROFIL,
                'password' => SANDI_BARU_PROFIL,
                'password_confirmation' => SANDI_BARU_PROFIL,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Hash::check(SANDI_BARU_PROFIL, $user->fresh()->password))->toBeTrue()
            ->and(auditProfil($user, AuditEvent::Ubah))->toBe(1);

        $this->get('/profil')->assertOk();
    });

    // Klaim di halaman profil: "sesi di perangkat lain akan keluar otomatis".
    // Sesi lain disimulasikan sebagai sesi yang masih menyimpan hash lama.
    it('mengeluarkan sesi lain yang masih memegang kata sandi lama', function () {
        $user = penggunaProfil();
        $hashLama = $user->password;

        $this->actingAs($user)->put('/profil/kata-sandi', [
            'current_password' => SANDI_LAMA_PROFIL,
            'password' => SANDI_BARU_PROFIL,
            'password_confirmation' => SANDI_BARU_PROFIL,
        ]);

        $this->flushSession();

        $this->withSession(['password_hash_web' => $hashLama])
            ->get('/profil')
            ->assertRedirect('/login');
    });

    it('menolak kata sandi saat ini yang salah', function () {
        $user = penggunaProfil();

        $this->actingAs($user)
            ->put('/profil/kata-sandi', [
                'current_password' => 'salah',
                'password' => SANDI_BARU_PROFIL,
                'password_confirmation' => SANDI_BARU_PROFIL,
            ])
            ->assertSessionHasErrors(['current_password' => 'Kata sandi saat ini salah.']);

        expect(Hash::check(SANDI_LAMA_PROFIL, $user->fresh()->password))->toBeTrue();
    });

    it('menerapkan kebijakan kata sandi yang sama dengan Admin', function (string $sandi, string $pesan) {
        $this->actingAs(penggunaProfil())
            ->put('/profil/kata-sandi', [
                'current_password' => SANDI_LAMA_PROFIL,
                'password' => $sandi,
                'password_confirmation' => $sandi,
            ])
            ->assertSessionHasErrors(['password' => $pesan]);
    })->with([
        'terlalu pendek' => ['Ab#1', 'Kata sandi baru minimal 12 karakter.'],
        'tanpa huruf besar' => ['sandipanjang#2026', 'Kata sandi baru harus memuat huruf besar dan huruf kecil.'],
        'tanpa angka' => ['SandiPanjang#Sekali', 'Kata sandi baru harus memuat angka.'],
    ]);

    it('menolak konfirmasi yang tidak cocok', function () {
        $this->actingAs(penggunaProfil())
            ->put('/profil/kata-sandi', [
                'current_password' => SANDI_LAMA_PROFIL,
                'password' => SANDI_BARU_PROFIL,
                'password_confirmation' => 'LainLagi#20266',
            ])
            ->assertSessionHasErrors(['password' => 'Konfirmasi kata sandi tidak cocok.']);
    });

    it('menolak kata sandi baru yang sama dengan kata sandi saat ini', function () {
        $sandi = 'SandiSamaPersis#2026';
        $user = penggunaProfil(attributes: ['password' => $sandi]);

        $this->actingAs($user)
            ->put('/profil/kata-sandi', [
                'current_password' => $sandi,
                'password' => $sandi,
                'password_confirmation' => $sandi,
            ])
            ->assertSessionHasErrors(['password' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.']);
    });
});

describe('foto profil', function () {
    it('mengunggah foto ke disk privat dan mencatat audit', function () {
        $user = penggunaProfil();

        $this->actingAs($user)
            ->post('/profil/foto', ['avatar' => UploadedFile::fake()->image('wajah.jpg', 512, 512)])
            ->assertRedirect()
            ->assertSessionHas('status', 'Foto profil berhasil diperbarui.');

        $path = $user->fresh()->avatar_path;

        expect($path)->toStartWith('avatars/')
            ->and($path)->not->toContain('wajah')
            ->and(auditProfil($user, AuditEvent::Unggah))->toBe(1);

        Storage::disk('local')->assertExists($path);
    });

    it('membuang berkas lama saat foto diganti', function () {
        $user = penggunaProfil();
        $lama = pasangFotoProfil($user);

        $this->actingAs($user)
            ->post('/profil/foto', ['avatar' => UploadedFile::fake()->image('baru.png', 300, 300)]);

        $baru = $user->fresh()->avatar_path;

        expect($baru)->not->toBe($lama);
        Storage::disk('local')->assertMissing($lama);
        Storage::disk('local')->assertExists($baru);
    });

    it('menolak berkas yang bukan gambar, terlalu besar, atau terlalu kecil', function (UploadedFile $berkas, string $pesan) {
        $user = penggunaProfil();

        $this->actingAs($user)
            ->post('/profil/foto', ['avatar' => $berkas])
            ->assertSessionHasErrors(['avatar' => $pesan]);

        expect($user->fresh()->avatar_path)->toBeNull();
    })->with([
        'PDF' => [fn () => UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf'), 'Berkas harus berupa gambar.'],
        'lebih dari 2 MB' => [fn () => UploadedFile::fake()->image('besar.jpg', 600, 600)->size(3000), 'Ukuran foto maksimal 2 MB.'],
        'di bawah 96 piksel' => [fn () => UploadedFile::fake()->image('kecil.jpg', 50, 50), 'Foto terlalu kecil. Minimal 96×96 piksel.'],
    ]);

    it('menghapus foto sendiri beserta berkasnya', function () {
        $user = penggunaProfil();
        $path = pasangFotoProfil($user);

        $this->actingAs($user)
            ->delete('/profil/foto')
            ->assertSessionHas('status');

        expect($user->fresh()->avatar_path)->toBeNull()
            ->and(auditProfil($user, AuditEvent::Hapus))->toBe(1);
        Storage::disk('local')->assertMissing($path);
    });

    it('menyajikan foto kepada pengguna lain yang sudah masuk', function () {
        $pemilik = penggunaProfil();
        pasangFotoProfil($pemilik);

        $response = $this->actingAs(penggunaProfil(UserRole::PejabatPengadaan))
            ->get($pemilik->urlFoto())
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->headers->get('Cache-Control'))->toContain('max-age=604800');
    });

    it('tidak menyajikan foto kepada tamu', function () {
        $pemilik = penggunaProfil();
        pasangFotoProfil($pemilik);

        $this->get($pemilik->urlFoto())->assertRedirect('/login');
    });

    it('mengembalikan 404 bila akun tidak punya foto atau berkasnya hilang', function () {
        $tanpaFoto = penggunaProfil();
        $berkasHilang = penggunaProfil();
        Storage::disk('local')->delete(pasangFotoProfil($berkasHilang));

        $this->actingAs(penggunaProfil())
            ->get(route('profile.avatar.show', $tanpaFoto))
            ->assertNotFound();

        $this->get($berkasHilang->urlFoto())->assertNotFound();
    });

    it('menampilkan menu lihat dan hapus foto hanya bila foto sudah ada', function () {
        $user = penggunaProfil();

        $this->actingAs($user)
            ->get('/profil')
            ->assertSee('Atur foto')
            ->assertDontSee('Lihat foto')
            ->assertDontSee('Hapus foto');

        pasangFotoProfil($user);

        $this->get('/profil')
            ->assertSee('Lihat foto')
            ->assertSee('Hapus foto')
            ->assertSee($user->urlFoto(), false);
    });

    it('menampilkan foto di header, dan inisial bila belum ada foto', function () {
        $user = penggunaProfil(attributes: ['fullname' => 'Budi Santoso']);

        $this->actingAs($user)
            ->get('/dasbor')
            ->assertSee('BS')
            ->assertDontSee('/profil/foto/', false);

        pasangFotoProfil($user);

        $this->get('/dasbor')->assertSee($user->fresh()->urlFoto(), false);
    });
});

describe('moderasi foto oleh Admin', function () {
    it('mengizinkan Admin menghapus foto pengguna lain', function () {
        $admin = penggunaProfil(UserRole::Admin);
        $user = penggunaProfil();
        $path = pasangFotoProfil($user);

        $this->actingAs($admin)
            ->from(route('users.edit', $user))
            ->delete(route('users.avatar.destroy', $user))
            ->assertRedirect(route('users.edit', $user))
            ->assertSessionHas('status');

        expect($user->fresh()->avatar_path)->toBeNull()
            ->and(auditProfil($admin, AuditEvent::Hapus, AuditModule::Pengguna))->toBe(1);
        Storage::disk('local')->assertMissing($path);
    });

    it('menampilkan tombol hapus foto hanya bila pengguna punya foto', function () {
        $admin = penggunaProfil(UserRole::Admin);
        $user = penggunaProfil();

        $this->actingAs($admin)->get(route('users.edit', $user))->assertDontSee('Hapus foto');

        pasangFotoProfil($user);

        $this->get(route('users.edit', $user))->assertSee('Hapus foto');
    });

    it('menolak selain Admin', function () {
        $user = penggunaProfil();
        pasangFotoProfil($user);

        $this->actingAs(penggunaProfil(UserRole::KasubbagUmumTi))
            ->delete(route('users.avatar.destroy', $user))
            ->assertForbidden();

        expect($user->fresh()->avatar_path)->not->toBeNull();
    });

    it('ikut membuang berkas foto saat akun dihapus', function () {
        $admin = penggunaProfil(UserRole::Admin);
        $user = penggunaProfil();
        $path = pasangFotoProfil($user);

        $this->actingAs($admin)->delete(route('users.destroy', $user))->assertSessionHas('status');

        expect(User::find($user->user_id))->toBeNull();
        Storage::disk('local')->assertMissing($path);
    });
});
