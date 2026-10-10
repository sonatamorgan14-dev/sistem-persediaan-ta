<?php

namespace App\View\Components\Header;

use App\Enums\NotificationLevel;
use App\Enums\NotificationType;
use App\Models\Notification;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

/**
 * Lonceng notifikasi pada header, tampil di seluruh halaman aplikasi.
 *
 * Datanya diambil di sini, bukan disuntikkan tiap controller, supaya tidak ada
 * halaman yang bisa lupa menyediakannya. Yang dibaca hanya notifikasi yang
 * belum dibaca dan dibatasi jumlahnya, sehingga biayanya tetap tiga kueri
 * ringan per permintaan: dua daftar dan satu hitungan per jenis.
 *
 * Lonceng adalah kotak masuk, bukan arsip (ADR 0036): begitu dibaca — diklik
 * satu per satu atau lewat "Tandai semua dibaca" — sebuah notifikasi
 * menyingkir dari sini, termasuk keadaan yang kondisinya masih berlangsung.
 * Yang sudah dibaca tetap tersedia di halaman notifikasi.
 */
class NotificationDropdown extends Component
{
    private const MAKS_PER_BAGIAN = 5;

    /** @var Collection<int, Notification> */
    public Collection $perluTindakan;

    /** @var Collection<int, Notification> */
    public Collection $aktivitas;

    public int $jumlahPerluTindakan = 0;

    public int $jumlahAktivitas = 0;

    public int $belumDibaca = 0;

    public bool $adaYangMendesak = false;

    public function __construct()
    {
        $this->perluTindakan = collect();
        $this->aktivitas = collect();

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        // Kuota tiap bagian diambil sendiri-sendiri (ADR 0037). Dulu sepuluh
        // baris diambil lebih dulu lalu dibagi dua, sehingga keadaan yang
        // memenuhi kuota membuat "Aktivitas Terbaru" kosong padahal ada kabar.
        $this->perluTindakan = Notification::query()
            ->untuk($user)
            ->belumDibaca()
            ->keadaan()
            ->terpenting()
            ->limit(self::MAKS_PER_BAGIAN)
            ->get();

        // Peristiwa dibaca sebagai linimasa, jadi urut waktu saja: kabar
        // terbaru tidak boleh tenggelam di bawah kabar lama yang tingkatnya
        // lebih tinggi.
        $this->aktivitas = Notification::query()
            ->untuk($user)
            ->belumDibaca()
            ->peristiwa()
            ->terbaru()
            ->limit(self::MAKS_PER_BAGIAN)
            ->get();

        // Hitungan mentah per jenis, supaya satu kueri cukup untuk badge dan
        // kedua bagian. `toBase()` membiarkan kunci jenis tetap berupa teks,
        // tanpa dicetak menjadi enum.
        $perJenis = Notification::query()
            ->untuk($user)
            ->belumDibaca()
            ->toBase()
            ->selectRaw('type, count(*) as jumlah')
            ->groupBy('type')
            ->pluck('jumlah', 'type');

        foreach ($perJenis as $type => $jumlah) {
            $bersifatKeadaan = NotificationType::tryFrom((string) $type)?->bersifatKeadaan();

            if ($bersifatKeadaan === true) {
                $this->jumlahPerluTindakan += (int) $jumlah;
            } elseif ($bersifatKeadaan === false) {
                $this->jumlahAktivitas += (int) $jumlah;
            }
        }

        // Dijumlahkan dari kedua bagian, bukan dihitung terpisah, supaya angka
        // badge tidak pernah menjanjikan sesuatu yang tidak ditampilkan lonceng.
        $this->belumDibaca = $this->jumlahPerluTindakan + $this->jumlahAktivitas;

        // Keadaan diurutkan menurut tingkat, jadi yang mendesak — bila ada —
        // selalu termuat di kuota.
        $this->adaYangMendesak = $this->perluTindakan->contains(
            fn (Notification $n) => $n->level === NotificationLevel::Mendesak,
        );
    }

    public function kosong(): bool
    {
        return $this->perluTindakan->isEmpty() && $this->aktivitas->isEmpty();
    }

    public function render(): View|Closure|string
    {
        return view('components.header.notification-dropdown');
    }
}
