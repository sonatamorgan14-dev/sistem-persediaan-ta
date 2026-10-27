<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>BAST {{ $document->document_number }}</title>
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

        /* --- Kop Surat --- */
        table.kop-table { width: 100%; border-collapse: collapse; margin-bottom: 3pt; }
        table.kop-table td { vertical-align: middle; padding: 0; }
        .kop-logo { width: 15%; text-align: left; }
        .kop-logo img { width: 2.5cm; }
        .kop-teks { width: 85%; text-align: center; line-height: 1.1; }
        .kop-teks .lembaga { font-size: 14pt; font-weight: bold; }
        .kop-teks .satker { font-size: 12pt; font-weight: bold; }
        .kop-teks .alamat { font-size: 10pt; margin-top: 2pt; }
        .garis-kop { border-top: 2.25pt solid #000; border-bottom: 0.75pt solid #000; height: 1pt; margin-top: 2pt; }

        /* --- Isi Dokumen --- */
        .judul { margin-top: 15pt; text-align: center; font-weight: bold; }
        .tengah { text-align: center; }
        .blok { margin-top: 11pt; }
        .rata { text-align: justify; }

        table.pihak { border-collapse: collapse; width: 100%; }
        table.pihak td { padding: 0; vertical-align: top; }
        table.pihak .no { width: 0.65cm; text-align: right; padding-right: 0.2cm; }
        table.pihak .label { width: 2.2cm; }
        table.pihak .titik { width: 0.3cm; }

        /* --- Tabel Data --- */
        table.data { width: 100%; border-collapse: collapse; margin-top: 11pt; }
        table.data th, table.data td { border: 0.75pt solid #000; padding: 3pt 4pt; vertical-align: top; }
        table.data th { font-weight: bold; text-align: center; vertical-align: middle; }
        table.data .center { text-align: center; }
        table.data .num { text-align: right; }

        /* --- Tanda Tangan --- */
        table.ttd { width: 100%; border-collapse: collapse; margin-top: 15pt; }
        table.ttd td { width: 50%; padding: 0; text-align: center; vertical-align: top; }
        table.ttd .ruang { height: 2.5cm; }

        .ttd-mengetahui { margin-top: 15pt; text-align: center; }
        .ttd-mengetahui .ruang { height: 2.5cm; }

        /* --- Footer --- */
        .footer { margin-top: 20pt; font-size: 9pt; font-style: italic; }
        .footer .kanan { float: right; }
    </style>
</head>

<body>
    @php
        // Data URI untuk logo BPK agar kompatibel dengan dompdf
        $logo = 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('images/logo_bpk_kop.jpeg')));
        $kosong = '..............................................';
    @endphp

    {{-- ============================ KOP SURAT ============================ --}}
    <table class="kop-table">
        <tr>
            <td class="kop-logo">
                <img src="{{ $logo }}" alt="Logo BPK">
            </td>
            <td class="kop-teks">
                <div class="lembaga">BADAN PEMERIKSA KEUANGAN</div>
                <div class="satker">PERWAKILAN PROVINSI LAMPUNG</div>
                <div class="alamat">
                    Jalan Pangeran Emir M. Noor, No.11B, Kelurahan Sumur Putri, Kecamatan Teluk Betung Selatan, Bandar Lampung, Lampung, 35215<br>
                    Telepon (0721) 474828, <em>WhatsApp/Hotline</em> 081369694488
                </div>
            </td>
        </tr>
    </table>
    <div class="garis-kop"></div>

    {{-- ============================ JUDUL ============================ --}}
    <p class="judul">BERITA ACARA SERAH TERIMA BARANG</p>
    <p class="tengah">No. {{ $document->document_number }}</p>

    {{-- ============================ ISI DOKUMEN ============================ --}}
    <p class="blok rata">
        Pada hari ini, {{ $issuedAt->translatedFormat('l') }} tanggal {{ $issuedAt->translatedFormat('j F Y') }}, bertempat di Kantor Badan Pemeriksa Keuangan Perwakilan Provinsi Lampung, yang bertanda tangan di bawah ini:
    </p>

    <table class="pihak blok">
        <tr>
            <td class="no">I.</td>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td>{{ $request->user?->fullname ?? $kosong }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">NIP</td>
            <td class="titik">:</td>
            <td>{{ $request->user?->nip ?? $kosong }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Jabatan</td>
            <td class="titik">:</td>
            <td>{{ $request->user?->position ?? 'Penerima Barang' }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Unit Kerja</td>
            <td class="titik">:</td>
            <td>{{ $request->user?->unit ?? $kosong }}</td>
        </tr>
    </table>
    <p class="blok">
        Dalam hal ini bertindak untuk dan atas nama Unit Kerja yang meminta persediaan, selanjutnya disebut <strong>PIHAK KESATU</strong>.
    </p>

    <table class="pihak blok">
        <tr>
            <td class="no">II.</td>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td>{{ $issuer->fullname ?? $kosong }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">NIP</td>
            <td class="titik">:</td>
            <td>{{ $issuer->nip ?? $kosong }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Jabatan</td>
            <td class="titik">:</td>
            <td>Pengelola Gudang Persediaan</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Instansi</td>
            <td class="titik">:</td>
            <td>BPK Perwakilan Provinsi Lampung</td>
        </tr>
    </table>
    <p class="blok">
        Dalam hal ini bertindak untuk dan atas nama Petugas Persediaan, selanjutnya disebut <strong>PIHAK KEDUA</strong>.
    </p>

    <p class="blok rata">
        PIHAK KEDUA menyerahkan barang persediaan kepada PIHAK KESATU, dan PIHAK KESATU menerima barang tersebut sesuai permintaan barang No. {{ $request->request_number }}, dengan rincian sebagai berikut:
    </p>

    {{-- ============================ TABEL RINCIAN ============================ --}}
    <table class="data">
        <thead>
            <tr>
                <th style="width: 8%">No</th>
                <th style="width: 20%">Kode</th>
                <th style="width: 47%">Nama Barang</th>
                <th style="width: 12%">Satuan</th>
                <th style="width: 13%">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($request->item as $index => $detail)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $detail->item?->item_code ?? '-' }}</td>
                    <td>{{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}</td>
                    <td class="center">{{ $detail->item?->unit?->label() ?? '-' }}</td>
                    <td class="num">{{ number_format($detail->quantity_approved ?: $detail->quantity_requested, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="blok rata">
        Demikian berita acara ini dibuat, apabila dikemudian hari terdapat kekeliruan akan dilakukan perbaikan sebagaimana mestinya.
    </p>

    {{-- ============================ TANDA TANGAN ============================ --}}
    <table class="ttd">
        <tr>
            <td>PIHAK KESATU</td>
            <td>PIHAK KEDUA</td>
        </tr>
        <tr>
            <td class="ruang"></td>
            <td class="ruang"></td>
        </tr>
        <tr>
            <td>{{ $request->user?->fullname ?? $kosong }}</td>
            <td>{{ $issuer->fullname ?? $kosong }}</td>
        </tr>
        <tr>
            <td>NIP {{ $request->user?->nip ?? $kosong }}</td>
            <td>NIP {{ $issuer->nip ?? $kosong }}</td>
        </tr>
    </table>

    <div class="ttd-mengetahui">
        <p>Mengetahui,</p>
        <p>Kepala Subbagian Umum & TI</p>
        <div class="ruang"></div>
        <p>Rizky Azora</p>
        <p>NIP 198802192011051001</p>
    </div>

    {{-- ============================ FOOTER ============================ --}}
    <div class="footer">
        Sistem Informasi Persediaan &mdash; BPK Perwakilan Provinsi Lampung
        <span class="kanan">Dicetak {{ now()->translatedFormat('d F Y H:i') }}</span>
    </div>
</body>

</html>