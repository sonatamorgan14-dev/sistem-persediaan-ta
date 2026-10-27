<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\Procurement;
use App\Models\ProcurementDocument;
use App\Models\ProcurementRequest;
// Model permintaan barang bernama Request, bentrok dengan Illuminate\Http\Request.
use App\Models\Request as PermintaanBarang;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sistem tertutup: paksa HTTPS di luar lingkungan pengembangan.
        if (! $this->app->environment('local', 'testing')) {
            URL::forceScheme('https');
        }

        // Kebijakan kata sandi minimum untuk seluruh aplikasi.
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers();

            // Pemeriksaan kebocoran memanggil layanan luar; hanya relevan di produksi.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        $this->registerMorphMap();
        $this->registerGates();
    }

    /**
     * Alias untuk relasi polimorfik `stock_mutations.reference_*` dan
     * `audit_logs.subject_*`.
     *
     * Memakai morphMap (bukan enforceMorphMap) supaya relasi polimorfik yang
     * nanti dibuat pengembang sub sistem permintaan tidak wajib terdaftar di sini.
     *
     * Tiga alias pertama dipakai baris `stock_mutations` yang sudah tersimpan:
     * mengganti namanya akan memutus relasi seluruh buku mutasi. Sisanya
     * ditambahkan untuk jejak audit (ADR 0034).
     */
    private function registerMorphMap(): void
    {
        Relation::morphMap([
            'pengadaan' => Procurement::class,
            'permintaan' => PermintaanBarang::class,
            'opname' => StockOpname::class,

            'pengguna' => User::class,
            'barang' => Item::class,
            'penyedia' => Supplier::class,
            'usulan_barang' => ItemProposal::class,
            'usulan_pengadaan' => ProcurementRequest::class,
            'dokumen_pengadaan' => ProcurementDocument::class,
        ]);
    }

    /**
     * Gate berbasis peran, dipakai lewat @can pada Blade maupun ->can() pada rute.
     */
    private function registerGates(): void
    {
        // Admin adalah peran tertinggi: lolos seluruh pemeriksaan otorisasi.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        Gate::define('kelola-pengguna', fn (User $user) => $user->hasRole(UserRole::Admin));

        Gate::define('kelola-gudang', fn (User $user) => $user->hasRole(UserRole::PengelolaGudang));

        Gate::define('kelola-pengadaan', fn (User $user) => $user->hasRole(UserRole::PejabatPengadaan));

        Gate::define('kelola-permintaan', fn (User $user) => $user->hasRole(UserRole::PengelolaGudang));

        // Persetujuan realisasi pengadaan adalah wewenang jabatan Kasubbag Umum
        // dan TI. Admin tetap lolos lewat Gate::before di atas: pemisahan staf
        // TI dari pemberi persetujuan bersifat organisasional, tidak ditegakkan
        // sistem. Lihat ADR 0030.
        Gate::define('setujui-pengadaan', fn (User $user) => $user->hasRole(UserRole::KasubbagUmumTi));

        // Dokumen pengadaan dibaca tiga peran sekaligus: Pejabat Pengadaan yang
        // merealisasi, Pengelola Gudang yang mengusulkan dan menerima barangnya,
        // serta Kasubbag Umum dan TI yang harus membaca dokumen sebelum
        // memutuskannya. Perlu gate tersendiri karena middleware `can:` yang
        // ditumpuk bersifat DAN, bukan ATAU.
        Gate::define(
            'akses-pengadaan',
            fn (User $user) => $user->hasRole(
                UserRole::PejabatPengadaan,
                UserRole::PengelolaGudang,
                UserRole::KasubbagUmumTi,
            ),
        );

        // Kasubbag Umum dan TI mengawasi gudang dan penyedia sebagai atasan
        // langsung pelaksananya, tetapi hanya membaca: penyetuju yang bisa
        // mengubah data yang ia nilai meruntuhkan pemisahan tugas ADR 0016.
        // Rute tulis tetap di gate `kelola-*`. Lihat ADR 0041.
        Gate::define(
            'akses-gudang',
            fn (User $user) => $user->hasRole(UserRole::PengelolaGudang, UserRole::KasubbagUmumTi),
        );

        Gate::define(
            'akses-penyedia',
            fn (User $user) => $user->hasRole(UserRole::PejabatPengadaan, UserRole::KasubbagUmumTi),
        );

        // Melampirkan berkas adalah pekerjaan pelaksana pengadaan (ADR 0022).
        // Bukan `akses-pengadaan`: gate itu juga meloloskan Kasubbag, yang
        // tidak boleh menambahkan bukti ke realisasi yang ia nilai sendiri.
        Gate::define(
            'unggah-berkas-pengadaan',
            fn (User $user) => $user->hasRole(UserRole::PejabatPengadaan, UserRole::PengelolaGudang),
        );

        // Nomor BAST diisi salah satu dari dua penanda tangannya: Pelaksana
        // Belanja atau Petugas Persediaan. Kasubbag mencetak BAST yang sudah
        // bernomor, tetapi tidak menomorinya (ADR 0041, ADR 0042).
        Gate::define(
            'isi-nomor-bast',
            fn (User $user) => $user->hasRole(UserRole::PejabatPengadaan, UserRole::PengelolaGudang),
        );
    }
}
