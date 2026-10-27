# Catatan Progres

Urut dari yang terbaru. Satu entri untuk tiap sesi kerja yang menghasilkan
perubahan.

---

## 24 Oktober 2026 (lanjutan) — BAST pengadaan format resmi BPK

Narendra membawa format resmi Berita Acara Serah Terima Barang Persediaan BPK
Perwakilan Lampung. `pdf/bast-pengadaan.blade.php` ditulis ulang mengikutinya:
halaman 1 persis format aslinya (kop berlogo `logo_bpk_kop.jpeg`, tanggal
terbilang, data PIHAK PERTAMA/KEDUA dengan NIP dan jabatan, tanda tangan dua
kolom, tanpa footer; A4, margin 1,5/2 cm, Times 12 pt), halaman 2 lampiran
rincian barang yang diterima. Keputusannya di
[ADR 0042](keputusan/0042-bast-pengadaan-format-resmi-bpk.md), menggantikan
sebagian ADR 0021.

**Yang berubah.**

- **Pihaknya internal**: PIHAK PERTAMA = Pelaksana Belanja (`purchased_by`,
  cadangannya pembuat realisasi), PIHAK KEDUA = Petugas Persediaan
  (`received_by`). Penyedia pindah ke lampiran.
- **`users.nip` + `users.position`** (migrasi baru, tabel bersama — menunggu
  Alma). Diisi Admin di Manajemen Pengguna, NIP 18 digit dengan spasi dibuang;
  profil menampilkannya baca-saja. Seeder: NIP fiktif untuk `kasubbag`,
  `gudang`, `pengadaan`.
- **Nomor BAST pindah ke cetak pertama**: `procurement_receipts.bast_number`
  nullable, dibuang dari formulir konfirmasi penerimaan beserta saran
  berurutnya. Rute baru `PATCH pengadaan/{procurement}/bast`
  (`procurements.bast-number`, gate baru `isi-nomor-bast`: Pejabat Pengadaan +
  Pengelola Gudang). Dialog di kartu Penerimaan, kolom kosong tanpa saran, PDF
  terbuka di tab baru dan tab asal dimuat ulang saat dikunjungi lagi. Boleh
  dikoreksi sampai BAST bertanda tangan diunggah (`nomorBastDapatDiubah()`),
  unik, tercatat di audit.
- **Mutasi penerimaan** kini `reference_number` = judul realisasi, bukan nomor
  BAST (nomornya belum ada saat mutasi tercatat, dan mutasi immutable).
- `App\Support\Terbilang` baru; kalimat notifikasi `BastBelumDiunggah` tidak
  lagi menyebut nomor BAST; laporan pengadaan PDF menulis `-` bila belum ada.

**Temuan di luar cakupan.** Sejak master barang diganti data kantor (entri di
bawah), `ProcurementRequestSeeder` tidak menemukan kode `ATK-0xx` lagi, sehingga
realisasi contoh yang "diterima" lahir **tanpa satu pun rincian** — di basis
data utama juga. Lampiran BAST contoh karena itu kosong. Belum diperbaiki;
perlu memilih kode barang nyata untuk data contoh pengadaan.

**Verifikasi.** 608 test lulus (+17 `BastPengadaanTest`, +23 `TerbilangTest`,
+3 manajemen pengguna; blok "saran nomor BAST" diganti satu test). Pint bersih
untuk berkas yang diubah — `NotificationScanner.php` punya satu temuan gaya
yang **sudah ada sebelum** sesi ini, tidak disentuh. `migrate:fresh --seed`
berhasil di basis data uji terpisah (basis data utama hanya `migrate`, supaya
datanya tidak terhapus). PDF dirender dan dirasterisasi (PyMuPDF): A4, font
tersemat Times-Roman/Times-Bold, margin 2,00 cm kiri/kanan dan 1,50 cm atas,
tepat 2 halaman; dibandingkan visual dengan contoh BAST. Dialog nomor BAST
dicoba di Edge headless (Playwright, server kedua di port 8123 atas basis data
uji): dialog terbuka, PDF terbuka di tab baru, tab asal memuat ulang dan
menampilkan nomornya. Peristiwa `visibilitychange` disimulasikan — di mode
headless tab tidak pernah benar-benar tersembunyi — jadi perpindahan tab
sungguhan belum dicoba tangan. Galat JS `classList of null` yang muncul juga
muncul di `/pengadaan` dan `/barang`, jadi bukan dari sesi ini.

**Cacat yang ditemukan uji peramban.** Setelah nomor diisi, "Tanggal Diterima"
bergeser dari 19 ke 24 Oktober. Penyebabnya MariaDB 10.4 XAMPP
(`explicit_defaults_for_timestamp = 0`) diam-diam memasang
`ON UPDATE CURRENT_TIMESTAMP` pada kolom TIMESTAMP NOT NULL pertama tabel —
`procurement_receipts.received_at`. Tidak pernah terasa karena baris
penerimaan dulu tidak pernah diubah; pengisian nomor BAST adalah update
pertamanya, dan tanggal itulah yang tercetak terbilang di BAST. Diperbaiki
migrasi `2026_10_24_000003` (DEFAULT eksplisit, tanpa ON UPDATE), diverifikasi
ulang di peramban. Pest (SQLite) tidak dapat menangkapnya. Kolom lain yang kena
hanya `audit_logs.timestamp` — tabel bersama, dan barisnya immutable sehingga
ON UPDATE-nya tak pernah terpicu; dibiarkan.

---

## 24 Oktober 2026 — Master barang dan saldo awal dari data kantor

`ItemSeeder` kini membaca `database/seeders/data/barang.csv` (2.742 barang) dan
`saldo_awal_2026_10_01.csv` (324 barang, total 10.560). Data contoh ATK-xxx
dibuang. Barang bersaldo dicatat lewat `StockLedger` sebagai mutasi
`SaldoAwal` bertanggal 1 Oktober 2026 atas nama Pengelola Gudang; 2.418 barang
tanpa saldo didaftarkan dengan stok nol dan langsung diarsipkan. Semua barang
memakai batas default stok minimum 1, maksimum 1.000. Seeder tetap idempoten
(`firstOrCreate`, saldo hanya untuk barang yang baru dibuat).

`SatuanBarang` mendapat 30 case baru sesuai kolom `unit` CSV, termasuk `Dummy`
(satuan placeholder dari sumber data, 150 barang, 32 di antaranya bersaldo).
Dua test "menolak satuan di luar daftar" memakai `karung` sebagai contoh tak
sah — kini sah, diganti `ember`.

**Catatan.** `StockMutationSeeder`, `StockOpnameSeeder`, dan seeder pengadaan
masih membuat data contoh di atas barang yang kini nyata — saldo awal
tertimpa mutasi rekaan.

**Verifikasi.** `migrate:fresh --seed` berhasil: 2.742 barang, 324 aktif,
324 mutasi saldo awal berjumlah 10.560. 566 test lulus; Pint bersih untuk
berkas yang diubah.

---

## 5 Oktober 2026 (lanjutan) — Seluruh UI gudang, pengadaan, dan Admin dibersihkan

Cicilan pedoman "UI copy: clean by default" (CLAUDE.md, 1 Oktober) dituntaskan
sekaligus untuk semua kelompok halaman yang tersisa: pengguna, barang, mutasi,
opname, log aktivitas, notifikasi (beserta lonceng), penyedia, usulan
pengadaan, usulan barang baru, realisasi pengadaan, dan pesan flash
controller. Tanpa ADR — ini pelaksanaan pedoman yang sudah dicatat, bukan
keputusan baru. Halaman Alma (`pages/request/*`) dan halaman demo TailAdmin
tidak disentuh.

**Yang dibuang.** Subjudul di bawah judul halaman; `help=` yang berisi format,
alasan, atau akibat samping; kalimat "Wajib diisi…" (tanda bintang sudah
menandainya) dan label "(opsional)"; paragraf alasan di kartu dan formulir;
ekor penjelasan di dialog konfirmasi. Tooltip `title` penjelas di kepala tabel
dan kartu ringkasan (mutasi, opname, log aktivitas) ikut dibuang — pedomannya
membuang penjelasan, bukan memindahkannya ke tooltip. Alasan yang masih
berguna bagi pengembang dipindah ke komentar Blade.

**Yang dipertahankan.** Teks terkunci beserta jalan keluarnya (peran/status
akun sendiri, stok yang tak dapat diubah di formulir barang); dua teks wajib
dari tabel gotcha — mengapa usulan Disetujui tak punya tombol Realisasikan
(ADR 0024) dan bahwa menyimpan realisasi yang ditolak berarti mengajukannya
ulang (ADR 0030), keduanya dipersingkat; peringatan tak terurungkan di dialog
hapus/batal; "Isi 0 bila tidak dibatasi" pada stok maksimum (maknanya tidak
tertebak); dua peringatan kehilangan data ("Simpan draf sebelum memposting"
pada opname, "Menyimpan daftar kosong akan membuang draf ini" pada draf
usulan); keadaan kosong; angka-angka sekunder.

**Yang diubah bentuk, bukan sekadar dibuang.**

- Ringkasan riwayat penyedia menjadi deret angka ("1 pengadaan berjalan ·
  1 dibatalkan · 1 menunggu perbaikan") — yang dibatalkan dan ditolak tetap
  tampil terpisah dan tidak ikut dihitung (ADR 0026, ADR 0030).
- Kalimat "barang yang belum ada di master barang harus diusulkan lebih dulu"
  di formulir realisasi diganti tautan **Usulkan barang baru** yang muncul
  tepat saat pencarian barang tidak menemukan apa pun (formulir buat dan ubah).
- Keterangan tahap Proses Belanja kini hanya tampil bagi yang tidak memegang
  tombol tandai (gudang, Kasubbag). Pejabat Pengadaan cukup melihat tombolnya.
- Dialog batal realisasi hanya menyebut akibat tak terurungkan. Kalimat
  "usulan asalnya tidak dapat direalisasikan ulang" kini hanya muncul pada
  realisasi berusulan — **sebelumnya ikut tampil pada realisasi langsung**,
  yang tidak punya usulan sama sekali.
- Pilihan penyaring Sifat di `/notifikasi` menjadi "Perlu tindakan" /
  "Aktivitas", tanpa istilah teknis "(keadaan)" / "(peristiwa)".
- Formulir pengguna milik Admin masih menampilkan teks syarat kata sandi yang
  ditulis tangan dan **masih menyebut simbol** sejak syarat itu dibuang
  1 Oktober. Kini memakai daftar centang yang sama dengan profil, lewat
  komponen baru `x-form.syarat-sandi` yang membaca
  `Password::defaults()->appliedRules()` sendiri; profil ikut memakainya,
  sehingga `kebijakanSandi` dibuang dari `ProfileController`.

**Pesan flash.** Ekor penjelasan dibuang dari sembilan pesan (usulan barang
diajukan, opname dibuat/diposting, realisasi dibuat/disetujui/ditolak, draf
dibuang, ditandai dibelanjakan, penerimaan dikonfirmasi). Pesan ubah realisasi
tetap menyebut akibatnya — "perlu disetujui ulang" / "diajukan ke Kasubbag
Umum dan TI" — karena menyimpan memang mengulang persetujuan (ADR 0030).
Pesan galat beserta jalan keluarnya tidak diubah.

**Ikut dirapikan.** Variabel `jumlahBarang` di `StockOpnameController::create()`
tidak terpakai lagi dan dibuang. `description()` pada `ProcurementRequestSource`,
`ItemProposalStatus`, dan `AuditModule` kini tidak tampil di layar mana pun,
tetapi dibiarkan sesuai konvensi enum. `modul/autentikasi.md` dan
`modul/profil.md` masih menyebut syarat simbol — diperbaiki;
`modul/pengadaan.md` disesuaikan dua kalimat.

**Kesalahan kecil di tengah jalan.** Heredoc panjang berisi skrip
penggantian terpotong lagi (gotcha yang sudah tercatat); skripnya ditulis ke
berkas di folder scratch. Saat *mutation-check*, cadangan view tersimpan ke
`$TMP` (terdefinisi di Git Bash) alih-alih folder scratch, sehingga pemulihan
pertama gagal; view dipulihkan dari `$TMP`, diperiksa dengan grep, dan ujinya
dijalankan ulang.

**Uji dan pemeriksaan.** Seluruh suite 566 lolos (562 + 4 baru): syarat kata
sandi di formulir Admin mengikuti kebijakan dan skripnya termuat sekali,
tautan usulan barang baru di formulir realisasi langsung, dialog batal
realisasi langsung tanpa "usulan asal", dan dialog batal realisasi berusulan
yang tetap memperingatkannya. Satu uji proses belanja kini juga memastikan
keterangannya tidak tampil bagi Pejabat Pengadaan. Lima asersi teks lama
disesuaikan dengan kalimat baru. Dua *mutation-check* (memaksa kalimat "usulan
asal" selalu tampil, membuang `@cannot`) membuat uji baru gagal. Pint terbatas
bersih (15 berkas PHP); `npm run build` berhasil. Tidak ada tangkapan layar
peramban pada sesi ini — perubahan tata letaknya hanya membuang paragraf, dan
setiap halaman tetap dirender oleh uji.

---

## 5 Oktober 2026 (lanjutan) — Kasubbag Umum dan TI sebagai pengawas baca-saja

Kasubbag kini membuka seluruh menu gudang dan pengadaan seperti Admin, tanpa
Manajemen Pengguna dan Log Aktivitas, dan **hanya membaca serta mencetak** —
satu-satunya hal yang bisa ia ubah tetap keputusan persetujuan realisasi.
Lihat [ADR 0041](keputusan/0041-kasubbag-pengawas-baca-saja.md).

- Tiga gate baru: `akses-gudang` (Pengelola Gudang + Kasubbag),
  `akses-penyedia` (Pejabat Pengadaan + Kasubbag), dan
  `unggah-berkas-pengadaan` (Pejabat Pengadaan + Pengelola Gudang).
- Rute barang, mutasi, opname, dan penyedia dipisah baca/tulis; grup baca
  didaftarkan sesudah grup tulis supaya `…/create` tidak tertelan rute
  berparameter. Gate `kelola-*` tidak berubah.
- Tombol tulis di halaman barang, mutasi, opname, dan penyedia dibungkus
  `@can`; kolom Aksi hilang bila kosong. Draf opname tampil "Lihat" bagi
  Kasubbag. Tidak ada spanduk "baca-saja".
- Sidebar: grup Pengadaan dan Manajemen Gudang terbuka bagi Kasubbag; tiga
  sub-item kembar di grup Manajemen Gudang kini khusus Pengelola Gudang, jadi
  Admin dan Kasubbag tidak lagi melihat "Usulan Pengadaan", "Realisasi
  Pengadaan", dan "Usulan Barang Baru" dua kali.
- Dasbor mengikuti gate baca baru (ADR 0040): Kasubbag mendapat 8 ubin, kondisi
  stok bertaut, dan kedua grafik; antreannya tetap hanya persetujuan.

**Celah yang ditemukan dan ditutup.** Rute unggah berkas pengadaan dijaga
`akses-pengadaan`, yang sejak ADR 0030 ikut meloloskan Kasubbag — jadi
penilai realisasi bisa melampirkan BAST, SPK, dan foto belanja ke realisasi
yang ia nilai sendiri. ADR 0022 ditulis saat gate itu baru berisi dua peran
pelaksana dan tidak pernah ditinjau ulang. Kini rute dan form request-nya
memakai `unggah-berkas-pengadaan`; ADR 0022 berstatus *sebagian digantikan*.

**Uji dan pemeriksaan.** `tests/Feature/Auth/PengawasanKasubbagTest.php` baru,
16 uji: gate, seluruh rute baca 200 bagi Kasubbag, seluruh halaman formulir
dan aksi tulis 403 (18 aksi, data tidak berubah), unggah ditolak tanpa berkas
tersimpan, pelaksana tetap bisa mengunggah, tombol tulis tersembunyi bagi
Kasubbag tetapi tampil bagi pelaksana, sidebar tanpa administrasi, tautan
pengadaan tepat sekali di sidebar Admin/Kasubbag/Gudang, dan urutan rute
`…/create`. Dua mutation-check: mengembalikan gate unggah ke
`akses-pengadaan`, dan memaksa kolom Aksi penyedia tampil — keduanya membuat
uji gagal. Dataset Kasubbag di `DashboardTest` disesuaikan. Seluruh suite 562
lolos; `npm run build` berhasil. Pint terbatas bersih kecuali satu temuan
**lama** di `MenuHelper.php` (baris "Daftar Permintaan" milik Alma: spasi di
ujung baris dan koma), tidak disentuh. Tangkapan layar Edge headless akun
`kasubbag` (dasbor, master barang, realisasi menunggu persetujuan) terhadap
SQLite sementara seperti sesi sebelumnya.

---

## 5 Oktober 2026 — Dasbor berbasis peran dari data nyata

Dasbor lama ditulis ulang: semua angka dummy dan kalimat penjelas dibuang,
diganti lima bagian yang menyesuaikan gate pengguna — akses cepat (ubin modul
berikon dan berwarna kontras), **Perlu Ditindaklanjuti** (antrean beban kerja,
hanya baris > 0), **Kondisi Stok** (bilah tersegmen + lima barang paling
mendesak), **Alur Pengadaan** (enam tahap dengan jumlah), dan grafik mutasi
masuk/keluar serta nilai pengadaan diterima per bulan. Setiap angka yang
bisa diklik menautkan filter daftar yang menghasilkan angka yang sama. Lihat
[ADR 0040](keputusan/0040-dasbor-berbasis-peran-dari-data-langsung.md) dan
[modul/dasbor.md](modul/dasbor.md).

Keputusan pengguna saat perencanaan: grafik sesuai peran, strip alur
pengadaan ditampilkan, dan baris permintaan barang milik Alma diisi minimal
memakai status yang sudah dipakai notifikasinya — tanpa menyentuh berkasnya.

**Bug lama yang ikut hilang.** Metrik "Permintaan Menunggu" memakai
`whereNot('status', 'rejected')->orWhereNot('status', 'completed')`, yang
selalu benar — angkanya adalah seluruh permintaan. Diganti baris antrean per
peran dengan cakupan yang sama dengan `RequestController::index()`.

**Satu perubahan dari rencana.** Tahap akhir alur menjadi "Diterima" (total),
bukan "Diterima bulan ini": filter tanggal daftar realisasi menyaring
`procurement_date`, bukan tanggal terima, sehingga angka bulanan tidak bisa
ditautkan ke daftar yang sama.

**Kesalahan di tengah jalan.** Hitungan grafik mula-mula menaikkan elemen
`Collection` dengan `$koleksi[$kunci]++`; `ArrayAccess` menolak inkremen
("Indirect modification of overloaded element"), padahal `+=` di grafik nilai
lolos — jadi hanya uji grafik mutasi yang menangkapnya (500). Diganti array
biasa. Uji juga sempat mengharapkan singkatan "Agu"; Carbon berbahasa
Indonesia memakai "Agt". Lalu tangkapan layar menunjukkan batang grafik
mutasi ~42 px: lebar piksel `columnWidth` ApexCharts berlaku **per batang**,
bukan per kelompok. Disamakan 24 px.

**Uji dan pemeriksaan.** `tests/Feature/DashboardTest.php` baru, 27 uji (tamu
→ `/login`, keenam peran → 200 termasuk Admin, isi bersih, akses cepat per
gate, antrean tiap peran termasuk cakupan permintaan, sebaran dan urutan stok,
tautan barang hanya bagi `kelola-gudang`, alur tanpa draf, grafik per bulan,
nilai dari `amount_received`, grafik per peran). Mutation-check: mengikutkan
`SaldoAwal` membuat uji grafik gagal. `RoleAccessTest` disesuaikan — asersi
"Gudang tidak melihat menu Persetujuan" kini mencocokkan `href` relatif khas
sidebar, karena dasbor menautkan filter yang sama secara sah. Seluruh suite
546 lolos; Pint terbatas bersih; `npm run build` berhasil. Palet grafik diuji
dengan validator palet (CVD dan kontras, terang dan gelap).

Tangkapan layar Edge headless (CDP) untuk `gudang` (terang, 1440 px), `admin`
(gelap, 1440 px), `pengadaan` (ponsel, 390 px — tanpa gulir horizontal), dan
`pengguna`, terhadap **SQLite sementara di folder scratch** yang diisi
`migrate:fresh --seed` — MySQL XAMPP sedang mati, dan basis data dev tidak
disentuh sama sekali. `TypeError` tema di `<head>` (temuan 1 Oktober) masih
muncul di konsol, tetap tidak disentuh.

---

## 1 Oktober 2026 (lanjutan) — Kotak pencarian bawaan template dibuang dari header

Kotak "Search or type command..." (beserta tombol ⌘K) di
`layouts/app-header.blade.php` hanyalah hiasan bawaan TailAdmin: `<form>`
tanpa `action`, tidak ada skrip yang menanganinya, dan tidak ada uji yang
bergantung padanya. Dibuang seluruhnya atas permintaan pengguna. Uji
`ProfileTest` dan `NotificationTest` (merender layout yang sama): 63 lolos.

---

## 1 Oktober 2026 (lanjutan) — UI profil dibersihkan, sandi tanpa simbol, pemotong foto

Tiga permintaan susulan pada hari yang sama:

1. **Kata sandi tidak lagi mewajibkan simbol** — `->symbols()` dibuang dari
   `Password::defaults()`, berlaku juga di formulir Admin. Atas permintaan
   pengguna, tanpa ADR.
2. **Tampilan profil dibersihkan dari kalimat penjelasan.** Satu-satunya
   keterangan yang tersisa adalah "Hubungi Admin untuk mengubah data ini."
   Daftar syarat kata sandi baru muncul saat mengetik. Pedomannya — apa yang
   dipertahankan, apa yang dibuang — dan daftar cicilan halaman lain dicatat
   di bagian "UI copy" CLAUDE.md, untuk dikerjakan per kelompok halaman.
3. **Pemotong foto interaktif seperti WhatsApp** — jendela "Atur foto" dengan
   bingkai lingkaran, geser, perbesar, cubit; menu Lihat / Ganti / Hapus pada
   avatar. Ditulis sendiri dengan Alpine, tanpa pustaka. Lihat
   [ADR 0039](keputusan/0039-pemotong-foto-profil-interaktif.md); ADR 0038
   berstatus *sebagian digantikan*.

**Kesalahan kecil di tengah jalan.** Heredoc bash yang memuat seluruh skrip
pemotong terpotong oleh lapisan tool ("unexpected EOF") — gotcha yang memang
sudah tercatat di CLAUDE.md. Skripnya ditulis ke berkas lalu disambung.

**Uji dan pemeriksaan.** `ProfileTest` dan `UserManagementTest`: 53 lolos
(satu uji baru untuk menu avatar; satu uji simbol dihapus). Hitungan pemotong
diuji di Node dengan elemen tiruan: batas geser tanpa celah, perbesaran di
sekitar pusat, cubitan tanpa loncatan saat jari diangkat, perubahan ukuran
layar, koordinat potongan. `npm run build` berhasil.

**Kesalahan yang ditemukan pengguna, lalu dikoreksi.** Versi pertama pemotong
menampilkan pratinjau kosong dan mengunggah JPEG putih polos. Penyebabnya
urutan waktu Alpine: `x-show` menampilkan modal pada *animation frame*
berikutnya, sedangkan `$nextTick` — tempat bingkai diukur — selesai lebih dulu
lewat `setTimeout`. Lebar bingkai terbaca 0, skala 0, foto disembunyikan, dan
koordinat potongan menjadi `NaN` sehingga kanvas hanya berisi latar putih. Uji
Node tidak menangkapnya karena lebar bingkainya diisi tangan. Diperbaiki
dengan mengukur lewat `ResizeObserver` (terpicu tepat saat bingkai mendapat
ukuran, juga saat layar berubah), dan `simpan()` kini menolak mengirim bila
skalanya masih 0.

Dibuktikan ujung-ke-ujung di **Edge headless lewat Chrome DevTools Protocol**
terhadap server dev: masuk sebagai `gudang`, memilih gambar uji 800×600
(kiri merah, kanan biru), menyeret dengan tetikus 150 px, menyimpan. Bingkai
terukur 408 px dan foto tampil; seretan berhenti tepat di tepi foto (68 px);
berkas tersimpan JPEG 512×512 dengan kiri dan tengah merah, kanan biru —
persis potongan yang dipilih. Foto uji dihapus lagi (baris audit unggah dan
hapusnya tetap tersisa di basis data lokal). Cubitan dua jari di layar sentuh
masih hanya teruji lewat hitungan di Node.

Temuan sampingan, **tidak disentuh**: skrip tema di `<head>` kedua layout
memanggil `document.body.classList` sebelum `<body>` ada, sehingga setiap
halaman melempar `TypeError` di konsol sejak 1 September 2026.

---

## 1 Oktober 2026 — Profil pengguna dan foto profil

Menu "Profil Saya" selama ini merender halaman contoh TailAdmin berisi data
palsu, dan **tidak ada satu pun pengguna yang dapat mengganti kata sandinya
sendiri** — kata sandi awal buatan Admin tidak pernah berganti. Permintaan
pengguna: profil fungsional dari data `users`, swalayan untuk sebagian data
pribadi, foto profil dengan gambar bawaan, sekaligus pendapat tentang data
mana yang pantas diubah sendiri.

**Rencana diajukan lebih dulu**, dengan empat pertanyaan keputusan; pengguna
memilih seluruh rekomendasi:

1. Nama lengkap **hanya lewat Admin** — ia tercetak di blok tanda tangan BAST
   dan laporan, dan PDF dirender ulang dari data terkini.
2. Foto di kolom baru `users.avatar_path`, bukan path tetap tanpa kolom —
   dengan konsekuensi perlu persetujuan Alma.
3. Gambar bawaan berupa **avatar inisial**, bukan siluet generik.
4. Dari tambahan opsional, hanya **moderasi foto oleh Admin**. Riwayat
   aktivitas sendiri dan info login sebelumnya tidak diambil.

Lihat [ADR 0038](keputusan/0038-profil-pengguna-dan-foto-profil.md) dan
[modul/profil.md](modul/profil.md).

**Yang dikerjakan.** Migrasi `avatar_path`; `ProfileController` (halaman,
email, kata sandi) dan `ProfileAvatarController` (unggah, hapus, sajikan);
tiga form request di `Requests/Profile/`; `AvatarStorage` meniru
`DocumentStorage` di disk privat; `User::punyaFoto()`/`urlFoto()`; kasus
`AuditModule::Profil`. Halaman profil ditulis ulang menjadi empat kartu —
identitas + foto, informasi akun (baca saja), email, kata sandi dengan daftar
syarat yang tercentang saat mengetik. Foto diolah di peramban (potong tengah,
512 px, JPEG) sebelum dikirim. Komponen `x-ui.user-avatar` menggantikan empat
lingkaran inisial yang ditulis tangan; `x-profile.hapus-foto` dipakai di
profil dan di halaman Ubah Pengguna (yang juga baru mendapat `x-ui.flash`).
`UserController::destroy()` ikut membuang berkas foto. Komponen `x-profile.*`
bawaan template dan `pages/profile.blade.php` lama dihapus.

**Temuan di tengah jalan.**

- Ekstensi `exif` PHP tidak aktif di mesin pengembangan. Memotong foto dengan
  GD di server akan menyimpan foto potret dari ponsel dalam keadaan miring —
  inilah yang memindahkan pengolahan foto ke peramban.
- Proyek belum punya `lang/id`, sehingga pesan `Password::defaults()` keluar
  berbahasa Inggris, **termasuk di formulir Admin yang sudah ada**. Formulir
  profil membawa pesannya sendiri; formulir Admin belum disentuh.
- Pint menandai urutan `use` di `routes/web.php` — impor `Profile*` semula
  disisipkan sebelum `Pengadaan\*`. Diperbaiki.

**Uji dan pemeriksaan.** `php artisan test`: **519 lolos** (488 + 31 baru di
`ProfileTest`, termasuk uji bahwa sesi lain yang memegang hash kata sandi lama
dikeluarkan). Pint terbatas pada 12 berkas PHP yang disentuh: bersih.
`migrate:fresh --seed` di MySQL dan `npm run build` berhasil. Skrip pengolah
foto diperiksa sintaksnya dengan Node, dan logika syarat kata sandinya
dijalankan terhadap contoh; **pengolahan kanvasnya sendiri belum dicoba di
peramban sungguhan.**

**Masih terbuka:** persetujuan Alma atas `users.avatar_path`; uji manual unggah
foto ponsel berorientasi potret; batas unggah PHP di server tetap perlu
dinaikkan untuk foto belanja (butir lama), meski foto profil tidak
memerlukannya.

---

## 27 September 2026 (lanjutan) — Kuota lonceng, pengingat ulang, peristiwa berulang

Lanjutan langsung sesi ADR 0036, menutup tiga hal yang sengaja dibiarkan
terbuka di sana: "Aktivitas Terbaru" dapat kosong (selisih nomor 2), keadaan
yang sudah dibaca tidak pernah menagih lagi (konsekuensi yang diterima ADR 0036),
dan peristiwa yang terulang tidak terdengar (selisih nomor 3).

**Rencana diajukan lebih dulu**, dengan tiga pertanyaan keputusan; pengguna
menyetujui seluruh rekomendasinya:

1. Pengingat ulang menurut tingkat — Mendesak tiap pagi, Perlu Perhatian tujuh
   hari setelah dibaca, Informasi tidak pernah — bukan Mendesak saja. Alasan
   penentunya: `RealisasiMenungguPersetujuan` tidak punya keadaan lanjutan
   seperti `UsulanMenggantung`, jadi tanpa pengingat Perlu Perhatian, Kasubbag
   yang sekali membacanya lalu lupa tidak pernah ditagih siapa pun.
2. Peristiwa yang terulang memakai baris lama tetapi disetel ulang, bukan satu
   baris per kejadian.
3. Filter **Sifat** dan tautan "Lihat N lainnya" ikut dikerjakan.

Lihat [ADR 0037](keputusan/0037-kuota-lonceng-pengingat-ulang-dan-peristiwa-berulang.md);
ADR 0036 berstatus *sebagian digantikan*.

**Yang dikerjakan.** `NotificationDropdown` mengambil kedua bagian dengan kueri
terpisah; aktivitas diurutkan menurut waktu saja, keduanya dengan UUID sebagai
penentu seri; hitungan per jenis dalam satu kueri `GROUP BY` untuk badge dan
jumlah tiap bagian. Scope baru `keadaan()`, `peristiwa()`, `terbaru()` pada
`Notification`, dan `NotificationType::keadaan()`. `NotificationLevel::hariIngatkanUlang()`
dan `Notifier::ingatkanUlang()`, dipanggil `notifikasi:periksa` sesudah
pemeriksaan. `catatPeristiwa()` menyetel ulang `read_at`/`created_at`;
`terapkanKeadaan()` tidak. Penyaring Sifat di halaman notifikasi.
`bersihkan()` kini memakai scope `peristiwa()`.

**Kesalahan yang ditemukan dan dikoreksi di tengah jalan.** Uji pengingat
ulang mula-mula memakai waktu relatif (`now()->subDay()`, `now()->subDays(7)`).
Saat dibuktikan dengan memasang sementara ambang 24 jam, ujinya memang gagal —
tetapi karena alasan yang salah: kolom waktu hanya menyimpan sampai detik, jadi
"dibaca tepat 24 jam lalu" jatuh di detik yang sama dengan ambangnya. Diganti
waktu tepat di batas hari (`endOfDay()` kemarin, `startOfDay()` hari ini, dan
kedua sisi batas hari ketujuh), lalu dibuktikan ulang: ambang 24 jam dan ambang
yang meleset sehari sama-sama tertangkap. Mematikan reset peristiwa dan
melewatkan `ingatkanUlang()` juga masing-masing tertangkap.

**Uji dan pemeriksaan.** `php artisan test`: **488 lolos** (479 + 9 baru; satu
di antaranya alur nyata "ditolak dua kali" di `ProcurementApprovalTest`). Pint
terbatas pada 9 berkas PHP yang disentuh: bersih. Kueri lonceng yang baru
dijalankan hanya-baca terhadap MySQL lokal sebagai `admin`: angka badge sama
dengan jumlah belum dibaca di basis data.

**Masih terbuka:** notifikasi Permintaan milik Alma (selisih nomor 5) — Alma
perlu diberi tahu, dan kini juga bahwa keadaan Perlu Perhatian di sisi
Pengadaan diingatkan ulang sepekan sekali, sementara "Permintaan menunggu
persetujuan" miliknya, karena berupa peristiwa, tidak.

---

## 27 September 2026 — Lonceng sebagai kotak masuk; notifikasi yang selesai dihapus

Sesi dibuka dengan permintaan menjelaskan mekanisme notifikasi sebelum
perilakunya diubah. Penjelasan itu sekaligus menjadi audit, dan menemukan
**lima selisih** antara kode dan rancangan:

1. Peristiwa tidak pernah hilang dari lonceng — dokumentasi dan ADR 0013
   bilang "hilang saat dibaca", tetapi lonceng hanya menyaring `resolved_at`,
   yang pada peristiwa selamanya null. Tidak ada uji atas isi lonceng.
2. Lonceng mengambil 10 baris **sebelum** membaginya ke dua bagian, sehingga
   "Aktivitas Terbaru" bisa kosong bila keadaan menghabiskan kuota.
3. Peristiwa yang terulang pada subjek yang sama memakai baris lama tanpa
   menyetel ulang `read_at`/`created_at` — realisasi yang ditolak dua kali,
   penolakan keduanya tidak terdengar.
4. Badge menghitung keadaan yang sudah pulih tetapi belum dibaca, yang tidak
   lagi ditampilkan lonceng.
5. Tiga notifikasi Permintaan (Alma) menuntut tindakan tetapi berupa peristiwa;
   dan `PermintaanDitolak` tidak punya penerima sama sekali.

**Permintaan pengguna**, empat butir: "Tandai semua dibaca" membuat lonceng
benar-benar bersih sementara isinya tetap dapat dilihat di halaman notifikasi;
lonceng tidak menampilkan kalimat lengkap; baris yang selesai langsung dihapus
sehingga status "Sudah selesai" tidak diperlukan; peristiwa dapat selesai saat
diklik, lewat "Tandai semua dibaca", lewat "Tindak Lanjuti", dan sendiri
setelah tujuh hari.

**Bentrokan yang ditanyakan sebelum membangun.** Butir 3 + 4 dibaca harfiah
berarti peristiwa yang ditandai dibaca langsung dihapus — padahal butir 1
meminta ia tetap terlihat di halaman notifikasi. Pengguna memilih:
**peristiwa yang dibaca disimpan sampai berumur tujuh hari**, lalu dihapus
bersama yang belum dibaca.

**Keputusan rancangan yang diambil tanpa ditanyakan**, dicatat di ADR berikut
alternatifnya: lonceng berisi **yang belum dibaca saja** — satu aturan untuk
kedua sifat, sehingga keadaan yang diklik satu per satu pun ikut menyingkir
dari lonceng walau kondisinya masih berlangsung (bukan penanda `dismissed_at`
terpisah). Kolom `resolved_at` **dibuang**, bukan dibiarkan selalu null.
Batas tujuh hari hanya ditegakkan pemeriksaan terjadwal, bukan juga saat kueri,
sejalan dengan ADR 0014.

Lihat [ADR 0036](keputusan/0036-lonceng-kotak-masuk-dan-notifikasi-selesai-dihapus.md);
ADR 0013 berstatus *sebagian digantikan*.

**Yang dikerjakan.** Migrasi `2026_09_27_000001_drop_resolved_at_from_user_notifications_table`.
`Notifier::selesaikan()` kini menghapus; logika "kambuh" dibuang karena baris
baru sudah memberi hasil yang sama; `bersihkan()` membuang peristiwa berumur
lebih dari tujuh hari lewat `NotificationType::peristiwa()` yang baru.
`NotificationDropdown` membaca `belumDibaca()`, lonceng tidak lagi mencetak
isi. Halaman notifikasi: penyaring *Belum dibaca*/*Sudah dibaca*, kartu
*Total*, penanda "Sudah pulih" dibuang. `scopeAktif()` dan `masihAktif()`
dihapus; `->aktif()` dilepas dari tujuh berkas uji Pengadaan — adanya baris
kini berarti keadaannya masih berlaku. Lima uji baru, termasuk isi lonceng
yang sebelumnya tidak diuji sama sekali.

**Kesalahan yang ditemukan dan dikoreksi di tengah jalan.**

- **Migrasi yang menghidupkan kembali keadaan lama.** Versi pertama langsung
  membuang kolom `resolved_at`. Keadaan yang dulu sudah pulih akan kembali
  tampil sebagai notifikasi yang masih berlaku — dan yang belum sempat dibaca
  masuk lagi ke lonceng. Migrasinya kini menghapus baris itu lebih dulu.
  (Basis data lokal kebetulan tidak punya baris selesai, jadi tidak ada yang
  terlihat salah; server bisa berbeda.)
- **Uji yang tidak menguji apa-apa.** Uji "lonceng tanpa kalimat lengkap" mula-mula
  memakai notifikasi Stok Habis yang kalimatnya panjang. Lonceng lama memotong
  isi di 90 karakter, jadi `assertDontSee(isi lengkap)` lolos juga pada Blade
  lama. Diganti peristiwa berkalimat pendek, lalu dibuktikan gagal dengan
  memasang kembali Blade lama sementara.

**Uji dan pemeriksaan.** `php artisan test`: **479 lolos** (474 + 5 baru).
Pint terbatas pada 17 berkas yang disentuh: bersih. Migrasi dijalankan di
MySQL lokal, lalu `rollback --step=1` dan `migrate` lagi — keduanya berhasil,
7 baris lokal utuh.

**Belum dikerjakan**, dijadwalkan sesi berikut: selisih nomor 2 (Aktivitas
Terbaru dapat kosong). Selisih nomor 3 dan 5 masih terbuka; nomor 5 menyangkut
alur Alma, dan sejak sesi ini notifikasi Permintaan ikut terhapus setelah tujuh
hari — **Alma perlu diberi tahu**. Selisih nomor 4 tertutup dengan sendirinya:
tidak ada lagi keadaan pulih yang tersisa untuk dihitung badge.

---

## 23 September 2026 — Tahap belanja pada realisasi pengadaan, berbukti foto

Permintaan langsung **Pejabat Pengadaan** di lapangan. Setelah disetujui
Kasubbag Umum dan TI, realisasi seharusnya tidak langsung "menunggu
penerimaan", karena barangnya baru akan dibelanjakan. Beliau juga meminta
realisasi dapat dilampiri foto barang atau foto kegiatan belanjanya, dengan
format berkas yang lebih longgar. Pertanyaan terbuka dari pengguna: dua status
baru ("menunggu dibelanjakan" dan "sudah dibelanjakan") atau cukup satu, dan
apakah foto boleh menyusul seperti BAST atau menjadi syarat.

**Yang ditemukan sebelum membangun.** `ProcurementApprovalController::approve()`
langsung memindahkan realisasi ke `MenungguPenerimaan`, sehingga
`RealisasiMenungguPenerimaan` terbit ke gudang pada detik persetujuan — gudang
ditagih menunggu barang yang belum dibeli siapa pun. Permintaan lapangannya
tepat sasaran.

**Keputusan pengguna**, lewat empat pertanyaan eksplisit sebelum membangun;
keempatnya memilih rekomendasi:

1. **Satu** status baru, `ProsesBelanja`, bukan dua. "Sudah dibelanjakan" dan
   "menunggu penerimaan" adalah keadaan fisik yang sama, dan tidak ada siapa pun
   yang memicu perpindahan di antara keduanya.
2. Foto belanja **wajib minimal satu** saat menandai, dihitung dari keadaan
   (foto yang diunggah lebih dulu ikut dihitung). Berkebalikan dengan BAST
   (ADR 0018) karena foto belanja sudah ada di ponsel ketika tombolnya
   ditekan, sedangkan BAST butuh tanda tangan orang lain.
3. Gudang **tidak** boleh menerima selama `ProsesBelanja`.
4. Penagih `RealisasiBelumDibelanjakan` setelah **7 hari**.

Lihat [ADR 0035](keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md).

**Keputusan rancangan yang diambil tanpa ditanyakan**, dan dicatat di ADR
berikut alternatif yang ditolak: fakta belanja disimpan sebagai kolom
(`purchased_at`/`purchased_by`/`purchase_note`) dan **tidak dikosongkan saat
realisasi diubah**. Persetujuan ulang atas realisasi yang sudah dibelanjakan
langsung membawanya ke `MenungguPenerimaan`: mengubah dokumen mengulang
penilaian, bukan mengulang belanja. Format berkas diperluas ke WEBP dan HEIC
(format bawaan kamera iPhone), dengan batas 10 MB, tetapi **tidak dibebaskan**:
berkas kini dapat ditampilkan langsung di peramban, dan SVG/HTML membawa skrip.
Ada tombol **Lihat** yang membuka foto/PDF di tab baru dan tetap dicatat seperti
unduhan. Sengaja tanpa gambar kecil di halaman detail, supaya log aktivitas
tidak dibanjiri pembacaan setiap kali halaman dibuka.

**Yang dikerjakan.** Migrasi aditif `2026_09_23_000001_add_purchase_to_procurements_table`.
`ProcurementStatus::ProsesBelanja`, `ProcurementDocumentType::FotoBelanja`
beserta `formatBerkas()` sebagai satu sumber format per jenis, dan
`NotificationType::RealisasiBelumDibelanjakan`. `ProcurementPurchaseController`
dan `MarkProcurementPurchasedRequest` baru, dengan rute
`PATCH /pengadaan/{procurement}/dibelanjakan` (`kelola-pengadaan`) dan
`GET .../dokumen/{document}/lihat`. Dialog "Tandai Sudah Dibelanjakan", kartu
*Belanja*, dan keterangan bagi gudang di halaman detail. Angka *Proses Belanja*
di rekap dan laporan PDF. `User::purchasedProcurements()` langsung masuk
`RESTRICTED_RELATIONS`. Seeder mendapat realisasi keempat yang berhenti di
`ProsesBelanja` dengan persetujuan 8 hari lalu, supaya penagihnya punya contoh.

**Kesalahan yang ditemukan dan dikoreksi di tengah jalan.**

- **Jebakan yang sudah diperkirakan dan dimasukkan ke rencana:**
  `NotificationScanner::catatRealisasiDiputuskan()` menilai "disetujui" dengan
  `isMenungguPenerimaan()`. Kalau baris itu dibiarkan, setiap persetujuan yang
  kini berujung di `ProsesBelanja` akan terkirim sebagai kabar **penolakan**.
  Diganti `! isDitolak()`, dan ada uji regresinya.
- **Galat parse Blade.** `Foto Belanja@if (...)...@endif` tidak dikenali
  sebagai direktif, karena Blade hanya mengenali `@` yang tidak menempel pada
  huruf. `@if`-nya menjadi teks biasa sementara `@endif`-nya tetap dikompilasi,
  dan halaman detail meledak 500. Ketahuan dari 57 uji gagal sekaligus.
- **Celah yang ditemukan sebelum ditutup:** unggahan umum di Berkas Pendukung
  menerima PDF untuk jenis apa pun, jadi PDF bisa diunggah sebagai "Foto
  Belanja" dan meloloskan syarat foto tanpa satu pun foto. Format kini
  ditentukan per jenis lewat `formatBerkas()`, dan ada ujinya.

**Temuan lingkungan.** PHP 8.4 di laptop (WinGet) memuat
`upload_max_filesize=2M` dan `post_max_size=8M`. Batas 5 MB yang lama pun ternyata
tidak pernah tercapai: pindaian BAST di atas 2 MB sudah ditolak PHP sebelum
sampai ke Laravel. Harus dinaikkan (mis. 12M / 64M) di laptop dan server. Aturan
`uploaded` kini diberi pesan yang menyebut kemungkinan ini.

**Uji.** Berkas baru `tests/Feature/Pengadaan/ProcurementPurchaseTest.php`
(30 uji, pembantu berakhiran `Belanja` supaya lulus juga saat dijalankan
sendirian), dan 8 uji baru di `ProcurementDocumentTest` untuk format dan rute
*Lihat*. Fixture `setujuiRealisasi()` kini berujung di `ProsesBelanja`. Helper
baru `belanjakanRealisasi()` meneruskannya ke `MenungguPenerimaan`, dan
`buatRealisasiDisetujui()` berganti nama menjadi `buatRealisasiSiapDiterima()`
di tujuh berkas uji.

**Verifikasi.** `php artisan test` — **474 lulus** (sebelumnya 436).
`php artisan migrate` di MySQL lokal: migrasi ini beserta migrasi Alma
`2026_09_22_000001` yang masih tertunda. `migrate:fresh --seed`,
`notifikasi:periksa`, dan `migrate:rollback --step=1` diuji di basis data SQLite
sementara, bukan di MySQL, supaya data lokal tidak terhapus. Pint `--test` bersih
pada 27 berkas yang disentuh. `npm run build` berhasil.

**Tidak ada tabel bersama yang tersentuh.** `procurements` dan
`procurement_documents` milik sub sistem ini; `users` hanya mendapat relasi
Eloquent.

---

## 16 September 2026 (lanjutan) — Modul Log Aktivitas: jejak audit yang akhirnya dapat dibaca

Permintaan pengguna: layar log aktivitas yang menampilkan seluruh aktivitas
semua pengguna, khusus Admin, dengan ekspor data — fitur yang dianggapnya
penting untuk institusi seperti BPK yang menuntut akuntabilitas. Disertai
pertanyaan terbuka: bagaimana desainnya sebaiknya, apa saja yang masuk log, dan
seperti apa pemfilterannya.

**Yang ditemukan sebelum membangun.** Separuh infrastrukturnya ternyata sudah
ada dan sudah jalan sejak lama: tabel `audit_logs`, model `AuditLog`, dan
`AuditLogger` dengan **34 titik panggil** tersebar di Auth, Admin, Gudang, dan
Pengadaan. Yang tidak pernah ada adalah layarnya — menu "Log Aktivitas" sudah
berdiri di sidebar dengan `'path' => '#'`, dan kartu modulnya sudah ada di
dasbor tanpa kunci `route`. Jejaknya ditulis, tidak pernah dibaca.

**Masalah desain yang sesungguhnya** baru muncul saat layarnya hendak dibuat.
Kolom `activity` adalah kalimat bebas, enak dibaca tetapi mustahil disaring
dengan andal: penyaring "modul Pengadaan" akan jadi `LIKE '%pengadaan%'` yang
ikut menangkap *usulan* pengadaan dan *dokumen* pengadaan — tiga modul berbeda
dalam satu hasil. Prinsip yang diambil: **kolom terstruktur untuk menyaring,
kalimat untuk dibaca**, saling melengkapi dan bukan duplikasi. Lihat
[ADR 0034](keputusan/0034-log-aktivitas-terstruktur.md).

**Keputusan pengguna** (ditanya lewat tiga opsi eksplisit sebelum membangun):
skema lengkap — `module`, `event`, `actor_role`, `ip_address`, dan pasangan
`subject_type`/`subject_id`; tiga celah pencatatan ditutup; ekspor **CSV saja**,
tanpa PDF.

**Dua hal yang ditawarkan lalu ditolak pengguna**, keduanya layak dicatat
karena penolakannya membentuk hasil akhirnya. *Percobaan masuk gagal* tidak
jadi dicatat — konsekuensi baiknya nyata: `user_id` tetap `NOT NULL`, sehingga
migrasinya murni aditif tanpa menyentuh kunci asing `restrictOnDelete` sama
sekali, `AuditLogger::record()` tetap menuntut `User` sungguhan, dan
`LoginRequest` tidak perlu diubah sedikit pun. *Ekspor dan cetak laporan* juga
tidak dicatat, supaya log tidak tenggelam baris bervolume tinggi bernilai
rendah — tetapi unduhan **berkas pengadaan** tetap dicatat, karena itu berkas
bukti pada disk privat, bukan laporan yang isinya sudah terlihat di layar.

**Yang dikerjakan.** Enam kolom aditif pada `audit_logs`, tiga indeks, dan
`activity` dilebarkan ke 500 karakter karena kalimatnya kini dapat memuat nama
berkas unggahan. Dua enum baru (`AuditModule` 12 nilai, `AuditEvent` 14 nilai).
Tanda tangan `AuditLogger::record()` berubah dan **seluruh 34 titik panggil
dikonversi ke named arguments** menyamai `StockLedger::record()`; `module` dan
`event` dibuat wajib tanpa nilai bawaan, karena nilai bawaan justru menjamin
terjadinya pembusukan data diam-diam. Tiga titik panggil baru menutup celah
yang ada: pengisian hasil cacah opname (angka yang jadi dasar penyesuaian stok,
selama ini tidak berjejak sama sekali), perubahan draf usulan, dan unduhan
berkas pengadaan. Morph map ditambah enam alias. Layar `/log-aktivitas` khusus
Admin dengan tujuh penyaring, empat kartu rekap, dan ekspor CSV — tanpa satu
pun rute tulis, karena baris audit bersifat append-only.

**Butir rancangan yang paling mudah dianggap mubazir**, dan justru paling
penting: `actor_role` disimpan sebagai **cuplikan**, bukan dibaca dari relasi
`user`. Relasi memberi peran seseorang *sekarang*, sedangkan jejak audit harus
menyatakan kapasitas apa yang ia pakai *waktu itu*. Begitu Pengelola Gudang
dipromosikan jadi Admin, seluruh riwayat lamanya akan ikut terbaca sebagai
tindakan Admin — pemalsuan riwayat yang terjadi tanpa seorang pun menyentuh
satu baris. Ada uji khusus untuk ini.

**Kesalahan yang ditemukan dan dikoreksi di tengah jalan.**
`app()->runningInConsole()` ternyata **juga bernilai true** saat rangkaian uji
dijalankan lewat `php artisan test`, padahal uji fitur mensimulasikan
permintaan HTTP sungguhan. Pengisian `ip_address` yang semula hanya memakai
pengecekan itu menghasilkan null di seluruh lingkungan uji, sehingga
perilakunya tidak dapat diuji sama sekali — ketahuan justru karena ujinya
gagal. Diperbaiki dengan ikut mengecualikan `runningUnitTests()`.

**Injeksi rumus CSV** ditangani untuk pertama kalinya di proyek ini. Sebagian
kalimat log memuat nama berkas unggahan — satu-satunya teks di sistem ini yang
asalnya dari luar dan memang tidak dipercaya, alasan yang sama yang membuat
`DocumentStorage` menyimpannya dengan nama UUID (ADR 0022). Nama seperti
`=HYPERLINK("http://...")` akan dijalankan Excel begitu Admin membuka hasil
ekspornya; sel yang diawali `=`, `+`, `-`, `@`, tab, atau CR kini dibubuhi
kutip tunggal lebih dulu.

**`AuditLogSeeder` ternyata diperlukan.** Seeder lain menulis model secara
langsung tanpa melewati controller, sehingga tidak satu pun menghasilkan baris
`audit_logs` — tanpa seeder ini layarnya kosong sesudah `migrate:fresh --seed`
dan tidak satu penyaring pun dapat diperagakan saat sidang.

**Masih menunggu.** `audit_logs` tabel bersama, dan **Alma perlu dikabari**
bahwa skemanya bertambah enam kolom. Perubahannya aditif dan nullable serta
sub sistem Permintaan belum pernah menulis satu baris pun ke tabel ini, jadi
risiko merusaknya nihil — tetapi aturannya tetap berlaku.
`AuditModule::Permintaan` sudah disediakan untuk dipakainya. Bahwa seluruh alur
`RequestController` sampai sekarang tidak meninggalkan jejak audit apa pun
adalah wilayah keputusannya, bukan keputusan yang dibuat di sini.

**Verifikasi.** `php artisan migrate` bersih di MySQL, diperiksa langsung:
`user_id` tetap `NOT NULL`, FK `ON DELETE RESTRICT` utuh, enam kolom baru dan
tiga indeks terpasang. `migrate:fresh --seed` menghasilkan 267 baris log contoh
menyebar di sepuluh modul. `php artisan test` **436 lolos** (410 lama tetap
lolos, 26 baru). `npm run build` bersih. Pint bersih pada 26 dari 27 berkas
yang disentuh — `app/Helpers/MenuHelper.php` sudah gagal Pint sebelum sesi ini
dimulai (diperiksa terhadap versi di HEAD) dan sengaja tidak dirapikan, karena
perubahan di berkas itu hanya satu string dan merapikannya akan jadi penyapuan
berkas yang tidak berkaitan.

---

## 16 September 2026 (lanjutan) — Merge dengan Alma: `item_proposals` jadi tabel bersama

`git pull` dari repositori Alma ternyata bercabang dari commit yang sama dengan
sesi ADR 0031/0032 (`e887695`), dan riwayatnya berisi `feat: add item proposal`
(15 September 23:47) — Alma membangun fitur usulan barang barunya sendiri untuk
sub sistem Permintaan secara paralel, tanpa tahu tabel `item_proposals` ini baru
saja dirancang. Kasubbag Umum dan TI rupanya menyampaikan permintaan ketiganya
ke kedua pengembang secara terpisah pada sesi presentasi yang sama. Lihat
[ADR 0033](keputusan/0033-usulan-barang-baru-tabel-bersama-dengan-permintaan.md)
untuk detail lengkap keputusannya.

**Yang ditemukan.** Satu konflik nyata ditandai git: `app/Models/ItemProposal.php`
(`both added`), dua desain berbeda untuk kebutuhan serupa — skema Alma
(`user_id`/`reason`/status `ditindaklanjuti`) tidak menyambung balik ke
pembuatan barangnya, berhenti di ubah status saja. Dua hal **tidak** ditandai
git tetapi sama fatalnya: `routes/web.php` berisi dua `use ...ItemProposalController`
(folder `Gudang` dan `Request`) dengan nama pendek sama — auto-merge tekstual
menggabungnya tanpa keributan, padahal PHP akan berhenti dengan fatal error
begitu dieksekusi; dan dua migrasi nama berkas berbeda sama-sama membuat tabel
`item_proposals`, yang akan gagal pada `migrate` kedua meski git tidak
melihatnya sebagai konflik.

**Keputusan pengguna** (ditanya lewat opsi eksplisit): satukan ke satu tabel
bersama memakai skema ADR 0032 — yang lebih lengkap, karena menyetujui usulan
di situ berarti benar-benar mendaftarkan barangnya lewat `items.store()`.

**Yang dikerjakan.** Migrasi duplikat Alma dihapus; model diselesaikan memakai
versi ADR 0032 penuh; sisi Permintaan (`Request\ItemProposalController`,
`Request\StoreItemProposalRequest`, dua view) diadaptasi memakai nama kolom
skema bersama, dengan `method decide()` dan rutenya dihapus — digantikan dua
jalur yang sudah ada dan dipakai bersama: tolak lewat `item-proposals.reject`
(gate `kelola-gudang`), setuju lewat `items.create?item_proposal_id=…`. Alasan
tidak membuat jalur keputusan kedua: usulan dari pintu mana pun harus diputuskan
lewat satu jalur yang sama, atau dua jalur itu cepat atau lambat berselisih
perilaku. `use` di `routes/web.php` diberi alias untuk menghindar tabrakan
nama. Empat skenario ujung ke ujung diverifikasi lewat berkas uji sementara
(ditulis, dijalankan, lalu dihapus — menambah uji permanen di wilayah Alma
bukan keputusan yang dibuat di sesi ini): mengusulkan lewat pintu Permintaan,
indeks menampilkan usulan sendiri dan tautan tinjau bagi Gudang, penolakan lewat
rute bersama, dan persetujuan lewat `items.store` yang menautkan `item_id`.

**Yang tidak disentuh.** Tidak satu pun berkas di `app/Http/Controllers/Gudang/`,
`app/Http/Requests/Gudang/`, `app/Models/ItemProposal.php`, atau
`app/Enums/ItemProposalStatus.php` diubah. `composer.json`/`composer.lock`
bertambah `endroid/qr-code` dari fitur QR Alma yang tidak terkait sama sekali,
dibiarkan apa adanya lalu `composer install` dijalankan.

**Masih menunggu.** Alma perlu dikabari: tombol "Selesaikan" pada halamannya
sudah tidak ada, dan tabel `item_proposals` sekarang tabel bersama yang
perubahan skemanya butuh kesepakatan (menutup butir terbuka lama di
`erd/penyelarasan-erd.md`). Menambah uji otomatis permanen untuk pintu
Permintaan adalah keputusannya, bukan yang dibuat di sini.

**Verifikasi.** `php artisan migrate:fresh --seed` bersih di MySQL dengan satu
tabel `item_proposals`; `php artisan test` tetap 410 lolos; `npm run build`
bersih; Pint bersih pada seluruh berkas yang disentuh.

---

## 16 September 2026 — Realisasi pengadaan tanpa usulan, dan pintu depan katalog

Dua permintaan **Kasubbag Umum dan TI** dari presentasi prototipe yang sama
dengan sesi 15 September: (1) Pejabat Pengadaan perlu dapat membuat realisasi
sendiri tanpa menunggu usulan Pengelola Gudang, dan (2) ia perlu dapat
mengadakan barang yang belum ada datanya di master barang. Permintaan ketiga —
pengguna dapat meminta barang yang belum ada di sistem — bentuknya berbeda
tetapi kebutuhannya sama dengan yang kedua, dan dijawab mekanisme yang sama.
Sekali lagi bukan belokan mendadak:
[ADR 0029](keputusan/0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md)
sudah menutup dirinya dengan mencatat realisasi tanpa usulan sebagai langkah
berikutnya yang belum dikerjakan.

**Rekomendasi yang diberikan sebelum membangun.** Empat hal diluruskan lebih
dulu, dan keempatnya disetujui pengguna apa adanya.

Pertama, **mengapa permintaan (1) baru layak sekarang**: sebelum
[ADR 0030](keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md),
realisasi tanpa usulan akan membuat satu peran menjalankan separuh siklus
sendirian — memilih barang, penyedia, dan harga tanpa dokumen hulu maupun
pemutus, sehingga pemisahan tugas ADR 0016 bolong. Dengan jenjang Kasubbag,
trionya tetap tiga orang berbeda. Instingnya sendiri ("karena sekarang sudah ada
peran KUT") justru itulah inti argumennya, dan itu yang ditulis di ADR.

Kedua, **pertanyaan pengguna sendiri** "apakah perlu alasan, padahal rancangan
awal menaruh alasan di usulan?" dijawab: perlu, wajib, dan bukan konsep baru.
Rancangan awal bukan "tanpa alasan" — alasannya ada, tempatnya di dokumen usulan
beserta pengaju dan `source`-nya. Aturannya jadi satu kalimat: *alasan tidak
pernah hilang dari sistem, ia hanya pindah ke dokumen yang tidak punya hulu.*
Tiga urgensinya: layar persetujuan KUT kehilangan satu-satunya bahan penilaian
tanpa itu; `audit_logs` mencatat siapa dan kapan, tidak pernah mengapa; dan
bentuk berenum dapat direkap sehingga kebiasaan memakai pintu langsung terlihat
angkanya. Bentuknya mengikuti pola yang proyek ini sudah pakai dua kali — enum
baku berpasangan keterangan wajib, seperti ADR 0011 dan ADR 0024.

Ketiga, untuk permintaan (2) bentuk yang diusulkan pengguna sudah benar arahnya
tetapi digeser dua hal. **Nama barang bebas pada rincian pengadaan ditolak
karena mustahil, bukan karena selera**: stok tidak dapat diposting ke sebuah
string — `StockLedger::record()` menuntut sebuah `Item` dan
`procurement_details.item_id` adalah FK NOT NULL. **Kolom `verified_at` pada
`items` juga ditolak**: `items` tabel bersama dengan Alma, dan jangkauannya
besar — barang stok nol yang belum diverifikasi akan langsung memicu `StokHabis`
sekaligus tersedot ke draf usulan otomatis, jadi enam titik harus diaudit lebih
dulu. Gantinya satu tabel kecil `item_proposals` yang nol dampak ke `items` dan
nol ketergantungan ke Alma.

Keempat, penilaian pengguna atas permintaan (3) — "meminta barang yang tidak ada
itu desain jelek, apa bedanya dengan mulut ke mulut?" — dinyatakan **separuh
benar**, dan separuh yang lain justru kunci penyelesaiannya. Membiarkan baris
`requests` menunjuk barang yang tidak ada memang harus ditolak. Tetapi "apa
bedanya dengan mulut ke mulut" punya jawaban: usulan adalah **dokumen** —
bertuan, bertanggal, berkeputusan, beralasan, terhitung; percakapan lisan tidak
punya kelimanya. Yang membuat sesuatu terstruktur bukan "barangnya sudah ada di
master", melainkan "permintaannya punya dokumen dan ada pihak yang wajib
menjawabnya". Jadi permintaan KUT dapat dipenuhi utuh asal disalurkan ke dokumen
yang benar — dan dokumen itu sama dengan jawaban permintaan (2). Satu konsep,
dua pintu masuk, satu layar keputusan milik gudang: **katalog punya pintu
depan**.

**Yang dikerjakan — ADR 0031, realisasi tanpa usulan.**

- Migrasi `allow_procurements_without_request`: `procurement_request_id` menjadi
  nullable, **indeks uniknya dipertahankan**. MySQL dan SQLite mengizinkan
  banyak baris NULL pada indeks unik, jadi satu perubahan ini melayani dua
  kebutuhan — realisasi langsung boleh banyak, sementara "satu usulan satu
  realisasi" tetap dijaga basis data, bukan kode.
- Migrasi `add_basis_to_procurements_table`: `basis_reason`, `basis_note`.
  Sejajar pola `cancel_*` dan `review_*`.
- `AlasanPengadaan` baru: `KebutuhanMendesak`, `BarangBaru`, `ArahanPimpinan`,
  `BelanjaRutinNilaiKecil`, `Lainnya`.
- `Procurement`: `tanpaUsulan()` dan `dasarRingkas()`, plus penyaring `sumber`
  pada `scopeFilter()`. Keadaan "tanpa usulan" **diturunkan** dari satu kolom di
  baris yang sama, bukan disimpan sebagai kolom `source` tersendiri.
- `ProcurementController::create()` melayani dua pintu; `store()` melewati
  transisi ADR 0028 dan `periksaUsulan()` bila tidak ada usulan; `summary()`
  mendapat `langsung` sebagai **irisan**, bukan penjumlah, sehingga invarian
  ADR 0030 tidak terganggu.
- Validasi: jalan keluar `withValidator()` dipecah dua supaya pemeriksaan per
  baris **selalu** jalan, dan baris yang mengaku berasal dari usulan ditolak
  pada realisasi langsung. `rincianDisetujui()` di kedua form request diberi
  guard eksplisit.
- Tampilan: satu blade dengan dua rupa (bukan berkas kedua), kartu Dasar
  Pengadaan di formulir dan halaman detail, tombol "Buat Realisasi Langsung",
  penyaring dasar, label "Langsung" pada tabel, kolom **Dasar** di CSV, dan
  baris kedua di sel Realisasi pada PDF.
- Isi notifikasi `RealisasiMenungguPersetujuan` menyebut dasarnya bila tanpa
  usulan — pada realisasi langsung, dasar itulah yang justru dinilai.

**Yang dikerjakan — ADR 0032, usulan barang baru.**

- Tabel `item_proposals` beserta `ItemProposalStatus`, model `ItemProposal`, dan
  `Gudang\ItemProposalController` (index, create, store, show, reject).
- **Tidak ada rute "setujui".** Menyetujui berarti mendaftarkan barangnya:
  tombolnya menuju `items.create?item_proposal_id=…`, dan
  `ItemController::store()` yang menutup usulannya **di dalam transaksi yang
  sama** — sehingga tidak mungkin ada usulan `Disetujui` tanpa barang, atau
  sebaliknya. Polanya sama dengan ADR 0030: menyimpan perbaikan *adalah*
  pengajuan ulangnya.
- Tiga tipe notifikasi baru beserta `periksaUsulanBarang()`,
  `periksaSeluruhUsulanBarang()` (disambung ke `periksaSemua()`), dan
  `catatUsulanBarangDiputuskan()`.
- Menu "Usulan Barang Baru" pada grup Pengadaan **dan** grup Manajemen Gudang.
- Seeder baru `ItemProposalSeeder` (1 menunggu keputusan, 1 disetujui, 1
  ditolak) dan satu realisasi langsung contoh di `ProcurementSeeder`.

**Temuan penting.** Tiga hal.

Pertama, **migrasi nullability tidak perlu melepas FK-nya**. Rencana awal
menyiapkan jalur mundur `dropForeign` → `change()` → pasang ulang, karena
mengubah kolom ber-FK di MySQL sering ditolak. Ternyata yang ditolak adalah
perubahan **tipe**, bukan nullability: `$table->uuid(...)->nullable()->change()`
cukup, dan SQLite menanganinya dengan membangun ulang tabel beserta seluruh
constraint-nya. Diperiksa langsung ke basis data — `Null: YES`, indeks
`procurements_procurement_request_id_unique` tetap unik, FK tetap ada.

Kedua, **ADR 0029 ternyata sudah membangun separuh pekerjaan ADR 0031 tanpa
menyadarinya**. "Baris di luar usulan" sudah punya bentuk yang mengirim
`item_id` langsung, validasi yang memilah dua bentuk baris, dan `update()` yang
tahu baris tambahan boleh dihapus. Realisasi langsung hanyalah kasus batas dari
bentuk yang sama — seluruh barisnya baris tambahan. Yang terasa seperti fitur
besar sebenarnya pelonggaran satu kendala.

Ketiga, `edit.blade.php` sempat dicurigai akan meledak pada realisasi langsung
karena `$procurement->procurementRequest?->details->filter(...)->keyBy(...)`.
Ternyata **sudah aman**: operator `?->` memutus seluruh rantai, bukan hanya satu
langkah, sehingga `?? collect()` di ujungnya memang bekerja. Kecurigaan itu
salah, dan tidak ada yang perlu diubah di sana.

**Catatan.** Tiga hal ditemukan sambil jalan dan **sekalian diperbaiki**, karena
ketiganya berada di blok yang memang sedang disunting:

1. `cancelledBy` akhirnya terdaftar di `User::RESTRICTED_RELATIONS` lewat relasi
   `cancelledProcurements()`. Celah ini butir terbuka sejak ADR 0024 —
   menghapus akun yang pernah membatalkan realisasi meledak jadi 500 alih-alih
   pesan validasi ADR 0003. Dua baris, di konstanta yang sedang ditambahi dua
   relasi baru; meninggalkannya justru lebih buruk.
2. `modul/notifikasi.md` ternyata belum memuat tiga tipe milik ADR 0030
   (`RealisasiMenungguPersetujuan`, `RealisasiDisetujui`, `RealisasiDitolak`)
   walau tipenya sudah hidup sejak sesi sebelumnya. Katalognya dilengkapi.
3. Seluruh berkas yang disunting lewat skrip Python sempat berubah menjadi CRLF
   — `pathlib.write_text()` menerjemahkan `\n` ke `os.linesep` di Windows,
   sedangkan repositori ini `eol=lf`. Terdeteksi sebagai 13 temuan `line_ending`
   pada `pint --test` dan dinormalkan ulang. Layak diingat: gejalanya muncul
   sebagai temuan gaya, bukan sebagai galat.

Temuan lama *trailing whitespace* di `app/Helpers/MenuHelper.php` tetap
dibiarkan — sudah dipastikan hanya menyentuh blok menu Permintaan Barang
(baris 31-38, area Alma), bukan dua baris menu yang ditambahkan sesi ini.

**Masih menunggu.** Tabel `item_proposals` sengaja dibentuk netral peran supaya
sub sistem permintaan dapat memakainya tanpa perubahan skema — itu **tawaran**
untuk Alma, bukan asumsi, dan perlu dikabari. Selama hanya Naren yang memakai,
tabelnya miliknya sendiri. Butir lama juga masih berdiri: kesepakatan enum
`kasubbag_umum_ti` pada tabel bersama `users`, dan ejaan `unit` akun Kasubbag.

Satu butir baru untuk lapangan: **arti "barang kosong"** pada permintaan KUT
sebenarnya dua hal yang mungkin tertukar — barang yang ada di katalog tetapi
stoknya nol (sudah didukung, bahkan sudah otomatis menjadi draf usulan) versus
barang yang tidak ada di katalog sama sekali (yang dijawab ADR 0032). Perlu
dipastikan yang mana, karena bila yang pertama, sisanya hanya soal apakah sub
sistem permintaan mengizinkan permintaan atas barang berstok nol — wilayah Alma.

**Verifikasi.** 410 pengujian lolos (sebelumnya 365 — 45 uji baru: 22 di
`ProcurementDirectTest.php`, 23 di `ItemProposalTest.php`). Yang paling
menentukan di antaranya: dua realisasi langsung dapat berdiri bersama (inti
migrasi indeks unik), persetujuan gugur setelah dasar pengadaannya diubah,
penerimaan realisasi langsung tetap memposting stok lewat `StockLedger`,
pendaftaran barang dari usulan lahir berstok nol tanpa baris mutasi, penutupan
tagihan keputusan pada kedua jalur, dan jahitan ujung ke ujung usulan barang →
realisasi langsung. `migrate:fresh --seed` bersih pada MySQL; `npm run build`
bersih; `pint --test` bersih pada 26 berkas yang disunting kecuali temuan lama
MenuHelper di atas; `notifikasi:periksa` idempoten (18 notifikasi aktif pada
jalannya yang pertama maupun kedua).

Dua gotcha lama terbukti masih hidup dan keduanya menjatuhkan uji lebih dulu
sebelum diperbaiki: prefiks `judul()` yang identik untuk dua baris yang lahir
pada milidetik yang sama (UUID v7 berurut), sehingga penyaring dibuktikan
dengan kunci penuh, bukan judul; dan `assertDontSee` atas nama barang yang
meleset karena **pesan flash** dari permintaan sebelumnya masih terbawa dan
memuat nama itu — yang terakhir ini baru, dan layak diingat karena gejalanya
menyesatkan: terlihat seperti penyaring yang tidak bekerja padahal penyaringnya
benar.

---

## 15 September 2026 — Peran Kasubbag Umum dan TI dan jenjang persetujuan realisasi

Permintaan langsung dari **Kasubbag Umum dan TI** saat presentasi prototipe di
lapangan: ia ingin meninjau realisasi pengadaan yang dibuat Pejabat Pengadaan
sebelum berjalan — cukup terima atau tolak. Sekaligus permintaan memecah peran
`admin`, karena di lapangan Admin akan dipegang **staf TI**. Bukan belokan
mendadak: [ADR 0029](keputusan/0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md)
sudah menutup dirinya dengan mencatat jenjang ini sebagai langkah berikutnya
yang belum dikerjakan.

**Rekomendasi yang diberikan sebelum membangun.** Pertanyaan pengguna
"persetujuan sebelum atau sesudah realisasi dibuat?" diluruskan lebih dulu:
dokumennya **harus** tersimpan agar dapat ditinjau — penyedia, barang, dan
harganya justru yang dinilai — yang dimaksud sebenarnya adalah *belum berlaku
sampai disetujui*. Bentuknya jadi status kelahiran baru
`MenungguPersetujuan`, pola yang sama dengan `ProcurementRequestStatus::Draf`
(ADR 0027). Dua lubang alur diangkat sebelum dibangun, keduanya disetujui
pengguna: (1) `Ditolak` **tidak boleh** terminal, karena indeks unik
`procurement_request_id` akan membuat usulan asalnya ikut mati permanen —
jalan buntu yang sama persis dengan yang diperbaiki ADR 0024; (2)
[ADR 0025](keputusan/0025-realisasi-dapat-diubah-sebelum-penerimaan.md)
membuka celah tata kelola, karena harga dapat diubah setelah persetujuan terbit.
Keduanya ditutup satu aturan: **setiap penyimpanan perubahan mengembalikan
realisasi ke `MenungguPersetujuan`** dan mengosongkan jejak review — itu
sekaligus menjadi mekanisme "ajukan ulang" sehingga tidak perlu rute tersendiri.

**Keputusan pengguna yang berbeda dari rekomendasi.** Diusulkan mengecualikan
Admin dari gate persetujuan supaya staf TI tidak pernah bisa menyetujui belanja;
pengguna memilih **membiarkan `Gate::before` apa adanya** demi jalan darurat dan
konsistensi konvensi uji "Admin → 200". Keterbatasan itu dicatat terus terang di
ADR, bukan didiamkan. Untuk tampilan, dipilih **grup menu "Persetujuan"** berisi
tautan ke daftar terfilter alih-alih layar "Kotak Persetujuan" tersendiri —
nol controller baru, dan grup menunya menjadi titik komposisi murah untuk
menampung tautan persetujuan sub sistem permintaan kelak tanpa kopling data.

**Yang dikerjakan.**

- `UserRole::KasubbagUmumTi` baru; gate `setujui-pengadaan` baru dan
  `akses-pengadaan` diperluas ke peran itu.
- `ProcurementStatus` dari tiga menjadi lima case
  (`MenungguPersetujuan`, `Ditolak`), plus helper `masihBerjalan()` dan
  `takPernahJadiTransaksi()`.
- Migrasi `add_approval_to_procurements_table`: `reviewed_at`, `reviewed_by`,
  `review_note`. Satu set kolom untuk keputusan terakhir, sejajar pola
  `cancelled_*`; riwayat lengkapnya tetap di `audit_logs` yang immutable.
- `ProcurementApprovalController` baru dengan `approve()`/`reject()`, dua form
  request (`...RealizationRequest`, dinamai begitu karena
  `ApproveProcurementRequestRequest` sudah dipakai persetujuan usulan), dan grup
  rute `can:setujui-pengadaan` berisi dua rute PATCH.
- `ProcurementController`: status kelahiran berubah; `edit()`, `update()`, dan
  `cancel()` berpindah ke `masihBerjalan()`; `update()` menyetel ulang status dan
  jejak review; `summary()` memakai invarian baru
  `total = menunggu_persetujuan + menunggu + diterima`.
- Tiga tipe notifikasi baru; `NotificationScanner::periksaRealisasi()` menerima
  koleksi penerima kedua karena antrean persetujuan ditagih ke jabatan berbeda.
- Tampilan: grup menu Persetujuan, tombol Setujui dan modal Tolak di halaman
  detail, kartu "Hasil Review", kartu rekap 5 → 6, plus kolom pada laporan PDF.
- Seeder: akun `kasubbag`, satu usulan tambahan, dan satu realisasi contoh yang
  berhenti di `MenungguPersetujuan` supaya layar pemutus tidak kosong.
- Dokumentasi: [ADR 0030](keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md),
  `modul/pengadaan.md`, `modul/autentikasi.md`, `ikhtisar-sistem.md`, dan
  `erd/penyelarasan-erd.md` — tabel penyimpangan yang terakhir itu sekalian
  disusulkan untuk ADR 0024, 0027, dan 0028 yang sebelumnya belum tercatat.

**Temuan penting.** Sisipan ini jauh lebih murah dari dugaan karena arsitektur
"status sebagai penjaga" yang sudah terbangun menutup sendiri sebagian besar
risikonya: `ProcurementReceiptController::create()`,
`ProcurementReceiptPoster::pastikanSiap()`, dan keadaan
`RealisasiMenungguPenerimaan` semuanya sudah berkunci pada
`isMenungguPenerimaan()`, sehingga realisasi yang belum disetujui otomatis tidak
dapat diterima dan stok tidak mungkin bertambah — **ketiganya tidak diubah satu
baris pun**, dan tetap diberi uji tersendiri. Dua gotcha lama juga terbukti
masih hidup: dua realisasi di seeder lahir dengan prefiks `judul()` identik
(UUID v7 berurut) sampai diberi UUID v4 eksplisit, dan `MenuHelper::isActive()`
mencocokkan path saja sehingga query string harus dipisah ke kunci `query`
tersendiri agar penandaan menu aktif tidak meleset.

**Catatan.** Ditemukan sambil jalan, **di luar cakupan dan belum diperbaiki**:
relasi `cancelledBy` tidak terdaftar di `User::RESTRICTED_RELATIONS` padahal
FK-nya `restrictOnDelete()`, sehingga menghapus akun yang pernah membatalkan
realisasi akan meledak jadi 500 alih-alih pesan validasi ADR 0003. Celah itu ada
sejak ADR 0024; relasi `reviewedProcurements` yang baru sengaja langsung
didaftarkan supaya tidak mengulanginya. Juga: `app/Helpers/MenuHelper.php` gagal
`pint --test` karena *trailing whitespace* pada blok menu Permintaan Barang —
sudah ada sebelum sesi ini (terbukti identik saat perubahan sesi ini di-stash)
dan sengaja tidak diperbaiki agar tidak menimbulkan diff di area Alma.

**Masih menunggu.** Penambahan case enum menyentuh `users`, **tabel bersama**,
jadi perlu disepakati dengan Alma. Tawarannya dua arah: transisi final permintaan
(`processed_by_staff → completed`) saat ini di-*hardcode* ke Admin pada
`RequestController`, padahal secara jabatan itu Kasubbag Umum dan TI — jadi ia
mendapat case enum yang memang dibutuhkannya. Tidak ada satu pun berkas sub
sistem permintaan yang disentuh sesi ini. Perlu dikonfirmasi juga ke lapangan:
kolom `unit` pada akun seed lama tertulis "Subbagian Umum dan TU", sedangkan
nama peran yang diminta berbunyi "Umum dan **TI**"; akun `kasubbag` baru memakai
ejaan yang konsisten dengan nama perannya.

**Verifikasi.** 365 pengujian lolos (sebelumnya 338 — 27 uji baru, sebagian besar
di `ProcurementApprovalTest.php`: matriks hak akses termasuk Admin → 200,
persetujuan, penolakan berikut alasan wajib, penerimaan yang ditolak sebelum
disetujui beserta bukti `stock_mutations` tetap kosong, perbaikan setelah
ditolak, gugurnya persetujuan setelah realisasi diubah, dan keempat jalur
notifikasinya). `migrate:fresh --seed` bersih pada MySQL; `npm run build`
bersih; `pint --test` bersih pada seluruh berkas yang diubah kecuali temuan lama
di atas. Alur ujung-ke-ujung juga diperiksa langsung terhadap basis data
pengembangan dengan akun seed: sidebar `kasubbag` memuat grup Persetujuan
beserta tautan terfilternya, daftar tersaring menampilkan realisasi yang
mengantre, halaman detail menampilkan tombol Setujui/Tolak, dan halaman yang
sama untuk `gudang` tidak memunculkan jalan apa pun menuju formulir penerimaan.

---

## 13 September 2026 — Realisasi boleh melebihi usulan, dan boleh menambah barang

Permintaan perubahan pada dua titik alur pengadaan: (1) Pejabat Pengadaan
menyetujui nominal usulan lebih besar dari yang diajukan, dan (2) Pejabat
Pengadaan mengubah nominal realisasi menjadi lebih besar dari usulan sekaligus
menambahkan barang sendiri di luar usulan (barang dari usulan tetap wajib ada,
tidak boleh dihapus). Alasannya: keputusan pembelian akhir ada di tangan
Pejabat Pengadaan, dan ini langkah awal menuju realisasi yang bisa dibuat
Pejabat Pengadaan langsung tanpa usulan (rencana lanjutan, belum dikerjakan).

**Rekomendasi yang diberikan sebelum membangun**: hanya kerjakan (2), bukan
(1). Melonggarkan persetujuan usulan (`amount_granted > amount_request`) tidak
menambah kemampuan apa pun yang belum bisa dicapai lewat barang tambahan pada
realisasi, tapi melemahkan makna `amount_granted` sebagai representasi
kebutuhan Pengelola Gudang. Disetujui pengguna — hanya (2) yang dibangun.
Keputusan lengkap: [ADR 0029](keputusan/0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md).

**Yang dikerjakan.**

- `StoreProcurementRequest` dan `UpdateProcurementRequest`: batas
  `amount ≤ amount_granted` dicabut sepenuhnya. Baris realisasi sekarang punya
  dua bentuk — baris usulan (mengirim id baris usulan, `item_id` diturunkan
  server-side seperti sebelumnya) dan baris tambahan (mengirim `item_id`
  langsung, tanpa id baris usulan apa pun).
- Kelengkapan rincian usulan (`amount_granted > 0`) ditegakkan sebagai
  validasi keras di **kedua** form request — sebelumnya di `Store` itu cuma
  disiplin UI, bukan validasi sungguhan.
- `procurement_details` tidak bertambah kolom — baris usulan vs tambahan
  dibedakan lewat keanggotaan `item_id` pada rincian usulan yang disetujui,
  pola yang sama dengan `UpdateProcurementRequest::rincianDisetujui()` yang
  sudah ada sebelum perubahan ini.
- `ProcurementController::update()` sekarang bisa membuat baris tambahan baru
  dan menghapus baris tambahan yang tidak lagi dikirim — aman karena hapus
  baris cuma mungkin selama `MenungguPenerimaan`, belum ada stok atau mutasi
  yang bergantung padanya.
- `procurements/create.blade.php` dan `edit.blade.php` ditulis ulang: tabel
  usulan (statis, tanpa `max`, tanpa tombol hapus) dipisah dari bagian
  "Barang di Luar Usulan" (Alpine.js, pemilih barang bergaya pencarian —
  pola yang sama dengan pemilih barang `procurement-requests/draft.blade.php`
  — dengan tombol Tambah/Hapus per baris).
- Diverifikasi lewat browser sungguhan (Playwright headless, karena
  `chromium-cli` tak tersedia di lingkungan ini): login sebagai
  `pengadaan`, buat realisasi dengan jumlah melebihi disetujui sekaligus
  barang tambahan, submit, buka lagi lewat Ubah Realisasi, tambah satu
  barang tambahan lagi, simpan — seluruhnya persisten dan tampil benar di
  halaman detail. Data uji dibersihkan kembali dari database dev setelahnya.
- 6 test baru di `ProcurementTest.php`/`ProcurementEditTest.php` (jumlah
  melebihi disetujui diterima, barang tambahan diterima, kelengkapan rincian
  usulan tetap wajib, barang tambahan boleh dihapus kembali, barang usulan
  tidak boleh dihapus, barang tambahan dobel dengan barang usulan ditolak).
  338 pengujian lolos; Pint bersih pada seluruh berkas yang diubah.

---

## 13 September 2026 — Status usulan "Disetujui" dipecah dua

Permintaan lanjutan setelah sesi draf otomatis di hari yang sama: status
`Disetujui` pada usulan pengadaan menaungi dua keadaan riil yang berbeda —
belum ada realisasi sama sekali, dan sudah ada realisasi apa pun nasibnya —
tetapi keduanya tampil identik di tabel dan filter `/usulan-pengadaan`.
Diminta agar terlihat jelas dan bisa disaring terpisah. Keputusan lengkap di
[ADR 0028](keputusan/0028-status-usulan-disetujui-dipecah-dua.md).

**Definisi yang dikonfirmasi bersama pengguna sebelum dibangun**: "selesai"
berarti sudah dibuat dokumen realisasinya, titik — realisasi yang dibatalkan
tetap terhitung "selesai", tidak kembali jadi "menunggu realisasi". Ini
menyederhanakan kategorinya jadi murni biner (bukan tiga rupa yang juga
membedakan nasib realisasi), dan pilihan status **tersimpan** (bukan
turunan/computed seperti `Item::stockStatus()`) diambil justru karena user
secara eksplisit ingin bisa **disaring** lewat filter dropdown.

**Yang dikerjakan.**

- `ProcurementRequestStatus::Disetujui` → `DisetujuiMenungguRealisasi` (badge
  biru, label "Menunggu Realisasi") dan `DisetujuiSelesai` (badge hijau,
  label "Selesai"). Label tampilan sengaja tidak mengulang kata "Disetujui"
  di kedua badge.
- `ProcurementRequestController::approve()` mengisi `DisetujuiMenungguRealisasi`;
  `ProcurementController::store()` — satu-satunya tempat baris `procurements`
  pernah lahir dari sebuah usulan — memindahkannya ke `DisetujuiSelesai`
  dalam transaksi yang sama. Transisi permanen, tidak pernah berbalik.
- Migrasi **data** (bukan skema — kolom `status` tetap string polos)
  membackfill baris lama `disetujui` berdasarkan ada/tidaknya baris di
  `procurements`.
- `ProcurementRequest::isDisetujui()` diganti `isDisetujuiMenungguRealisasi()`,
  `isDisetujuiSelesai()`, dan `sudahDisetujui()` (gabungan keduanya, dipakai
  tempat yang cuma peduli keputusannya positif).
- Efek samping yang menyederhanakan, bukan cuma menambah kerumitan: beberapa
  pengecekan gabungan (`isDisetujui() && procurement()->exists()`/`=== null`)
  di `ProcurementController::create()`, `StoreProcurementRequest`, dan
  `NotificationScanner::periksaUsulanBelumDirealisasi()` menyusut jadi satu
  kondisi status saja, tanpa perlu memuat relasi `procurement` lagi.
- `ProcurementSeeder` membuat `Procurement` langsung lewat model (bukan lewat
  controller), sehingga transisi statusnya ditiru manual di sana — dicatat
  eksplisit di komentar seeder supaya tidak terlewat sesi berikutnya.
- Kartu ringkasan `/usulan-pengadaan` bertambah dari 4 jadi 5: "Disetujui"
  yang dulu satu kartu kini dua kartu terpisah.
- 2 uji baru (transisi status setelah realisasi dibuat di `ProcurementTest`,
  status tetap `DisetujuiSelesai` setelah dibatalkan di
  `ProcurementCancellationTest`) plus penyesuaian assertion status di
  `ProcurementRequestTest`. 332 pengujian lolos, diverifikasi juga lewat
  `migrate:fresh --seed` terhadap MySQL sungguhan (distribusi status pada
  data contoh diperiksa langsung lewat query, hasilnya konsisten).

---

## 13 September 2026 — Draf usulan pengadaan otomatis

Permintaan perubahan mekanisme: sebelumnya diasumsikan sistem sudah otomatis
membuat usulan pengadaan per barang begitu stoknya di bawah minimum, dengan
rumus `stock_max - stock_current`. Penelusuran memastikan asumsi itu keliru —
yang otomatis selama ini hanya notifikasi `StokMenipis`/`StokHabis`; membuat
usulannya tetap sepenuhnya manual lewat jalan pintas satu-barang yang mengisi
formulir tanpa jumlah. Keputusan lengkap di
[ADR 0027](keputusan/0027-draf-usulan-pengadaan-otomatis.md).

**Arah yang dipilih**, dikonfirmasi bersama pengguna sebelum dibangun: draf
tunggal yang dijaga otomatis oleh sistem (bukan sekadar pemilih saran di
dalam formulir manual, yang tetap pasif) — seluruh barang di bawah minimum
ditampung dalam satu dokumen berstatus `Draf`, barang baru bergabung ke draf
yang sama saat pemindaian ulang, barang yang pulih dibuang otomatis dari
draf, dan jalan pintas lama di halaman barang diarahkan ke halaman draf.

**Yang dikerjakan.**

- `ProcurementRequestStatus::Draf`, dikecualikan dari `options()` dan dari
  hitungan/daftar `/usulan-pengadaan` (`ProcurementRequest::scopeBukanDraf()`)
  — draf ditampilkan lewat banner tersendiri, bukan sebagai baris usulan.
- `procurement_requests.user_id` dijadikan nullable (migrasi) — draf lahir
  tanpa pengaju manusia, baru terisi saat benar-benar diajukan.
- `procurement_request_details.is_auto` (migrasi) — menandai baris yang masih
  dikelola otomatis; begitu Pengelola Gudang menyimpan draf, seluruh baris
  yang dikirim menjadi milik manusia dan kebal dari perubahan otomatis
  selanjutnya, termasuk penghapusan saat barangnya pulih.
- `App\Support\ProcurementDraftScanner` (baru, sejajar `NotificationScanner`):
  `perbaruiBarang()`, `perbaruiSeluruh()`, `perbaruiBarangTerdampak()`,
  `sinkronkanNotifikasi()`. Dipanggil eksplisit dari titik yang sama dengan
  `NotificationScanner::periksaBarang()` (`ItemController` x3,
  `StockMutationController::store()`), ditambah `OpnamePoster::post()` dan
  sapuan terjadwal `notifikasi:periksa`.
- Saran jumlah: `max(max(stock_max, stock_min) - stock_current, 1)` — jatuh
  kembali ke `stock_min` saat `stock_max = 0` (tanpa batas atas), sehingga
  tidak pernah menyarankan angka nol atau negatif.
- `App\Http\Controllers\Pengadaan\ProcurementDraftController` (baru):
  `edit()`, `update()` (ganti seluruh rincian sekaligus — baris yang tidak
  dikirim ulang dianggap dibuang, kebalikan dari konvensi
  `ProcurementController::update()`), `finalize()`, `discard()`. Empat rute
  baru di bawah `usulan-pengadaan/draf`, didaftarkan sebelum
  `usulan-pengadaan/{procurementRequest}` sesuai gotcha urutan rute statis.
- `NotificationType::DraftUsulanTersedia` (keadaan, Gudang + Admin), meniru
  pola `UsulanMenungguPersetujuan`.
- `StoreProcurementRequestRequest` tidak lagi menerima `source = pemantauan_stok`
  — jalur itu seluruhnya milik draf; formulir manual murni untuk usulan dari
  nol.
- Tombol "Ajukan Usulan Pengadaan" di halaman barang menjadi "Lihat Draf
  Usulan Pengadaan", menautkan ke draf, bukan lagi memprefill formulir manual.
- 25 uji baru (`ProcurementDraftTest`) plus penyesuaian `ProcurementRequestTest`
  untuk jalur pintas dan validasi `source`. 330 pengujian lolos.

**Dua trade-off yang diterima secara sadar, dicatat supaya tidak ditemukan
kembali sebagai bug di kemudian hari:**

1. **Barang yang dibuang manual dari draf bisa muncul lagi** pada pemindaian
   berikutnya selama stoknya masih di bawah minimum — bukan bug, karena
   kekurangannya memang belum selesai. Tidak ada memori "dismiss".
2. **Tidak ada constraint tingkat basis data** yang menjamin hanya satu draf
   terbuka pada satu waktu. `lockForUpdate()` pada draf yang sudah ada sudah
   cukup untuk kasus umum; risiko dua permintaan bersamaan sama-sama membuat
   draf pertama diterima sebagai kasus tepi berpeluang sangat kecil, sepadan
   dengan profil pemakaian sistem ini.

Satu catatan implementasi yang layak diketahui sesi berikutnya: direktif
Blade `@json()` memecah argumennya dengan `explode(',', ...)` tanpa
memperhatikan kurung/array bersarang — ekspresi kompleks dengan banyak koma
di dalamnya (seperti `$koleksi->map(fn ($x) => [...banyak field...])`)
**terpotong diam-diam**, bukan gagal dengan galat yang jelas. Hitung ekspresi
semacam itu lebih dulu di blok `@php`, lalu `@json()`-kan variabel polosnya.

---

## 7 September 2026 — Koreksi realisasi, dan agregat yang jujur

Bermula dari laporan pemakaian: setelah sebuah realisasi dibatalkan, kolom
"Pengadaan" pada daftar penyedia tetap bertambah satu. Usul pertamanya adalah
menghapus baris realisasinya supaya angkanya otomatis benar. Usul itu ditolak,
dan penelusurannya justru memunculkan dua masalah yang lebih dalam. Keputusan
lengkap di [ADR 0025](keputusan/0025-realisasi-dapat-diubah-sebelum-penerimaan.md)
dan [ADR 0026](keputusan/0026-agregat-tidak-menghitung-realisasi-batal.md).

**Yang salah angkanya, bukan datanya.** `SupplierController::index()` memakai
`withCount('procurements')` — mencacah *dokumen*, bukan pengadaan yang
benar-benar terjadi. Kebocoran yang sama ternyata ada di kartu "Jumlah Realisasi"
dan "Nilai Dipesan" pada `/pengadaan` serta rekap laporan PDF. Menariknya,
halaman **detail** penyedia sudah jujur sejak awal karena tiap barisnya membawa
badge status; yang menyesatkan hanya angka ringkasnya.

**Pembatalan sedang dipakai sebagai tombol "ubah".** Pembatalannya terjadi
*karena salah ketik*. Sejak ADR 0024 pembatalan bersifat terminal bagi usulannya,
sehingga satu angka nol yang kelebihan pada kolom harga memaksa Pengelola Gudang
mengajukan usulan baru dari nol, dan meninggalkan satu baris realisasi batal
permanen di riwayat penyedia. `KesalahanPencatatan` yang ditambahkan ADR 0024
sebagai jalan keluar salah ketik ternyata jauh terlalu mahal — padahal selama
status masih `MenungguPenerimaan` belum ada apa pun yang terjadi secara fisik.

**Yang dikerjakan.**

- Rute `GET pengadaan/{procurement}/edit` dan `PUT pengadaan/{procurement}` di
  bawah `kelola-pengadaan`, beserta `UpdateProcurementRequest` dan
  `pages/procurements/edit.blade.php`. Boleh diubah: penyedia, tanggal, jumlah
  pesan, dan harga satuan. Tidak boleh: `procurement_request_id` dan susunan
  barangnya.
- Baris diperbarui **di tempat**, dikunci `procurement_detail_id`, supaya
  formulir penerimaan tetap menunjuk baris yang sama. Kiriman wajib mencakup
  seluruh baris.
- `update()` memanggil `NotificationScanner::periksaRealisasi()` — isi
  `RealisasiMenungguPenerimaan` memuat nama penyedia, jadi basi begitu
  penyedianya diganti.
- Tombol **Ubah Realisasi** diletakkan di atas **Batalkan Realisasi**: urutan
  tombol adalah anjuran tentang mana yang seharusnya lebih sering dipakai.
- `AlasanPembatalan::KesalahanPencatatan` menyempit — tinggal untuk realisasi
  yang dibuat dari usulan yang sama sekali salah, satu-satunya kekeliruan yang
  memang tidak dapat diperbaiki dengan mengubah.
- Agregat di tiga tempat mengecualikan realisasi batal, dengan cacah pembatalan
  selalu ikut ditampilkan: daftar penyedia (`SupplierController::cacahPengadaan()`
  dipakai bersama daftar dan detail), kartu `/pengadaan`, dan rekap laporan PDF.
  Barisnya sendiri **tetap ditampilkan** di mana pun — ditampilkan, tidak
  dihitung.
- Catatan kaki laporan PDF ditulis ulang: sejak nilai batal tidak lagi masuk
  "Nilai Dipesan", selisih dua angka nilai kembali hanya punya dua sebab.
- 22 uji baru: `ProcurementEditTest` (16) plus tambahan di `ProcurementReportTest`
  dan `SupplierManagementTest`.

**Mengapa usul menghapus ditolak.** Tiga akibatnya. Kolom `cancelled_by`,
`cancel_reason`, dan `cancel_note` yang baru dibuat kemarin menjadi sia-sia.
Menghapus barisnya membebaskan kembali indeks unik `procurement_request_id`
sehingga usulan asalnya hidup lagi — membalik keputusan "pembatalan bersifat
terminal" lewat pintu belakang. Dan yang paling menentukan: pola "lima realisasi
dibatalkan kepada penyedia yang sama" justru tepat hal yang ingin dilihat
pemeriksa pada lembaga pemeriksa keuangan; menghapusnya bukan membersihkan data,
melainkan menghapus temuan.

**Temuan saat verifikasi.** Jejak audit sempat menampilkan dua entri "Mengubah
realisasi pengadaan" padahal hanya satu pengubahan yang dilakukan lewat skrip.
Pemeriksaan kronologi `audit_logs` menunjukkan entri pertama berasal dari Naren
sendiri yang sedang mencoba fitur barunya di peramban pada menit yang sama —
dibuat 02:08:46, diubah 02:08:58, dibatalkan 02:09:50. Penjaga statusnya terbukti
benar: realisasi yang sudah `diterima` tidak punya satu pun jejak pengubahan.

**Verifikasi.** 305 test lulus (890 asersi), naik dari 283 — 22 uji baru, tanpa
regresi. Pint bersih pada 23 berkas. `migrate:fresh --seed` berhasil.

**Uji HTTP nyata.** Realisasi dibuat dengan harga sengaja salah (Rp99.999), lalu
diperbaiki lewat formulir Ubah Realisasi menjadi Rp12.500 sekaligus berganti
penyedia: nilai total ikut berubah, jejak audit tercatat, dan isi notifikasi
lonceng gudang menyebut penyedia yang baru. Setelah itu daftar penyedia
menampilkan `1×` dengan `1 dibatalkan` di bawahnya, dan rekap `/pengadaan`
menampilkan `2` dengan `1 dibatalkan` — persis keluhan yang memicu sesi ini.

---

## 7 September 2026 — Menutup celah alur pengadaan

Bukan tahap baru, melainkan tindak lanjut pemeriksaan menyeluruh sehari setelah
modul Pengadaan dinyatakan selesai. Yang ditemukan bukan bug di kode yang baru
ditulis, melainkan dua lubang di **alurnya sendiri**. Keputusan lengkap di
[ADR 0024](keputusan/0024-pembatalan-realisasi-pengadaan.md).

**Jalan buntu yang ditemukan.** `ProcurementStatus::Dibatalkan` ada di enum
sejak Tahap 1 tetapi tidak pernah punya pemicu, dan
[modul/pengadaan.md](modul/pengadaan.md) mencatatnya sebagai "belum ada
kebutuhan lapangan yang mengonfirmasinya". Ternyata kebutuhannya sudah tersirat
di ADR 0016 sendiri: pembelian barang terjadi **di luar sistem**, dan di ruang
itulah penyedia gagal kirim. Ketika itu terjadi, realisasinya terkunci
`MenungguPenerimaan` selamanya, notifikasi `RealisasiMenungguPenerimaan` aktif
selamanya, dan satu-satunya jalan keluar adalah mengonfirmasi penerimaan barang
yang tidak pernah datang — persis kebohongan yang dicegah ADR 0017.

**Titik buta yang ditemukan.** `periksaUsulan()` hanya bekerja selama usulan
`isDiajukan()`, sehingga jarak antara "disetujui" dan "direalisasi" adalah
satu-satunya perpindahan tangan di seluruh alur yang tidak punya penagih.

**Yang dikerjakan.**

- Migrasi `add_cancellation_to_procurements_table` (empat kolom nullable:
  `cancelled_at`, `cancelled_by`, `cancel_reason`, `cancel_note`) dan enum
  `AlasanPembatalan` dengan lima case, meniru bentuk `AlasanSelisih`.
- Rute `PATCH pengadaan/{procurement}/batal` di bawah `kelola-pengadaan`,
  `ProcurementController::cancel()`, dan `CancelProcurementRequest` dengan
  keterangan **wajib** apa pun alasan yang dipilih (pola ADR 0011).
- Pembatalan **hanya dari `MenungguPenerimaan`**, sehingga tidak ada satu pun
  mutasi stok yang perlu dibalik dan `StockLedger` tidak tersentuh sama sekali
  — inilah yang membuat fitur ini aman.
- Dua notifikasi baru: `RealisasiDibatalkan` (peristiwa, ke gudang beserta
  alasan dan keterangannya — keadaan lamanya menutup diri sendiri, tetapi
  menghilang diam-diam tidak cukup bagi orang yang sudah disuruh menunggu
  barang) dan `UsulanBelumDirealisasi` (keadaan, ambang 7 hari, dilipat ke
  dalam `periksaUsulan()` yang sudah ada).
- Saran nomor BAST kini dicacah per tahun berjalan lewat `received_at`;
  sebelumnya memakai `count()` sepanjang masa, sehingga BAST pertama Januari
  2027 akan disarankan `015/BAST/2027`.
- `ConfirmProcurementReceiptRequest` mewajibkan seluruh baris barang terisi.
  Sebelumnya baris yang hilang dari kiriman terbaca `?? 0` — nol diterima,
  **permanen**, karena penerimaan peristiwa sekali jadi tanpa rute ubah. Ini
  alasan yang sama yang membuat ADR 0021 mewajibkan formulir realisasi mencakup
  seluruh rincian usulan yang disetujui.
- `ProcurementRequestSeeder` mendapat usulan kelima (`disetujuiMenganggur`,
  ATK-007, sembilan hari) supaya `UsulanBelumDirealisasi` punya contoh nyata.
  Umurnya sengaja sembilan hari, bukan lebih tua daripada usulan sepuluh hari
  yang sudah ada, karena `ProcurementSeeder` memilih usulan tertua yang belum
  direalisasi dan usulan baru ini tidak boleh merebut gilirannya.

**Keputusan yang paling menentukan.** Pembatalan bersifat **terminal bagi
usulannya**: indeks unik `procurements.procurement_request_id` dipertahankan,
sehingga usulan yang realisasinya batal tidak dapat direalisasikan ulang.
Alternatifnya — melepas indeks unik supaya usulan dapat direalisasi ke penyedia
lain — ditolak karena memindahkan jaminan ADR 0016 dari basis data ke kode
(MySQL tidak mendukung indeks unik parsial). Konsekuensinya harus terlihat di
layar, jadi halaman usulan menjelaskan mengapa tombol "Realisasikan" tidak ada;
tanpa itu, status `Disetujui` tanpa tombol terbaca seperti bug.

**Temuan saat verifikasi.** Pemeriksaan HTTP sempat terlihat seperti menemukan
bug — notifikasi `UsulanBelumDirealisasi` tetap 2 setelah usulan direalisasi.
Ternyata usulan yang direalisasi dan usulan yang ditagih adalah **dua baris
berbeda** yang delapan karakter pertama UUID-nya identik (`01A077D3`), gotcha
UUID v7 yang sudah tercatat di `CLAUDE.md` sejak Tahap 3. Pemeriksaan ulang
dengan UUID penuh membuktikannya benar. Meski begitu satu celah nyata memang
ada dan ikut ditutup: `ProcurementController::store()` belum memanggil
`periksaUsulan()`, sehingga tagihan bertahan sampai sapuan terjadwal berikutnya
— tidak salah menurut ADR 0014, tetapi tidak sejalan dengan
`catatUsulanDiputuskan()` yang menyegarkan keadaan usulannya seketika.

**Verifikasi.** 283 test lulus (825 asersi), naik dari 258 — 25 uji baru, tanpa
regresi. Pint bersih pada 27 berkas. `migrate:fresh --seed` berhasil dan
`notifikasi:periksa` menghasilkan 12 notifikasi aktif, termasuk 2
`UsulanBelumDirealisasi` dari data contoh baru.

**Uji HTTP nyata.** Realisasi dibuat lalu dibatalkan lewat peramban sungguhan:
halaman realisasi menampilkan kartu pembatalan lengkap dengan alasan dan
keterangan, halaman usulan menampilkan penjelasan tanpa tombol "Realisasikan",
`RealisasiMenungguPenerimaan` tertutup, dan lonceng akun `gudang` menerima
"Dibatalkan: Realisasi #01A077D5" beserta alasan "Penyedia Gagal Kirim".

**Catatan.** Masih tersisa satu keadaan tanpa jalan keluar yang setara: usulan
`Disetujui` yang barangnya sudah tidak dibutuhkan hanya akan ditagih terus oleh
`UsulanBelumDirealisasi`. Dicatat sebagai butir menunggu lapangan — kali ini
dengan kesadaran bahwa catatan serupa untuk `Dibatalkan` ternyata menyembunyikan
jalan buntu selama satu tahap penuh.

---

## 6 September 2026 — Pengadaan Tahap 4: Dokumen, BAST, dan laporan

Tahap penutup modul pengadaan, sekaligus penutup seluruh sub sistem. Tidak ada
migrasi baru — `procurement_documents` sudah mendarat di Tahap 1, satu tahap
sebelum fiturnya ditulis. Keputusan baru dicatat di dua ADR:
[ADR 0022](keputusan/0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md)
(wewenang berkas) dan
[ADR 0023](keputusan/0023-laporan-pengadaan-per-baris-barang.md)
(bentuk laporan dan isi BAST).

**Yang dikerjakan.**

- `App\Support\DocumentStorage` sebagai satu-satunya seam penyimpanan berkas:
  path `pengadaan/{procurement_id}/{uuid}.{ext}` di disk privat, pengacakan
  nama, penghapusan, dan pemeriksaan keberadaan berkas. Controller tidak pernah
  menyebut nama disk maupun menyusun path.
- `ProcurementDocumentController` (unggah / unduh / hapus) beserta
  `StoreProcurementDocumentRequest` — `mimes:pdf,jpg,jpeg,png`, maksimal 5 MB.
  Ketiganya di bawah gate `akses-pengadaan`; penghapusan dipersempit lagi di
  controller menjadi pengunggahnya sendiri atau Admin.
- BAST PDF (`pdf/bast-pengadaan.blade.php`): dokumen naratif dua pihak, bukan
  tabel laporan. Seluruh angkanya `amount_received`, dan kiriman kurang
  menghasilkan paragraf catatan yang menyebut jenis serta jumlah kekurangannya.
- Laporan pengadaan PDF + CSV (`procurements.pdf`, `procurements.csv`) berbutir
  **satu baris per barang per realisasi**, mengikuti empat penyaring yang sama
  dengan layar: status, penyedia, tanggal awal, tanggal akhir.
  `Procurement::scopeFilter()` diperluas dari satu penyaring menjadi empat, dan
  kini dipakai bersama oleh layar, PDF, CSV, dan penjaga batas ekspor.
- Rekap di halaman daftar diubah menjadi **mengikuti penyaring** (sebelumnya
  selalu menghitung seluruh basis data) dan ditambah dua kartu nilai: Nilai
  Dipesan dan Nilai Diterima, dihitung lewat subkueri ke `procurement_details`.
  `Procurement::nilaiDiterima()` melengkapi `nilaiTotal()` yang sudah ada.
- Notifikasi keadaan `BastBelumDiunggah` (ambang 3 hari, ke Gudang + Admin),
  dilipat ke dalam `periksaRealisasi()` yang sudah ada sehingga ikut terpicu
  jalur reaktif maupun sapuan terjadwal `notifikasi:periksa`. Ditutup oleh
  unggahan BAST, berlaku lagi bila berkasnya dihapus.
- Kartu "Berkas Pendukung" pada halaman realisasi (daftar + formulir unggah +
  unduh + hapus), tombol "Cetak BAST" pada kartu penerimaan, dan tombol
  Ekspor CSV / Cetak PDF pada halaman daftar — pola tombol yang sama dengan
  halaman Riwayat Mutasi.
- `ProcurementSeeder`: tanggal penerimaan contoh dimundurkan ke 5 hari lalu
  (dari 1 hari) supaya melewati ambang tiga hari — `BastBelumDiunggah` kini
  punya contoh nyata di data awal, sejalan dengan alasan kiriman kurang
  sengaja diseed di Tahap 3.
- 42 uji baru dalam dua berkas: `ProcurementDocumentTest` (25) dan
  `ProcurementReportTest` (17).

**Keputusan yang sempat hampir diambil terbalik.** Godaan pertama adalah
membagi izin unggah menurut jenis dokumen — BAST hanya Pengelola Gudang, SPK
hanya Pejabat Pengadaan — supaya konsisten dengan pemisahan tugas ADR 0016.
Ditolak setelah disadari bahwa yang dipisah ADR 0016 adalah wewenang
**memutuskan**, bukan wewenang melampirkan bukti; sebuah pindaian tidak
menggerakkan status apa pun dan tidak menyentuh stok. Sistem yang menolak
lampiran yang sudah benar tidak membuat orang menunggu orang yang "berhak",
melainkan membuat berkas itu beredar di luar sistem. Yang dibutuhkan adalah
atribusi, dan `uploaded_by` + `audit_logs` sudah menyediakannya. Alasan lengkap
beserta empat alternatif lain di ADR 0022.

**Temuan saat menulis uji.** `$this->actingAs()` di dalam `beforeEach` membuat
sesi uji tetap terautentikasi sepanjang test case, sehingga uji "tamu diarahkan
ke halaman masuk" justru menerima 200. Berkas uji Tahap 2 dan 3 tidak
terpengaruh karena membangun datanya di dalam tiap uji, bukan di `beforeEach`.
Diperbaiki dengan `auth()->logout()` eksplisit di awal uji tamu — bukan dengan
memindahkan pembangunan data, karena `beforeEach` yang berbagi satu realisasi
justru yang membuat berkas uji ini terbaca.

**Verifikasi.** 258 test lulus (762 asersi), naik dari 216 — 42 uji baru, tanpa
regresi. Pint bersih pada 10 berkas baru/ubahan; `routes/web.php` tetap
dilaporkan bermasalah, tetapi isunya pra-ada di blok `permintaan` milik Alma
(dibuktikan dengan menjalankan Pint pada versi `HEAD` berkas itu) sehingga
sengaja tidak disentuh. `migrate:fresh --seed` berhasil dari basis data kosong,
dan `notifikasi:periksa` menghasilkan 2 baris `BastBelumDiunggah` — satu untuk
Pengelola Gudang, satu untuk Admin, persis penerima yang dimaksud.

**Uji HTTP nyata** (server hidup, MySQL, disk sungguhan — bukan `Storage::fake`):
laporan PDF 1,6 MB dan BAST 880 KB keduanya diawali `%PDF-1.7`; CSV memuat
`40;15000;600000;40;0;600000` untuk barang yang lengkap dan
`20;25000;500000;15;5;375000` untuk yang kurang kirim, sehingga dua sisi nilai
ADR 0017 benar-benar terlihat terpisah. Unggahan 880 KB mendarat di
`storage/app/private/pengadaan/{id}/{uuid}.pdf` dengan nama teracak, diunduh
kembali utuh dengan `Content-Disposition` bernama asli `BAST Ditandatangani.pdf`,
dan `BastBelumDiunggah` turun 2 &rarr; 0 seketika. Janji keamanan ADR 0018
diuji langsung: `/storage/{path}` menjawab 403, `/{path}` menjawab 404, dan
rute unduh tanpa sesi mengarahkan ke `/login`. Penghapusan oleh akun
`pengadaan` (bukan pengunggahnya) ditolak dengan pesan dan barisnya tetap ada;
penghapusan oleh `gudang` membuang baris **dan** berkas fisiknya, lalu
`BastBelumDiunggah` berlaku lagi.

**Catatan.** Satu konsekuensi ADR 0018 belum ada yang memanggilnya: menghapus
sebuah pengadaan harus ikut membuang berkasnya lewat `DocumentStorage`, karena
`cascadeOnDelete` pada basis data tidak menyentuh berkas fisik. Rute hapus
pengadaan memang belum ada, jadi tidak ada kode mati yang ditulis untuk itu —
tetapi inilah hal pertama yang harus diingat bila rute itu kelak dibuat.

---

## 6 September 2026 — Pengadaan Tahap 3: Realisasi dan Penerimaan

Inti modul pengadaan: realisasi dari usulan yang disetujui, dan konfirmasi
penerimaan yang benar-benar menambah stok. Tidak ada migrasi baru — seluruh
kolom yang dibutuhkan (`unit_price`, `amount_received`) sudah mendarat di
Tahap 1. Keputusan lengkap di
[ADR 0021](keputusan/0021-realisasi-dan-penerimaan-pengadaan.md).

**Yang dikerjakan.**

- `ProcurementController` (realisasi, gate `kelola-pengadaan` untuk
  create/store, `akses-pengadaan` untuk index/show) dan
  `ProcurementReceiptController` (penerimaan, gate `kelola-gudang`,
  create/store saja — peristiwa sekali jadi tanpa rute ubah) — tiga
  controller terpisah sesuai rancangan ADR 0016.
- `App\Support\ProcurementReceiptPoster`, mengikuti pola `OpnamePoster`:
  transaksi terkunci, `StockLedger::record(type: Masuk, amount: amount_received)`
  per barang (baris nol tidak menghasilkan mutasi), status realisasi menjadi
  `Diterima`, baris `ProcurementReceipt` dibuat, lalu `AuditLogger` dan
  notifikasi dipanggil di luar transaksi.
- `StoreProcurementRequest` (form request realisasi) menurunkan `item_id`
  server-side dari `procurement_request_detail_id` lewat `rincianDisetujui()`,
  pola yang sama dengan `ApproveProcurementRequestRequest` Tahap 2 — klien
  tidak pernah mengirim `item_id` secara langsung.
  `ConfirmProcurementReceiptRequest` memvalidasi jumlah diterima tanpa batas
  atas (hanya kekurangan kiriman yang jadi perhatian ADR 0017).
- Tombol "Realisasikan" pada halaman usulan (tampil hanya bila `Disetujui`
  dan belum punya realisasi) dan "Konfirmasi Penerimaan" pada halaman
  realisasi (tampil hanya bila masih `MenungguPenerimaan`) — pola jalur masuk
  yang sama dengan tombol usulan pengadaan pada halaman barang di Tahap 2.
- `NotificationScanner::periksaRealisasi()`/`periksaSeluruhRealisasi()`/
  `catatPenerimaanDikonfirmasi()`, dan dua jenis notifikasi baru sesuai
  rencana yang sudah dicatat sejak modul Notifikasi dibangun:
  `RealisasiMenungguPenerimaan` (keadaan, ke Pengelola Gudang + Admin) dan
  `PenerimaanDikonfirmasi` (peristiwa, ke Pejabat Pengadaan yang merealisasi).
- `ProcurementSeeder`: satu realisasi contoh dibawa **sampai diterima lewat
  `ProcurementReceiptPoster::post()` sungguhan** (bukan baris yang ditulis
  langsung ke tabel), sengaja dibuat diterima sebagian (tinta warna kurang 5
  botol) supaya `ProcurementDetail::kurangKirim()` punya contoh nyata.
  `ProcurementRequestSeeder` mendapat usulan keempat (`disetujuiPenuh`,
  ATK-012) yang sengaja dibiarkan belum direalisasikan untuk mendemokan
  tombol "Realisasikan".
- 15 uji baru: matriks akses tiga gate (realisasi) dan gate tunggal
  (penerimaan), validasi (usulan belum disetujui, realisasi ganda, jumlah
  melebihi disetujui), jalur pintas dari halaman usulan, dan yang paling
  penting — uji yang membuktikan stok bertambah sebanyak `amount_received`
  bukan `amount` lewat HTTP sungguhan.

**Temuan saat verifikasi manual (bukan bug, tapi layak dicatat).** Dua
dokumen yang dibuat pada detik yang sama saat `migrate:fresh --seed`
menampilkan label 8-karakter (`judul()`) yang identik di tampilan
(`Realisasi #01A076E9` dan `Usulan #01A076E9`) padahal UUID sebenarnya
berbeda total. Penyebabnya `HasUuids` menghasilkan UUID v7 berurut yang
prefiksnya berbasis waktu; kemunculannya sama karena dibuat pada milidetik
yang berdekatan dalam satu proses seeding. Tidak memengaruhi fungsi apa pun
(route model binding dan relasi memakai UUID penuh), tetapi dicatat sebagai
gotcha di `CLAUDE.md` supaya tidak disangka bug saat pemeriksaan berikutnya.

**Verifikasi.** 216 test lulus (656 asersi), naik dari 201 — 15 uji baru,
tanpa regresi. Pint bersih pada seluruh 13 berkas baru/ubahan inti (dua
berkas lain — `MenuHelper.php` dan `routes/web.php` — punya isu gaya
pra-ada di blok milik Alma, sengaja tidak disentuh sesuai aturan cakupan
sempit). `migrate:fresh --seed` berhasil dari basis data kosong; pemeriksaan
lewat `tinker` membuktikan stok ATK-003 bertambah 40 (kiriman penuh) dan
ATK-004 bertambah 15 dari 20 dipesan (kiriman kurang, `kurangKirim()` = 5),
notifikasi `PenerimaanDikonfirmasi` tercatat 1 baris, dan keadaan
`RealisasiMenungguPenerimaan` tertutup begitu diterima.

**Berikutnya.** Tahap 4 — Dokumen, BAST, dan laporan: unggahan berkas ke disk
privat, BAST PDF, ekspor PDF/CSV, dan notifikasi keadaan `BastBelumDiunggah`.

---

## 6 September 2026 — Pengadaan Tahap 2: Usulan Pengadaan

Modul Pengadaan sesungguhnya, menyusul rancangan skema Tahap 1. Tidak ada
migrasi baru — seluruh kolom yang dibutuhkan (`source`, `status`,
`amount_granted`) sudah mendarat di Tahap 1.

**Keputusan yang baru diambil saat pengodean** (belum ditetapkan ADR 0016):
bagaimana persetujuan sebagian ditangkap, dan katalog notifikasi usulan.
Keduanya didokumentasikan di
[ADR 0020](keputusan/0020-notifikasi-usulan-dan-persetujuan-sebagian.md):

- Persetujuan **satu dokumen sekali putus** lewat peta `granted[]` per barang,
  bukan satu aksi per barang — usulan dengan banyak barang tetap satu
  permintaan HTTP untuk satu keputusan.
- Menolak mengisi `amount_granted = 0` eksplisit pada seluruh barang, karena
  `null` sudah bermakna "belum diputuskan" pada model.
- Empat notifikasi baru: `UsulanMenungguPersetujuan`/`UsulanMenggantung`
  (keadaan, saling eksklusif dengan ambang 7 hari, meniru pola
  `OpnameSiapDiposting`/`OpnameDrafMenggantung`), `UsulanDisetujui`/`UsulanDitolak`
  (peristiwa ke pengaju).
- `source` bukan field pilihan pengguna — diturunkan dari titik masuk
  formulir (tombol pada halaman barang vs navigasi langsung). Sumber
  `DariPermintaan` sengaja diblokir di validasi: integrasi ke sub sistem
  Permintaan belum punya titik masuk aplikasi.

**Yang dikerjakan.**

- `ProcurementRequestController`: `index`/`show` di bawah gate `akses-pengadaan`
  (kedua peran baca), `create`/`store` di bawah `kelola-gudang` (Pengelola
  Gudang mengusulkan), `approve`/`reject` di bawah `kelola-pengadaan` (Pejabat
  Pengadaan memutuskan) — tiga gate berbeda pada satu controller, pola baru di
  proyek ini.
- `StoreProcurementRequestRequest` dan `ApproveProcurementRequestRequest`;
  yang kedua memvalidasi `granted.*` terhadap `amount_request` sesungguhnya
  lewat `withValidator()` yang membaca model rute, pola yang sama dengan
  `UpdateOpnameCountRequest`.
- Tombol "Ajukan Usulan Pengadaan" pada halaman barang, muncul hanya saat
  status stok Habis/Menipis dan pengguna punya gate `kelola-gudang`; membawa
  `?item_id=` ke formulir usulan yang otomatis memilihkan barangnya.
- `NotificationScanner::periksaUsulan()`/`periksaSeluruhUsulan()`/
  `catatUsulanDiputuskan()`, mendampingi method sejenis untuk stok dan opname.
- Tiga halaman (`index`, `create`, `show`) — pemilih barang pada `create`
  memakai ulang pola pencarian Alpine.js yang sudah ada pada formulir
  permintaan barang (Alma), disesuaikan nama field dan tanpa batas stok
  (usulan pengadaan justru mengusulkan menambah stok).
- `ProcurementRequestSeeder`: tiga usulan contoh (Diajukan, Disetujui
  sebagian, Ditolak) supaya modul langsung bisa dicoba.
- 22 uji baru: matriks akses tiga gate, validasi (barang kosong, duplikat,
  jumlah nol, sumber diblokir), persetujuan sebagian, penolakan mengisi nol,
  penjagaan usulan yang sudah diputuskan, dan jalur pintas dari halaman
  barang.

**Gotcha yang ditemukan.** Rute `usulan-pengadaan/create` (GET) harus
terdaftar sebelum `usulan-pengadaan/{procurementRequest}` (GET) di
`routes/web.php`, walau keduanya berada di grup gate yang berbeda — kalau
tidak, "create" tertangkap sebagai parameter dan gagal *route model binding*.
Diselesaikan dengan urutan pendaftaran grup, dicatat sebagai gotcha di
`CLAUDE.md`.

**Verifikasi.** 201 test lulus (608 asersi), naik dari 179 — 22 uji baru,
tanpa regresi. Pint bersih pada seluruh berkas baru/ubahan (satu isu gaya
pra-ada pada `MenuHelper.php` milik blok Alma sengaja tidak disentuh, sesuai
aturan cakupan sempit). `migrate:fresh --seed` **belum diverifikasi** sesi ini
karena MySQL (XAMPP) tidak berjalan di lingkungan pengembangan saat itu —
tidak ada migrasi baru yang berisiko, tetapi tetap perlu dijalankan sebelum
Tahap 3 dimulai.

**Berikutnya.** Tahap 3 — Realisasi dan Penerimaan: `ProcurementReceiptPoster`
yang menambah stok lewat `StockLedger` sebanyak `amount_received`, bukan
`amount` (ADR 0017).

---

## 6 September 2026 — Pengadaan Tahap 1: rancangan alur, skema, dan Penyedia

Sesi perancangan modul Pengadaan, dilanjutkan pengerjaan tahap pertamanya.
Modul ini dipecah **empat tahap** karena jauh lebih besar dari modul mana pun
sebelumnya; ini tahap pertama.

**Batas cakupan yang disepakati.** Sistem hanya menangkap proses pengadaan di
dalam kantor: usulan → realisasi → penerimaan. Pembelian barangnya sendiri
terjadi di luar sistem, di antara realisasi dan penerimaan.

**Temuan utama diskusi: penerimaan harus boleh jujur.**
`procurement_details` cuma punya satu kolom jumlah (`amount`), sehingga stok
akan bertambah sebanyak yang *dipesan*. Padahal penyedia sering mengirim
kurang. Tanpa tempat mencatat "pesan 100, datang 80", petugas hanya punya tiga
pilihan yang semuanya buruk: tidak mengonfirmasi (stok sistem tertinggal dari
fisik), mengonfirmasi angka palsu (opname berikutnya memunculkan selisih
misterius), atau menambal lewat mutasi `KoreksiPencatatan` yang namanya
berbohong — kekurangan kiriman bukan kesalahan pencatatan. Kolom
`amount_received` menutup ketiganya sekaligus. Ini perubahan tunggal paling
menentukan di modul ini ([ADR 0017](keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)).

**Kesalahan urutan yang ikut ketahuan.**
`procurement_receipts.file_url` bersifat **NOT NULL**, artinya penerimaan tidak
dapat dikonfirmasi sebelum berkas BAST ada. Padahal urutan fisiknya justru
sebaliknya: cetak → tanda tangan → pindai. Skema lama memblokir pencatatan stok
di belakang alur pemindaian. Sekarang berkasnya boleh menyusul, ditagih
notifikasi keadaan yang menutup diri sendiri
([ADR 0018](keputusan/0018-berkas-unggahan-di-disk-privat.md)).

**Butir terbuka ADR 0015 ditutup.** Kolom `status` pada
`procurement_request_details` dihapus — ia duplikat `amount_granted` (null =
belum diputuskan, 0 = ditolak, N = disetujui sejumlah N), tanpa enum maupun
cast, dan menyimpan keduanya berarti dua sumber kebenaran.

**Yang dikerjakan.**

- Enam migrasi: `suppliers` (baru), `procurement_documents` (baru),
  `procurements` ditautkan ke penyedia, `unit_price` + `amount_received` pada
  rincian realisasi, penghapusan `status` pada rincian usulan, dan penghapusan
  `file_url` pada penerimaan.
- Enum `ProcurementDocumentType`; model `Supplier` dan `ProcurementDocument`;
  pemutakhiran `Procurement`, `ProcurementDetail`, `ProcurementRequestDetail`,
  dan `ProcurementReceipt`.
- Gate baru `akses-pengadaan` — kedua peran perlu membaca dokumen pengadaan
  yang sama, sedangkan `can:` yang ditumpuk bersifat DAN, bukan ATAU.
- Modul **Penyedia Barang** lengkap: controller, trio form request, empat
  halaman, rute, factory, seeder, menu.
- Empat ADR: [0016](keputusan/0016-alur-pengadaan-tiga-dokumen.md) (alur tiga
  dokumen dan pemisahan tugas), [0017](keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md),
  [0018](keputusan/0018-berkas-unggahan-di-disk-privat.md), dan
  [0019](keputusan/0019-harga-di-realisasi-tanpa-penilaian-persediaan.md)
  (harga dicatat, penilaian persediaan di luar cakupan).

**Celah yang sekalian ditutup.** `User` tidak punya relasi
`procurementReceipts()` maupun entrinya di `User::RESTRICTED_RELATIONS`,
padahal FK `received_by` sudah `restrictOnDelete()` — pengarsipan akun bisa
salah hitung.

**Catatan.** `CLAUDE.md` mulai sesi ini ditulis dalam bahasa Inggris atas
permintaan Narendra. Dokumentasi di folder ini tetap bahasa Indonesia.

**Verifikasi.** 179 test lulus (161 dasar + 18 baru), tanpa regresi. Pint bersih
pada 29 berkas cakupan sempit. `migrate:fresh --seed` membuktikan seluruh 34
migrasi berjalan dari basis data kosong, termasuk penambahan kolom NOT NULL
tanpa default pada tabel yang sudah ada.

**Berikutnya.** Tahap 2 — Usulan Pengadaan beserta notifikasinya.

---

## 5 September 2026 — Perbaikan skema Pengadaan sebelum modulnya dibangun

Sebelum menulis satu baris controller pun untuk modul Pengadaan, migrasi
warisan ERD-nya (`procurements`, `procurement_details`, `procurement_requests`,
`request_details`) dibaca ulang untuk memastikan tidak ada yang perlu
disepakati dengan Alma lebih dulu — CLAUDE.md mencatatnya sebagai syarat wajib.

**Yang tadinya dikira tiga "perbatasan" ternyata bukan perbatasan sama
sekali.** Pembacaan literal terhadap migrasi menyimpulkan tiga hal perlu
dibahas dengan Alma: kontrak `procurement_requests.request_id → requests`,
kepemilikan `basts`/`nota_dinas`, dan sumber nomor dokumen. Setelah
diklarifikasi langsung, ketiganya bubar sekaligus:

- `procurement_requests.request_id` **bukan foreign key yang disengaja** —
  sisa kebingungan penamaan primary key (`procurement_request_id` yang
  sekarang sudah benar sejak awal). Bukan relasi apa pun.
- `request_details` **ternyata bukan rincian permintaan unit milik Alma**,
  melainkan rincian usulan pengadaan milik sub sistem ini sejak awal — salah
  nama dan salah arah FK.
- `basts`/`nota_dinas` **sepenuhnya milik Alma**. BAST penerimaan barang dari
  penyedia ke gudang (sisi Pengadaan) memang belum pernah punya tempat di
  skema — desain awal cuma bermaksud menyimpannya sebagai berkas hasil pindai
  lewat kolom string bebas (`procurements.bast_scan`), dan itu sekarang
  dinilai desain yang kurang baik.

Sekaligus, narasumber lapangan (Kasubbag Umum dan TI) mengonfirmasi usulan
pengadaan lahir dari **tiga jalur berbeda**: permintaan unit yang stoknya
kurang, pemantauan stok otomatis (notifikasi Stok Habis/Menipis yang sudah
dibangun), dan diajukan manual oleh Pengelola Gudang.

**Hasilnya: tidak ada lagi satu pun butir yang menunggu kesepakatan dengan
Alma sebelum modul Pengadaan dibangun.** Lihat
[ADR 0015](keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) untuk
keputusan skema lengkap.

**Yang dikerjakan.**

- Tiga enum baru: `ProcurementRequestSource` (tiga asal-usul usulan),
  `ProcurementRequestStatus` (Diajukan/Disetujui/Ditolak, status usulan),
  `ProcurementStatus` (MenungguPenerimaan/Diterima/Dibatalkan, status
  realisasi — nama case `MenungguPenerimaan` sengaja memakai istilah persis
  dari ADR 0008). Status dipecah jadi dua enum, bukan satu enum lima-tahap
  tunggal, karena usulan dan realisasi adalah dua dokumen berbeda.
- Empat migrasi susulan: `procurement_requests` (hapus `request_id`/
  `request_type`, tambah `source`/`reference_type`/`reference_id`/`status`),
  rename `request_details` → `procurement_request_details` (PK dan FK
  diperbaiki menunjuk `procurement_requests`), `procurements` (tambah
  `procurement_request_id` wajib+unik, hapus `bast_scan`), dan tabel baru
  `procurement_receipts` (BAST penerimaan, `received_by` sengaja terpisah
  dari pengaju realisasi — pemisahan tugas ADR 0008 tercermin di skema, bukan
  cuma alur aplikasi).
- `reference_type`/`reference_id` polimorfik nullable pada
  `procurement_requests`, pola yang sama dengan `stock_mutations.reference_*`
  (ADR 0007), lewat alias morph map `permintaan` yang sudah terdaftar. Diisi
  hanya untuk sumber `DariPermintaan` — **bukan kewajiban**, sub sistem
  Pengadaan berfungsi penuh tanpa sub sistem Permintaan ada.
- Model diperbarui: `ProcurementRequest`, `Procurement`; model baru
  `ProcurementReceipt`; `RequestDetail` dihapus, diganti
  `ProcurementRequestDetail`.
- Perbaikan tak terhindarkan pada dua model warisan bersama:
  `App\Models\Request::details()`/`procurementRequests()` dihapus (dibangun
  di atas premis yang keliru — `requests` milik Alma belum punya tabel
  rincian barangnya sendiri sama sekali), dan `Item::requestDetails()`
  diganti nama `procurementRequestDetails()` beserta label pada
  `RESTRICTED_RELATIONS`.
- `naren-documentations/erd/penyelarasan-erd.md` ditulis ulang total: bagian
  "Perbatasan" dihapus, riwayat kesalahan desain dicatat eksplisit, dan
  atribusi Temuan #4 yang dulu keliru (sempat menuduh gambar ERD Alma yang
  salah, padahal migrasi Naren sendiri yang keliru) diperbaiki.

**Verifikasi.** 161 test tetap lulus (500 asersi) — tidak ada regresi. Pint
bersih pada 13 berkas dalam cakupan sempit. Keempat migrasi dijalankan di
MySQL setelah dipastikan seluruh tabel terkait kosong (tidak ada seeder untuk
Procurement/Request/Bast/NotaDinas, tidak ada controller yang pernah menulis
ke sana) — tidak perlu migrasi data.

**Berikutnya.** Modul Pengadaan sesungguhnya: controller, form request, view,
dan uji 4-kasus hak akses per aksi — mengikuti pola modul gudang. Kandidat
notifikasi untuk modul ini sudah dicatat di
[modul/notifikasi.md](modul/notifikasi.md).

---

## 4–5 September 2026 — Modul Notifikasi

Fitur kecil yang disisipkan sebelum modul Pengadaan, sekaligus menyiapkan
lahannya: modul Pengadaan tinggal menambah case enum untuk ikut memakainya.

**Keputusan inti — notifikasi stok bukan peristiwa, melainkan keadaan.**
Permintaan awalnya sederhana ("beri tahu kalau stok habis atau menipis"), tetapi
bentuk datanya tidak. "Stok kertas habis" tidak *terjadi* pada satu titik waktu;
ia **sedang benar**, dan bisa berhenti benar tanpa siapa pun menekan tombol.

Dua jalan buntu yang dihindari:

| Pendekatan | Akibatnya |
|---|---|
| Satu notifikasi per mutasi | Barang yang sudah habis tetap dimutasi. Lonceng terisi puluhan baris berbunyi sama dalam hitungan hari, lalu diabaikan orang |
| Satu notifikasi yang tak pernah ditutup | "Stok kertas habis" tetap terpampang setelah diisi kemarin — **menyesatkan**, dan itu lebih mahal daripada tidak ada |

Jadi tiap jenis menyatakan dirinya keadaan atau peristiwa lewat
`NotificationType::bersifatKeadaan()`. Keadaan menutup dirinya sendiri
(`resolved_at`) begitu pulih; peristiwa tidak pernah ditutup dan hilang setelah
dibaca ([ADR 0013](keputusan/0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md)).

Penopangnya satu hal teknis: indeks unik `(user_id, dedup_key)`, sehingga
seluruh pemeriksaan **idempoten**. `Notifier::terapkanKeadaan()` menerima sebuah
boolean, bukan perintah "kirim" — pemanggil tidak pernah perlu tahu apakah
notifikasinya sudah ada.

**Temuan teknis yang menentukan arah — `DB::afterCommit()` tidak pernah jalan di
bawah `RefreshDatabase`.** Rencana awal memasang hook otomatis di dalam
`StockLedger::record()` supaya semua pemanggil termasuk sub sistem Alma ikut
terlayani tanpa perlu ingat memanggil apa pun. Sebelum dipakai, mekanismenya
diperiksa langsung ke kode kerangka kerja:

- `DatabaseTransactionsManager::afterCommitCallbacksShouldBeExecuted()`
  mengembalikan `$level === 0` — callback hanya jalan saat transaksi terluar
  benar-benar commit.
- `RefreshDatabase::beginDatabaseTransaction()` membungkus **setiap uji** dalam
  transaksi yang di-rollback, tidak pernah di-commit.

Artinya level tidak pernah kembali ke nol di dalam uji, dan callback-nya tidak
pernah jalan sama sekali. Memilih mekanisme itu berarti membangun fitur yang
tidak dapat diuji. Diganti pemanggilan eksplisit seperti pola `AuditLogger`
([ADR 0014](keputusan/0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md)).

Yang menyelamatkan keputusan itu dari kerapuhannya adalah idempotensi tadi:
**pemeriksaan reaktif hanyalah percepatan, bukan syarat kebenaran.** Jalur yang
lupa memanggilnya tetap tertangkap pemeriksaan terjadwal; yang hilang cuma
kesegeraan, bukan datanya.

**Katalog disaring dengan dua ujian** — penerimanya belum tahu, dan ada yang bisa
dilakukan. Sepuluh jenis lolos: empat keadaan stok, tiga keadaan opname, tiga
peristiwa. Yang dibuang antara lain "Anda mencatat mutasi" (gagal ujian pertama)
dan "akun Anda dinonaktifkan" (gagal ujian kedua — penerimanya sudah ditendang
keluar sesi).

Tiga jenis yang tidak terpikir di permintaan awal tetapi bernilai tinggi:

- **Mutasi Manual Sensitif** — Rusak/Hilang/Kadaluarsa/Koreksi mengurangi stok
  **tanpa dokumen sumber**. Pada instansi pemeriksa, justru inilah yang paling
  layak diketahui Admin. Pelakunya sendiri tidak diberi tahu.
- **Stok Bergerak Setelah Dicacah** — `OpnameDetail::stokBerubahSejakDicacah()`
  sudah ada sejak modul opname tetapi **tidak pernah ditampilkan kepada siapa
  pun**.
- **Periode Belum Diopname** — memanfaatkan enum `PeriodeOpname` yang sudah ada.

**Yang dikerjakan.**

- Dua enum: `NotificationType` (10 case), `NotificationLevel` (Mendesak /
  Perhatian / Info, dengan `bobot()` untuk pengurutan).
- Migrasi `user_notifications` — **bukan** `notifications`, karena `User` sudah
  memakai trait `Notifiable` yang mengklaim nama itu beserta bentuk kolomnya.
- `app/Support/Notifier.php` dan `app/Support/NotificationScanner.php`,
  mendampingi `StockLedger`, `AuditLogger`, dan `OpnamePoster`. Keduanya menelan
  kegagalannya sendiri ke log, meniru `AuditLogger`.
- **Penjadwal pertama di proyek ini**: `php artisan notifikasi:periksa`,
  dijadwalkan harian 06.00 di `routes/console.php`.
- Lonceng header dirombak dari dummy TailAdmin ("Terry Franci",
  `console.log`) menjadi data nyata: badge berangka, dua bagian "Perlu Tindakan"
  dan "Aktivitas Terbaru", tombol tandai semua dibaca.
- Halaman `/notifikasi` dengan tiga penyaring dan kartu rekap. Disajikan sebagai
  **daftar, bukan tabel** — isi notifikasi berupa kalimat utuh yang tidak
  terbaca dalam sel sempit.
- Pemicu reaktif di `StockMutationController`, `ItemController` (4 titik), dan
  `OpnamePoster::post()`.
- Ikon `bell` dan entri menu "Notifikasi" di `MenuHelper`, tanpa kunci `roles`.

**Verifikasi.** 161 uji lulus (500 asersi), naik dari 142 — 19 uji baru. Pint
bersih pada 16 berkas dalam cakupan sempit. Migrasi berjalan di MySQL. Siklus
penuh diuji pada data nyata: `ATK-001` dikuras → 2 notifikasi (admin + gudang)
dengan kalimat tindak lanjut; diperiksa 2× lagi → **tetap 2 baris**; diisi ulang
→ **0 aktif, 2 baris tetap ada** (ditutup, bukan dihapus). Perintah terjadwal
pada data contoh menghasilkan tepat satu keadaan yang benar: Triwulan I 2026
belum diopname, sedangkan Triwulan II dan III sudah punya dokumen dan Triwulan
IV belum mendekati akhir.

**Catatan.** Verifikasi manual itu menulis 2 mutasi nyata berketerangan "Uji
coba" pada `ATK-001` di basis data pengembangan (stok 132 → 0 → 40). Baris
mutasi immutable sehingga tidak dihapus; `php artisan migrate:fresh --seed`
mengembalikan data contoh bila diperlukan.

**Berikutnya.** Modul Pengadaan, dengan syarat yang tidak berubah:
**penyelarasan tabel perbatasan bersama Alma lebih dulu**, dan wajib menerapkan
[ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md). Notifikasi
untuk modul itu cukup menambah case enum — kandidatnya sudah dicatat di
[modul/notifikasi.md](modul/notifikasi.md).

---

## 4 September 2026 — Modul Stok Opname

Modul terakhir sub sistem manajemen gudang. Dibangun dari activity diagram yang
sudah dibuat, dengan empat perbaikan: dokumen laporan dibuat **setelah** posting
(pada diagram ia bercabang paralel sehingga jadi sebelum alasan dimasukkan),
tambahan keadaan **draf** karena pencacahan tidak selesai dalam satu duduk,
**periode terstruktur** karena laporan diminta per triwulan, dan penanganan
**stok yang bergerak saat opname berjalan** yang tidak disinggung diagram.

**Yang dikerjakan.**

- Tiga enum baru: `PeriodeOpname` (8 case), `AlasanSelisih` (6 case),
  `OpnameStatus`.
- Dua migrasi: kolom alur dan periode pada `stock_opnames`; kolom alasan dan
  jejak posting pada `opname_details`, sekaligus melonggarkan `stock_real` dan
  `difference` menjadi nullable agar draf dapat menyimpan barang yang belum
  dicacah.
- `app/Support/OpnamePoster.php` — penerapan hasil opname dalam satu transaksi
  berkunci, mendampingi `StockLedger` dan `AuditLogger`.
- Alur draf → selesai: draf boleh sebagian, posting wajib lengkap dan setiap
  selisih wajib beralasan, opname selesai bersifat final
  ([ADR 0010](keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md)).
- Alasan selisih memakai enum baku **berpasangan** keterangan bebas, keduanya
  wajib ([ADR 0011](keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md)).
- Periode disimpan sebagai enum jenis + tahun, terpisah dari `opname_date`
  ([ADR 0012](keputusan/0012-periode-opname-enum-jenis-dan-tahun.md)).
- Empat halaman (`index`, `create`, `edit`, `show`) dan Laporan Stok Opname PDF
  lanskap, memakai ulang infrastruktur laporan modul mutasi.

**Temuan penting — rancangan yang sempat disetujui ternyata salah.** Rencana
awal menetapkan penyesuaian sebagai `stock_real − stock_at_posting`, yaitu
memaksa stok akhir sama dengan hasil cacah. Verifikasi manual lewat HTTP
membuktikannya keliru:

| Langkah | Stok |
|---|---|
| Stok tercatat saat draf dibuat | 132 |
| Hasil cacah — cocok, tidak ada kesalahan pencatatan | 132 |
| Setelah pencacahan, 20 unit dicatat rusak lewat mutasi manual | 112 |
| Posting dengan rumus lama: `132 − 112 = +20` | **132** |

Posting **membatalkan kerusakan yang sah tercatat**. Rumus itu memperlakukan
seluruh jarak antara hasil cacah dan stok terkini sebagai kesalahan pencatatan,
padahal sebagian di antaranya justru transaksi yang benar.

Diperbaiki menjadi: yang diterapkan adalah **`difference`**, selisih yang
ditemukan saat mencacah. Stok akhir = `stock_at_posting + difference`, sehingga
transaksi setelah pencacahan tidak ikut dibatalkan. `OpnamePoster`, controller,
`show.blade.php`, templat PDF, dan uji ikut disesuaikan; satu uji baru
ditambahkan khusus untuk kasus "pencatatan cocok tetapi stok berubah setelahnya".

**Bug lain yang ditemukan dan diperbaiki dalam sesi ini.**

- `UpdateOpnameCountRequest` memvalidasi sebelum penjaga "sudah diposting" di
  controller, sehingga opname selesai memunculkan pesan selisih yang menyesatkan.
  Diperbaiki dengan keluar lebih awal di `withValidator`.
- `StockOpnameSeeder` berbagi koleksi barang yang sudah basi antara dua
  methodnya, sehingga `stock_stored` pada draf tidak sama dengan stok
  sebenarnya. Tiap method kini mengambil barang segar.
- Dua uji bergantung pada urutan yang tidak deterministik
  (`details->first()` dan `latest('created_at')` pada baris sedetik) — diganti
  `keyBy('item_id')` dan pembacaan URL redirect.

**Verifikasi.** 142 test lulus (443 asersi). Pint bersih pada 31 berkas dalam
cakupan sempit. Uji HTTP: draf berisi seluruh 15 barang aktif dengan stok
tercatat yang benar; posting ditolak selama masih ada yang belum dicacah;
selisih tanpa alasan ditolak; PDF diawali `%PDF`; `pengadaan` menerima 403 dan
`admin` menerima 200.

**Catatan.** `stock_opnames` dan `opname_details` **bukan tabel bersama**,
sehingga seluruh perubahan skema di sesi ini tidak perlu kesepakatan dengan
Alma — cukup dicatat di
[penyelarasan-erd.md](erd/penyelarasan-erd.md) agar gambar ERD naskah Naren
diperbarui.

**Berikutnya.** Sub sistem manajemen gudang selesai. Modul Pengadaan
**wajib menyelaraskan tabel perbatasan lebih dulu bersama Alma**, dan wajib
menerapkan [ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md):
realisasi berhenti di status *Menunggu Penerimaan*, dan Pengelola Gudang yang
mengonfirmasi penerimaan sehingga stok bertambah.

---

## 3 September 2026 — Dokumentasi dipisah per pengembang

**Latar belakang.** Narendra dan Alma menulis **skripsi terpisah dengan judul
berbeda**, masing-masing tentang sub sistemnya sendiri. Konsekuensinya ERD
keduanya pun berbeda — dan itu **memang benar**, bukan kesalahan yang harus
disatukan menjadi satu gambar.

Framing sebelumnya keliru: dokumentasi memperlakukan perbedaan ERD sebagai
selisih yang harus diperbaiki seluruhnya.

**Yang dikerjakan.**

- Folder `documentations/` diganti nama menjadi `naren-documentations/`, karena
  Alma memelihara dokumentasinya sendiri.
- Sebutan "rekan" diganti nama sebenarnya, **Alma**, di seluruh dokumentasi.
- `erd/penyelarasan-erd.md` ditulis ulang: dari "selisih ERD yang harus
  diperbaiki" menjadi **peta kepemilikan tabel**. Kini memuat empat kelompok —
  tabel bersama (wajib identik), milik Naren, milik Alma, dan perbatasan yang
  perlu kesepakatan. Keempat temuan lama tetap ada, tetapi dikelompokkan menurut
  siapa yang berhak memperbaikinya.
- `README.md` root menegaskan bahwa ERD berbeda itu wajar, dan menyebut keempat
  tabel bersama beserta aturannya.

**Catatan.** Temuan #1 (`request_date` pada `procurement_requests`) ternyata milik
Naren sendiri, jadi dapat dikerjakan tanpa menunggu kesepakatan. Temuan #2 dan #3
berada di perbatasan, #4 milik Alma.

---

## 3 September 2026 — Koreksi semantik riwayat mutasi + dokumentasi proyek

**Latar belakang.** Klarifikasi bersama pengguna menemukan bahwa data contoh dan
sebagian semantik modul mutasi menanamkan **asumsi bisnis yang salah**. Berbahaya
justru karena tidak terlihat sebagai bug: sistemnya jalan, tetapi mengajarkan
proses yang keliru.

Tiga hal yang terbukti keliru saat diperiksa terhadap data nyata:

| Temuan | Bukti |
|---|---|
| Nomor referensi `PG01001`/`PR01001` tidak menunjuk apa pun | `reference` terisi 0 dari 77 baris |
| Keterangan "pengadaan **triwulan**" | Kadensi yang tidak pernah disebutkan, dikarang seeder |
| Atribusi petugas Barang Masuk tanpa dasar | Dipilih tanpa alasan yang dinyatakan |

**Yang dikerjakan.**

- Aturan proses baru: penerimaan barang dikonfirmasi **Pengelola Gudang**, bukan
  Pejabat Pengadaan — pemisahan tugas ([ADR 0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md)).
  Ini menentukan rancangan modul Pengadaan: realisasi berhenti di status
  *Menunggu Penerimaan*.
- Kolom `reference` diganti `reference_id`, menjadi relasi polimorfik Laravel
  dengan morph map ([ADR 0007](keputusan/0007-kolom-audit-dan-referensi-polimorfik.md)).
  Parameter `$referenceType` dihapus dari `StockLedger::record()`; jenis dokumen
  diturunkan dari `getMorphClass()`.
- Kolom **Sumber** ditambahkan di layar, PDF, dan CSV.
- Data contoh diperbaiki: kata "triwulan" dan kode karangan dihapus, keterangan
  dirakit dari data dokumen yang realistis.
- Folder `documentations/` dibuat beserta 9 ADR, 4 dokumen modul, ikhtisar
  sistem, dan catatan progres ini. `README.md` root dirampingkan menjadi hanya
  berisi hal yang diperlukan untuk pengembangan bersama.

**Bug yang ditemukan dan diperbaiki dalam sesi ini.** Seeder menulis
`reference_type` ke baris yang **salah**: seluruh baris dibuat pada detik yang
sama sehingga `latest('created_at')` mengembalikan baris sembarang — ternyata
mengenai `saldo_awal`. Akibatnya 14 baris saldo awal salah berlabel Permintaan,
dan 44 baris jatuh ke Sumber "Sistem". Diperbaiki dengan memakai objek yang
dikembalikan `StockLedger::record()` alih-alih mencarinya ulang lewat query.

**Verifikasi.** 111 test lulus. Sebaran Sumber setelah perbaikan: Pengadaan 15,
Permintaan 30, Manual 15, Saldo Awal 14. Tidak ada lagi `saldo_awal` yang punya
`reference_type`, dan tidak ada `masuk`/`keluar` tanpa `reference_type`.

**Catatan.** Satu kegagalan test transien pernah teramati satu kali dan tidak
dapat direproduksi dalam 4 kali percobaan berikutnya; penyebabnya belum
ditemukan.

---

## 3 September 2026 — Modul Mutasi Stok & Riwayat Mutasi

Dibangun sesuai rancangan low-fi "Riwayat Mutasi", dengan tujuh penyempurnaan:
jumlah bertanda berwarna, kolom Stok Setelah, kolom Petugas, referensi terbaca,
penyaring diperluas, kartu rekap, dan sifat immutable.

**Yang dikerjakan.**

- `MutationType` diperluas dari 4 menjadi 10 case, merangkap alasan mutasi manual
  ([ADR 0006](keputusan/0006-mutationtype-merangkap-alasan-manual.md)).
- Empat kolom audit ditambahkan ke `stock_mutations`: `user_id`, `stock_after`,
  `reference_number`, `notes` — dengan pengisian ulang `stock_after` untuk baris
  lama.
- Formulir mutasi manual dengan enam alasan baku, keterangan wajib, dan penolakan
  jenis milik dokumen.
- Laporan PDF (dompdf), ekspor CSV, dan Kartu Persediaan per barang
  ([ADR 0009](keputusan/0009-laporan-pdf-dan-csv.md)).
- Buku mutasi diurutkan menurut waktu pencatatan, bukan tanggal transaksi
  ([ADR 0005](keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)).

**Temuan penting.** Formulir mengizinkan tanggal mundur, sehingga urutan tanggal
tidak selalu sama dengan urutan pencatatan. Karena `stock_after` adalah saldo
pada saat mutasi dicatat, buku mutasi harus dibaca dalam urutan pencatatan.

**Verifikasi.** 107 test lulus. Uji HTTP nyata: PDF 880 KB diawali `%PDF-1.7`;
mutasi manual menurunkan stok dengan benar; jenis `masuk` ditolak dari formulir
manual; stok kurang memunculkan pesan validasi yang ramah.

**Catatan.** Pint sempat memformat ulang 9 model dan 13 migrasi warisan ERD
karena dijalankan pada direktori terlalu luas — sudah dikembalikan. Untuk
repositori berdua, reformat berkas bersama hanya memicu konflik merge.

---

## 3 September 2026 — Modul Master Barang

Inti sub sistem gudang, karena seluruh modul lain memiliki foreign key ke
`items`.

**Yang dikerjakan.**

- `StockLedger` sebagai satu-satunya jalur perubahan stok, dengan penguncian
  baris dan penolakan stok negatif
  ([ADR 0004](keputusan/0004-stok-hanya-lewat-stockledger.md)).
- Enum `SatuanBarang`, `MutationType`, `StockStatus`.
- CRUD master barang dengan pencarian, tiga penyaring, ringkasan, dan halaman
  detail berisi riwayat mutasi.
- Kolom `is_active` pada `items` — barang bertransaksi diarsipkan, bukan dihapus
  ([ADR 0003](keputusan/0003-arsip-alih-alih-hapus.md)).
- 15 barang ATK/RTK contoh.

**Temuan penting.** Middleware `role:pengelola_gudang` akan **menolak Admin**,
karena `EnsureUserHasRole` memeriksa peran secara harfiah tanpa bypass. Rute
gudang karena itu memakai `can:kelola-gudang`
([ADR 0002](keputusan/0002-otorisasi-peran-gate-dan-can.md)).

**Verifikasi.** 75 test lulus. Uji HTTP: kode barang dinormalkan ke huruf besar;
`stock_current=9999` yang diselundupkan ke request tidak mengubah stok; barang
bermutasi menolak dihapus; `pengadaan` menerima 403, `admin` menerima 200.

---

## 1–2 September 2026 — Manajemen Pengguna

CRUD akun khusus Admin. Karena sistem tertutup tanpa pendaftaran mandiri, modul
inilah satu-satunya cara akun bertambah.

**Yang dikerjakan.**

- CRUD lengkap dengan pencarian, penyaring peran dan status, ringkasan, serta
  pengaktifan/penonaktifan cepat.
- Penjaga: Admin tidak dapat mengubah peran, menonaktifkan, atau menghapus
  akunnya sendiri; akun bertransaksi tidak dapat dihapus.
- Komponen bersama lahir di sini dan dipakai ulang seluruh modul berikutnya:
  `x-form.text-field`, `x-form.select-field`, `x-form.password-field`,
  `x-ui.flash`, dan paginasi bergaya TailAdmin.

**Catatan.** Aturan `alpha_dash` diganti regex sendiri agar pola `budi.santoso`
dapat dipakai. Penjaga "Admin aktif terakhir" ditulis lalu dihapus karena tidak
pernah dapat tereksekusi.

**Verifikasi.** 42 test lulus. Uji HTTP: buat, ubah, toggle, hapus semua bekerja;
`gudang` menerima 403 dan menunya tidak muncul.

---

## 1 September 2026 — Autentikasi & Otorisasi

Fondasi sistem: masuk, keluar, peran, dan penjagaan rute.

**Yang dikerjakan.**

- Autentikasi sesi Laravel tanpa starter kit, disesuaikan dengan skema `users`
  dari ERD ([ADR 0001](keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md)).
- Enum `UserRole` tiga peran, gate berbasis peran dengan `Gate::before` untuk
  Admin, middleware `role:` dan `EnsureUserIsActive`.
- Halaman masuk berbahasa Indonesia tanpa tombol sosial dan tanpa pendaftaran
  mandiri; dasbor sementara dengan modul yang tersaring peran.
- Kolom `last_login_at` dan `last_login_ip`; jejak masuk dan keluar ke
  `audit_logs`.
- Tiga akun awal: `admin`, `gudang`, `pengadaan`.

**Catatan.** Halaman `signin` dan `signup` bawaan template dihapus. Zona waktu
aplikasi disetel `Asia/Jakarta` dan locale `id`.

**Verifikasi.** 20 test lulus. Uji HTTP: login/logout, penguncian setelah lima
percobaan gagal, akun nonaktif ditendang di tengah sesi.
