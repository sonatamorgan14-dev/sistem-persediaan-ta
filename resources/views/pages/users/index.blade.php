@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Manajemen Pengguna</h2>

        <a href="{{ route('users.create') }}"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
            <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M10 3.25a.75.75 0 01.75.75v5.25H16a.75.75 0 010 1.5h-5.25V16a.75.75 0 01-1.5 0v-5.25H4a.75.75 0 010-1.5h5.25V4a.75.75 0 01.75-.75z" />
            </svg>
            Tambah Pengguna
        </a>
    </div>

    <x-ui.flash />

    {{-- Ringkasan --}}
    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
        @foreach ([
            ['label' => 'Total Akun', 'value' => $summary['total']],
            ['label' => 'Akun Aktif', 'value' => $summary['aktif']],
            ['label' => 'Akun Nonaktif', 'value' => $summary['nonaktif']],
            ['label' => 'Akun Admin', 'value' => $summary['admin']],
        ] as $card)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90">{{ $card['value'] }}</h4>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        {{-- Penyaring --}}
        <form method="GET" action="{{ route('users.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800 sm:p-6">
            <div class="xl:col-span-2">
                <x-form.text-field name="q" label="Cari" :value="$keyword"
                    placeholder="Nama, nama pengguna, email, atau unit" />
            </div>

            <x-form.select-field name="role" label="Peran" :options="\App\Enums\UserRole::options()"
                :selected="$role" placeholder="Semua peran" />

            <x-form.select-field name="status" label="Status"
                :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" :selected="$status"
                placeholder="Semua status" />

            <div class="flex items-center gap-3 sm:col-span-2 xl:col-span-4">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if ($keyword !== '' || $role !== '' || $status !== '')
                    <a href="{{ route('users.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        {{-- Tabel --}}
        <div x-data="{ confirmOpen: false, confirmAction: '', confirmName: '' }">
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Pengguna', 'Peran', 'Unit Kerja', 'Status', 'Login Terakhir', 'Aksi'] as $heading)
                                <th class="px-5 py-3 text-left sm:px-6">
                                    <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $item)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4 sm:px-6">
                                    <div class="flex items-center gap-3">
                                        <x-ui.user-avatar :user="$item" class="text-theme-xs size-10" />
                                        <div>
                                            <span class="text-theme-sm block font-medium text-gray-800 dark:text-white/90">
                                                {{ $item->fullname }}
                                                @if ($item->is(auth()->user()))
                                                    <span class="text-theme-xs ml-1 text-gray-400">(Anda)</span>
                                                @endif
                                            </span>
                                            <span class="text-theme-xs block text-gray-500 dark:text-gray-400">
                                                {{ '@' . $item->username }} &middot; {{ $item->email }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $item->role->badgeClass() }}">
                                        {{ $item->role->label() }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $item->unit?->label() }}</p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span @class([
                                        'text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium',
                                        'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $item->is_active,
                                        'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => !$item->is_active,
                                    ])>
                                        {{ $item->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $item->last_login_at?->translatedFormat('d M Y, H:i') ?? 'Belum pernah' }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('users.edit', $item) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Ubah
                                        </a>

                                        @unless ($item->is(auth()->user()))
                                            <form method="POST" action="{{ route('users.toggle-status', $item) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                    class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                                    {{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                                </button>
                                            </form>

                                            <button type="button"
                                                @click="confirmOpen = true; confirmAction = @js(route('users.destroy', $item)); confirmName = @js($item->fullname)"
                                                class="text-theme-xs text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                                Hapus
                                            </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        Tidak ada akun yang cocok.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $users->links('vendor.pagination.tailadmin') }}

            {{-- Konfirmasi hapus --}}
            <div x-show="confirmOpen" x-cloak @keydown.escape.window="confirmOpen = false"
                class="fixed inset-0 z-99999 flex items-center justify-center p-5">
                <div @click="confirmOpen = false" class="fixed inset-0 bg-gray-900/50"></div>

                <div @click.stop class="relative w-full max-w-md rounded-2xl bg-white p-6 dark:bg-gray-900">
                    <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">Hapus akun?</h3>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Akun <span class="font-medium text-gray-700 dark:text-gray-300" x-text="confirmName"></span>
                        akan dihapus permanen. Tindakan ini tidak dapat dibatalkan.
                    </p>

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="confirmOpen = false"
                            class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            Batal
                        </button>
                        <form method="POST" :action="confirmAction">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                Ya, hapus
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
