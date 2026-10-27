# Penyelarasan Tabel Bersama

> **Bahas bersama Alma bila ada perubahan pada tabel bersama.**

## ERD yang berbeda itu wajar

Narendra dan Alma menulis **skripsi terpisah dengan judul berbeda**, masing-masing
tentang sub sistemnya sendiri. Karena itu **ERD keduanya memang berbeda, dan itu
benar** — bukan kesalahan yang harus disatukan menjadi satu gambar.

Yang perlu diselaraskan hanya **tabel yang dipakai kedua sub sistem**. Bila
sebuah tabel muncul di kedua ERD, kolomnya harus digambar sama persis. Sumber
kebenarannya adalah migrasi yang benar-benar berjalan di
`database/migrations/`, bukan gambar ERD di naskah.

## Peta kepemilikan tabel

### Tabel bersama — wajib identik di kedua ERD

| Tabel | Peran |
|---|---|
| `users` | Autentikasi dan peran, dipakai kedua sub sistem |
| `items` | Master barang; dirujuk `procurement_details`/`procurement_request_details` (Naren) — Alma belum punya tabel rincian barangnya sendiri, lihat catatan di bawah |
| `stock_mutations` | Buku besar stok; dibangun Naren, ditulis Alma lewat `StockLedger` |
| `audit_logs` | Jejak aktivitas. Ditulis Naren; sub sistem Permintaan belum pernah menulis satu baris pun ke sini (lihat catatan di bawah) |
| `item_proposals` | Usulan barang baru — pintu depan katalog. Dibangun Naren (skema, keputusan, `items.store()`), ditulis juga oleh sub sistem Permintaan lewat pintunya sendiri (lihat catatan di bawah) |

Perubahan pada kelima tabel ini **hanya lewat kesepakatan**. Migrasi dari dua
orang pada tabel yang sama rawan bentrok saat merge — persis yang terjadi pada
`item_proposals` sendiri, lihat [ADR 0033](../keputusan/0033-usulan-barang-baru-tabel-bersama-dengan-permintaan.md).

`audit_logs` bertambah enam kolom pada 16 September 2026
([ADR 0034](../keputusan/0034-log-aktivitas-terstruktur.md)): `module`,
`event`, `actor_role`, `ip_address`, serta pasangan `subject_type`/`subject_id`.
Seluruhnya **aditif dan nullable**, dan `user_id` tidak disentuh sama sekali —
tetap `NOT NULL` dengan kunci asing `restrictOnDelete`-nya utuh. Karena sub
sistem Permintaan sampai saat ini belum pernah memanggil `AuditLogger` sekali
pun, perubahan ini tidak mungkin merusak kodenya. **Alma tetap perlu
dikabari**, dan `AuditModule::Permintaan` sudah disediakan untuk dipakai
sub sistemnya kelak. Bahwa seluruh alur `RequestController` — mengajukan,
mengubah, menyetujui, menolak permintaan — sampai sekarang tidak meninggalkan
jejak audit apa pun adalah wilayah keputusannya, bukan keputusan yang dibuat
dari sisi ini.

`item_proposals` (sejak 16 September 2026,
[ADR 0032](../keputusan/0032-usulan-barang-baru.md)) dirancang **sengaja netral
peran** sejak awal — pengusulnya cukup seorang `users`, dan perannya sendiri
sudah menjelaskan asal usulannya — justru supaya sub sistem Permintaan bisa
memakai tabel yang sama tanpa perubahan skema begitu ia butuh "pengguna meminta
barang yang belum ada di sistem". ADR 0032 mencatatnya sebagai "ditawarkan,
bukan diasumsikan": pintunya baru dibangun sisi Pengadaan, sisi Permintaan
sengaja tidak disentuh.

Alma ternyata membangun pintunya sendiri secara paralel dengan skema yang
berbeda (kolom dan status berbeda, dan tidak menyambung ke pembuatan barang
asli) tanpa tahu tabel ini sudah ada — baru ketahuan lewat konflik `git pull`.
ADR 0033 menyatukannya: skema dan keputusan (ADR 0032) dipertahankan, sisi
Permintaan diadaptasi memakainya. **Sejak itu tabel ini genuinely tabel
bersama**, bukan lagi "milik Naren, ditawarkan".

### Milik Narendra — bebas diubah sendiri

`procurements`, `procurement_details`, `procurement_requests`,
`procurement_request_details`, `procurement_receipts`, `procurement_documents`,
`suppliers`, `stock_opnames`, `opname_details`

### Milik Alma — bebas diubah sendiri

`requests`, `basts`, `nota_dinas`

Tidak ada lagi kategori "perbatasan". Sampai 4 September 2026 dokumen ini
mencatat tiga butir perbatasan yang perlu disepakati bersama Alma; ketiganya
gugur setelah ditelusuri ke sumber aslinya — lihat riwayat di bawah.

## Riwayat: kesalahan desain awal yang sudah diperbaiki (5 September 2026)

Desain ERD awal (satu commit, 25 Agustus 2026, tidak pernah disentuh lagi
sampai sesi ini) sempat terbaca seolah menghubungkan sub sistem Pengadaan
dengan sub sistem Permintaan lewat beberapa foreign key. Setelah ditelusuri
langsung ke sumber aslinya, ternyata itu kesalahan penamaan akibat kurangnya
komunikasi saat desain awal — bukan relasi yang disengaja.

| Yang dulu dikira | Kenyataannya | Perbaikan |
|---|---|---|
| `procurement_requests.request_id` adalah FK ke `requests` | Sisa kebingungan penamaan primary key; bukan relasi apa pun | Kolom dihapus |
| `request_details` adalah rincian permintaan unit, milik Alma | Rincian usulan pengadaan, milik Narendra sejak awal | Tabel + model diganti nama menjadi `procurement_request_details` / `ProcurementRequestDetail`, FK diperbaiki menunjuk `procurement_requests` |
| BAST pengadaan cukup kolom string bebas (`procurements.bast_scan`) | Desain yang kurang baik — tanpa nomor dokumen maupun siapa yang mengonfirmasi | Tabel baru `procurement_receipts` |

Konsekuensinya, `App\Models\Request::details()` dan `Request::procurementRequests()`
dihapus — keduanya dibangun di atas premis yang keliru. **`requests` milik Alma
belum punya tabel rincian barangnya sendiri** sama sekali; itu keputusan yang
menyusul saat sub sistem Permintaan dirancang, bukan sesuatu yang sudah ada dan
salah tempat.

Rincian keputusan skema lengkap ada di
[ADR 0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md).

## Titik integrasi yang tersedia — bukan kewajiban

`procurement_requests` punya kolom `source` (enum `ProcurementRequestSource`)
yang membedakan tiga asal-usul usulan pengadaan, dikonfirmasi narasumber
lapangan (Kasubbag Umum dan TI):

1. **`DariPermintaan`** — permintaan unit tidak bisa dipenuhi karena stok
   kurang atau habis.
2. **`PemantauanStok`** — dari notifikasi Stok Habis/Menipis.
3. **`Manual`** — diajukan langsung Pengelola Gudang.

Hanya jenis pertama yang menunjuk dokumen asal, lewat `reference_type`/
`reference_id` polimorfik — pola yang sama dengan `stock_mutations.reference_*`
([ADR 0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md)) —
mengarah ke baris `requests` lewat alias morph map `'permintaan'` yang sudah
terdaftar di `AppServiceProvider`.

Ini **bukan foreign key basis data** dan **bukan kewajiban**: sub sistem
Pengadaan berfungsi penuh tanpa sub sistem Permintaan ada, lewat dua sumber
lainnya. Begitu Alma membangun alurnya, baris `requests` yang menandai stok
kurang tinggal dipakai mengisi kolom ini; sebelum itu, kolomnya dibiarkan
kosong.

## Penyimpangan dari ERD yang sudah diterapkan Naren

Seluruhnya sudah disetujui dan **menyentuh tabel bersama**, sehingga perlu
dikabarkan ke Alma dan tercermin di kedua naskah:

| Tabel | Perubahan | ADR |
|---|---|---|
| `users` | Tambah `last_login_at`, `last_login_ip` | [0001](../keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md) |
| `users` | Nilai peran baru `kasubbag_umum_ti` pada kolom `role`. **Tanpa migrasi** — `role` hanya `string` tanpa constraint — tetapi tetap perubahan pada tabel bersama, jadi harus disepakati | [0030](../keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md) |
| `users` | Tambah `avatar_path` (string, nullable) — path foto profil di disk privat. Aditif; **menunggu persetujuan Alma** | [0038](../keputusan/0038-profil-pengguna-dan-foto-profil.md) |
| `users` | Tambah `nip` (string 18, nullable) dan `position` (string, nullable) — NIP dan jabatan pegawai, dicetak di BAST pengadaan; bisa dipakai juga untuk BAST permintaan dan Nota Dinas. Aditif; **menunggu persetujuan Alma** | [0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md) |
| `items` | Tambah `is_active` | [0003](../keputusan/0003-arsip-alih-alih-hapus.md) |
| `stock_mutations` | Tambah `user_id`, `stock_after`, `reference_number`, `notes` | [0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) |
| `stock_mutations` | `reference` diganti nama menjadi `reference_id` | [0007](../keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) |

## Penyimpangan pada tabel milik Naren

**Tidak perlu kesepakatan dengan Alma** — dicatat di sini semata agar gambar
ERD pada naskah Naren diperbarui.

| Tabel | Perubahan | ADR |
|---|---|---|
| `stock_opnames` | Tambah `status`, `posted_at` | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `stock_opnames` | Tambah `period_type`, `period_year`, beserta indeks gabungannya | [0012](../keputusan/0012-periode-opname-enum-jenis-dan-tahun.md) |
| `stock_opnames` | Tambah `notes` | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `opname_details` | `stock_real` dan `difference` menjadi **nullable** — draf menyimpan barang yang belum dicacah | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `opname_details` | Tambah `counted_at`, `stock_at_posting`, `adjustment_amount` | [0010](../keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) |
| `opname_details` | Tambah `adjustment_reason` | [0011](../keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md) |
| `procurement_requests` | Hapus `request_id`, `request_type`; tambah `source`, `reference_type`, `reference_id`, `status` | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |
| `request_details` → `procurement_request_details` | Rename tabel + PK; FK diperbaiki menunjuk `procurement_requests` | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |
| `procurements` | Tambah `procurement_request_id`; hapus `bast_scan` | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |
| `procurement_receipts` | Tabel baru — BAST penerimaan dari penyedia ke gudang | [0015](../keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) |
| `suppliers` | Tabel baru — data induk penyedia; alasan utamanya BAST butuh identitas penyedia | [0016](../keputusan/0016-alur-pengadaan-tiga-dokumen.md) |
| `procurements` | Tambah `supplier_id` (FK); hapus `supplier` (teks bebas) dan `additional_doc` | [0016](../keputusan/0016-alur-pengadaan-tiga-dokumen.md), [0018](../keputusan/0018-berkas-unggahan-di-disk-privat.md) |
| `procurement_details` | Tambah `amount_received` (nullable) — stok bertambah sebanyak yang datang, bukan yang dipesan | [0017](../keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md) |
| `procurement_details` | Tambah `unit_price` (rupiah bulat, integer) | [0019](../keputusan/0019-harga-di-realisasi-tanpa-penilaian-persediaan.md) |
| `procurement_request_details` | Hapus `status` — duplikat `amount_granted`; menutup butir terbuka ADR 0015 | [0016](../keputusan/0016-alur-pengadaan-tiga-dokumen.md) |
| `procurement_documents` | Tabel baru — berkas BAST pindai dan SPK/kontrak, di disk privat | [0018](../keputusan/0018-berkas-unggahan-di-disk-privat.md) |
| `procurement_receipts` | Hapus `file_url` — berkas pindah ke `procurement_documents`, dan NOT NULL-nya memblokir stok di belakang alur pemindaian | [0018](../keputusan/0018-berkas-unggahan-di-disk-privat.md) |
| `procurements` | Tambah `cancelled_at`, `cancelled_by`, `cancel_reason`, `cancel_note` | [0024](../keputusan/0024-pembatalan-realisasi-pengadaan.md) |
| `procurement_requests` | `user_id` menjadi **nullable** — draf otomatis tidak punya pengaju | [0027](../keputusan/0027-draf-usulan-pengadaan-otomatis.md) |
| `procurement_request_details` | Tambah `is_auto` — menandai baris yang masih dikelola draf otomatis | [0027](../keputusan/0027-draf-usulan-pengadaan-otomatis.md) |
| `procurements` | Tambah `reviewed_at`, `reviewed_by`, `review_note` — keputusan terakhir Kasubbag Umum dan TI; statusnya yang membedakan setuju dari tolak | [0030](../keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md) |
| `procurements` | `procurement_request_id` menjadi **nullable**, indeks uniknya **dipertahankan** — realisasi tanpa usulan boleh banyak (NULL berulang diizinkan), tetapi satu usulan tetap paling banyak satu realisasi | [0031](../keputusan/0031-realisasi-pengadaan-tanpa-usulan.md) |
| `procurements` | Tambah `basis_reason`, `basis_note` — dasar pengadaan, wajib pada realisasi tanpa usulan; NULL berarti dasarnya adalah usulannya | [0031](../keputusan/0031-realisasi-pengadaan-tanpa-usulan.md) |
| `item_proposals` | Tabel baru — usulan barang baru; `item_id` nullable terisi saat Pengelola Gudang mendaftarkan barangnya | [0032](../keputusan/0032-usulan-barang-baru.md) |
| `procurements` | Tambah `purchased_at` (date), `purchased_by` (FK `users`, restrictOnDelete), `purchase_note` — fakta belanja, bertahan walau realisasinya diubah dan disetujui ulang. Nilai `status` baru `proses_belanja`, tanpa migrasi | [0035](../keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md) |
| `procurement_documents` | Nilai `type` baru `foto_belanja`, tanpa migrasi — kolomnya string polos | [0035](../keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md) |
| `user_notifications` | Hapus `resolved_at` beserta indeks `(user_id, resolved_at, read_at)`, diganti indeks `(user_id, read_at)` — notifikasi yang selesai kini dihapus, bukan ditandai. Tabelnya sendiri lahir di ADR 0013 | [0036](../keputusan/0036-lonceng-kotak-masuk-dan-notifikasi-selesai-dihapus.md) |
| `procurement_receipts` | `bast_number` menjadi **nullable** — nomor BAST diisi saat BAST dicetak pertama kali, bukan saat penerimaan dikonfirmasi; BAST-nya kini serah terima internal Pelaksana Belanja → Petugas Persediaan | [0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md) |
| `procurement_receipts` | `received_at` diberi `DEFAULT CURRENT_TIMESTAMP` eksplisit — membuang `ON UPDATE CURRENT_TIMESTAMP` yang dipasang MariaDB diam-diam, yang menggeser tanggal penerimaan setiap kali nomor BAST diisi | [0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md) |

## Butir yang perlu dikonfirmasi ke lapangan

1. **Format nomor dokumen resmi BPK** untuk Nota Dinas dan BAST Permintaan
   (milik Alma), maupun BAST Pengadaan (`procurement_receipts.bast_number`,
   milik Narendra). BAST Pengadaan terjawab sebagian oleh contoh BAST resmi —
   `{urut}/UP_TUP/445237/{tahun}`, nomor urut dari agenda kantor, diisi manual
   saat cetak pertama ([ADR 0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md)).
   Sisanya belum dikonfirmasi; nomor pada data contoh masih perkiraan.

Butir soal peran penerima barang terpisah sudah terjawab: **tidak ada**, hanya
Pengelola Gudang dan Admin — lihat [ADR 0008](../keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md).
