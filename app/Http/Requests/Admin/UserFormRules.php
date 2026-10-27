<?php

namespace App\Http\Requests\Admin;

/**
 * Aturan, label, dan pesan validasi yang dipakai bersama oleh formulir
 * tambah dan ubah pengguna.
 */
class UserFormRules
{
    /**
     * Nama pengguna: huruf, angka, titik, tanda hubung, garis bawah.
     * Titik diizinkan agar pola seperti "budi.santoso" tetap bisa dipakai.
     */
    public const USERNAME_REGEX = '/^[A-Za-z0-9._-]+$/';

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'username' => 'nama pengguna',
            'fullname' => 'nama lengkap',
            'email' => 'email',
            'role' => 'peran',
            'unit' => 'unit kerja',
            'nip' => 'NIP',
            'position' => 'jabatan',
            'password' => 'kata sandi',
            'is_active' => 'status akun',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'username.regex' => 'Nama pengguna hanya boleh berisi huruf, angka, titik, tanda hubung, dan garis bawah.',
            'username.min' => 'Nama pengguna minimal :min karakter.',
            'username.unique' => 'Nama pengguna sudah dipakai akun lain.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah dipakai akun lain.',
            'role.enum' => 'Peran yang dipilih tidak dikenali.',
            'unit.enum' => 'Unit kerja yang dipilih tidak dikenali.',
            'nip.digits' => 'NIP harus 18 digit angka.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ];
    }
}
