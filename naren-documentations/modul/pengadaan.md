# Modul Pengadaan

> **Status: selesai.** Dibangun empat tahap, keempatnya selesai
> 6 September 2026.

Mengelola pengadaan barang persediaan dari usulan sampai barang masuk gudang.

## Batas cakupan

Sistem hanya menangkap proses pengadaan **di dalam kantor**. Pembelian
barangnya sendiri — mencari penyedia, menawar, membayar — terjadi **di luar
sistem**, di antara realisasi dan penerimaan.

```
Pengelola Gudang    Pejabat Pengadaan    Kasubbag Umum & TI    [LUAR SISTEM]    Pengelola Gudang
      │                     │                     │                   │                  │
  Usulan ──disetujui──▶ Realisasi ──ditinjau──▶ disetujui ────▶ pembelian nyata ──▶ Penerimaan
                            ▲                     │                                      │
                            └────── ditolak ──────┘                                stok bertambah
                          (diperbaiki, diajukan ulang)                                   │
                                                                                   BAST dicetak
```

Sejak [ADR 0031](../keputusan/0031-realisasi-pengadaan-tanpa-usulan.md) ada
jalur kedua yang memotong dokumen usulan, tetapi **tidak** memotong pemutusnya:

```
Pejabat Pengadaan            Kasubbag Umum & TI    [LUAR SISTEM]    Pengelola Gudang
       │                             │                   │                  │
  Realisasi langsung ──ditinjau──▶ disetujui ────▶ pembelian nyata ──▶ Penerimaan
  (+ dasar pengadaan wajib)
```

Sejak [ADR 0035](../keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md)
pembelian nyata itu tidak lagi sunyi. Kegiatannya tetap di luar sistem, tetapi
realisasi yang disetujui berstatus **Proses Belanja** sampai Pejabat Pengadaan
menandainya sudah dibelanjakan beserta foto belanjanya. Baru setelah itu gudang
menunggu barangnya:

```
... disetujui ──▶ Proses Belanja ──[pembelian nyata]──▶ ditandai dibelanjakan (+foto) ──▶ Menunggu Penerimaan ──▶ Diterima
```

Rincian keputusan: [ADR 0016](../keputusan/0016-alur-pengadaan-tiga-dokumen.md),
[ADR 0030](../keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md),
[ADR 0031](../keputusan/0031-realisasi-pengadaan-tanpa-usulan.md), dan
[ADR 0035](../keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md).

## Pemisahan tugas

Tidak ada satu peran pun yang dapat menjalankan seluruh siklus sendirian:

| Tahap | Pelaku | Tercatat di |
|---|---|---|
| Mengusulkan | Pengelola Gudang | `procurement_requests.user_id` |
| Mengusulkan barang baru | Pejabat Pengadaan | `item_proposals.proposed_by` |
| Menyetujui / menolak usulan barang baru | Pengelola Gudang | `item_proposals.decided_by` |
| Menyetujui / menolak usulan | Pejabat Pengadaan | `procurement_requests.status` |
| Merealisasi | Pejabat Pengadaan | `procurements.user_id` |
| **Menyetujui / menolak realisasi** | **Kasubbag Umum dan TI** | `procurements.reviewed_by` |
| Menandai sudah dibelanjakan (+ foto) | Pejabat Pengadaan | `procurements.purchased_by` |
| Membatalkan realisasi | Pejabat Pengadaan | `procurements.cancelled_by` |
| Mengonfirmasi diterima | Pengelola Gudang | `procurement_receipts.received_by` |

Sejak [ADR 0030](../keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md)
siklusnya bertangan empat: jenjang persetujuan realisasi disisipkan antara
"merealisasi" dan "mengonfirmasi diterima", supaya ada pihak yang menilai
penyedia, barang, dan harganya sebelum barang benar-benar dipesan.

Realisasi tanpa usulan (ADR 0031) menghapus baris pertama tabel di atas, bukan
pemisahan tugasnya: trionya tetap tiga orang berbeda — Pejabat Pengadaan
menyusun, Kasubbag Umum dan TI memutus, Pengelola Gudang menerima. Justru
karena itu jalur langsung baru layak dibuka **setelah** ADR 0030 ada.

Admin lolos seluruhnya lewat `Gate::before`, **termasuk `setujui-pengadaan`**.
Pemisahan staf TI dari pemberi persetujuan karena itu bersifat organisasional,
bukan ditegakkan sistem — konsekuensi yang diambil dengan sadar, lihat ADR 0030.

## Aturan bisnis yang ditegakkan

1. Realisasi lahir dari usulan ber-status `Disetujui`, **atau** tanpa usulan
   sama sekali — dan yang tanpa usulan wajib membawa `basis_reason` berpasangan
   `basis_note` sebagai gantinya
   ([ADR 0031](../keputusan/0031-realisasi-pengadaan-tanpa-usulan.md)).
2. Seluruh rincian usulan dengan `amount_granted > 0` wajib ada di realisasi
   dan tidak dapat dihapus darinya — tapi Pejabat Pengadaan boleh menambah
   barang di luar usulan itu sendiri, dan boleh menghapusnya kembali sebelum
   penerimaan ([ADR 0029](../keputusan/0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md)).
3. `procurement_details.amount` **tidak** dibatasi terhadap `amount_granted`
   — Pejabat Pengadaan boleh memesan lebih banyak dari yang disetujui usulan
   (ADR 0029; sebelum ADR ini batasnya `amount ≤ amount_granted`).
4. Satu usulan → paling banyak satu realisasi (`procurement_request_id` unik).
   Kolomnya nullable sejak ADR 0031, tetapi indeks uniknya dipertahankan: banyak
   baris NULL diizinkan, sehingga invarian ini tetap dijaga basis data.
5. **Stok bertambah sebanyak `amount_received`, bukan `amount`**
   ([ADR 0017](../keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)).
6. `MutationType::Masuk` ber-`isManual() === false`, sehingga penerimaan
   pengadaan adalah satu-satunya sumber sah stok masuk.
7. Realisasi hanya dapat dibatalkan selama barang belum dikonfirmasi diterima,
   sehingga pembatalan tidak pernah perlu membalik mutasi stok
   ([ADR 0024](../keputusan/0024-pembatalan-realisasi-pengadaan.md)).
8. **Barang tidak boleh dipesan sebelum realisasinya disetujui Kasubbag Umum dan
   TI**, dan penerimaan hanya mungkin atas realisasi ber-status
   `MenungguPenerimaan` (ADR 0030).
9. **Setiap perubahan pada realisasi menggugurkan persetujuannya** dan
   mengembalikannya ke antrean Kasubbag Umum dan TI. Aturan ini sekaligus
   menjadi mekanisme "ajukan ulang" bagi realisasi yang ditolak — tidak ada rute
   tersendiri untuk itu (ADR 0030). Termasuk perubahan pada dasar pengadaan
   realisasi langsung — kalau tidak, persetujuan atas dasar yang wajar dapat
   ditukar setelah terbit (ADR 0031).
10. **Barang wajib sudah terdaftar di master barang sebelum dapat diadakan.**
   Barang yang belum ada diusulkan lebih dulu lewat Usulan Barang Baru, dan
   Pengelola Gudang yang mendaftarkannya
   ([ADR 0032](../keputusan/0032-usulan-barang-baru.md)).
11. **Barang baru dapat diterima setelah realisasinya ditandai sudah
   dibelanjakan**, dan penandaan itu wajib disertai minimal satu foto belanja.
   Perubahan realisasi setelah dibelanjakan mengulang persetujuan, tetapi tidak
   mengulang belanjanya
   ([ADR 0035](../keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md)).

## Tabel

| Tabel | Isi |
|---|---|
| `suppliers` | Data induk penyedia; identitasnya dicetak di BAST |
| `procurement_requests` | Usulan pengadaan; `source` membedakan tiga asal-usul |
| `procurement_request_details` | Rincian barang usulan; `amount_granted` merangkap keputusan per barang, `is_auto` menandai baris yang masih dikelola draf otomatis |
| `procurements` | Realisasi pengadaan; menautkan usulan ke penyedia, atau berdiri sendiri dengan `basis_reason`/`basis_note` bila tanpa usulan (ADR 0031). `reviewed_at`/`reviewed_by`/`review_note` menampung keputusan **terakhir** Kasubbag Umum dan TI — persetujuan maupun penolakan, karena statusnya yang membedakan |
| `procurement_details` | Rincian realisasi; `amount` dipesan, `amount_received` datang, `unit_price` harga satuan |
| `procurement_receipts` | Peristiwa penerimaan: siapa, kapan; nomor BAST menyusul saat cetak pertama (ADR 0042) |
| `procurement_documents` | Berkas BAST pindai dan SPK/kontrak, di disk privat |
| `item_proposals` | Usulan barang baru; `item_id` terisi saat Pengelola Gudang mendaftarkan barangnya (ADR 0032) |

---

## Tahap 1 — Skema dan Penyedia Barang ✅

### Penyedia Barang

Data induk penyedia. Alasan keberadaannya bukan normalisasi, melainkan BAST:
dokumen serah terima memuat identitas penyedia, dan mengetik ulang alamat
beserta narahubung pada tiap dokumen persis friksi yang membuat orang enggan
memakai sistem.

**Rute** — yang mengubah data di bawah gate `kelola-pengadaan`; daftar dan
rincian di bawah `akses-penyedia`, yang juga meloloskan Kasubbag Umum dan TI
baca-saja ([ADR 0041](../keputusan/0041-kasubbag-pengawas-baca-saja.md)):

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| GET | `/penyedia` | `suppliers.index` | Daftar, pencarian, penyaring status |
| GET | `/penyedia/create` | `suppliers.create` | Formulir tambah |
| POST | `/penyedia` | `suppliers.store` | |
| GET | `/penyedia/{supplier}` | `suppliers.show` | Identitas + riwayat pengadaan |
| GET | `/penyedia/{supplier}/edit` | `suppliers.edit` | |
| PUT | `/penyedia/{supplier}` | `suppliers.update` | |
| PATCH | `/penyedia/{supplier}/status` | `suppliers.toggle-status` | Arsip / aktifkan |
| DELETE | `/penyedia/{supplier}` | `suppliers.destroy` | Ditolak bila sudah dipakai |

**Aturan.**

- `supplier_name` wajib dan unik; `address` wajib **karena dicantumkan di BAST**.
- Telepon, surel, narahubung, dan NPWP boleh kosong — diisi sesuai apa yang
  diketahui saat penyedia didaftarkan.
- Validasi NPWP dan telepon sengaja longgar (hanya menyaring karakter yang
  jelas keliru): format resmi belum dikonfirmasi, dan validasi yang terlalu
  ketat hanya akan menolak data yang sebenarnya benar.
- Penyedia yang sudah dipakai realisasi **diarsipkan, bukan dihapus**
  ([ADR 0003](../keputusan/0003-arsip-alih-alih-hapus.md)) — BAST yang sudah
  terbit atas namanya harus tetap dapat dibaca.

### Perubahan skema pendukung tahap berikutnya

Sudah mendarat di Tahap 1 walau fiturnya menyusul:

- `procurement_details.amount_received` (nullable) dan `unit_price`.
- `procurement_documents` beserta enum `ProcurementDocumentType`.
- `procurement_receipts.file_url` dihapus.
- `procurement_request_details.status` dihapus.
- Gate `akses-pengadaan`.

---

## Tahap 2 — Usulan Pengadaan ✅

Pengajuan, persetujuan/penolakan, jalur pintas dari halaman barang, dan empat
jenis notifikasi baru. Rincian keputusan:
[ADR 0020](../keputusan/0020-notifikasi-usulan-dan-persetujuan-sebagian.md).

**Rute** — mengusulkan dan membaca dipisah gate berbeda:

| Metode | URL | Nama | Gate | Keterangan |
|---|---|---|---|---|
| GET | `/usulan-pengadaan` | `procurement-requests.index` | `akses-pengadaan` | Daftar, penyaring status dan sumber |
| GET | `/usulan-pengadaan/create` | `procurement-requests.create` | `kelola-gudang` | Formulir usulan manual, murni dari nol |
| POST | `/usulan-pengadaan` | `procurement-requests.store` | `kelola-gudang` | |
| GET | `/usulan-pengadaan/{procurementRequest}` | `procurement-requests.show` | `akses-pengadaan` | Detail + formulir keputusan bila masih Diajukan |
| PATCH | `/usulan-pengadaan/{procurementRequest}/setujui` | `procurement-requests.approve` | `kelola-pengadaan` | Menerima peta `granted[]` per barang, sekali putus |
| PATCH | `/usulan-pengadaan/{procurementRequest}/tolak` | `procurement-requests.reject` | `kelola-pengadaan` | Mengisi `amount_granted = 0` pada seluruh barang |

**Aturan.**

- Persetujuan **satu dokumen sekali putus**, bukan satu aksi per barang:
  `granted[procurement_request_detail_id] => jumlah` diterapkan ke seluruh
  rincian dalam satu transaksi. Barang dengan `granted = 0` berarti tidak
  disetujui tanpa menolak seluruh usulan — inilah wujud "disetujui sebagian".
- Usulan yang sudah diputuskan (`DisetujuiMenungguRealisasi`/`DisetujuiSelesai`/`Ditolak`)
  tidak dapat diputuskan ulang; rute `approve`/`reject` menolaknya dengan
  pesan, bukan 500.
- Formulir manual ini hanya menerima `source = Manual` sejak
  [ADR 0027](../keputusan/0027-draf-usulan-pengadaan-otomatis.md) —
  `PemantauanStok` sekarang seluruhnya lahir lewat draf otomatis (lihat
  [Draf usulan pengadaan otomatis](#draf-usulan-pengadaan-otomatis) di bawah),
  bukan lagi lewat formulir ini dengan satu barang terisi. Sumber
  `DariPermintaan` sengaja belum bisa dipilih sama sekali lewat formulir —
  integrasi ke sub sistem Permintaan belum ada titik masuknya.

**Notifikasi baru** (lihat [modul/notifikasi.md](notifikasi.md)):
`UsulanMenungguPersetujuan` dan `UsulanMenggantung` (keadaan, saling
eksklusif dengan ambang 7 hari), `UsulanDisetujui` dan `UsulanDitolak`
(peristiwa ke pengaju).

## Draf usulan pengadaan otomatis

Ditambahkan 13 September 2026, menggantikan jalan pintas per barang di atas
dengan draf tunggal yang dijaga otomatis oleh sistem. Rincian keputusan:
[ADR 0027](../keputusan/0027-draf-usulan-pengadaan-otomatis.md).

**Rute** — seluruhnya `kelola-gudang`, didaftarkan sebelum
`usulan-pengadaan/{procurementRequest}` karena ruas statis:

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| GET | `/usulan-pengadaan/draf` | `procurement-requests.draft.edit` | Menjalankan ulang pemindaian lalu menampilkan draf, atau keadaan kosong |
| PUT | `/usulan-pengadaan/draf` | `procurement-requests.draft.update` | Mengganti seluruh rincian draf sekaligus |
| POST | `/usulan-pengadaan/draf/ajukan` | `procurement-requests.draft.finalize` | Memindahkan status Draf → Diajukan |
| DELETE | `/usulan-pengadaan/draf` | `procurement-requests.draft.discard` | Membuang draf beserta rinciannya |

**Aturan.**

- `App\Support\ProcurementDraftScanner` menjaga satu baris `ProcurementRequest`
  berstatus `Draf` tetap berisi seluruh barang di bawah `stock_min`, dengan
  saran jumlah `max(max(stock_max, stock_min) - stock_current, 1)`. Dipanggil
  eksplisit di titik yang sama dengan `NotificationScanner::periksaBarang()`,
  ditambah `OpnamePoster::post()` dan sapuan terjadwal `notifikasi:periksa`.
- Baris `is_auto = true` sepenuhnya milik pemindaian: dihitung ulang tiap
  pemindaian, dibuang begitu barangnya pulih. Begitu Pengelola Gudang
  menyimpan draf lewat `draft.update`, seluruh baris yang dikirim ditandai
  `is_auto = false` dan sejak itu kebal dari perubahan otomatis.
- Draf yang kehabisan seluruh barisnya (baik karena pulih maupun dikosongkan
  manual) **dihapus**, bukan dibiarkan sebagai baris kosong.
- Draf tidak pernah muncul di tabel `/usulan-pengadaan`, hitungan
  ringkasannya, maupun pilihan penyaring status — `ProcurementRequestStatus::options()`
  mengecualikannya, dan query index memakai `bukanDraf()`. Ia ditampilkan
  lewat banner tersendiri di halaman yang sama.
- Tombol "Ajukan Usulan Pengadaan" pada halaman detail barang (stok
  Habis/Menipis) sekarang menautkan ke halaman draf, bukan lagi ke formulir
  manual dengan satu barang terisi.

**Notifikasi baru**: `DraftUsulanTersedia` (keadaan, ke Pengelola Gudang +
Admin), aktif selama draf berisi minimal satu barang.

## Status usulan disetujui: dua rupa

Ditambahkan 13 September 2026 agar tabel dan filter usulan membedakan dua
keadaan yang sebelumnya tercampur dalam satu status `Disetujui`. Rincian
keputusan: [ADR 0028](../keputusan/0028-status-usulan-disetujui-dipecah-dua.md).

| Status | Makna |
|---|---|
| `DisetujuiMenungguRealisasi` | Disetujui, belum ada `Procurement` untuk usulan ini |
| `DisetujuiSelesai` | Disetujui dan sudah ada `Procurement`, **apa pun nasibnya** — termasuk yang dibatalkan |

**Aturan.**

- Transisi `MenungguRealisasi → Selesai` terjadi tepat satu kali, di
  `ProcurementController::store()`, dan **tidak pernah berbalik** — realisasi
  yang dibatalkan tidak mengembalikan usulan ke `MenungguRealisasi`, karena
  "selesai" di sini berarti "sudah dibuatkan realisasi", bukan "realisasinya
  berhasil".
- `ProcurementRequest::sudahDisetujui()` mencakup kedua sub-status, dipakai
  tempat yang cuma peduli keputusannya positif tanpa peduli sudah
  direalisasi atau belum (mis. notifikasi `UsulanDisetujui`/`UsulanDitolak`).
- Kartu ringkasan pada `/usulan-pengadaan` menampilkan keduanya terpisah:
  "Disetujui, Menunggu Realisasi" dan "Disetujui, Selesai".
- `ProcurementSeeder` membuat `Procurement` langsung lewat model (bukan lewat
  controller), sehingga menirukan transisi status ini secara manual — perlu
  diingat bila menambah seeder serupa di kemudian hari.

## Tahap 3 — Realisasi dan Penerimaan ✅

Realisasi pengadaan dari usulan yang sudah disetujui, dan konfirmasi
penerimaan barang yang menambah stok lewat `StockLedger`. Rincian keputusan:
[ADR 0021](../keputusan/0021-realisasi-dan-penerimaan-pengadaan.md).

**Rute realisasi** — `ProcurementController`, tiga gate seperti Tahap 2:

| Metode | URL | Nama | Gate | Keterangan |
|---|---|---|---|---|
| GET | `/pengadaan` | `procurements.index` | `akses-pengadaan` | Daftar, penyaring status |
| GET | `/pengadaan/create` | `procurements.create` | `kelola-pengadaan` | Menerima `?procurement_request_id=` dari halaman usulan |
| POST | `/pengadaan` | `procurements.store` | `kelola-pengadaan` | |
| GET | `/pengadaan/{procurement}` | `procurements.show` | `akses-pengadaan` | Detail; tombol aksinya menyesuaikan status dan peran |

**Rute persetujuan** — `ProcurementApprovalController`, gate
`setujui-pengadaan`, hanya berlaku selama status `MenungguPersetujuan`
(ADR 0030):

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| PATCH | `/pengadaan/{procurement}/setujui` | `procurements.approve` | Catatan opsional; status menjadi `MenungguPenerimaan` |
| PATCH | `/pengadaan/{procurement}/tolak` | `procurements.reject` | Alasan **wajib**; status menjadi `Ditolak`, masih dapat diperbaiki |

Tidak ada rute "ajukan ulang": perbaikan dikirim lewat `procurements.update`
milik Pejabat Pengadaan, dan penyimpanan perubahan itulah pengajuan ulangnya.

**Rute penerimaan** — `ProcurementReceiptController`, gate `kelola-gudang`,
peristiwa sekali jadi tanpa rute ubah/hapus. Hanya terbuka untuk realisasi yang
sudah disetujui:

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| GET | `/pengadaan/{procurement}/penerimaan/create` | `procurement-receipts.create` | Formulir konfirmasi; tanpa nomor BAST sejak [ADR 0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md) |
| POST | `/pengadaan/{procurement}/penerimaan` | `procurement-receipts.store` | Menjalankan `ProcurementReceiptPoster` |

**Aturan.**

- Formulir realisasi hanya dapat dibuka dari usulan `Disetujui` yang belum
  punya realisasi — tombol **Realisasikan** pada halaman usulan, bukan
  halaman berdiri sendiri. Wajib mencakup **seluruh** rincian usulan yang
  disetujui (`amount_granted > 0`), karena `procurement_request_id` unik
  berarti satu usulan paling banyak menghasilkan satu realisasi selamanya —
  barang yang dikecualikan sekarang tidak akan pernah punya kesempatan kedua.
- `item_id` pada baris usulan diturunkan server-side dari
  `procurement_request_detail_id`, bukan dikirim klien — pola yang sama
  dengan persetujuan usulan Tahap 2. Sejak
  [ADR 0029](../keputusan/0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md),
  Pejabat Pengadaan juga boleh menambahkan baris **di luar** usulan, yang
  mengirim `item_id` langsung; jumlah pesan pun tidak lagi dibatasi terhadap
  `amount_granted`.
- `ProcurementReceiptPoster::post()` mengikuti pola `OpnamePoster`: transaksi
  terkunci, `StockLedger::record(type: Masuk, amount: amount_received)` per
  barang (baris bernilai nol tidak menghasilkan mutasi), referensi menunjuk
  `Procurement` (alias morph `pengadaan`), status realisasi berubah menjadi
  `Diterima`, lalu `AuditLogger` dan notifikasi dipanggil di luar transaksi.
- Kiriman yang **lebih** dari pesanan tidak dibatasi validasi — hanya
  kekurangan kiriman yang jadi perhatian ADR 0017.
- `received_at` adalah tanggal bisnis yang dapat diedit (seperti
  `procurement_date`/`opname_date`/`mutation_date`), bukan dikunci ke `now()`.

**Notifikasi baru**: `RealisasiMenungguPenerimaan` (keadaan, ke Pengelola
Gudang + Admin) dan `PenerimaanDikonfirmasi` (peristiwa, ke Pejabat Pengadaan
yang merealisasi).

**Sejak ADR 0030**, `RealisasiMenungguPenerimaan` baru terbit setelah Kasubbag
Umum dan TI menyetujui — tanpa mengubah satu baris pun pada kode notifikasinya,
karena keadaan itu memang sudah berkunci pada status `MenungguPenerimaan`.
Tiga tipe baru menyertainya: `RealisasiMenungguPersetujuan` (keadaan, ke
Kasubbag Umum dan TI + Admin), serta `RealisasiDisetujui` dan `RealisasiDitolak`
(peristiwa, ke Pejabat Pengadaan yang merealisasi).

**Belum dikerjakan di tahap ini**: status `ProcurementStatus::Dibatalkan`
masih tanpa pemicu. Diselesaikan 7 September 2026 — lihat
[Pembatalan realisasi](#pembatalan-realisasi) di bawah dan
[ADR 0024](../keputusan/0024-pembatalan-realisasi-pengadaan.md).

## Tahap 4 — Dokumen, BAST, dan laporan ✅

Keluaran cetak dan berkas pendukung. Rincian keputusan:
[ADR 0022](../keputusan/0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md)
(wewenang berkas) dan
[ADR 0023](../keputusan/0023-laporan-pengadaan-per-baris-barang.md)
(bentuk laporan dan isi BAST).

**Rute** — seluruhnya di bawah gate `akses-pengadaan`, karena mencetak dan
melampirkan bukti bukan bagian dari pemisahan tugas ADR 0016:

| Metode | URL | Nama | Keterangan |
|---|---|---|---|
| GET | `/pengadaan/laporan/pdf` | `procurements.pdf` | Laporan pengadaan, mengikuti penyaring aktif |
| GET | `/pengadaan/laporan/csv` | `procurements.csv` | Ekspor CSV, kolom identik dengan PDF |
| GET | `/pengadaan/{procurement}/bast` | `procurements.bast` | BAST siap tanda tangan; menolak bila nomornya belum diisi |
| PATCH | `/pengadaan/{procurement}/bast` | `procurements.bast-number` | Mengisi/mengoreksi nomor BAST lalu membuka PDF-nya. Ditambah gate `isi-nomor-bast` — Pejabat Pengadaan dan Pengelola Gudang, bukan Kasubbag ([ADR 0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md)) |
| POST | `/pengadaan/{procurement}/dokumen` | `procurement-documents.store` | Unggah BAST pindai, SPK/kontrak, atau foto belanja. Ditambah gate `unggah-berkas-pengadaan` — hanya Pejabat Pengadaan dan Pengelola Gudang; Kasubbag tidak melampirkan bukti ke realisasi yang ia nilai ([ADR 0041](../keputusan/0041-kasubbag-pengawas-baca-saja.md)) |
| GET | `/pengadaan/{procurement}/dokumen/{document}/unduh` | `procurement-documents.download` | Unduhan terautentikasi; tidak ada URL berkas |
| GET | `/pengadaan/{procurement}/dokumen/{document}/lihat` | `procurement-documents.show` | Tampil *inline* untuk JPEG/PNG/WEBP/PDF, tetap dicatat (ADR 0035) |
| DELETE | `/pengadaan/{procurement}/dokumen/{document}` | `procurement-documents.destroy` | Hanya pengunggah atau Admin |

### Berkas pendukung

`App\Support\DocumentStorage` memegang seluruh konvensi penyimpanan: path
`pengadaan/{procurement_id}/{uuid}.{ext}` di disk **privat**
(`storage/app/private`), pengacakan nama berkas, penghapusan, dan pemeriksaan
keberadaan berkas. Controller tidak pernah menyebut nama disk maupun menyusun
path sendiri.

**Aturan.**

- Unggahan: format per jenis dari `ProcurementDocumentType::formatBerkas()` —
  Foto Belanja hanya `jpg,jpeg,png,webp,heic,heif`, jenis lain ditambah `pdf` —
  maksimal 10 MB, jenis divalidasi dengan
  `Rule::enum(ProcurementDocumentType::class)`. Sebelum ADR 0035:
  `pdf,jpg,jpeg,png`, 5 MB. Batas 10 MB hanya berlaku bila `upload_max_filesize`
  dan `post_max_size` di php.ini cukup besar; lihat bagian tahap belanja.
- `uploaded_by` diisi dari sesi, tidak pernah dari klien; tiap unggahan,
  **tiap unduhan**, dan tiap penghapusan menulis satu baris `audit_logs`.
  Unduhan ikut dicatat sejak 16 September 2026
  ([ADR 0034](../keputusan/0034-log-aktivitas-terstruktur.md)): ini berkas
  bukti pada disk privat — kontrak dan BAST bertanda tangan — sehingga siapa
  yang membacanya sama layaknya ditelusuri dengan siapa yang mengunggahnya.
  Dicatat sebelum berkasnya dikirim, karena unduhan yang terputus di tengah
  jalan tetap berarti isinya sudah terbuka.
- Nama berkas di disk diacak; nama asli disimpan di `original_name` dan
  dikembalikan utuh saat diunduh.
- Berkas yang tidak menempel pada pengadaan di URL dijawab **404**, bukan 403 —
  403 justru membenarkan bahwa berkas itu ada.
- Berkas yang barisnya ada tetapi fisiknya hilang di disk (misalnya setelah
  pemulihan cadangan) menghasilkan pesan ramah, bukan galat 500.

### BAST

Dicetak dari sistem lalu ditandatangani, dipindai, dan diunggah kembali —
urutan yang ditetapkan [ADR 0018](../keputusan/0018-berkas-unggahan-di-disk-privat.md).
Sejak 24 Oktober 2026 mengikuti **format resmi Berita Acara Serah Terima Barang
Persediaan BPK Perwakilan Provinsi Lampung**
([ADR 0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md)).

**Isi.**

- **Halaman 1** menyalin format resmi persis: kop berlogo, judul, nomor,
  tanggal terbilang dari `received_at` ("Kamis tanggal Delapan belas bulan
  Desember tahun Dua ribu dua puluh lima (18-12-2025)"), data kedua pihak,
  dan tanda tangan dua kolom. A4, margin 1,5 cm atas/bawah dan 2 cm kiri/kanan,
  Times 12 pt, tanpa footer.
- **PIHAK PERTAMA — Pelaksana Belanja**: `procurements.purchased_by` (yang
  menandai dibelanjakan, ADR 0035), cadangannya pembuat realisasi.
  **PIHAK KEDUA — Petugas Persediaan**: `procurement_receipts.received_by`.
  Nama dari `fullname`, NIP dan jabatan dari `users.nip`/`users.position`
  (diisi Admin di Manajemen Pengguna); yang kosong dicetak titik-titik.
- **Halaman 2 — Lampiran**: nomor dan tanggal BAST, dasar realisasi, penyedia,
  tabel barang **yang diterima** (`amount_received > 0`) dengan harga, baris
  jumlah, catatan kekurangan kiriman, dan keterangan penerimaan.

**Nomor BAST.**

- Diisi saat **cetak pertama**, bukan saat penerimaan dikonfirmasi: nomornya
  dari agenda kantor. Tombol "Cetak BAST" membuka dialog satu kolom (kosong,
  hanya placeholder contoh), lalu PDF terbuka di tab baru.
- Pengisi: Pejabat Pengadaan, Pengelola Gudang, Admin (`isi-nomor-bast`).
  Kasubbag melihat "BAST dapat dicetak setelah nomornya diisi." sampai salah
  satunya mengisi.
- Boleh dikoreksi lewat "Ubah Nomor BAST" sampai BAST bertanda tangan
  diunggah; terbuka lagi bila berkas itu dihapus.
- Wajib, maksimal 100 karakter, unik di antara penerimaan; format bebas.
  Mengisi dan mengubah mencatat audit (`Penerimaan`/`Ubah`).

**Aturan.**

- Seluruh angka pada BAST adalah `amount_received`, bukan `amount`
  ([ADR 0017](../keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md)).
- Baru terbit setelah penerimaan dikonfirmasi **dan** nomornya diisi; sebelum
  itu rute menolak dengan redirect berpesan.
- Kiriman kurang tetap menghasilkan BAST, dengan catatan kekurangan di lampiran
  — keadaan itu justru yang paling membutuhkan dokumen bertanda tangan.
- Mutasi stok penerimaan merujuk judul realisasi (`reference_number`), bukan
  nomor BAST, karena nomornya baru ada sesudah mutasi tercatat. Mutasi sebelum
  ADR 0042 tetap memuat nomor BAST lamanya.

### Laporan pengadaan

**Satu baris = satu barang pada satu realisasi**, bukan satu dokumen, sehingga
laporan terbaca sebagai daftar barang yang diadakan. Kolom dokumen (tanggal,
nomor realisasi, penyedia, status, nomor BAST) diulang pada tiap baris.

**Aturan.**

- Empat penyaring dipakai bersama layar, PDF, CSV, dan penjaga batas ekspor:
  status, penyedia, tanggal awal, tanggal akhir — satu definisi
  (`Procurement::scopeFilter`), empat pemakai.
- Rentang tanggal bekerja pada `procurement_date`, dan laporan diurutkan
  **menaik** menurut tanggal itu. Sengaja berbeda dari buku mutasi, yang
  [ADR 0005](../keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)
  haruskan diurutkan menurut waktu pencatatan karena `stock_after` adalah saldo
  berjalan; pengadaan tidak punya saldo berjalan apa pun.
- Rekap membawa **dua** nilai berdampingan — nilai dipesan dan nilai diterima —
  dan selisihnya adalah kekurangan kiriman penyedia. Rekap di layar mengikuti
  penyaring, bukan seluruh basis data, supaya angka di kartu selalu sama dengan
  angka di laporan yang dicetak dari halaman yang sama.
- **Realisasi yang dibatalkan tidak ikut dihitung** pada `total` maupun `nilai`,
  dan cacahnya berdiri sendiri di `dibatalkan` — ia dokumen yang pernah ada
  tetapi tidak pernah menjadi transaksi. Barisnya **tetap ditampilkan** di tabel
  dan ekspor, lengkap dengan status dan nilainya: ditampilkan, tidak dihitung
  ([ADR 0026](../keputusan/0026-agregat-tidak-menghitung-realisasi-batal.md)).
  Aturan yang sama berlaku pada kolom "Pengadaan" di daftar penyedia. Sisanya
  menyisakan invarian yang enak dibaca: `total = menunggu + diterima`.
- Batas ekspor 2.000 realisasi, lebih ketat daripada 5.000 baris pada laporan
  mutasi, karena satu realisasi membawa seluruh rincian barangnya.

### Notifikasi baru

`BastBelumDiunggah` (keadaan, ke Pengelola Gudang + Admin): muncul bila
penerimaan sudah dikonfirmasi lebih dari **3 hari** lalu tetapi berkas BAST
belum diunggah, dan menutup dirinya sendiri begitu berkasnya masuk. Inilah
imbangan dari kelonggaran ADR 0018 — penerimaan sengaja tidak menunggu berkas
supaya stok tidak tertahan, dan justru karena itu ketiadaan berkas perlu
ditagih.

## Persetujuan realisasi oleh Kasubbag Umum dan TI

Ditambahkan 15 September 2026 atas permintaan langsung Kasubbag Umum dan TI saat
presentasi prototipe di lapangan: ia ingin meninjau realisasi yang dibuat Pejabat
Pengadaan sebelum barang dipesan — cukup terima atau tolak. Rincian keputusan:
[ADR 0030](../keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md).

Peran `kasubbag_umum_ti` dipisahkan dari `admin` justru karena ini: di lapangan
Admin dipegang staf TI, dan `Gate::before` meloloskan Admin pada seluruh gate.

**Lima status realisasi:**

| Status | Arti | Boleh diubah / dibatalkan |
|---|---|---|
| `MenungguPersetujuan` | Keadaan kelahiran. Barang belum boleh dipesan | Ya |
| `MenungguPenerimaan` | Disetujui; menunggu konfirmasi barang diterima | Ya (persetujuannya gugur) |
| `Diterima` | Final; stok sudah bertambah | Tidak |
| `Ditolak` | Ditolak, masih dapat diperbaiki lalu diajukan ulang | Ya |
| `Dibatalkan` | Terminal; usulan asalnya ikut selesai riwayatnya | Tidak |

Sejak ADR 0035 ada status keenam, `ProsesBelanja`, di antara persetujuan dan
`MenungguPenerimaan` — lihat bagian *Tahap belanja dan foto belanja* di bawah.

**Aturan.**

- Realisasi lahir sebagai `MenungguPersetujuan`, bukan `MenungguPenerimaan`.
  Dokumennya memang sudah tersimpan saat dinilai — penyedia, barang, dan
  harganya justru yang jadi bahan penilaian — tetapi belum berlaku sampai
  disetujui. Pola yang sama dengan `ProcurementRequestStatus::Draf` (ADR 0027).
- **Penjagaan penerimaan tidak perlu diubah sama sekali.**
  `ProcurementReceiptController::create()`, `ProcurementReceiptPoster::pastikanSiap()`,
  dan keadaan `RealisasiMenungguPenerimaan` sudah berkunci pada status
  `MenungguPenerimaan`, sehingga realisasi yang belum disetujui otomatis tidak
  dapat diterima dan stok tidak mungkin bertambah.
- `Ditolak` **bukan** keadaan terminal. Kalau dibuat terminal, indeks unik
  `procurements.procurement_request_id` membuat usulan asalnya ikut mati
  permanen — jalan buntu yang sama persis dengan yang diperbaiki ADR 0024.
- Alasan penolakan **wajib** diisi, berupa teks bebas dan bukan enum baku
  seperti `AlasanPembatalan`: daftar alasan yang sebenarnya dipakai di lapangan
  belum pernah ditanyakan.
- Transisi usulan `DisetujuiMenungguRealisasi → DisetujuiSelesai` tetap terjadi
  saat realisasi **dibuat**, bukan saat disetujui (ADR 0028).
- Agregat memperlakukan `Ditolak` sama dengan `Dibatalkan`: ditampilkan, tidak
  pernah dihitung (`ProcurementStatus::takPernahJadiTransaksi()`). Invarian rekap
  menjadi `total = menunggu_persetujuan + menunggu + diterima`.
- Tempat kerjanya adalah grup menu **Persetujuan** berisi tautan ke
  `/pengadaan?status=menunggu_persetujuan`, bukan layar tersendiri. Keputusannya
  diambil dari halaman detail realisasi, pola yang sama dengan cara Pejabat
  Pengadaan memutuskan usulan.

## Mengubah realisasi

Ditambahkan 7 September 2026. Salah ketik pada realisasi — salah penyedia, harga,
atau jumlah — diperbaiki di sini, **bukan** dengan membatalkan lalu mengulang
dari usulan baru. Rincian keputusan:
[ADR 0025](../keputusan/0025-realisasi-dapat-diubah-sebelum-penerimaan.md).

| Metode | URL | Nama | Gate |
|---|---|---|---|
| GET | `/pengadaan/{procurement}/edit` | `procurements.edit` | `kelola-pengadaan` |
| PUT | `/pengadaan/{procurement}` | `procurements.update` | `kelola-pengadaan` |

**Aturan.**

- Hanya selama barang belum dikonfirmasi diterima — batas yang sama dengan
  pembatalan, dinyatakan oleh `ProcurementStatus::masihBerjalan()` yang mencakup
  `MenungguPersetujuan`, `Ditolak`, dan `MenungguPenerimaan` (ADR 0030; sebelum
  ADR itu hanya `MenungguPenerimaan`). Selama itu belum ada apa pun yang terjadi
  secara fisik, sehingga **tidak ada satu pun mutasi stok yang perlu
  disesuaikan**.
- **Setiap penyimpanan mengembalikan status ke `MenungguPersetujuan`** dan
  mengosongkan `reviewed_at`/`reviewed_by`/`review_note`. Satu aturan untuk dua
  keperluan: ia menjadi mekanisme "ajukan ulang" bagi realisasi yang ditolak,
  sekaligus menutup celah tata kelola — tanpa itu, persetujuan dapat diminta
  atas harga yang wajar lalu harganya diubah setelah disetujui (ADR 0030).
- Yang boleh diubah: `supplier_id`, `procurement_date`, serta `amount` dan
  `unit_price` tiap baris — jumlah pesan tidak lagi dibatasi terhadap
  `amount_granted` sejak
  [ADR 0029](../keputusan/0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md).
- Yang **tidak** boleh: `procurement_request_id` (memindahkan realisasi ke usulan
  lain menabrak indeks unik ADR 0016), dan menghapus baris yang berasal dari
  usulan (susunannya ditetapkan usulan yang disetujui, sehingga aturan
  ADR 0021 terpenuhi dengan sendirinya). Baris **di luar** usulan boleh
  ditambah maupun dihapus kembali di sini (ADR 0029).
- Baris usulan diperbarui **di tempat**, dikunci `procurement_detail_id`,
  supaya formulir penerimaan tetap menunjuk baris yang sama; baris tambahan
  baru dibuat saat disimpan, dan baris tambahan yang hilang dari kiriman
  berarti sengaja dihapus. Kiriman wajib tetap mencakup seluruh baris
  usulan — yang hilang di antaranya ditolak validasi, bukan diam-diam
  tertinggal memakai nilai lamanya.
- `update()` memanggil `NotificationScanner::periksaRealisasi()` karena isi
  notifikasi memuat nama penyedia. Panggilan itu sekaligus menutup tagihan
  penerimaan yang sudah tidak berlaku dan membuka tagihan persetujuan yang baru.
- Tombol **Ubah Realisasi** diletakkan di atas **Batalkan Realisasi**: urutan
  tombol adalah anjuran tentang mana yang seharusnya lebih sering dipakai.

## Pembatalan realisasi

Ditambahkan 7 September 2026, setelah pemeriksaan menyeluruh menemukan bahwa
alur ini punya jalan buntu: pembelian barangnya terjadi **di luar sistem**, dan
ketika penyedia gagal mengirim, realisasinya terkunci `MenungguPenerimaan`
selamanya. Satu-satunya jalan keluar yang tersedia dulu adalah mengonfirmasi
penerimaan barang yang tidak pernah datang. Rincian keputusan:
[ADR 0024](../keputusan/0024-pembatalan-realisasi-pengadaan.md).

| Metode | URL | Nama | Gate |
|---|---|---|---|
| PATCH | `/pengadaan/{procurement}/batal` | `procurements.cancel` | `kelola-pengadaan` |

**Aturan.**

- Hanya selama barang belum dikonfirmasi diterima (`masihBerjalan()`). Begitu
  barang diterima, stoknya sudah bertambah lewat mutasi yang immutable dan tidak
  ada lagi yang boleh dibatalkan — sehingga **tidak ada satu pun mutasi yang
  perlu dibalik**, dan `StockLedger` tidak tersentuh sama sekali oleh fitur ini.
- Sejak ADR 0030 inilah pula jalan keluar bagi realisasi yang **ditolak** dan
  tidak akan diperbaiki: penolakan sendiri tidak terminal, jadi tanpa pembatalan
  dokumennya akan menggantung selamanya.
- `cancel_reason` memakai enum `AlasanPembatalan` (`PenyediaGagalKirim`,
  `PenyediaMembatalkan`, `KebutuhanBerubah`, `KesalahanPencatatan`, `Lainnya`)
  dan `cancel_note` **wajib diisi** apa pun alasannya, pola yang sama dengan
  alasan selisih opname (ADR 0011). `cancelled_at` dan `cancelled_by`
  melengkapinya, sejajar `received_by` pada penerimaan.
- Dokumennya **tidak dihapus**: realisasi yang batal tetap tersimpan sebagai
  bukti bahwa pengadaan itu pernah berjalan.
- **Usulan asalnya ikut selesai riwayatnya.** Indeks unik
  `procurements.procurement_request_id` dipertahankan, sehingga usulan yang
  realisasinya dibatalkan tidak dapat direalisasikan ulang; barang yang masih
  dibutuhkan diajukan lewat usulan baru. Halaman usulan menjelaskan hal ini,
  supaya status `Disetujui` tanpa tombol **Realisasikan** tidak terbaca seperti
  bug.
- `KesalahanPencatatan` **menyempit** sejak
  [ADR 0025](../keputusan/0025-realisasi-dapat-diubah-sebelum-penerimaan.md):
  salah ketik biasa diperbaiki lewat Ubah Realisasi, sehingga alasan ini tinggal
  untuk satu kasus yang memang tidak dapat diperbaiki dengan mengubah —
  realisasi yang dibuat dari usulan yang sama sekali salah, karena
  `procurement_request_id` terkunci. Efeknya `AlasanPembatalan` kembali menjadi
  daftar kegagalan nyata di lapangan, bukan campuran kegagalan lapangan dan
  kekeliruan administrasi.

**Notifikasi baru**: `RealisasiDibatalkan` (peristiwa, ke Pengelola Gudang +
Admin kecuali pelaku) dan `UsulanBelumDirealisasi` (keadaan, ke Pejabat
Pengadaan + Admin, ambang 7 hari). Yang kedua menutup satu-satunya serah terima
di seluruh alur yang dulu tidak punya penagih: jarak antara "disetujui" dan
"direalisasi".

## Realisasi pengadaan tanpa usulan

Ditambahkan 16 September 2026 atas permintaan langsung Kasubbag Umum dan TI pada
presentasi prototipe yang sama dengan ADR 0030: Pejabat Pengadaan perlu dapat
membuat realisasi sendiri, tanpa menunggu usulan Pengelola Gudang. Rincian
keputusan: [ADR 0031](../keputusan/0031-realisasi-pengadaan-tanpa-usulan.md).

Jalur ini baru layak dibuka **setelah** jenjang Kasubbag Umum dan TI ada.
Sebelumnya, realisasi tanpa usulan akan membuat satu peran menjalankan separuh
siklus sendirian — memilih barang, penyedia, dan harga tanpa dokumen hulu
maupun pemutus.

| Metode | URL | Nama | Gate |
|---|---|---|---|
| GET | `pengadaan/create` | `procurements.create` | `kelola-pengadaan` |
| POST | `pengadaan` | `procurements.store` | `kelola-pengadaan` |

Rutenya **tidak bertambah**: `procurements.create` kini melayani dua pintu —
dengan `?procurement_request_id=` dari halaman usulan, dan tanpa query dari
tombol "Buat Realisasi Langsung" pada daftar realisasi.

**Aturan.**

- Realisasi tanpa usulan wajib membawa **dasar pengadaan**: `basis_reason`
  (enum `AlasanPengadaan`) berpasangan `basis_note` yang wajib diisi. Aturannya
  satu kalimat — alasan tidak pernah hilang dari sistem, ia hanya pindah ke
  dokumen yang tidak punya hulu.
- `AlasanPengadaan`: `KebutuhanMendesak`, `BarangBaru`, `ArahanPimpinan`,
  `BelanjaRutinNilaiKecil`, `Lainnya`.
- Pada realisasi berusulan kedua kolom itu **disimpan NULL** walaupun kiriman
  memuatnya: dasarnya adalah usulan itu sendiri, dan alasan kedua di sana hanya
  akan menjadi data yang tak pernah ditampilkan.
- `procurement_request_id` menjadi nullable, **indeks uniknya dipertahankan**.
  MySQL dan SQLite mengizinkan banyak baris NULL pada indeks unik, jadi
  "satu usulan satu realisasi" tetap dijaga basis data.
- Keadaan "tanpa usulan" **diturunkan**, bukan disimpan:
  `Procurement::tanpaUsulan()` membaca `procurement_request_id === null`. Tidak
  ada kolom `source` tersendiri.
- Seluruh baris realisasi langsung tergolong baris tambahan, jadi semuanya boleh
  dihapus selama tersisa minimal satu.
- Mengubah dasar pengadaan **menggugurkan persetujuan**, sama seperti perubahan
  lain (ADR 0030). Tanpa itu, persetujuan atas dasar yang wajar dapat ditukar
  setelah terbit.
- Query `?procurement_request_id=` yang ada tetapi tidak sah tetap ditolak,
  bukan dialihkan ke formulir langsung: salah tempel tautan tidak boleh berubah
  diam-diam menjadi pengadaan tanpa usulan.

**Penyaring dan rekap.** Kunci `sumber` (`langsung`/`usulan`) diterjemahkan
menjadi `whereNull`/`whereNotNull`. Rekap mendapat angka `langsung` yang
merupakan **irisan** dari `total`, bukan penjumlahnya, sehingga invarian
`total = menunggu_persetujuan + menunggu + diterima` tidak terganggu. Tabelnya
menandai baris tanpa usulan dengan label "Langsung".

**Laporan.** Ekspor CSV mendapat kolom **Dasar** tersendiri. Laporan PDF sudah
sebelas kolom pada kertas lanskap, jadi dasarnya ditaruh sebagai baris kedua di
dalam sel "Realisasi" bersama statusnya, dan kepala laporan menyebut penyaring
dasar pengadaan bila sedang dipakai. Aturan
[ADR 0023](../keputusan/0023-laporan-pengadaan-per-baris-barang.md) tidak
berubah.

**Yang tidak disentuh satu baris pun.** `ProcurementReceiptPoster`,
`ProcurementReceiptController`, `StockLedger`, `ProcurementApprovalController`,
dan keadaan `RealisasiMenungguPenerimaan` — semuanya bekerja pada
`procurement_details` dan status, bukan pada usulan.

---

## Usulan barang baru

Ditambahkan 16 September 2026, menjawab permintaan kedua Kasubbag Umum dan TI:
Pejabat Pengadaan perlu dapat mengadakan barang yang belum ada di master barang.
Rincian keputusan: [ADR 0032](../keputusan/0032-usulan-barang-baru.md).

Barangnya **harus** menjadi baris `items` lebih dulu — stok tidak bisa diposting
ke sebuah nama bebas. Sementara kode barang dan batas stok adalah wewenang
Pengelola Gudang, dan langkahnya yang paling bernilai justru **pemeriksaan
duplikat**: satu barang fisik dengan dua baris di master membuat batas minimum,
stok opname, dan kartu persediaannya berhenti bisa dipercaya.

```
Pejabat Pengadaan        Pengelola Gudang                    Pejabat Pengadaan
       │                        │                                    │
  Usulan barang ──▶ Setujui & Buat Barang ──▶ items (stok 0) ──▶ Realisasi
       │                        │
       └──────── Tolak (alasan wajib) ─────────┘
```

| Metode | URL | Nama | Gate |
|---|---|---|---|
| GET | `usulan-barang` | `item-proposals.index` | `akses-pengadaan` |
| GET | `usulan-barang/create` | `item-proposals.create` | `kelola-pengadaan` |
| POST | `usulan-barang` | `item-proposals.store` | `kelola-pengadaan` |
| PATCH | `usulan-barang/{itemProposal}/tolak` | `item-proposals.reject` | `kelola-gudang` |
| GET | `usulan-barang/{itemProposal}` | `item-proposals.show` | `akses-pengadaan` |

**Aturan.**

- **Tidak ada rute "setujui".** Menyetujui berarti mendaftarkan barangnya:
  tombolnya menuju `items.create?item_proposal_id=…`, dan
  `ItemController::store()` yang menutup usulannya — di dalam transaksi yang
  sama, sehingga tidak mungkin ada usulan `Disetujui` tanpa barang.
- Pengusul mengisi nama bebas, satuan, dan kegunaan yang **wajib**. Kode barang
  serta batas minimum/maksimum tetap diisi Pengelola Gudang.
- Penolakan wajib berketerangan. Penolakan tersering adalah "barangnya sudah ada,
  pakai ATK-014", dan tanpa keterangan itu pengusul akan mengusulkan hal yang
  sama lagi.
- Nama barang **tidak** divalidasi keunikannya: pemeriksaan duplikat pekerjaan
  orang yang mengenal penamaan bakunya, bukan validator yang hanya mencocokkan
  huruf.
- Usulan yang sudah diputuskan tidak dapat diputuskan dua kali; penjaganya ada
  di `create()` maupun `StoreItemRequest`, karena formulirnya bisa dikirim ulang
  dari tab lama.

**Notifikasi baru.**

| Tipe | Sifat | Penerima |
|---|---|---|
| `UsulanBarangMenungguKeputusan` | keadaan | Pengelola Gudang dan Admin |
| `UsulanBarangDisetujui` | peristiwa | pengusul |
| `UsulanBarangDitolak` | peristiwa | pengusul, memuat keterangan penolakan |

**Konsekuensi yang perlu diingat.** Barang hasil usulan lahir berstok nol,
sehingga `StokHabis` langsung berlaku dan draf usulan otomatis menyedotnya.
Bukan kerusakan — stoknya memang habis — dan keduanya melepas sendiri begitu
barangnya diterima gudang.

**Batas kerja.** Tabel `item_proposals` sengaja netral peran supaya sub sistem
permintaan barang dapat memakainya kelak tanpa perubahan skema, tetapi yang
dibangun sekarang hanya pintu Pengadaan dan layar keputusan Gudang. Tidak ada
berkas sub sistem permintaan yang disentuh.

## Tahap belanja dan foto belanja

Ditambahkan 23 September 2026 atas permintaan langsung Pejabat Pengadaan di
lapangan. Setelah disetujui Kasubbag Umum dan TI, realisasi seharusnya tidak
langsung "menunggu penerimaan", karena barangnya baru akan dibelanjakan. Beliau
juga meminta realisasi dapat dilampiri foto barang atau foto kegiatan
belanjanya. Rincian keputusan:
[ADR 0035](../keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md).

**Enam status realisasi:**

| Status | Arti | Boleh diubah / dibatalkan |
|---|---|---|
| `MenungguPersetujuan` | Keadaan kelahiran. Barang belum boleh dibeli | Ya |
| **`ProsesBelanja`** | Disetujui; Pejabat Pengadaan sedang membelanjakan | Ya (persetujuannya gugur) |
| `MenungguPenerimaan` | Sudah dibelanjakan; menunggu konfirmasi barang diterima | Ya (persetujuannya gugur) |
| `Diterima` | Final; stok sudah bertambah | Tidak |
| `Ditolak` | Ditolak, masih dapat diperbaiki lalu diajukan ulang | Ya |
| `Dibatalkan` | Terminal; usulan asalnya ikut selesai riwayatnya | Tidak |

**Rute:**

| Metode | URL | Nama | Gate |
|---|---|---|---|
| PATCH | `/pengadaan/{procurement}/dibelanjakan` | `procurements.purchase` | `kelola-pengadaan` |

**Aturan.**

- Hanya **satu** status baru. "Sudah dibelanjakan" dan "menunggu penerimaan"
  adalah keadaan fisik yang sama. Dua status untuk keduanya berarti ada
  perpindahan yang tidak dipicu siapa pun.
- Persetujuan membawa realisasi ke `ProsesBelanja`. Tombol **Tandai Sudah
  Dibelanjakan** (Pejabat Pengadaan dan Admin) membuka dialog berisi tanggal
  belanja, foto (maks. 5 per kiriman, masing-masing maks. 10 MB), dan catatan
  opsional. Menyimpannya memindahkan status ke `MenungguPenerimaan` dan mengisi
  `purchased_at`, `purchased_by`, dan `purchase_note`.
- **Foto belanja wajib minimal satu**, dihitung dari keadaan: foto
  `FotoBelanja` yang sudah diunggah lebih dulu lewat Berkas Pendukung ikut
  dihitung, berkas jenis lain tidak. Berbeda dari BAST yang boleh menyusul
  (ADR 0018) karena foto belanja sudah ada di ponsel ketika tombolnya ditekan.
- Tanggal belanja tidak boleh melewati hari ini maupun mendahului tanggal
  realisasi.
- Selama `ProsesBelanja`, gudang **tidak** dapat mengonfirmasi penerimaan.
  Halaman detail menjelaskan sebabnya kepada yang tidak memegang tombol tandai
  (gudang, Kasubbag), dan pesan galatnya menunjuk Pejabat Pengadaan.
- **Mengubah realisasi setelah dibelanjakan** tetap mengembalikannya ke
  `MenungguPersetujuan` (ADR 0030), tetapi `purchased_*` tidak dikosongkan.
  Setelah disetujui ulang, ia **langsung** kembali ke `MenungguPenerimaan`.
  Mengubah dokumen mengulang penilaian, bukan mengulang belanja. Keputusannya
  ada di `ProcurementApprovalController::approve()`, lewat
  `Procurement::sudahDibelanjakan()`.
- Kartu **Belanja** di halaman detail menampilkan tanggal, penanda, jumlah foto,
  dan catatan belanja. Kartu ini tetap tampil saat realisasi yang sudah
  dibelanjakan menunggu persetujuan ulang.
- Berkas foto ditulis ke disk sebelum transaksi, dan dibuang lagi lewat
  `DocumentStorage::hapusBerkas()` bila transaksinya gagal.
- Jejak audit: satu baris `Pengadaan / UbahStatus`, ditambah satu baris
  `DokumenPengadaan / Unggah` per foto.
- Tombol **Lihat** pada daftar berkas membuka JPEG, PNG, WEBP, dan PDF langsung
  di tab baru (`DocumentStorage::tampilkan()`, dengan `nosniff`). HEIC tetap
  diunduh. Melihat dicatat seperti mengunduh. Karena itu tidak ada gambar kecil
  di halaman: setiap tampilan halaman akan tercatat sebagai pembacaan berkas.

**Notifikasi.**

- `RealisasiMenungguPenerimaan` kini baru terbit setelah barang ditandai sudah
  dibelanjakan, bukan saat disetujui. Isinya menyebut tanggal belanja.
- `RealisasiBelumDibelanjakan` (keadaan, ke Pejabat Pengadaan + Admin): muncul
  bila realisasi berada di `ProsesBelanja` lebih dari **7 hari** sejak
  disetujui, dan menutup dirinya sendiri begitu ditandai, diubah, atau
  dibatalkan.
- Isi peristiwa `RealisasiDisetujui` kini menyuruh membelanjakan lalu
  menandai. Bila barangnya ternyata sudah dibelanjakan sebelum diubah, isinya
  mengatakan gudang langsung menunggu.

**Rekap.** Kartu dan laporan PDF mendapat angka *Proses Belanja*. Invarian
rekap menjadi `total = menunggu_persetujuan + proses_belanja + menunggu + diterima`.

**Lingkungan.** Batas unggah PHP harus menampung foto ponsel. PHP di laptop
pengembangan ternyata memuat `upload_max_filesize=2M` dan `post_max_size=8M`,
sehingga foto di atas 2 MB ditolak PHP sebelum Laravel sempat memvalidasinya.
Naikkan, misalnya, ke `upload_max_filesize=12M` dan `post_max_size=64M`, di
laptop maupun server.

---

## Butir yang masih menunggu lapangan

**Label "Proses Belanja"** perlu dikonfirmasi ke Pejabat Pengadaan. Bila istilah
lapangannya lain ("Sedang Dibelanjakan", "Menunggu Dibelanjakan"), cukup ganti
`ProcurementStatus::label()`.

**Format nomor dokumen resmi BPK** untuk `procurement_receipts.bast_number`
terjawab sebagian oleh contoh BAST resmi: `{urut}/UP_TUP/445237/{tahun}`,
dengan nomor urut dari agenda kantor. Karena itu sistem tidak lagi menyarankan
nomor sama sekali — diisi manual saat cetak pertama, tanpa validasi format
([ADR 0042](../keputusan/0042-bast-pengadaan-format-resmi-bpk.md)). Yang masih
perlu dipastikan: apakah `445237` memang kode satker yang tetap, dan bagian
`UP_TUP` untuk mekanisme pembayaran lain.

**Menutup usulan yang sudah disetujui tetapi barangnya tidak lagi dibutuhkan**
belum ada jalannya — usulan itu hanya akan ditagih terus oleh
`UsulanBelumDirealisasi`. Belum diputuskan karena belum ada kebutuhan lapangan
yang mengonfirmasinya, dan kali ini catatan itu ditulis dengan kesadaran bahwa
catatan serupa untuk `Dibatalkan` ternyata menyembunyikan jalan buntu selama
satu tahap penuh.

**Arti "barang kosong" pada permintaan Kasubbag Umum dan TI** perlu dipastikan
di kunjungan berikutnya, karena sebenarnya dua hal yang mungkin tertukar:
barang yang **ada di katalog tetapi stoknya nol** — sudah didukung, bahkan sudah
otomatis menjadi draf usulan — versus barang yang **tidak ada di katalog sama
sekali**, yang dijawab [ADR 0032](../keputusan/0032-usulan-barang-baru.md). Bila
yang dimaksud yang pertama, sisanya hanya soal apakah sub sistem permintaan
mengizinkan permintaan atas barang berstok nol, dan itu wilayah Alma.
