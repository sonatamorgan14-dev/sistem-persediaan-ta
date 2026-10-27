<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Akun awal sistem. Sistem ini tertutup, tidak ada pendaftaran mandiri,
     * sehingga akun pertama harus dibuat lewat seeder.
     *
     * NIP di sini fiktif, hanya berbentuk sah (18 digit) supaya BAST contoh
     * tercetak lengkap. NIP pegawai sungguhan tidak masuk repositori.
     */
    public function run(): void
    {
        $password = $this->resolvePassword();

        $accounts = [
            [
                'username' => 'admin',
                'email' => 'admin@bpk.go.id',
                'fullname' => 'Administrator Sistem',
                'unit' => 'Sekretariat Perwakilan',
                'role' => UserRole::Admin,
            ],
            [
                'username' => 'kasubbag',
                'email' => 'kasubbag@bpk.go.id',
                'fullname' => 'Kepala Subbagian Umum dan TI',
                'nip' => '197803152005011002',
                'position' => 'Kepala Subbagian Umum dan TI',
                'unit' => 'Subbagian Umum dan TI',
                'role' => UserRole::KasubbagUmumTi,
            ],
            [
                'username' => 'gudang',
                'email' => 'gudang@bpk.go.id',
                'fullname' => 'Pengelola Gudang Persediaan',
                'nip' => '199001202015031004',
                'position' => 'Arsiparis Ahli Pertama (Pengelola Persediaan)',
                'unit' => 'Subbagian Umum dan TI',
                'role' => UserRole::PengelolaGudang,
            ],
            [
                'username' => 'pengadaan',
                'email' => 'pengadaan@bpk.go.id',
                'fullname' => 'Pejabat Pengadaan Barang/Jasa',
                'nip' => '198807112010121003',
                'position' => 'Pengelola Pengadaan Barang/Jasa Ahli Pertama',
                'unit' => 'Subbagian Umum dan TI',
                'role' => UserRole::PejabatPengadaan,
            ],
            [
                'username' => 'kepalaunit',
                'email' => 'kepalaunit@bpk.go.id',
                'fullname' => 'Kepala Unit',
                'unit' => 'Subbagian Umum dan TI',
                'role' => UserRole::KepalaUnit,
            ],

            [
                'username' => 'kalan',
                'email' => 'kalan@bpk.go.id',
                'fullname' => 'Kepala Perwakilan',
                'unit' => 'Perwakilan',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kasetlan',
                'email' => 'kasetlan@bpk.go.id',
                'fullname' => 'Kepala Sekretariat Perwakilan',
                'unit' => 'Sekretariat Perwakilan',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kabid_1',
                'email' => 'kabid_1@bpk.go.id',
                'fullname' => 'Kepala Bidang Pemeriksaan Lampung I',
                'unit' => 'Bidang Pemeriksaan Lampung I',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kabid_2',
                'email' => 'kabid_2@bpk.go.id',
                'fullname' => 'Kepala Bidang Pemeriksaan Lampung II',
                'unit' => 'Bidang Pemeriksaan Lampung II',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kasubbag_htu',
                'email' => 'kasubbag_htu@bpk.go.id',
                'fullname' => 'Kasubag Humas dan TU',
                'unit' => 'Subbagian Humas dan TU',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kasubbag_sdm',
                'email' => 'kasubbag_sdm@bpk.go.id',
                'fullname' => 'Kasubag SDM',
                'unit' => 'Subbagian SDM',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kasubbag_keuangan',
                'email' => 'kasubbag_keuangan@bpk.go.id',
                'fullname' => 'Kasubag Keuangan',
                'unit' => 'Subbagian Keuangan',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kasubbag_umumti',
                'email' => 'kasubbag_umumti@bpk.go.id',
                'fullname' => 'Kasubag Umum dan TI',
                'unit' => 'Subbagian Umum dan TI',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'kasubbag_hukum',
                'email' => 'kasubbag_hukum@bpk.go.id',
                'fullname' => 'Kasubag Hukum',
                'unit' => 'Subbagian Hukum',
                'role' => UserRole::KepalaUnit,
            ],
            [
                'username' => 'pengguna',
                'email' => 'pengguna@bpk.go.id',
                'fullname' => 'Pengguna Sistem',
                'unit' => 'Subbagian Umum dan TI',
                'role' => UserRole::Pengguna,
            ],
        ];

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['username' => $account['username']],
                $account + [
                    'password' => $password,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );
        }

        $this->reportCredentials($accounts, $password);
    }

    /**
     * Kata sandi awal: dari env bila disetel, kata sandi tetap saat pengembangan
     * lokal, dan kata sandi acak di lingkungan lain.
     */
    private function resolvePassword(): string
    {
        $configured = env('SEED_DEFAULT_PASSWORD');

        if (filled($configured)) {
            return $configured;
        }

        return app()->environment('local', 'testing')
            ? 'Persediaan#2026'
            : Str::password(16);
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     */
    private function reportCredentials(array $accounts, string $password): void
    {
        $this->command?->newLine();
        $this->command?->info('Akun awal berhasil dibuat:');
        $this->command?->table(
            ['Nama Pengguna', 'Peran', 'Kata Sandi'],
            array_map(
                fn (array $account) => [$account['username'], $account['role']->label(), $password],
                $accounts,
            ),
        );
        $this->command?->warn('Segera ganti kata sandi ini setelah login pertama.');
    }
}
