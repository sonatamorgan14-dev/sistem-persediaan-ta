{{-- Lonceng notifikasi. Datanya disiapkan App\View\Components\Header\NotificationDropdown.
     Hanya berisi yang belum dibaca, dan hanya judulnya: kalimat lengkapnya ada
     di halaman notifikasi (ADR 0036). --}}
<div class="relative" x-data="{
    dropdownOpen: false,
    toggleDropdown() { this.dropdownOpen = !this.dropdownOpen },
    closeDropdown() { this.dropdownOpen = false }
}" @click.away="closeDropdown()" @keydown.escape.window="closeDropdown()">
    <button
        class="hover:text-dark-900 relative flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
        @click="toggleDropdown()" type="button" aria-haspopup="true" :aria-expanded="dropdownOpen"
        aria-label="{{ $belumDibaca > 0 ? "Notifikasi, {$belumDibaca} belum dibaca" : 'Notifikasi' }}">

        {{-- Badge berisi angka, bukan sekadar titik: jumlah yang tertunda ikut
             menentukan apakah lonceng layak dibuka sekarang atau nanti. --}}
        @if ($belumDibaca > 0)
            <span @class([
                'absolute -right-0.5 -top-0.5 z-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[10px] font-semibold text-white',
                'bg-error-500' => $adaYangMendesak,
                'bg-orange-400' => !$adaYangMendesak,
            ])>
                {{ $belumDibaca > 9 ? '9+' : $belumDibaca }}
            </span>
        @endif

        <svg class="fill-current" width="20" height="20" viewBox="0 0 20 20" fill="none"
            xmlns="http://www.w3.org/2000/svg">
            <path fill-rule="evenodd" clip-rule="evenodd"
                d="M10.75 2.29248C10.75 1.87827 10.4143 1.54248 10 1.54248C9.58583 1.54248 9.25004 1.87827 9.25004 2.29248V2.83613C6.08266 3.20733 3.62504 5.9004 3.62504 9.16748V14.4591H3.33337C2.91916 14.4591 2.58337 14.7949 2.58337 15.2091C2.58337 15.6234 2.91916 15.9591 3.33337 15.9591H4.37504H15.625H16.6667C17.0809 15.9591 17.4167 15.6234 17.4167 15.2091C17.4167 14.7949 17.0809 14.4591 16.6667 14.4591H16.375V9.16748C16.375 5.9004 13.9174 3.20733 10.75 2.83613V2.29248ZM14.875 14.4591V9.16748C14.875 6.47509 12.6924 4.29248 10 4.29248C7.30765 4.29248 5.12504 6.47509 5.12504 9.16748V14.4591H14.875ZM8.00004 17.7085C8.00004 18.1228 8.33583 18.4585 8.75004 18.4585H11.25C11.6643 18.4585 12 18.1228 12 17.7085C12 17.2943 11.6643 16.9585 11.25 16.9585H8.75004C8.33583 16.9585 8.00004 17.2943 8.00004 17.7085Z"
                fill="" />
        </svg>
    </button>

    <div x-show="dropdownOpen" x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95" x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="shadow-theme-lg dark:bg-gray-dark absolute -right-[240px] z-50 mt-[17px] flex max-h-[480px] w-[350px] flex-col rounded-2xl border border-gray-200 bg-white p-3 sm:w-[361px] lg:right-0 dark:border-gray-800"
        style="display: none;">

        <div class="mb-3 flex items-center justify-between border-b border-gray-100 pb-3 dark:border-gray-800">
            <h5 class="text-lg font-semibold text-gray-800 dark:text-white/90">Notifikasi</h5>

            <div class="flex items-center gap-2">
                @if ($belumDibaca > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}">
                        @csrf
                        <button type="submit"
                            class="text-theme-xs hover:text-brand-500 dark:hover:text-brand-400 font-medium text-gray-500 dark:text-gray-400">
                            Tandai semua dibaca
                        </button>
                    </form>
                @endif

                <button @click="closeDropdown()" class="text-gray-500 dark:text-gray-400" type="button"
                    aria-label="Tutup">
                    <svg class="fill-current" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                            d="M6.21967 7.28131C5.92678 6.98841 5.92678 6.51354 6.21967 6.22065C6.51256 5.92775 6.98744 5.92775 7.28033 6.22065L11.999 10.9393L16.7176 6.22078C17.0105 5.92789 17.4854 5.92788 17.7782 6.22078C18.0711 6.51367 18.0711 6.98855 17.7782 7.28144L13.0597 12L17.7782 16.7186C18.0711 17.0115 18.0711 17.4863 17.7782 17.7792C17.4854 18.0721 17.0105 18.0721 16.7176 17.7792L11.999 13.0607L7.28033 17.7794C6.98744 18.0722 6.51256 18.0722 6.21967 17.7794C5.92678 17.4865 5.92678 17.0116 6.21967 16.7187L10.9384 12L6.21967 7.28131Z"
                            fill="" />
                    </svg>
                </button>
            </div>
        </div>

        <ul class="custom-scrollbar flex h-auto flex-col overflow-y-auto">
            @if ($perluTindakan->isEmpty() && $aktivitas->isEmpty())
                {{-- Sengaja tidak menyatakan "semua aman": keadaan yang sudah dibaca
                     bisa saja masih berlangsung, hanya tidak lagi ditampilkan di sini. --}}
                <li class="px-4 py-10 text-center">
                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                        Tidak ada notifikasi baru.
                    </p>
                </li>
            @endif

            @foreach ([
                ['judul' => 'Perlu Tindakan', 'items' => $perluTindakan, 'jumlah' => $jumlahPerluTindakan, 'sifat' => 'keadaan'],
                ['judul' => 'Aktivitas Terbaru', 'items' => $aktivitas, 'jumlah' => $jumlahAktivitas, 'sifat' => 'peristiwa'],
            ] as $bagian)
                @continue($bagian['items']->isEmpty())

                <li class="px-1 pt-2 pb-1">
                    <p class="text-theme-xs font-semibold tracking-wide text-gray-400 uppercase dark:text-gray-500">
                        {{ $bagian['judul'] }} &middot; {{ $bagian['jumlah'] }}
                    </p>
                </li>

                @foreach ($bagian['items'] as $notification)
                    <li>
                        {{-- Membaca notifikasi mengubah keadaan, jadi ia dikirim
                             lewat PATCH ber-CSRF, bukan tautan biasa. Controller
                             menandainya dibaca lalu mengantar ke tujuan. --}}
                        <form method="POST" action="{{ route('notifications.read', $notification) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                class="flex w-full gap-3 rounded-lg border-b border-gray-100 p-3 text-left hover:bg-gray-100 dark:border-gray-800 dark:hover:bg-white/5">
                                <span
                                    class="mt-1.5 size-2 shrink-0 rounded-full {{ $notification->level->dotClass() }}"></span>

                                <span class="block">
                                    <span class="text-theme-sm mb-1 block font-semibold text-gray-800 dark:text-white/90">
                                        {{ $notification->title }}
                                    </span>

                                    <span
                                        class="text-theme-xs flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                        <span>{{ $notification->type->label() }}</span>
                                        <span class="h-1 w-1 rounded-full bg-gray-400"></span>
                                        <span>{{ $notification->waktu() }}</span>
                                    </span>
                                </span>
                            </button>
                        </form>
                    </li>
                @endforeach

                {{-- Tiap bagian hanya memuat lima baris; sisanya disebut, bukan
                     disembunyikan, supaya lonceng tidak terkesan sudah habis. --}}
                @if ($bagian['jumlah'] > $bagian['items']->count())
                    <li class="px-3 pt-1 pb-2">
                        <a href="{{ route('notifications.index', ['status' => 'belum_dibaca', 'sifat' => $bagian['sifat']]) }}"
                            class="text-theme-xs hover:text-brand-500 dark:hover:text-brand-400 font-medium text-gray-500 dark:text-gray-400">
                            Lihat {{ $bagian['jumlah'] - $bagian['items']->count() }} lainnya
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>

        <a href="{{ route('notifications.index') }}"
            class="text-theme-sm shadow-theme-xs mt-3 flex justify-center rounded-lg border border-gray-300 bg-white p-3 font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03] dark:hover:text-gray-200">
            Lihat Semua Notifikasi
        </a>
    </div>
</div>
