<?php

namespace App\Support;

use App\Models\Request as ModelsRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

class RequestDocumentGenerator
{
    public function generateNotaDinas(ModelsRequest $request): RequestDocument
    {
        $request->loadMissing(['user', 'item.itemProposal']);

        $filePath = 'nota-dinas/'.$request->request_id.'.pdf';
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'nota_dinas',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => $request->request_number,
            'file_path' => $filePath,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'verification_token_hash' => null,
        ]);
        $document->save();

        $this->renderNotaDinas($request, $document, null, null, null);

        return $document;
    }

    public function approveNotaDinas(ModelsRequest $request, User $approver): RequestDocument
    {
        $request->loadMissing(['user', 'item.itemProposal']);
        $approvedAt = now();
        $verificationToken = Str::random(64);
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'nota_dinas',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => $request->request_number,
            'file_path' => 'nota-dinas/'.$request->request_id.'.pdf',
            'approved_by_user_id' => $approver->user_id,
            'approved_at' => $approvedAt,
            'verification_token_hash' => hash('sha256', $verificationToken),
        ]);
        $document->save();

        $verificationUrl = route('request.nota-dinas.verify', [
            'requestDocument' => $document,
            'token' => $verificationToken,
        ]);
        $qrCodeDataUri = (new Builder)->build(
            data: $verificationUrl,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
        )->getDataUri();

        $this->renderNotaDinas($request, $document, $approver, $approvedAt, $qrCodeDataUri);

        return $document;
    }

    private function renderNotaDinas(
        ModelsRequest $request,
        RequestDocument $document,
        ?User $approver,
        ?\Illuminate\Support\Carbon $approvedAt,
        ?string $qrCodeDataUri,
    ): void {

        $pdf = Pdf::loadView('pdf.notadinas-permintaan', [
            'request' => $request,
            'approvedBy' => $approver,
            'approvedAt' => $approvedAt,
            'qrCodeDataUri' => $qrCodeDataUri,
        ])->setPaper('a4', 'portrait');

        Storage::disk('local')->put($document->file_path, $pdf->output());
    }

    public function generateBast(ModelsRequest $request, User $issuer): RequestDocument
    {
        $request->loadMissing(['user', 'item']);
        $issuedAt = now();
        $filePath = 'bast-permintaan/'.$request->request_id.'.docx';
        $document = RequestDocument::query()->firstOrNew([
            'request_id' => $request->request_id,
            'document_type' => 'bast',
        ]);
        $document->fill([
            'user_id' => $request->user_id,
            'document_number' => 'BAST-'.$request->request_number,
            'file_path' => $filePath,
            'approved_by_user_id' => $issuer->user_id,
            'approved_at' => $issuedAt,
        ]);
        $document->save();

        $templatePath = resource_path('document/template-bast-persediaan.docx');
        $temporaryBasePath = tempnam(sys_get_temp_dir(), 'bast-');
        if ($temporaryBasePath === false) {
            throw new RuntimeException('Lokasi sementara untuk membuat BAST tidak dapat disiapkan.');
        }
        unlink($temporaryBasePath);

        $docxPath = $temporaryBasePath.'.docx';

        try {
            $template = new TemplateProcessor($templatePath);
            $requiredVariables = [
                'document_number',
                'issued_day',
                'issued_date',
                'recipient_name',
                'recipient_nip',
                'recipient_position',
                'recipient_unit',
                'issuer_name',
                'issuer_nip',
                'issuer_position',
                'issuer_unit',
                'request_number',
                'item_list',
            ];
            $missingVariables = array_diff($requiredVariables, $template->getVariables());
            if ($missingVariables !== []) {
                throw new RuntimeException(
                    'Template BAST belum lengkap. Placeholder yang belum tersedia: ${'
                    .implode('}, ${', $missingVariables).'}',
                );
            }

            $template->setValues([
                'document_number' => $document->document_number,
                'issued_day' => $issuedAt->translatedFormat('l'),
                'issued_date' => $issuedAt->translatedFormat('j F Y'),
                'recipient_name' => $request->user?->fullname ?? '',
                'recipient_nip' => $request->user?->nip ?? '',
                'recipient_position' => $request->user?->position ?? 'Penerima Barang',
                'recipient_unit' => $request->user?->unit ?? '',
                'issuer_name' => $issuer->fullname ?? '',
                'issuer_nip' => $issuer->nip ?? '',
                'issuer_position' => $issuer->position ?? 'Pengelola Gudang Persediaan',
                'issuer_unit' => $issuer->unit ?? 'BPK Perwakilan Provinsi Lampung',
                'request_number' => $request->request_number,
            ]);

         $details = $request->item->values();
        if ($details->isEmpty()) {
            throw new RuntimeException('BAST tidak dapat dibuat karena tidak memiliki rincian barang.');
        }

        $itemList = $details
            ->map(fn ($detail) => trim(sprintf(
                '%s %s %s',
                number_format($detail->quantity_approved ?: $detail->quantity_requested, 0, ',', '.'),
                $detail->item?->unit?->label() ?? '',
                $detail->item?->item_name ?? 'Barang tidak dikenal',
            )))
            ->implode(', ');

        $template->setValue('item_list', $itemList);

            $template->saveAs($docxPath);
            $docx = file_get_contents($docxPath);
            if ($docx === false || ! Storage::disk('local')->put($filePath, $docx)) {
                throw new RuntimeException('DOCX BAST tidak dapat disimpan ke penyimpanan aplikasi.');
            }
        } finally {
            if (is_file($docxPath)) {
                unlink($docxPath);
            }
        }

        return $document;
    }
}
