<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\MutationType;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use App\Support\StockLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RequestWorkflowService
{
    public function __construct(
        private readonly RequestDocumentGenerator $documentGenerator,
    ) {
    }

    /**
     * @param  string|null  $ndSequence  nomor urut Nota Dinas, wajib saat
     *                                    menyetujui permintaan yang sudah disetujui Kepala Unit
     */
    public function respond(
        ModelsRequest $request,
        User $actor,
        string $action,
        ?string $ndSequence = null,
    ): ModelsRequest
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Aksi tidak valid.');
        }

        $request->loadMissing('user');

        if ($request->status !== 'submitted' && $request->hasItemProposals()) {
            throw new AuthorizationException('Permintaan belum dapat diproses karena masih ada usulan barang yang harus diselesaikan atau dihapus dari permintaan.');
        }

        $this->assertCanRespond($request, $actor, $action);

        // Permintaan yang keluar dari tahap Disetujui Kepala Unit, baik normal
        // maupun darurat, harus sudah mendapat nomor Nota Dinas resmi.
        $assignsNumber = $action === 'approve' && $request->status === 'approved_by_head';

        DB::transaction(function () use ($request, $actor, $action, $assignsNumber, $ndSequence): void {
            if ($action === 'reject') {
                $request->update([
                    'status' => 'rejected',
                    'rejected_from_status' => $request->status,
                ]);

                return;
            }

            if ($assignsNumber) {
                $this->assignNotaDinasNumber($request, $ndSequence);
            }

            $nextStatus = $this->nextStatus($request, $actor);

            if ($nextStatus === 'processed_by_staff'
                || ($nextStatus === 'completed' && $request->type === 'emergency')) {
                $this->fillZeroApprovedQuantities($request);
            }

            $request->update(['status' => $nextStatus]);

            if ($actor->hasRole(UserRole::KepalaUnit)
                || ($actor->hasRole(UserRole::KasubbagUmumTi)
                    && $actor->unit === UnitKerja::SubbagianUmumTi
                    && $nextStatus === 'approved_by_head')
                || ($actor->isAdmin() && $nextStatus === 'approved_by_head')) {
                $this->documentGenerator->approveNotaDinas($request, $actor);
            } elseif ($nextStatus === 'completed') {
                $this->recordIssuance($request, $actor);
                $this->documentGenerator->generateBast($request, $actor);
            }

            if ($assignsNumber) {
                $this->documentGenerator->renumberNotaDinas($request);
            }
        });

        $request = $request->fresh('user');

        AuditLogger::record(
            user: $actor,
            activity: sprintf(
                '%s permintaan barang %s (status menjadi %s)',
                $action === 'approve' ? 'Menyetujui' : 'Menolak',
                $request->request_number,
                $request->statusLabel(),
            ),
            module: AuditModule::Permintaan,
            event: $action === 'approve' ? AuditEvent::Setuju : AuditEvent::Tolak,
            subject: $request,
        );
        NotificationScanner::periksaPermintaan($request);

        return $request;
    }

    /**
     * Admin mengembalikan permintaan satu langkah ke status sebelumnya untuk
     * membatalkan salah klik atau salah input, sekaligus membatalkan efek
     * samping langkah tersebut. Permintaan yang sudah selesai tidak bisa
     * dikembalikan karena stok sudah keluar dan BAST sudah terbit.
     */
    public function revert(ModelsRequest $request, User $actor, string $reason): ModelsRequest
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException('Hanya Admin yang dapat mengembalikan status permintaan.');
        }

        $fromStatus = $request->status;
        $toStatus = $request->previousStatus();

        if ($toStatus === null) {
            throw new AuthorizationException('Status permintaan ini tidak dapat dikembalikan.');
        }

        DB::transaction(function () use ($request, $fromStatus, $toStatus): void {
            $request->update([
                'status' => $toStatus,
                'rejected_from_status' => null,
            ]);

            // Penolakan tidak punya efek samping, jadi pemulihannya cukup
            // mengembalikan status.
            if ($fromStatus === 'approved_by_head') {
                // Nota Dinas dibuat ulang tanpa persetujuan; tautan QR lama mati.
                $this->documentGenerator->generateNotaDinas($request);
            } elseif ($fromStatus === 'processed_by_staff') {
                // Kembali ke kondisi sebelum Pengelola Gudang memproses:
                // jumlah disetujui kosong dan barang tambahan dari staf dibuang.
                $request->item()->where('quantity_requested', 0)->delete();
                $request->item()->update(['quantity_approved' => 0]);
            }
        });

        $request = $request->fresh('user');

        AuditLogger::record(
            user: $actor,
            activity: sprintf(
                'Mengembalikan status permintaan barang %s dari %s ke %s. Alasan: %s',
                $request->request_number,
                ModelsRequest::labelStatus($fromStatus),
                ModelsRequest::labelStatus($toStatus),
                $reason,
            ),
            module: AuditModule::Permintaan,
            event: AuditEvent::UbahStatus,
            subject: $request,
        );
        NotificationScanner::periksaPermintaan($request);

        return $request;
    }

    /**
     * Menetapkan nomor Nota Dinas dari nomor urut yang diisi Pengelola Gudang.
     * Nomor BAST ikut ditetapkan dengan nomor urut yang sama, dan keduanya
     * dibekukan di sini supaya tidak berubah walau unit pemohon diganti.
     *
     * @throws ValidationException
     */
    public function assignNotaDinasNumber(ModelsRequest $request, ?string $sequence): void
    {
        $sequence = trim((string) $sequence);

        if (preg_match(ModelsRequest::ND_SEQUENCE_PATTERN, $sequence) !== 1) {
            throw ValidationException::withMessages([
                'nd_sequence' => 'Nomor urut Nota Dinas wajib diisi berupa angka, paling banyak 6 digit.',
            ]);
        }

        $request->loadMissing('user');
        $ndNumber = $request->documentNumber('ND', $sequence);

        if (ModelsRequest::query()->where('nd_number', $ndNumber)->whereKeyNot($request->getKey())->exists()) {
            throw ValidationException::withMessages([
                'nd_sequence' => "Nomor Nota Dinas {$ndNumber} sudah dipakai permintaan lain.",
            ]);
        }

        $request->update([
            'nd_number' => $ndNumber,
            'bast_number' => $request->documentNumber('BAST', $sequence),
        ]);
    }

    private function assertCanRespond(ModelsRequest $request, User $actor, string $action): void
    {
        $canRespond = match (true) {
            $actor->isAdmin() => in_array($request->status, [
                'submitted',
                'approved_by_head',
                'processed_by_staff',
                'in_issuance',
            ], true),
            $actor->hasRole(UserRole::KepalaUnit) =>
                $request->status === 'submitted'
                    && $actor->unit === $request->user?->unit,
            $actor->hasRole(UserRole::PengelolaGudang) =>
                in_array($request->status, ['approved_by_head', 'in_issuance'], true),
            $actor->hasRole(UserRole::KasubbagUmumTi) =>
                ($request->status === 'submitted' && $actor->unit === UnitKerja::SubbagianUmumTi)
                || in_array($request->status, ['processed_by_staff', 'in_issuance'], true)
                || ($request->type === 'emergency' && $request->status === 'approved_by_head'),
            default => false,
        };

        $emergencyAlreadyApproved = $request->type === 'emergency'
            && $request->status === 'approved_by_head';

        if (! $canRespond || ($emergencyAlreadyApproved && $action === 'reject')) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk memproses permintaan ini.');
        }
    }

    private function nextStatus(ModelsRequest $request, User $actor): string
    {
        if ($request->type === 'emergency' && $request->status === 'approved_by_head') {
            return 'completed';
        }

        if ($actor->isAdmin()) {
            return match ($request->status) {
                'submitted' => 'approved_by_head',
                'approved_by_head' => 'processed_by_staff',
                'processed_by_staff' => 'in_issuance',
                default => 'completed',
            };
        }

        if ($actor->hasRole(UserRole::KepalaUnit)) {

            return 'approved_by_head';
        }

        if ($actor->hasRole(UserRole::KasubbagUmumTi)
            && $actor->unit === UnitKerja::SubbagianUmumTi
            && $request->status === 'submitted') {
            return 'approved_by_head';
        }

        if ($actor->hasRole(UserRole::PengelolaGudang)) {
            return $request->status === 'in_issuance'
                ? 'completed'
                : 'processed_by_staff';
        }

        return $request->status === 'processed_by_staff'
            ? 'in_issuance'
            : 'completed';
    }

    private function recordIssuance(ModelsRequest $request, User $actor): void
    {
        $request->loadMissing(['user', 'item.item']);

        foreach ($request->item as $detail) {
            if ($detail->item === null) {
                continue;
            }

            $amount = (int) $detail->quantity_approved;

            if ($amount <= 0) {
                continue;
            }

            $referenceNumber = $request->nd_number ?: $request->request_number;

            StockLedger::record(
                item: $detail->item,
                type: MutationType::Keluar,
                amount: $amount,
                recordedBy: $actor,
                reference: $request,
                referenceNumber: $referenceNumber,
                notes: "Penyerahan ke ".($request->user?->unit?->label() ?? 'unit pemohon')." sesuai {$referenceNumber}",
            );
        }
    }

    private function fillZeroApprovedQuantities(ModelsRequest $request): void
    {
        $request->loadMissing('item');

        foreach ($request->item as $detail) {
            if ((int) $detail->quantity_approved === 0) {
                $detail->loadMissing('item');
                if ($detail->item !== null && (int) $detail->quantity_requested > (int) $detail->item->stock_current) {
                    throw new InsufficientStockException(
                        $detail->item,
                        (int) $detail->quantity_requested,
                        (int) $detail->item->stock_current,
                    );
                }

                $detail->update([
                    'quantity_approved' => $detail->quantity_requested,
                ]);
            }
        }
    }

}
