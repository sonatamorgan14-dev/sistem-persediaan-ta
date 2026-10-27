{{--
    Kartu identitas dan foto profil (ADR 0038, 0039).

    Mengeklik avatar membuka menu Lihat / Ganti / Hapus foto — atau langsung
    pemilih berkas bila belum ada foto, karena menu satu butir hanya menambah
    klik. Foto yang dipilih dibuka di jendela "Atur foto": digeser, diperbesar
    lewat slider, roda tetikus, atau cubitan, lalu dipotong di peramban oleh
    fotoProfil() (lihat index.blade.php) dan langsung dikirim.

    Formulir unggahnya tak terlihat; modal hapus dibuka lewat event
    `buka-hapus-foto` karena membawa formulirnya sendiri.
--}}
@php
    $tombolIkon = 'flex size-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300';
    $butirMenu = 'text-theme-sm flex w-full items-center rounded-lg px-3 py-2 text-left font-medium transition hover:bg-gray-100 dark:hover:bg-white/5';
@endphp

<div x-data="fotoProfil()"
    class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="flex items-center gap-5">
        <div class="relative shrink-0" @click.outside="menuBuka = false" @keydown.escape="menuBuka = false">
            <button type="button" aria-label="Foto profil" aria-haspopup="menu" :aria-expanded="menuBuka"
                @click="{{ $user->punyaFoto() ? 'menuBuka = ! menuBuka' : 'pilihBerkas()' }}"
                class="group focus-visible:ring-brand-500/40 relative block rounded-full focus:outline-hidden focus-visible:ring-4">
                <x-ui.user-avatar :user="$user" class="size-20 text-2xl" />
                <span class="bg-brand-500 group-hover:bg-brand-600 absolute -right-1 -bottom-1 flex size-8 items-center justify-center rounded-full border-2 border-white text-white transition dark:border-gray-900">
                    <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M6.5 3.5a1 1 0 0 0-.83.45L4.83 5.2H3.5A1.5 1.5 0 0 0 2 6.7v8.3a1.5 1.5 0 0 0 1.5 1.5h13a1.5 1.5 0 0 0 1.5-1.5V6.7a1.5 1.5 0 0 0-1.5-1.5h-1.33l-.84-1.25a1 1 0 0 0-.83-.45h-7ZM10 14a3.25 3.25 0 1 0 0-6.5A3.25 3.25 0 0 0 10 14Z" />
                    </svg>
                </span>
            </button>

            @if ($user->punyaFoto())
                <div x-show="menuBuka" x-cloak x-transition.origin.top.left role="menu"
                    class="shadow-theme-lg dark:bg-gray-dark absolute top-full left-0 z-50 mt-2 w-44 rounded-xl border border-gray-200 bg-white p-1.5 dark:border-gray-800">
                    <button type="button" role="menuitem" @click="menuBuka = false; lihatBuka = true"
                        class="{{ $butirMenu }} text-gray-700 dark:text-gray-300">Lihat foto</button>
                    <button type="button" role="menuitem" @click="pilihBerkas()"
                        class="{{ $butirMenu }} text-gray-700 dark:text-gray-300">Ganti foto</button>
                    <button type="button" role="menuitem" @click="menuBuka = false; $dispatch('buka-hapus-foto')"
                        class="{{ $butirMenu }} text-error-600 dark:text-error-500">Hapus foto</button>
                </div>
            @endif
        </div>

        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">{{ $user->fullname }}</h3>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                {{ '@'.$user->username }} &middot; {{ $user->unit?->label() }}
            </p>
            <span class="text-theme-xs mt-2 inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $user->role->badgeClass() }}">
                {{ $user->role->label() }}
            </span>
        </div>
    </div>

    <p x-show="galat" x-cloak x-text="galat" class="text-theme-xs text-error-500 mt-4" role="alert"></p>
    @error('avatar')
        <p class="text-theme-xs text-error-500 mt-4" role="alert">{{ $message }}</p>
    @enderror

    <form x-ref="formulir" method="POST" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="avatar" x-ref="berkas" class="sr-only" tabindex="-1" aria-hidden="true"
            accept="image/jpeg,image/png,image/webp" @change="pilih($event)" />
    </form>

    @if ($user->punyaFoto())
        <x-profile.hapus-foto :action="route('profile.avatar.destroy')" :tombol="false" />

        {{-- Lihat foto --}}
        <div x-show="lihatBuka" x-cloak @keydown.escape.window="lihatBuka = false" role="dialog" aria-modal="true"
            aria-label="Foto profil" class="fixed inset-0 z-99999 flex items-center justify-center p-5">
            <div @click="lihatBuka = false" class="fixed inset-0 bg-gray-900/80"></div>
            <div class="relative w-full max-w-sm">
                <img src="{{ $user->urlFoto() }}" alt="Foto profil {{ $user->fullname }}"
                    class="aspect-square w-full rounded-2xl object-cover" />
                <button type="button" @click="lihatBuka = false" aria-label="Tutup"
                    class="shadow-theme-lg absolute -top-3 -right-3 flex size-9 items-center justify-center rounded-full bg-white text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M5.3 5.3a1 1 0 0 1 1.4 0L10 8.6l3.3-3.3a1 1 0 1 1 1.4 1.4L11.4 10l3.3 3.3a1 1 0 0 1-1.4 1.4L10 11.4l-3.3 3.3a1 1 0 0 1-1.4-1.4L8.6 10 5.3 6.7a1 1 0 0 1 0-1.4Z" />
                    </svg>
                </button>
            </div>
        </div>
    @endif

    {{-- Atur foto: geser, perbesar, potong --}}
    <div x-show="potongBuka" x-cloak role="dialog" aria-modal="true" aria-labelledby="judul-atur-foto"
        @keydown.escape.window="potongBuka && ! menyimpan && batalPotong()"
        class="fixed inset-0 z-99999 flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-900/80"></div>

        <div class="relative w-full max-w-md rounded-2xl bg-white p-5 dark:bg-gray-900">
            <div class="mb-4 flex items-center justify-between">
                <h3 id="judul-atur-foto" class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">Atur foto</h3>
                <button type="button" @click="batalPotong()" :disabled="menyimpan" aria-label="Tutup" class="{{ $tombolIkon }}">
                    <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M5.3 5.3a1 1 0 0 1 1.4 0L10 8.6l3.3-3.3a1 1 0 1 1 1.4 1.4L11.4 10l3.3 3.3a1 1 0 0 1-1.4 1.4L10 11.4l-3.3 3.3a1 1 0 0 1-1.4-1.4L8.6 10 5.3 6.7a1 1 0 0 1 0-1.4Z" />
                    </svg>
                </button>
            </div>

            <div x-ref="bingkai" tabindex="0" aria-label="Posisi foto"
                @pointerdown.prevent="tekan($event)" @pointermove="gerak($event)"
                @pointerup="lepas($event)" @pointercancel="lepas($event)"
                @wheel.prevent="roda($event)"
                @keydown.arrow-left.prevent="geser(-10, 0)" @keydown.arrow-right.prevent="geser(10, 0)"
                @keydown.arrow-up.prevent="geser(0, -10)" @keydown.arrow-down.prevent="geser(0, 10)"
                class="focus-visible:ring-brand-500/40 relative aspect-square w-full cursor-grab touch-none overflow-hidden rounded-xl bg-gray-900 select-none focus:outline-hidden focus-visible:ring-4 active:cursor-grabbing">
                <img :src="sumber" alt="" draggable="false" :style="gayaGambar"
                    class="pointer-events-none absolute top-1/2 left-1/2 max-w-none" />
                <div class="pointer-events-none absolute inset-0 rounded-full shadow-[0_0_0_9999px_rgba(0,0,0,0.55)] ring-2 ring-white/80"></div>
            </div>

            <div class="mt-4 flex items-center gap-2">
                <button type="button" @click="aturZoom(zoom - 0.25)" :disabled="zoom <= 1" aria-label="Perkecil" class="{{ $tombolIkon }}">
                    <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M4.5 10a.75.75 0 0 1 .75-.75h9.5a.75.75 0 0 1 0 1.5h-9.5A.75.75 0 0 1 4.5 10Z" />
                    </svg>
                </button>
                <input type="range" min="1" :max="zoomMaks" step="0.01" :value="zoom" :disabled="zoomMaks <= 1"
                    @input="aturZoom($event.target.value)" aria-label="Perbesaran"
                    class="accent-brand-500 h-1.5 w-full cursor-pointer disabled:cursor-default" />
                <button type="button" @click="aturZoom(zoom + 0.25)" :disabled="zoom >= zoomMaks" aria-label="Perbesar" class="{{ $tombolIkon }}">
                    <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <path d="M10 4.5a.75.75 0 0 1 .75.75v4h4a.75.75 0 0 1 0 1.5h-4v4a.75.75 0 0 1-1.5 0v-4h-4a.75.75 0 0 1 0-1.5h4v-4A.75.75 0 0 1 10 4.5Z" />
                    </svg>
                </button>
            </div>

            <div class="mt-5 flex justify-end gap-3">
                <button type="button" @click="batalPotong()" :disabled="menyimpan"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 disabled:opacity-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </button>
                <button type="button" @click="simpan()" :disabled="menyimpan"
                    x-text="menyimpan ? 'Menyimpan…' : 'Simpan'"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-2.5 text-sm font-medium text-white transition disabled:opacity-50">
                    Simpan
                </button>
            </div>
        </div>
    </div>
</div>
