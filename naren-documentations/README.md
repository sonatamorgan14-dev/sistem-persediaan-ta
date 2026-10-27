# Dokumentasi Naren — Pengadaan & Manajemen Gudang

Dokumentasi pengembangan **sub sistem pengadaan dan manajemen gudang** pada
sistem persediaan BPK Perwakilan Provinsi Lampung.

Repositori ini dikerjakan berdua dan menghasilkan **dua skripsi terpisah dengan
judul berbeda**, satu untuk tiap sub sistem. Karena itu dokumentasinya pun
dipisah: berkas di folder ini milik Narendra, sedangkan Alma memelihara
dokumentasinya sendiri untuk sub sistem permintaan barang.

Seluruh berkas ditulis dalam Markdown supaya ikut versi di Git, terbaca langsung
di editor, dan potongannya dapat dipakai ulang untuk naskah tugas akhir.

> `README.md` di root repositori **bukan** tempat dokumentasi pengembangan.
> Berkas itu hanya memuat hal yang diperlukan untuk bekerja bersama: cara
> menjalankan proyek, pembagian tugas, dan aturan yang wajib dipatuhi.

## Isi

| Berkas | Isi |
|---|---|
| [ikhtisar-sistem.md](ikhtisar-sistem.md) | Tujuan sistem, peran pengguna, pembagian sub sistem, tumpukan teknologi |
| [progres.md](progres.md) | Catatan kronologis tiap tahap pengerjaan |
| [keputusan/](keputusan/) | Architecture Decision Record — alasan di balik tiap keputusan rancangan |
| [modul/](modul/) | Rincian tiap modul: fitur, rute, hak akses, aturan bisnis |
| [erd/penyelarasan-erd.md](erd/penyelarasan-erd.md) | Peta kepemilikan tabel dan agenda penyelarasan tabel bersama dengan Alma |

## Daftar keputusan (ADR)

| No | Judul | Status |
|---|---|---|
| [0001](keputusan/0001-autentikasi-sesi-tanpa-starter-kit.md) | Autentikasi berbasis sesi tanpa starter kit | Diterima |
| [0002](keputusan/0002-otorisasi-peran-gate-dan-can.md) | Otorisasi: enum peran, gate, dan `can:` alih-alih `role:` | Diterima |
| [0003](keputusan/0003-arsip-alih-alih-hapus.md) | Akun dan barang diarsipkan, bukan dihapus | Diterima |
| [0004](keputusan/0004-stok-hanya-lewat-stockledger.md) | Stok hanya berubah lewat `StockLedger` | Diterima |
| [0005](keputusan/0005-buku-mutasi-immutable-dan-urutan-pencatatan.md) | Buku mutasi immutable dan diurutkan menurut waktu pencatatan | Diterima |
| [0006](keputusan/0006-mutationtype-merangkap-alasan-manual.md) | `MutationType` merangkap alasan mutasi manual | Diterima |
| [0007](keputusan/0007-kolom-audit-dan-referensi-polimorfik.md) | Kolom audit tambahan dan referensi dokumen polimorfik | Diterima |
| [0008](keputusan/0008-penerimaan-barang-oleh-pengelola-gudang.md) | Penerimaan barang dikonfirmasi Pengelola Gudang | Diterima |
| [0009](keputusan/0009-laporan-pdf-dan-csv.md) | Laporan PDF lewat dompdf dan CSV tanpa paket tambahan | Diterima |
| [0010](keputusan/0010-alur-stok-opname-dan-penyesuaian-selisih.md) | Alur stok opname: draf, posting wajib lengkap, dan penyesuaian hanya menerapkan selisih hasil cacah | Diterima |
| [0011](keputusan/0011-alasan-selisih-enum-baku-dan-keterangan-wajib.md) | Alasan selisih: enum baku berpasangan keterangan wajib | Diterima |
| [0012](keputusan/0012-periode-opname-enum-jenis-dan-tahun.md) | Periode opname sebagai enum jenis + tahun | Diterima |
| [0013](keputusan/0013-notifikasi-memisahkan-keadaan-dari-peristiwa.md) | Notifikasi memisahkan keadaan dari peristiwa | Sebagian digantikan oleh 0036 |
| [0014](keputusan/0014-pemeriksaan-notifikasi-dipanggil-eksplisit.md) | Pemeriksaan notifikasi dipanggil eksplisit, bukan lewat hook di `StockLedger` | Diterima |
| [0015](keputusan/0015-usulan-pengadaan-dan-penerimaan-barang.md) | Usulan pengadaan dan penerimaan barang: skema diperbaiki dan dipisah tegas dari sub sistem Permintaan | Diterima |
| [0016](keputusan/0016-alur-pengadaan-tiga-dokumen.md) | Alur pengadaan tiga dokumen dan pemisahan tugas | Diterima |
| [0017](keputusan/0017-jumlah-diterima-terpisah-dari-jumlah-dipesan.md) | Jumlah diterima dicatat terpisah dari jumlah dipesan | Diterima |
| [0018](keputusan/0018-berkas-unggahan-di-disk-privat.md) | Berkas unggahan di disk privat, dan BAST bertanda tangan boleh menyusul | Diterima |
| [0019](keputusan/0019-harga-di-realisasi-tanpa-penilaian-persediaan.md) | Harga dicatat di realisasi pengadaan; penilaian persediaan di luar cakupan | Diterima |
| [0020](keputusan/0020-notifikasi-usulan-dan-persetujuan-sebagian.md) | Notifikasi usulan pengadaan dan persetujuan sebagian per barang | Diterima |
| [0021](keputusan/0021-realisasi-dan-penerimaan-pengadaan.md) | Realisasi dan penerimaan pengadaan: batas cakupan Tahap 3 | Sebagian digantikan oleh 0042 |
| [0022](keputusan/0022-wewenang-unggah-dan-hapus-berkas-pengadaan.md) | Wewenang mengunggah dan menghapus berkas pengadaan | Sebagian digantikan oleh 0041 |
| [0023](keputusan/0023-laporan-pengadaan-per-baris-barang.md) | Laporan pengadaan berbutir barang, dan BAST mencetak jumlah diterima | Diterima |
| [0024](keputusan/0024-pembatalan-realisasi-pengadaan.md) | Pembatalan realisasi pengadaan, dan penagih usulan yang menganggur | Diterima |
| [0025](keputusan/0025-realisasi-dapat-diubah-sebelum-penerimaan.md) | Realisasi dapat diubah selama belum dikonfirmasi diterima | Diterima |
| [0026](keputusan/0026-agregat-tidak-menghitung-realisasi-batal.md) | Agregat mencacah pengadaan yang terjadi, bukan dokumen yang pernah dibuat | Diterima |
| [0027](keputusan/0027-draf-usulan-pengadaan-otomatis.md) | Draf usulan pengadaan otomatis, satu dokumen terkelola sistem | Diterima |
| [0028](keputusan/0028-status-usulan-disetujui-dipecah-dua.md) | Status usulan "Disetujui" dipecah menjadi dua | Diterima |
| [0029](keputusan/0029-realisasi-boleh-melebihi-usulan-dan-barang-tambahan.md) | Realisasi boleh melebihi usulan, dan boleh menambah barang | Diterima |
| [0030](keputusan/0030-persetujuan-realisasi-oleh-kasubbag-umum-dan-ti.md) | Persetujuan realisasi pengadaan oleh Kasubbag Umum dan TI | Diterima |
| [0031](keputusan/0031-realisasi-pengadaan-tanpa-usulan.md) | Realisasi pengadaan tanpa usulan, berbekal dasar pengadaan | Diterima |
| [0032](keputusan/0032-usulan-barang-baru.md) | Usulan barang baru: pintu depan katalog | Diterima |
| [0033](keputusan/0033-usulan-barang-baru-tabel-bersama-dengan-permintaan.md) | `item_proposals` resmi jadi tabel bersama dengan sub sistem Permintaan | Diterima |
| [0034](keputusan/0034-log-aktivitas-terstruktur.md) | Log aktivitas: kolom terstruktur mendampingi kalimat audit | Diterima |
| [0035](keputusan/0035-tahap-belanja-dan-foto-belanja-realisasi.md) | Tahap belanja pada realisasi pengadaan, berbukti foto belanja | Diterima |
| [0036](keputusan/0036-lonceng-kotak-masuk-dan-notifikasi-selesai-dihapus.md) | Lonceng sebagai kotak masuk; notifikasi yang selesai dihapus | Sebagian digantikan oleh 0037 |
| [0037](keputusan/0037-kuota-lonceng-pengingat-ulang-dan-peristiwa-berulang.md) | Lonceng: kuota per bagian, pengingat ulang keadaan, dan peristiwa berulang | Diterima |
| [0038](keputusan/0038-profil-pengguna-dan-foto-profil.md) | Profil pengguna: swalayan terbatas dan foto profil | Sebagian digantikan oleh 0039 |
| [0039](keputusan/0039-pemotong-foto-profil-interaktif.md) | Pemotong foto profil interaktif, tanpa pustaka | Diterima |
| [0040](keputusan/0040-dasbor-berbasis-peran-dari-data-langsung.md) | Dasbor berbasis peran, dihitung langsung dari data | Diterima |
| [0041](keputusan/0041-kasubbag-pengawas-baca-saja.md) | Kasubbag Umum dan TI sebagai pengawas baca-saja gudang dan pengadaan | Diterima |
| [0042](keputusan/0042-bast-pengadaan-format-resmi-bpk.md) | BAST pengadaan mengikuti format resmi BPK, nomornya diisi saat cetak pertama | Diterima |

## Cara memelihara dokumentasi ini

| Pemicu | Tindakan |
|---|---|
| Keputusan rancangan baru | Tambah ADR bernomor urut berikutnya di `keputusan/` |
| Keputusan lama berubah | **Jangan sunting ADR lama.** Buat ADR baru, lalu ubah status ADR lama menjadi `Digantikan oleh 00XX` |
| Modul selesai atau berubah | Perbarui berkas terkait di `modul/` |
| Sesi kerja menghasilkan perubahan | Tambah satu entri di [progres.md](progres.md) |
| Skema database berubah | Catat pada ADR terkait dan [erd/penyelarasan-erd.md](erd/penyelarasan-erd.md) |
| Skema **tabel bersama** berubah | Selain dicatat, **kabari Alma** — lihat peta kepemilikan tabel |

**ADR lama tidak pernah disunting.** Alasannya: jejak perubahan cara berpikir
justru bernilai untuk naskah tugas akhir dan untuk menjawab pertanyaan sidang.
Menyunting ADR lama menghapus bukti bahwa keputusan itu pernah dipertimbangkan
ulang.

### Format ADR

Tiap ADR memuat lima bagian tetap:

1. **Status** — Diterima / Digantikan oleh 00XX
2. **Konteks** — keadaan yang membuat keputusan ini perlu diambil
3. **Keputusan** — apa yang diputuskan
4. **Alternatif yang ditolak** — beserta alasan penolakannya
5. **Konsekuensi** — akibat yang harus diterima, baik menguntungkan maupun tidak
