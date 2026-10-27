# Modul Profil Pengguna

Halaman akun milik pengguna yang sedang masuk: menampilkan data akunnya,
mengganti email dan kata sandi, serta mengunggah foto profil. **Seluruh peran**,
termasuk peran sub sistem Permintaan. Lihat
[ADR 0038](../keputusan/0038-profil-pengguna-dan-foto-profil.md).

## Siapa mengubah apa

| Data | Di halaman profil | Diubah oleh |
|---|---|---|
| Kata sandi | Formulir, wajib kata sandi saat ini | Pemilik |
| Email | Formulir, wajib kata sandi saat ini | Pemilik (juga Admin) |
| Foto profil | Unggah / ganti / hapus | Pemilik; Admin hanya menghapus |
| Nama lengkap, NIP, jabatan, nama pengguna, peran, unit, status | Baca saja | Admin, lewat Manajemen Pengguna (NIP dan jabatan sejak [ADR 0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md)) |
| Terdaftar sejak, login terakhir + IP | Baca saja | Sistem |

## Rute

Di grup `auth` **tanpa gate** — setiap akun berhak atas profilnya, dan tidak
ada parameter yang menunjuk akun lain kecuali rute penyaji foto.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/profil` | `profile` | Halaman profil |
| PATCH | `/profil/email` | `profile.email.update` | Ganti email |
| PUT | `/profil/kata-sandi` | `profile.password.update` | Ganti kata sandi |
| POST | `/profil/foto` | `profile.avatar.update` | Unggah / ganti foto |
| DELETE | `/profil/foto` | `profile.avatar.destroy` | Hapus foto sendiri |
| GET | `/profil/foto/{user}` | `profile.avatar.show` | Sajikan foto siapa pun kepada pengguna yang sudah masuk |
| DELETE | `/pengguna/{user}/foto` | `users.avatar.destroy` | Moderasi: Admin menghapus foto pengguna lain (`role:admin`) |

## Aturan bisnis

- Mengganti email atau kata sandi **wajib** menyertakan kata sandi saat ini.
- Kebijakan kata sandi sama dengan pembuatan akun (`Password::defaults()`:
  minimal 12 karakter, huruf besar dan kecil, angka, ditambah
  `uncompromised()` di produksi), dan kata sandi baru harus berbeda dari yang
  lama. Daftar syaratnya di layar datang dari komponen `x-form.syarat-sandi`,
  yang juga dipakai formulir pengguna milik Admin.
- Setelah kata sandi diganti, sesi di perangkat lain keluar otomatis pada
  request berikutnya (`AuthenticateSession`); sesi yang dipakai tetap masuk.
- Mengirim email yang sama tidak dianggap perubahan dan tidak dicatat.
- Foto disimpan di disk **privat** `avatars/` dengan nama UUID; berkas lama
  dibuang setelah baris menunjuk berkas baru. Menghapus akun ikut membuang
  berkas fotonya.
- Tanpa foto, atau bila berkasnya hilang dari disk, tampil avatar inisial.

## Foto profil

Mengeklik avatar membuka menu **Lihat foto / Ganti foto / Hapus foto** (bila
belum ada foto, langsung pemilih berkas). Foto yang dipilih dibuka di jendela
**Atur foto** — bingkai lingkaran, geser dengan seret, perbesar lewat slider,
tombol −/+, roda tetikus, atau cubitan, tombol panah untuk papan ketik — lalu
"Simpan" langsung mengunggah ([ADR 0039](../keputusan/0039-pemotong-foto-profil-interaktif.md)).

Pemotongan terjadi di peramban (`window.fotoProfil` di
`pages/profile/index.blade.php`): bagian yang tampak di bingkai digambar ke
kanvas 512 px dan disimpan ulang sebagai JPEG. Orientasi foto ponsel ikut
benar, metadata GPS terbuang, dan ukurannya ~100 KB. Perbesaran dibatasi 4×
dan sampai potongan tidak lebih kecil dari 128 piksel foto asli.

| Validasi server | Nilai |
|---|---|
| Format | JPG, PNG, WEBP (bukan HEIC) |
| Ukuran | Maks 2 MB |
| Dimensi | Minimal 96×96 piksel |

## Jejak audit

| Aksi | Modul | Event |
|---|---|---|
| Ganti email (email lama ikut dicatat) | `profil` | `ubah` |
| Ganti kata sandi | `profil` | `ubah` |
| Unggah / ganti foto | `profil` | `unggah` |
| Hapus foto sendiri | `profil` | `hapus` |
| Admin menghapus foto pengguna lain | `pengguna` | `hapus` |

Melihat foto tidak dicatat.

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Http/Controllers/ProfileController.php](../../app/Http/Controllers/ProfileController.php) | Halaman, email, kata sandi |
| [app/Http/Controllers/ProfileAvatarController.php](../../app/Http/Controllers/ProfileAvatarController.php) | Unggah, hapus, sajikan foto |
| [app/Http/Requests/Profile/](../../app/Http/Requests/Profile/) | `UpdateProfileEmailRequest`, `UpdateProfilePasswordRequest`, `UpdateProfileAvatarRequest` |
| [app/Support/AvatarStorage.php](../../app/Support/AvatarStorage.php) | Satu-satunya tempat foto disimpan, disajikan, dan dibuang |
| [app/Models/User.php](../../app/Models/User.php) | `punyaFoto()`, `urlFoto()` |
| [resources/views/pages/profile/](../../resources/views/pages/profile/) | `index`, `_identitas`, `_akun`, `_email`, `_kata-sandi` |
| [resources/views/components/ui/user-avatar.blade.php](../../resources/views/components/ui/user-avatar.blade.php) | Foto atau inisial, dipakai di header, dasbor, daftar dan ubah pengguna |
| [resources/views/components/profile/hapus-foto.blade.php](../../resources/views/components/profile/hapus-foto.blade.php) | Tombol + modal konfirmasi hapus foto |
| [tests/Feature/ProfileTest.php](../../tests/Feature/ProfileTest.php) | 31 uji |

## Catatan

- Halaman ini menggantikan halaman contoh TailAdmin (kartu profil, info
  pribadi, dan alamat berisi data palsu). Komponen `x-profile.*` bawaan
  template dihapus.
- Proyek belum memiliki `lang/id`, sehingga pesan kebijakan kata sandi ditulis
  di `UpdateProfilePasswordRequest::messages()`. Formulir Admin masih
  menampilkan pesan kebijakan bawaan berbahasa Inggris.
