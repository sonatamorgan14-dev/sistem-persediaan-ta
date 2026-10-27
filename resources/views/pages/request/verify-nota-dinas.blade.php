<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body { background: #f3f4f6; color: #1f2937; font-family: Arial, sans-serif; margin: 0; }
        main { background: #fff; border: 1px solid #d1d5db; margin: 48px auto; max-width: 640px; padding: 32px; }
        h1 { color: #166534; font-size: 24px; margin: 0 0 8px; }
        p { line-height: 1.5; }
        dl { display: grid; gap: 12px; grid-template-columns: 180px 1fr; margin-top: 24px; }
        dt { color: #6b7280; }
        dd { font-weight: 600; margin: 0; }
        .valid { color: #166534; }
        @media (max-width: 520px) { main { margin: 16px; padding: 24px; } dl { grid-template-columns: 1fr; gap: 4px; } dd { margin-bottom: 10px; } }
    </style>
</head>
<body>
    <main>
        <h1 class="valid">Dokumen Terverifikasi</h1>
        <p>Nota dinas ini tercatat sebagai dokumen yang disetujui secara digital oleh sistem.</p>
        <dl>
            <dt>Nomor dokumen</dt>
            <dd>{{ $document->document_number ?? 'Belum diberi nomor' }}</dd>
            <dt>Nomor permintaan</dt>
            <dd>{{ $document->request?->request_number ?? '-' }}</dd>
            <dt>Pemohon</dt>
            <dd>{{ $document->request?->user?->fullname ?? '-' }}</dd>
            <dt>Unit</dt>
            <dd>{{ $document->request?->user?->unit?->label() ?? '-' }}</dd>
            <dt>Disetujui oleh</dt>
            <dd>{{ $document->approvedBy?->fullname ?? '-' }}</dd>
            <dt>Waktu persetujuan</dt>
            <dd>{{ $document->approved_at?->translatedFormat('j F Y H:i:s') ?? '-' }}</dd>
        </dl>
    </main>
</body>
</html>
