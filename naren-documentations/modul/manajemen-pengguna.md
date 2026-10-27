# Modul Manajemen Pengguna

CRUD akun pengguna. **Khusus Admin.** Karena sistem tertutup tanpa pendaftaran
mandiri, modul inilah satu-satunya cara akun bertambah.

## Rute

Seluruhnya di bawah `middleware('role:admin')` — modul ini memang eksklusif satu
peran, sehingga `role:` tepat dipakai di sini.

| Metode | URL | Nama | Aksi |
|---|---|---|---|
| GET | `/pengguna` | `users.index` | Daftar + cari + saring + paginasi |
| GET | `/pengguna/create` | `users.create` | Formulir tambah |
| POST | `/pengguna` | `users.store` | Simpan |
| GET | `/pengguna/{user}/edit` | `users.edit` | Formulir ubah |
| PUT | `/pengguna/{user}` | `users.update` | Perbarui |
| PATCH | `/pengguna/{user}/status` | `users.toggle-status` | Aktifkan / nonaktifkan |
| DELETE | `/pengguna/{user}` | `users.destroy` | Hapus |
| DELETE | `/pengguna/{user}/foto` | `users.avatar.destroy` | Hapus foto profil pengguna (moderasi) |

## Aturan bisnis

- Admin **tidak dapat** mengubah peran, menonaktifkan, atau menghapus akunnya
  sendiri. Tombolnya disembunyikan di antarmuka **dan** ditolak di server.
- Akun yang sudah memiliki jejak audit, mutasi stok, stock opname, pengadaan,
  atau usulan pengadaan **tidak dapat dihapus**. Sistem menolak dengan menyebut
  jenis data penghalangnya dan menyarankan menonaktifkan. Lihat
  [ADR 0003](../keputusan/0003-arsip-alih-alih-hapus.md).
- Kata sandi bersifat opsional saat mengubah — kosongkan bila tidak diganti.
- Menonaktifkan akun memutus sesi aktifnya pada permintaan berikutnya.
- Setiap tambah, ubah, hapus, dan ubah status tercatat ke `audit_logs`.
- Admin dapat **menghapus** foto profil pengguna lain dari halaman Ubah
  Pengguna, tetapi tidak mengunggahnya — foto adalah pilihan pemilik akun.
  Menghapus akun ikut membuang berkas fotonya. Kata sandi, email, dan foto
  juga dapat diubah pemilik akun sendiri lewat [Profil](profil.md); nama
  lengkap, nama pengguna, peran, unit, dan status tetap hanya lewat modul ini.
  Lihat [ADR 0038](../keputusan/0038-profil-pengguna-dan-foto-profil.md).
- **NIP dan jabatan** (sejak 24 Oktober 2026) juga hanya lewat modul ini, karena
  tercetak di blok identitas BAST pengadaan bersama nama lengkap. Boleh kosong;
  BAST mencetak titik-titik untuk diisi tangan. Lihat
  [ADR 0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md).

## Validasi

| Field | Aturan |
|---|---|
| `username` | Wajib, 3–50 karakter, huruf/angka/titik/tanda hubung/garis bawah, unik |
| `fullname` | Wajib, maks 255 |
| `email` | Wajib, format email, unik |
| `role` | Wajib, harus salah satu `UserRole` |
| `unit` | Wajib, maks 255 |
| `nip` | Opsional, tepat 18 digit; spasi pengelompokan (`19850614 200312 1 001`) dibuang sebelum validasi |
| `position` | Opsional (jabatan), maks 255 |
| `password` | Wajib saat menambah, opsional saat mengubah; kebijakan kata sandi berlaku |
| `is_active` | Wajib, boolean |

Nama pengguna dinormalkan; titik diizinkan agar pola seperti `budi.santoso` dapat
dipakai — aturan `alpha_dash` bawaan Laravel menolak titik, sehingga diganti
dengan regex sendiri.

## Berkas terkait

| Berkas | Peran |
|---|---|
| [app/Http/Controllers/Admin/UserController.php](../../app/Http/Controllers/Admin/UserController.php) | Seluruh aksi |
| [app/Http/Requests/Admin/](../../app/Http/Requests/Admin/) | `StoreUserRequest`, `UpdateUserRequest`, `UserFormRules` |
| [resources/views/pages/users/](../../resources/views/pages/users/) | `index`, `create`, `edit`, `_form` |
| [app/Models/User.php](../../app/Models/User.php) | `blockingRelations()`, `hasRole()`, `initials()`, `punyaFoto()`, `urlFoto()` |

## Komponen yang lahir dari modul ini

Dipakai ulang oleh seluruh modul berikutnya:

- `x-form.text-field`, `x-form.select-field`, `x-form.password-field`
- `x-ui.flash` — banner pesan sukses dan gagal
- `x-ui.user-avatar` — foto profil atau avatar inisial (menyusul pada
  [ADR 0038](../keputusan/0038-profil-pengguna-dan-foto-profil.md))
- `resources/views/vendor/pagination/tailadmin.blade.php` — paginasi bergaya
  TailAdmin

## Catatan

Penjaga "Admin aktif terakhir" sempat ditulis lalu **dihapus** karena tidak
pernah dapat tereksekusi: pelaku aksi selalu Admin aktif lain, sedangkan
menghapus atau menonaktifkan diri sendiri sudah diblokir lebih dulu. Sistem tetap
terjamin memiliki minimal satu Admin.
