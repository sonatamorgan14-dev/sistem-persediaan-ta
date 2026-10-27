# ADR 0042 — BAST pengadaan mengikuti format resmi BPK, nomornya diisi saat cetak pertama

## Status

Diterima — 24 Oktober 2026

Menggantikan sebagian [ADR 0021](0021-realisasi-dan-penerimaan-pengadaan.md):
`reference_number` mutasi penerimaan tidak lagi diisi nomor BAST, dan nomor
BAST tidak lagi diisi di formulir konfirmasi penerimaan (berikut saran
berurutnya).

## Konteks

Narendra membawa format resmi **Berita Acara Serah Terima Barang Persediaan**
BPK Perwakilan Provinsi Lampung (contoh: BAST ATK bernomor
`…/UP_TUP/445237/2025`, 18 Desember 2025). Format itu berbeda dari BAST yang
dicetak sistem sejak Tahap 4 dalam tiga hal mendasar:

1. **Pihaknya internal.** PIHAK PERTAMA adalah *Pelaksana Belanja* (Pejabat
   Pengadaan), PIHAK KEDUA adalah *Petugas Persediaan* (Pengelola Gudang).
   BAST sistem selama ini menempatkan penyedia sebagai PIHAK PERTAMA,
   sedangkan format kantor mendokumentasikan serah terima antarpegawai.
2. **Identitas pegawai lengkap**: nama, NIP, dan jabatan, dan tanggalnya
   ditulis terbilang ("Kamis tanggal Delapan belas bulan Desember tahun Dua
   ribu dua puluh lima (18-12-2025)"). Sistem tidak menyimpan NIP maupun
   jabatan.
3. **Nomornya dari agenda kantor.** Pada contoh, nomor urut di depan
   `/UP_TUP/445237/2025` dibiarkan kosong untuk diisi — nomornya ditentukan
   di luar sistem, dan sering belum ada ketika barang datang. Sistem justru
   mewajibkannya di formulir konfirmasi penerimaan dan menyarankan
   `{urut}/BAST/{tahun}` buatannya sendiri.

## Keputusan

**1. Halaman 1 menyalin format resmi persis; rincian barang di lampiran.**
Kop berlogo (`public/images/logo_bpk_kop.jpeg`), garis bawah kop, judul,
nomor, paragraf pembuka, data kedua pihak, dan tabel tanda tangan dua kolom
"Pelaksana Belanja" / "Petugas Persediaan". Kalimatnya disalin apa adanya,
termasuk ketidakseragaman format aslinya ("Yang selanjutnya disebut sebagai"
vs "yang selanjutnya disebut", dan "Serah Terima Pekerjaan" di kalimat
penutup) dan "dengan rincian sebagaimana dokumen tagihan yang terlampir" —
dokumen tagihan tetap boleh ikut dilampirkan. Rincian barang ada di halaman 2
berjudul "Lampiran", bergaya sama dengan halaman 1: blok nomor/tanggal di
kanan atas, dasar realisasi, penyedia, tabel barang yang diterima, catatan
kekurangan kiriman, dan keterangan penerimaan. Tanpa footer. A4, margin atas/
bawah 1,5 cm, kiri/kanan 2 cm, Times 12 pt.

**2. PIHAK PERTAMA adalah yang menandai realisasi dibelanjakan.**
`procurements.purchased_by` ([ADR 0035](0035-tahap-belanja-dan-foto-belanja-realisasi.md))
adalah "Pelaksana Belanja" secara harfiah. Realisasi lama tanpa
`purchased_by` jatuh ke pembuatnya (`procurements.user_id`), yang juga
Pejabat Pengadaan. PIHAK KEDUA adalah `procurement_receipts.received_by` —
akun yang mengonfirmasi barang diterima.

**3. NIP dan jabatan menjadi kolom akun, hanya diubah Admin.** `users.nip`
(18 digit, spasi pengelompokan dibuang sebelum validasi) dan
`users.position`, keduanya nullable. Alasannya sama dengan `fullname` pada
[ADR 0038](0038-profil-pengguna-dan-foto-profil.md): tercetak di blok tanda
tangan dokumen yang dirender ulang dari data langsung, jadi profil hanya
menampilkannya. Kolom kosong dicetak sebagai titik-titik untuk diisi tangan,
bukan menghalangi cetak.

**4. Nomor BAST diisi saat BAST dicetak pertama kali.**
`procurement_receipts.bast_number` menjadi nullable; formulir konfirmasi
penerimaan tidak lagi memintanya. Tombol "Cetak BAST" pada BAST tanpa nomor
membuka dialog berisi satu kolom nomor — **kosong, tanpa saran**, hanya
placeholder contoh — lalu menyimpan dan langsung membuka PDF-nya
(`PATCH pengadaan/{procurement}/bast`).

- **Siapa:** gate baru `isi-nomor-bast` — Pejabat Pengadaan dan Pengelola
  Gudang, dua penanda tangannya (Admin lolos lewat `Gate::before`). Kasubbag
  mencetak BAST yang sudah bernomor, tetapi tidak menomorinya
  ([ADR 0041](0041-kasubbag-pengawas-baca-saja.md)).
- **Kapan terkunci:** nomor boleh dikoreksi sampai BAST bertanda tangan
  diunggah (`Procurement::nomorBastDapatDiubah()`), dan terbuka lagi bila
  berkas itu dihapus. Setelah ada pindaian bertanda tangan, nomornya sudah
  menjadi bukti yang diarsipkan.
- **Validasi:** wajib, maksimal 100 karakter, **unik** di antara penerimaan
  (kecuali dirinya sendiri). Formatnya tidak divalidasi — bergantung pada
  mekanisme pembayaran (UP/TUP dan lainnya), bukan pada sistem. Keunikan
  dijaga validasi, bukan indeks basis data, supaya migrasi tidak gagal pada
  data lama yang mungkin sudah memuat nomor kembar.
- **Jejak:** mengisi dan mengubah nomor mencatat audit
  (`AuditModule::Penerimaan`, `AuditEvent::Ubah`); mengirim nomor yang sama
  tidak.

**5. Mutasi penerimaan merujuk judul realisasi, bukan nomor BAST.** Baris
`stock_mutations` tidak dapat diubah ([ADR 0005](0005-buku-mutasi-immutable-dan-urutan-pencatatan.md)),
sedangkan nomor BAST kini baru ada sesudah mutasinya tercatat.
`reference_number` diisi `Procurement::judul()` — pola yang sama dengan opname
— dan catatannya menjadi "Penerimaan barang dari {penyedia} sesuai {judul}".
Mutasi lama tetap memuat nomor BAST lamanya.

**6. Teknis cetak.** Tetap dompdf ([ADR 0009](0009-laporan-pdf-dan-csv.md)),
tanpa pustaka baru. Font: font inti PDF *Times-Roman/Times-Bold* bawaan dompdf,
yang metriknya sama dengan Times New Roman. Logo disematkan sebagai data URI
base64. Tanggal terbilang lewat `App\Support\Terbilang`. CSS-nya berdiri
sendiri, bukan `pdf._style`, karena BAST permintaan milik Alma masih bergantung
pada kelas BAST di sana.

## Alternatif yang ditolak

**Nomor tetap diisi di formulir konfirmasi penerimaan.** Itulah yang diganti:
nomornya sering belum ada saat barang datang, dan memaksanya diisi berarti
petugas mengarang nomor.

**Saran nomor urut otomatis** (`001/UP_TUP/445237/{tahun}`, lanjutan pola
ADR 0021). Nomor urutnya dari agenda kantor yang kemungkinan dipakai bersama
dokumen lain, jadi sistem tidak tahu urutan sebenarnya — dan saran yang keliru
justru dipercaya. Saran sufiksnya saja juga tidak dipakai: bagian `UP_TUP`
bergantung pada mekanisme pembayaran.

**Nomor terkunci sejak cetak pertama.** Salah ketik sebelum ditandatangani
tidak bisa dikoreksi lewat aplikasi sama sekali.

**Nomor selalu bisa diubah.** Cetak ulang bisa memuat nomor berbeda dari kertas
yang sudah ditandatangani dan diarsipkan.

**Menyematkan TTF Times New Roman.** Berkas font Windows berlisensi dan tidak
tersedia di server Linux; font inti PDF Times sudah identik metriknya.

**Kop diulang di halaman lampiran.** Lampiran dinas lazim tanpa kop; blok
nomor dan tanggal di kanan atas sudah mengikatnya ke berita acaranya.

**Barang yang tidak datang sama sekali tetap dicantumkan dengan jumlah 0.**
Lampiran berjudul rincian barang *yang diterima*; barang itu tetap tercatat di
catatan kekurangan kiriman di bawah tabel.

## Konsekuensi

- **`users` tabel bersama** — kolom `nip` dan `position` menunggu persetujuan
  Alma, sama seperti `avatar_path`. Kolomnya aditif dan nullable, berkas Alma
  tidak disentuh. Kolom ini juga bisa ia pakai untuk BAST permintaan dan Nota
  Dinas-nya.
- Penerimaan contoh di seeder kini lahir tanpa nomor, sehingga alur cetak
  pertama bisa didemonstrasikan.
- Baris `procurement_receipts` kini **diubah** untuk pertama kalinya, dan itu
  menyingkap `ON UPDATE CURRENT_TIMESTAMP` yang dipasang MariaDB diam-diam
  pada `received_at` (`explicit_defaults_for_timestamp = 0`): mengisi nomor
  BAST menggeser tanggal penerimaan — tanggal yang tercetak terbilang. Migrasi
  `2026_10_24_000003` memberinya DEFAULT eksplisit tanpa ON UPDATE.
- Notifikasi `BastBelumDiunggah` tidak lagi menyebut nomor BAST, karena
  keadaan itu justru paling sering berlaku sebelum nomornya ada.
- Laporan pengadaan (PDF/CSV) menampilkan `-`/kosong untuk penerimaan yang
  BAST-nya belum dicetak.
- Butir terbuka "format nomor dokumen resmi BPK" terjawab sebagian untuk BAST
  Pengadaan: bentuknya terlihat `{urut}/UP_TUP/445237/{tahun}`, diisi manual.
