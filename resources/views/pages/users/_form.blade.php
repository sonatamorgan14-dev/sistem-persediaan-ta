{{--
    Formulir akun yang dipakai bersama halaman tambah dan ubah.

    $user     : App\Models\User  — instance kosong saat menambah
    $isEdit   : bool             — true pada halaman ubah
    $action   : string           — URL tujuan submit
--}}
@php
    $isSelf = $isEdit && $user->is(auth()->user());
@endphp

<form method="POST" action="{{ $action }}" novalidate>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
        <x-form.text-field name="fullname" label="Nama Lengkap" :value="$user->fullname" required
            placeholder="mis. Budi Santoso" autocomplete="name" />

        <x-form.text-field name="username" label="Nama Pengguna" :value="$user->username" required
            placeholder="mis. budi.santoso" autocomplete="off" />

        <x-form.text-field name="email" label="Email" type="email" :value="$user->email" required
            placeholder="nama@bpk.go.id" autocomplete="email" />

        <x-form.select-field name="unit" label="Unit Kerja" :options="\App\Enums\UnitKerja::options()"
            :selected="$user->unit?->value" required placeholder="Pilih unit kerja" />

        {{-- NIP dan jabatan tercetak di blok identitas BAST pengadaan, sama
             seperti nama lengkap, sehingga hanya Admin yang mengubahnya
             (ADR 0042). --}}
        <x-form.text-field name="nip" label="NIP" :value="$user->nip" inputmode="numeric"
            placeholder="mis. 198506142003121001" />

        <x-form.text-field name="position" label="Jabatan" :value="$user->position"
            placeholder="mis. Pengelola Pengadaan Barang/Jasa Ahli Pertama" />

        <x-form.select-field name="role" label="Peran" :options="\App\Enums\UserRole::options()"
            :selected="$user->role?->value" required placeholder="Pilih peran"
            :disabled="$isSelf" :help="$isSelf ? 'Peran akun sendiri tidak dapat diubah.' : null" />

        @if ($isSelf)
            {{-- Field yang dinonaktifkan tidak ikut terkirim; kirim nilai saat ini. --}}
            <input type="hidden" name="role" value="{{ $user->role->value }}" />
        @endif

        <div>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                Status Akun<span class="text-error-500">*</span>
            </label>

            <div x-data="{ active: {{ old('is_active', $user->is_active ?? true) ? 'true' : 'false' }} }"
                class="flex h-11 items-center">
                {{-- value awal jadi cadangan bila Alpine belum sempat memuat --}}
                <input type="hidden" name="is_active"
                    value="{{ old('is_active', $user->is_active ?? true) ? 1 : 0 }}" :value="active ? 1 : 0" />

                <button type="button" @click="active = !active" @disabled($isSelf)
                    :aria-pressed="active ? 'true' : 'false'"
                    class="flex items-center gap-3 {{ $isSelf ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
                    <span :class="active ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-700'"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors">
                        <span :class="active ? 'translate-x-5' : 'translate-x-1'"
                            class="inline-block size-4 rounded-full bg-white transition-transform"></span>
                    </span>
                    <span class="text-theme-sm text-gray-700 dark:text-gray-400"
                        x-text="active ? 'Aktif' : 'Nonaktif'"></span>
                </button>
            </div>

            @error('is_active')
                <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
            @else
                @if ($isSelf)
                    <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">Status akun sendiri tidak dapat diubah.</p>
                @endif
            @enderror
        </div>
    </div>

    <div class="mt-8 border-t border-gray-100 pt-6 dark:border-gray-800">
        <h4 class="text-theme-sm mb-5 font-medium text-gray-800 dark:text-white/90">
            {{ $isEdit ? 'Ubah Kata Sandi' : 'Kata Sandi' }}
        </h4>

        {{-- Syaratnya muncul begitu kata sandi diketik; pada halaman ubah,
             placeholder sudah mengatakan kolom ini boleh dikosongkan. --}}
        <x-form.syarat-sandi>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                <x-form.password-field name="password" label="Kata Sandi" :required="!$isEdit"
                    :placeholder="$isEdit ? 'Biarkan kosong bila tidak diubah' : 'Masukkan kata sandi'" />

                <x-form.password-field name="password_confirmation" label="Konfirmasi Kata Sandi"
                    :required="!$isEdit" placeholder="Ulangi kata sandi" />
            </div>
        </x-form.syarat-sandi>
    </div>

    <div class="mt-8 flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
        <a href="{{ route('users.index') }}"
            class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Batal
        </a>
        <button type="submit"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition">
            {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Akun' }}
        </button>
    </div>
</form>
