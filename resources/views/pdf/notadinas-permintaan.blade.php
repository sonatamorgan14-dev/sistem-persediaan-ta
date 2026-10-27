<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Nota Dinas</title>
    <style>
        /* Margin halaman disamakan dengan DOCX (A4):
           atas 68pt, kanan 65pt, bawah 14pt, kiri 67pt */
        @page {
            margin: 68pt 65pt 14pt 67pt;
        }

        body {
            color: #000;
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 13.8pt;
            margin: 0;
            padding: 0;
        }

        /* ===== Judul ===== */
        .header-title {
            text-align: center;
            font-weight: bold;
            padding-top: 4pt;
        }

        .header-no {
            text-align: center;
            font-weight: bold;
            margin-top: 2pt;
            margin-bottom: 15.5pt;
        }

        /* ===== Blok meta (Kepada Yth., Dari, Perihal, Tempat/Tanggal) ===== */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .meta-table td {
            vertical-align: top;
            padding: 8.9pt 0 0 0;
            line-height: 13.8pt;
        }

        .meta-label {
            width: 90.7pt;
            padding-left: 10.4pt !important;
        }

        .meta-colon {
            width: 6.3pt;
            text-align: left;
        }

        .separator {
            border: 0;
            border-top: 1.06pt solid #000;
            height: 0;
            margin: 5pt 0 11pt 5pt;
            width: 453pt;
        }

        /* ===== Paragraf isi ===== */
        .content {
            text-align: left;
            margin: 0 0 10.7pt 5pt;
            text-indent: 36pt;
        }

        .content-closing {
            text-align: left;
            margin: 11pt 0 20pt 5pt;
            text-indent: 36pt;
            line-height: 13pt;
        }

        /* ===== Tabel barang ===== */
        .item-table {
            width: 454pt;
            margin: 0 0 0 5pt;
            border-collapse: collapse;
        }

        .item-table th,
        .item-table td {
            border: 1pt solid #000;
            padding: 0;
            vertical-align: top;
            line-height: 13pt;
        }

        .item-table th {
            font-weight: bold;
            text-align: center;
            height: 20.5pt;
        }

        .item-table td {
            height: 13.5pt;
        }

        .col-no {
            width: 39.35pt;
        }

        .col-name {
            width: 205pt;
        }

        .col-qty {
            width: 209.65pt;
        }

        .text-center {
            text-align: center;
        }

        .text-left-pad {
            text-align: left;
            padding-left: 5.5pt !important;
        }

        /* ===== Tanda tangan ===== */
        .signature-container {
            float: right;
            width: 181pt;
            text-align: center;
            font-weight: bold;
            line-height: 13pt;
            page-break-inside: avoid;
        }

        .signature-container .sig-head {
            line-height: 13.8pt;
        }

        .verification-qr {
            display: block;
            height: 62pt;
            width: 62pt;
            margin: 6pt auto 6.2pt;
        }

        .signature-name {
            font-weight: bold;
        }
    </style>
</head>
<body>

    <div class="header-title">NOTA DINAS</div>
    <div class="header-no">No. 51/B/ND/DJPKN-V.BLP.2/KEU.02.02/06/2026</div>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Kepada Yth.</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Kepala Subbagian Umum dan TI</td>
        </tr>
        <tr>
            <td class="meta-label">Dari</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">{{ $request->user?->unit ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Perihal</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Permintaan Alat Tulis Kantor (ATK)</td>
        </tr>
        <tr>
            <td class="meta-label">Tempat/Tanggal</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Bandar Lampung, {{ $request->date?->translatedFormat('j F Y') ?? '-' }}</td>
        </tr>
    </table>

    <hr class="separator">

    <div class="content">
        Sehubungan dengan kebutuhan Alat Tulis Kantor (ATK) terkait pelaksanaan kegiatan perkantoran pada {{ $request->user?->unit ?? '-' }}, bersama ini kami sampaikan daftar permintaan ATK sebagai berikut:
    </div>

    <table class="item-table">
        <thead>
            <tr>
                <th class="col-no">No.</th>
                <th class="col-name">Nama Barang</th>
                <th class="col-qty">Jumlah Barang</th>
            </tr>
        </thead>
        <tbody>
            @foreach($request->item as $index => $detail)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td class="text-left-pad">{{ $detail->item?->item_name ?? $detail->itemProposal?->item_name ?? '-' }}</td>
                <td class="text-center">{{ number_format($detail->quantity_requested, 0, ',', '.') }} {{ $detail->item?->unit?->label() ?? $detail->itemProposal?->unit?->label() ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="content-closing">
        Atas perhatian dan kerjasama yang baik, kami ucapkan terima kasih.
    </div>

    <div class="signature-container">
        @if ($approvedBy)
            <div class="sig-head">Kepala {{ $approvedBy->unit ?? '-' }},</div>
            @if ($qrCodeDataUri)
                <img style="position: relative; left: 50px;" class="verification-qr" src="{{ $qrCodeDataUri }}" alt="QR verifikasi persetujuan">
            @else
                <div class="verification-qr" aria-hidden="true"></div>
            @endif
            <div class="signature-name">{{ $approvedBy->fullname ?? '-' }}</div>
            <div>NIP: {{ $approvedBy->nip ?? '-' }}</div>
        @else
            <div class="sig-head">Kepala {{ $request->user?->unit ?? '-' }},</div>
            <div class="verification-qr" aria-hidden="true"></div>
            <div class="signature-name">{{ $kepalaUnit?->fullname ?? '-' }}</div>
            <div>NIP: {{ $kepalaUnit?->nip ?? '-' }}</div>
        @endif
    </div>

</body>
</html>