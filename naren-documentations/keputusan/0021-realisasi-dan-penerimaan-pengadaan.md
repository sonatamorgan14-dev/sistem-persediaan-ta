# ADR 0021 — Realisasi dan penerimaan pengadaan: batas cakupan Tahap 3

## Status

Diterima — 6 September 2026

Sebagian digantikan oleh [ADR 0042](0042-bast-pengadaan-format-resmi-bpk.md):
nomor BAST tidak lagi diisi (maupun disarankan) di formulir konfirmasi
penerimaan, melainkan saat BAST dicetak pertama kali, dan `reference_number`
mutasi penerimaan kini diisi judul realisasi, bukan nomor BAST.

## Konteks

[ADR 0016](0016-alur-pengadaan-tiga-dokumen.md) menetapkan alur tiga dokumen
dan pemisahan tugasnya, [ADR 0017](0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)
menetapkan `amount_received`, dan [ADR 0018](0018-berkas-unggahan-di-disk-privat.md)
menetapkan BAST boleh menyusul. Tahap 3 adalah saat ketiganya benar-benar
dikodekan sebagai `ProcurementController`, `ProcurementReceiptController`, dan
`ProcurementReceiptPoster` — dan sejumlah hal baru terlihat begitu ditulis.

## Keputusan

### Tiga controller terpisah, sesuai rancangan ADR 0016

`ProcurementController` (realisasi, Pejabat Pengadaan) dan
`ProcurementReceiptController` (penerimaan, Pengelola Gudang) — bukan satu
controller gemuk dengan aksi status. `ProcurementReceiptController` hanya
`create`/`store`: penerimaan adalah peristiwa sekali jadi, tidak ada rute ubah
maupun hapus.

### Realisasi hanya dibuka dari usulan yang sudah disetujui

Tidak ada halaman "buat realisasi" berdiri sendiri. Satu-satunya jalan masuk
adalah tombol **Realisasikan** pada halaman detail usulan
(`procurement-requests.show`), tampil hanya bila usulan `Disetujui` dan belum
punya realisasi. `ProcurementController::create()` menerima
`?procurement_request_id=` dan menolak (redirect dengan pesan) bila usulan
tidak ditemukan atau tidak memenuhi syarat — pola yang sama dengan jalur
pintas `?item_id=` pada formulir usulan pengadaan Tahap 2.

**Barang pada formulir realisasi wajib mencakup seluruh rincian usulan yang
disetujui** (`amount_granted > 0`), masing-masing dengan jumlah pesan yang
boleh dikurangi (hingga minimal 1) tetapi tidak dihilangkan. Ini konsekuensi
langsung dari `procurements.procurement_request_id` yang unik (ADR 0016):
karena satu usulan paling banyak menghasilkan **satu** realisasi selamanya,
barang yang tidak diikutsertakan sekarang tidak akan pernah punya kesempatan
kedua. Menyembunyikan kemungkinan mengecualikan barang dari formulir mencegah
kesalahan yang tidak bisa diperbaiki.

**`item_id` tidak diminta dari klien.** `StoreProcurementRequest` menerima
`procurement_request_detail_id` per baris dan menurunkan `item_id`-nya
server-side lewat `rincianDisetujui()`, pola yang sama dengan
`ApproveProcurementRequestRequest::withValidator()` pada Tahap 2. Batas
`amount ≤ amount_granted` diperiksa terhadap data rincian usulan sesungguhnya,
bukan nilai yang dikirim klien.

### `ProcurementReceiptPoster` mengikuti pola `OpnamePoster`

Method statis `post()`: memvalidasi realisasi masih `MenungguPenerimaan`
(melempar `ProcurementTidakSiapException` bila tidak), lalu di dalam satu
transaksi terkunci menulis `amount_received` per rincian, memanggil
`StockLedger::record()` per barang dengan jenis `Masuk` sebanyak jumlah yang
benar-benar diterima (baris bernilai nol tidak menghasilkan mutasi — sama
seperti `OpnamePoster` melewati selisih nol), mengubah status realisasi
menjadi `Diterima`, dan membuat baris `ProcurementReceipt`. `AuditLogger` dan
`NotificationScanner::catatPenerimaanDikonfirmasi()` dipanggil **di luar**
transaksi, sejajar dengan `OpnamePoster::post()`.

Referensi mutasi menunjuk **`Procurement`** (alias morph map `pengadaan`,
sudah terdaftar sejak Tahap 1), bukan `ProcurementReceipt` — dokumen sumber
stok masuk adalah realisasinya; BAST hanya mencatat peristiwa penerimaannya.
`reference_number` diisi nomor BAST, karena itulah nomor dokumen yang
tercantum secara resmi pada penerimaan.

### `received_at` adalah tanggal yang bisa diedit, bukan `now()` tetap

Mengikuti pola `procurement_date`, `opname_date`, dan `mutation_date` yang
sudah ada di seluruh sistem: setiap dokumen punya tanggal bisnisnya sendiri,
terpisah dari `created_at` (waktu pencatatan). Validasinya sama persis:
`required|date|before_or_equal:today`. Urutan buku mutasi tetap mengikuti
`created_at`, bukan `received_at` — konsisten dengan gotcha yang sudah dicatat
untuk formulir mutasi manual.

### Nomor BAST: teks bebas dengan saran berurut

Sesuai butir terbuka di [modul/pengadaan.md](../modul/pengadaan.md): format
nomor dokumen resmi BPK belum dikonfirmasi lapangan. `ProcurementReceiptController::create()`
menyiapkan saran `{urutan}/BAST/{tahun}` sebagai nilai awal input teks biasa,
tanpa validasi format — pengguna bebas menimpanya.

### Kiriman lebih dari pesanan tidak dibatasi

`ConfirmProcurementReceiptRequest` memvalidasi `received.*` minimal 0 tanpa
batas atas. ADR 0017 mengatasi kekurangan kiriman; penyedia mengirim
**lebih** dari pesanan bukan kasus yang pernah disinggung narasumber lapangan,
dan menolaknya di validasi hanya akan memaksa Pengelola Gudang mencari jalan
lain untuk mencatat kenyataan yang sudah ada di rak gudang — masalah yang
sama persis dengan yang coba dihindari ADR 0017.

### Dua jenis notifikasi baru, sesuai rencana yang sudah dicatat

Katalog di [modul/notifikasi.md](../modul/notifikasi.md) sudah menuliskan
rencana ini sejak modul Notifikasi dibangun:

| Jenis | Sifat | Penerima | Menutup diri |
|---|---|---|---|
| `RealisasiMenungguPenerimaan` | Keadaan | Pengelola Gudang + Admin | Begitu dikonfirmasi diterima |
| `PenerimaanDikonfirmasi` | Peristiwa | Pejabat Pengadaan yang merealisasi | — |

Tidak ada pasangan "menggantung" seperti `UsulanMenggantung` pada Tahap 2 —
rencana yang tercatat hanya menyebut dua jenis ini, dan menambah lebih banyak
tanpa kebutuhan lapangan yang menyatakannya melanggar prinsip proyek ini
untuk tidak membangun di depan permintaan nyata.

### Status `Dibatalkan` tetap tidak punya pemicu

`ProcurementStatus::Dibatalkan` sudah ada sejak Tahap 1 tetapi Tahap 3 ini
tidak menambahkan aksi apa pun yang mengubah realisasi ke status tersebut.
Ini konsisten dengan pola yang sudah terjadi sebelumnya di modul ini — kolom
dan enum kerap mendarat lebih dulu, fiturnya menyusul di tahap lain (mis.
`procurement_documents` mendarat di Tahap 1, fiturnya baru di Tahap 4).
Pembatalan realisasi yang gagal dibeli (mis. penyedia mundur) belum
dikonfirmasi kebutuhan lapangannya; dicatat sebagai butir terbuka, bukan
dibangun spekulatif.

## Alternatif yang ditolak

**Formulir realisasi membolehkan mengecualikan sebagian barang disetujui.**
Lebih fleksibel bagi Pejabat Pengadaan yang mungkin ingin membeli barang di
lain waktu. Ditolak: `procurement_request_id` unik pada `procurements` berarti
usulan yang sama tidak bisa direalisasikan dua kali, sehingga barang yang
dikecualikan sekarang hilang selamanya tanpa peringatan yang cukup keras.
Bila kebutuhan itu nyata, jalan yang benar adalah melonggarkan keunikan
tersebut (mengizinkan realisasi bertahap) sebagai keputusan tersendiri, bukan
diam-diam dibuka lewat formulir yang tidak menjelaskan konsekuensinya.

**`received_at` dikunci ke `now()` seperti `StockOpname::posted_at`.**
Lebih sederhana dan menghindari kemungkinan backdating yang keliru. Ditolak
demi konsistensi dengan `procurement_date` yang sudah berupa input bertanggal
pada dokumen yang sama persis satu langkah sebelumnya — pengguna akan bingung
kenapa satu tanggal boleh diedit dan tanggal berikutnya tidak, padahal
keduanya sama-sama tanggal bisnis, bukan waktu pencatatan.

**Menu penerimaan sebagai halaman tersendiri di luar `procurements.show`.**
Ditolak karena penerimaan adalah kelanjutan langsung satu realisasi tertentu,
bukan dokumen yang berdiri sendiri untuk dicari lewat daftar — tombol
"Konfirmasi Penerimaan" pada halaman realisasi sudah cukup sebagai titik
masuk, sama seperti tombol "Realisasikan" pada halaman usulan.

## Konsekuensi

- Rute `pengadaan/create` (GET, gate `kelola-pengadaan`) harus terdaftar di
  `routes/web.php` sebelum `pengadaan/{procurement}` (GET, gate
  `akses-pengadaan`) — gotcha yang sama persis dengan Tahap 2, didaftarkan
  ulang di `CLAUDE.md`.
- `Procurement` mendapat `judul()` yang meniru pola `ProcurementRequest::judul()`,
  dipakai tampilan dan notifikasi.
- `ProcurementSeeder` menambah satu realisasi contoh yang dibawa sampai
  diterima **lewat `ProcurementReceiptPoster::post()` sungguhan**, bukan baris
  yang ditulis langsung ke tabel — supaya stok, buku mutasi, dan dokumennya
  tetap konsisten sejak `migrate:fresh --seed`. `ProcurementRequestSeeder`
  mendapat usulan keempat (`disetujuiPenuh`, ATK-012) yang sengaja dibiarkan
  belum direalisasikan, untuk mendemokan tombol "Realisasikan".
- Tahap 4 (dokumen, BAST PDF, laporan) tinggal menambah unggahan berkas dan
  cetak dokumen di atas struktur yang sudah ada di sini — tidak ada perubahan
  skema yang diperkirakan.
