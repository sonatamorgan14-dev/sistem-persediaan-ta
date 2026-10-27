{{-- Berita Acara Serah Terima Barang Persediaan dengan format resmi BPK
     Perwakilan Provinsi Lampung (ADR 0042). Halaman 1 menyalin format aslinya
     persis, kalimatnya juga; rincian barang ada di lampiran halaman 2.
     Dicetak untuk ditandatangani, dipindai, lalu diunggah kembali (ADR 0018).

     Serah terimanya internal: PIHAK PERTAMA adalah Pelaksana Belanja — yang
     menandai realisasi dibelanjakan (ADR 0035) — dan PIHAK KEDUA adalah
     Petugas Persediaan yang mengonfirmasi penerimaan. Penyedia tidak ikut
     menandatangani.

     Seluruh jumlah di lampiran adalah `amount_received` — yang benar-benar
     datang — bukan `amount` yang dipesan (ADR 0017).

     CSS-nya sengaja berdiri sendiri, bukan `pdf._style`: dokumen dinas ini
     memakai Times 12 pt dan margin resmi, sedangkan `_style` melayani laporan
     (dan BAST permintaan milik Alma masih bergantung padanya). Times di sini
     adalah font inti PDF bawaan dompdf, yang metriknya sama dengan Times New
     Roman — tidak perlu menyematkan berkas font. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>BAST {{ $receipt->bast_number }}</title>
    <style>
        @page { margin: 1.5cm 2cm; }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.2;
            color: #000;
            margin: 0;
        }

        p { margin: 0; }

        .kop { text-align: center; line-height: 1.1; }
        .kop img { height: 2.5cm; }
        .kop .lembaga { font-size: 16pt; font-weight: bold; margin-top: 3pt; }
        .kop .satker { font-size: 14pt; font-weight: bold; }
        .kop .alamat { font-size: 10pt; }
        .garis-kop { border-top: 2.25pt solid #000; margin-top: 3pt; }

        .judul { margin-top: 20pt; text-align: center; font-weight: bold; text-decoration: underline; }
        .tengah { text-align: center; }
        .blok { margin-top: 11pt; }
        .isi { line-height: 1.15; }
        .rata { text-align: justify; }
        .menjorok { text-indent: 1.27cm; }
        .utuh { white-space: nowrap; }

        table.pihak { border-collapse: collapse; }
        table.pihak td { padding: 0; vertical-align: top; }
        table.pihak .no { width: 0.65cm; }
        table.pihak .label { width: 1.95cm; }
        table.pihak .titik { width: 0.3cm; }

        table.ttd { width: 100%; border-collapse: collapse; }
        table.ttd td { width: 50%; padding: 0; text-align: center; vertical-align: top; }
        table.ttd .ruang { height: 2.5cm; }
        .nama-ttd { font-weight: bold; text-decoration: underline; }

        /* --- Lampiran --- */
        .lampiran { page-break-before: always; }

        table.kepala-lampiran { width: 100%; border-collapse: collapse; }
        table.kepala-lampiran td { padding: 0; vertical-align: top; }
        table.kepala-lampiran .label { width: 1.7cm; }
        table.kepala-lampiran .titik { width: 0.3cm; }

        table.identitas { border-collapse: collapse; }
        table.identitas td { padding: 0; vertical-align: top; }
        table.identitas .label { width: 2.2cm; }
        table.identitas .titik { width: 0.3cm; }

        table.rincian { width: 100%; border-collapse: collapse; }
        table.rincian thead { display: table-header-group; }
        table.rincian th,
        table.rincian td { border: 0.75pt solid #000; padding: 3pt 4pt; vertical-align: top; }
        table.rincian th { font-weight: bold; text-align: center; vertical-align: middle; }
        table.rincian .angka { text-align: right; }
        table.rincian .tengah { text-align: center; }
        table.rincian tr.jumlah td { font-weight: bold; }
    </style>
</head>

<body>
    @php
        // Pelaksana Belanja adalah yang menandai realisasi dibelanjakan. Realisasi
        // sebelum ADR 0035 belum punya `purchased_by`; pembuatnya, yang juga
        // Pejabat Pengadaan, menjadi cadangan.
        $pihakPertama = $procurement->purchasedBy ?? $procurement->user;
        $pihakKedua = $receipt->receivedBy;

        // Kolom kosong dicetak sebagai titik-titik untuk diisi tangan, supaya
        // dokumennya tetap bisa ditandatangani hari itu juga.
        $kosong = '..............................................';

        $pihak = [
            ['nomor' => '1.', 'user' => $pihakPertama],
            ['nomor' => '2.', 'user' => $pihakKedua],
        ];

        $tanggal = $receipt->received_at;
        $kalimatTanggal = sprintf(
            '%s tanggal %s bulan %s tahun %s',
            $tanggal->translatedFormat('l'),
            ucfirst(\App\Support\Terbilang::angka($tanggal->day)),
            $tanggal->translatedFormat('F'),
            ucfirst(\App\Support\Terbilang::angka($tanggal->year)),
        );

        // Data URI, bukan path berkas: dompdf membatasi berkas lokal pada
        // chroot-nya, dan data URI tidak bergantung pada konfigurasi itu.
        $logo = 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('images/logo_bpk_kop.jpeg')));

        $diterima = $procurement->details
            ->filter(fn ($detail) => ($detail->amount_received ?? 0) > 0)
            ->values();
        $kurangKirim = $procurement->details->filter(fn ($detail) => $detail->kurangKirim() > 0);
    @endphp

    {{-- ============================ Halaman 1 ============================ --}}
    <div class="kop">
        <img src="{{ $logo }}" alt="Logo BPK">
        <div class="lembaga">BADAN PEMERIKSA KEUANGAN</div>
        <div class="satker">PERWAKILAN PROVINSI LAMPUNG</div>
        <div class="alamat">Jl. Pangeran Emir M. Noor No.12B, Telp/Fax. (0721) 472872, Bandar Lampung -35215</div>
    </div>
    <div class="garis-kop"></div>

    <p class="judul">BERITA ACARA SERAH TERIMA BARANG PERSEDIAAN</p>
    <p class="tengah">Nomor : {{ $receipt->bast_number }}</p>

    {{-- Teks isi, dari "Pada hari ini" sampai "sebagaimana mestinya.", berspasi 1,15. --}}
    <div class="isi">
    <p class="blok">
        {{-- Tanggal angkanya tidak boleh terpotong di tanda hubung. --}}
        Pada hari ini, {{ $kalimatTanggal }} <span class="utuh">({{ $tanggal->format('d-m-Y') }}),</span>
        yang bertanda tangan di bawah ini :
    </p>

    @foreach ($pihak as $baris)
        <table class="pihak blok">
            <tr>
                <td class="no">{{ $baris['nomor'] }}</td>
                <td class="label">Nama</td>
                <td class="titik">:</td>
                <td>{{ $baris['user']?->fullname ?? $kosong }}</td>
            </tr>
            <tr>
                <td></td>
                <td class="label">NIP</td>
                <td class="titik">:</td>
                <td>{{ $baris['user']?->nip ?? $kosong }}</td>
            </tr>
            <tr>
                <td></td>
                <td class="label">Jabatan</td>
                <td class="titik">:</td>
                <td>{{ $baris['user']?->position ?? $kosong }}</td>
            </tr>
        </table>

        {{-- Kalimatnya berbeda antara kedua pihak, persis seperti format aslinya. --}}
        @if ($loop->first)
            <p>Yang selanjutnya disebut sebagai <strong>PIHAK PERTAMA</strong>.</p>
        @else
            <p>yang selanjutnya disebut <strong>PIHAK KEDUA.</strong></p>
        @endif
    @endforeach

    <p class="blok rata">
        PIHAK KEDUA telah menerima barang persediaan yang diserahkan oleh PIHAK PERTAMA,
        dengan rincian sebagaimana dokumen tagihan yang terlampir.
    </p>

    <p class="blok rata menjorok">
        Demikianlah Berita Acara Serah Terima Pekerjaan ini dibuat untuk dipergunakan
        sebagaimana mestinya.
    </p>
    </div>

    <table class="ttd blok">
        <tr>
            <td>Pelaksana Belanja</td>
            <td>Petugas Persediaan</td>
        </tr>
        <tr>
            <td class="ruang"></td>
            <td class="ruang"></td>
        </tr>
        <tr>
            <td><span class="nama-ttd">{{ $pihakPertama?->fullname ?? $kosong }}</span></td>
            <td><span class="nama-ttd">{{ $pihakKedua?->fullname ?? $kosong }}</span></td>
        </tr>
        <tr>
            <td><strong>NIP {{ $pihakPertama?->nip ?? $kosong }}</strong></td>
            <td><strong>NIP {{ $pihakKedua?->nip ?? $kosong }}</strong></td>
        </tr>
    </table>

    {{-- ============================ Lampiran ============================= --}}
    <div class="lampiran">
        <table class="kepala-lampiran">
            <tr>
                <td style="width: 41%"></td>
                <td>
                    <table class="kepala-lampiran">
                        <tr>
                            <td colspan="3">Lampiran Berita Acara Serah Terima Barang Persediaan</td>
                        </tr>
                        <tr>
                            <td class="label">Nomor</td>
                            <td class="titik">:</td>
                            <td>{{ $receipt->bast_number }}</td>
                        </tr>
                        <tr>
                            <td class="label">Tanggal</td>
                            <td class="titik">:</td>
                            <td>{{ $tanggal->translatedFormat('j F Y') }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <p class="judul">RINCIAN BARANG PERSEDIAAN YANG DITERIMA</p>

        <table class="identitas blok">
            <tr>
                <td class="label">Dasar</td>
                <td class="titik">:</td>
                <td>{{ $procurement->judul() }} tanggal {{ $procurement->procurement_date->translatedFormat('j F Y') }}</td>
            </tr>
            <tr>
                <td class="label">Penyedia</td>
                <td class="titik">:</td>
                <td>{{ $procurement->supplier?->supplier_name ?? '-' }}</td>
            </tr>
        </table>

        <table class="rincian blok">
            <thead>
                {{-- Kode barang BPK 16 digit; kolomnya dilebarkan supaya tidak
                     menyempitkan Nama Barang. --}}
                <tr>
                    <th style="width: 6%">No</th>
                    <th style="width: 21%">Kode Barang</th>
                    <th>Nama Barang</th>
                    <th style="width: 9%">Jumlah</th>
                    <th style="width: 10%">Satuan</th>
                    <th style="width: 13%">Harga Satuan (Rp)</th>
                    <th style="width: 14%">Jumlah Harga (Rp)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($diterima as $i => $detail)
                    <tr>
                        <td class="tengah">{{ $i + 1 }}</td>
                        <td>{{ $detail->item?->item_code ?? '-' }}</td>
                        <td>{{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}</td>
                        <td class="angka">{{ number_format($detail->amount_received, 0, ',', '.') }}</td>
                        <td>{{ $detail->item?->unit->label() ?? '-' }}</td>
                        <td class="angka">{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                        <td class="angka">{{ number_format($detail->amount_received * $detail->unit_price, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="tengah">Tidak ada barang yang diterima.</td>
                    </tr>
                @endforelse
                <tr class="jumlah">
                    <td colspan="6" class="angka">Jumlah</td>
                    <td class="angka">{{ number_format($procurement->nilaiDiterima(), 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        @if ($kurangKirim->isNotEmpty())
            <p class="blok rata">
                Catatan: jumlah yang diterima kurang dari pesanan untuk {{ $kurangKirim->count() }} jenis
                barang, yaitu
                @foreach ($kurangKirim as $detail)
                    {{ $detail->item?->item_name }} kurang
                    {{ number_format($detail->kurangKirim(), 0, ',', '.') }} {{ $detail->item?->unit->label() }}{{ $loop->last ? '.' : ',' }}
                @endforeach
                Kekurangan tersebut belum termasuk dalam serah terima ini.
            </p>
        @endif

        @if ($receipt->notes)
            <p class="blok rata">Keterangan: {{ $receipt->notes }}</p>
        @endif
    </div>
</body>

</html>
