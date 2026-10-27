{{--
    Data akun yang dikelola Admin: ditampilkan, tidak dapat diubah di sini.
    Nama lengkap, NIP, dan jabatan tercetak di dokumen resmi, sedangkan
    sisanya menentukan cara masuk dan wewenang akun. Lihat ADR 0038 dan
    ADR 0042.

    Satu-satunya teks keterangan di halaman profil adalah kalimat "hubungi
    Admin" di bawah — pengguna perlu tahu ke mana harus meminta perubahan.
    Alasannya (tanda tangan dokumen, dsb.) tidak ditampilkan; lihat
    "UI copy" di CLAUDE.md.
--}}
@php
    $loginTerakhir = $user->last_login_at
        ? $user->last_login_at->translatedFormat('d F Y, H:i').($user->last_login_ip ? ' dari '.$user->last_login_ip : '')
        : 'Belum pernah';

    $baris = [
        'Nama lengkap' => $user->fullname,
        'NIP' => $user->nip ?? '-',
        'Jabatan' => $user->position ?? '-',
        'Nama pengguna' => $user->username,
        'Peran' => $user->role->label(),
        'Unit kerja' => $user->unit?->label(),
        'Status akun' => $user->is_active ? 'Aktif' : 'Nonaktif',
        'Terdaftar sejak' => $user->created_at?->translatedFormat('d F Y') ?? '-',
        'Login terakhir' => $loginTerakhir,
    ];
@endphp

<div class="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="mb-4 flex items-start justify-between gap-3">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Informasi Akun</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Hubungi Admin untuk mengubah data ini.
            </p>
        </div>
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400"
            title="Hanya dapat diubah Admin">
            <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M10 2a4 4 0 0 0-4 4v2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-1V6a4 4 0 0 0-4-4Zm2.5 6V6a2.5 2.5 0 0 0-5 0v2h5Z" />
            </svg>
        </span>
    </div>

    <dl class="divide-y divide-gray-100 dark:divide-gray-800">
        @foreach ($baris as $label => $nilai)
            <div class="grid grid-cols-1 gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="text-theme-sm font-medium break-words text-gray-800 sm:col-span-2 dark:text-white/90">{{ $nilai }}</dd>
            </div>
        @endforeach
    </dl>
</div>
