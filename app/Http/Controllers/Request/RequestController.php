<?php

namespace App\Http\Controllers\Request;

use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Enums\SatuanBarang;
use App\Enums\ItemProposalStatus;
use App\Http\Requests\Request\StorePermintaanBarangRequest;
use App\Http\Requests\Request\StorePermintaanUmumRequest;
use App\Http\Requests\Request\UpdatePermintaanBarangRequest;
use App\Http\Requests\Request\UpdatePermintaanUmumRequest;
use App\Http\Requests\Request\MergePermintaanUmumRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\ItemProposal;
use App\Models\Request as ModelsRequest;
use App\Models\RequestDetail;
use App\Models\RequestDocument;
use App\Models\User;
use App\Services\RequestProcurementService;
use App\Services\RequestWorkflowService;
use App\Support\AuditLogger;
use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Exceptions\InsufficientStockException;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RequestController extends Controller
{
    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');
        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');

        $isKepalaUnit = $request->user()->hasRole(UserRole::KepalaUnit);
        $isPengelolaGudang = $request->user()->hasRole(UserRole::PengelolaGudang);
        $isAdmin = $request->user()->isAdmin();
        $isKasubbag = $request->user()->hasRole(UserRole::KasubbagUmumTi);
        $isUser = $request->user()->hasRole(UserRole::Pengguna);

        $requests = ModelsRequest::with(['user', 'item.itemProposal'])
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('request_number', 'like', "%{$keyword}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('fullname', 'like', "%{$keyword}%"));
                });
            })
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->where('type', '!=', 'umum')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($dateFrom !== '', fn ($query) => $query->whereDate('date', '>=', $dateFrom))
            ->when($dateTo !== '', fn ($query) => $query->whereDate('date', '<=', $dateTo))
            ->when($isKepalaUnit, function ($query) use ($request) {
                $query->whereHas('user', fn ($userQuery) => $userQuery->where('unit', $request->user()->unit));
            })
            ->when($isUser, fn ($query) => $query->where('user_id', $request->user()->user_id))
            ->latest('date')
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        $summary = [
            'pending' => ModelsRequest::where('type', '!=', 'umum')->whereIn('status', [
                'submitted', 
                'approved_by_head', 
                'processed_by_staff',
                'in_issuance'
            ])->count(),
            'bulan_ini' => ModelsRequest::where('type', '!=', 'umum')->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'selesai_bulan_ini' => ModelsRequest::where('type', '!=', 'umum')->whereIn('status', ['completed'])
                ->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];

        return view('pages.request.index', [
            'title' => 'Request',
            'requests' => $requests,
            'summary' => $summary,
            'keyword' => $keyword,
            'type' => $type,
            'status' => $status,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'isKepalaUnit' => $isKepalaUnit,
            'isPengelolaGudang' => $isPengelolaGudang,
            'isAdmin' => $isAdmin,
            'isKasubbag' => $isKasubbag,
            'isUser' => $isUser,
        ]);
    }
    public function create(Request $request)
    {
        $items = Item::where('is_active', true)->get();
        $canCreateEmergency = $request->user()->hasRole(UserRole::Admin, UserRole::KasubbagUmumTi, UserRole::PengelolaGudang);
        $users = $canCreateEmergency
            ? User::query()->where('is_active', true)->orderBy('fullname')->get(['user_id', 'fullname', 'username', 'unit'])
            : collect();

        return view('pages.request.create', [
            'title' => 'Request',
            'items' => $items,
            'satuan' => SatuanBarang::options(),
            'users' => $users,
            'canCreateEmergency' => $canCreateEmergency,
        ]);
    }

    public function createUmum(Request $request)
    {
        $this->assertCanManageUmum($request->user());

        return view('pages.request.umum.create', [
            'title' => 'Buat Permintaan Umum',
            'items' => Item::where('is_active', true)->orderBy('item_name')->get(),
        ]);
    }

    public function storeUmum(StorePermintaanUmumRequest $request)
    {
        $validatedData = $request->validated();
        $userId = $request->user()->user_id;

        DB::transaction(function () use ($validatedData, $userId) {
            $itemRequest = ModelsRequest::create([
                'user_id' => $userId,
                'date' => now(),
                'type' => 'umum',
                'status' => 'submitted',
                'request_number' => 'REQ-UMUM-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            ]);

            $now = now();
            RequestDetail::insert(array_map(
                fn (array $item) => [
                    'request_id' => $itemRequest->request_id,
                    'item_id' => $item['item_id'],
                    'quantity_requested' => $item['qty'],
                    'quantity_approved' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $validatedData['items'],
            ));
        });

        return redirect()->route('request.index')->with('status', 'Permintaan umum berhasil dibuat.');
    }

    public function umumIndex(Request $request)
    {
        abort_unless($request->user()->hasRole(UserRole::Admin, UserRole::PengelolaGudang), 403);

        $requests = ModelsRequest::with(['user', 'item.item'])
            ->where('type', 'umum')
            ->latest('date')
            ->latest('created_at')
            ->paginate(15);

        return view('pages.request.umum.index', [
            'title' => 'Permintaan Umum',
            'requests' => $requests,
        ]);
    }

    public function editUmum(ModelsRequest $request)
    {
        abort_unless(
            $request->type === 'umum'
                && $request->status === 'submitted'
                && auth()->user()->hasRole(UserRole::Admin, UserRole::PengelolaGudang),
            404,
        );

        return view('pages.request.umum.create', [
            'title' => 'Ubah Permintaan Umum',
            'items' => Item::where('is_active', true)->orderBy('item_name')->get(),
            'requestData' => $request->load('item.item'),
            'isEdit' => true,
        ]);
    }

    public function updateUmum(UpdatePermintaanUmumRequest $httpRequest, ModelsRequest $request)
    {
        $validatedData = $httpRequest->validated();

        DB::transaction(function () use ($validatedData, $request): void {
            abort_unless($request->fresh()->status === 'submitted', 409);

            $details = collect($validatedData['items'])
                ->filter(fn (array $item): bool => (int) $item['qty'] > 0)
                ->map(fn (array $item): array => [
                    'request_id' => $request->request_id,
                    'item_id' => $item['item_id'],
                    'quantity_requested' => $item['qty'],
                    'quantity_approved' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->values()
                ->all();

            $request->item()->delete();
            if ($details !== []) {
                RequestDetail::insert($details);
            }
        });

        return redirect()->route('request.umum.index')->with('status', 'Permintaan umum berhasil diperbarui.');
    }

    public function rejectUmum(ModelsRequest $request)
    {
        abort_unless(auth()->user()->hasRole(UserRole::Admin, UserRole::PengelolaGudang), 403);
        abort_unless($request->type === 'umum' && $request->status === 'submitted', 409);

        $request->update(['status' => 'rejected']);

        return redirect()->route('request.umum.index')->with('status', 'Permintaan umum ditolak.');
    }

    public function mergeUmum(MergePermintaanUmumRequest $httpRequest)
    {
        $requestIds = $httpRequest->validated()['request_ids'];
        $kasubbag = User::where('role', UserRole::KasubbagUmumTi->value)
            ->where('is_active', true)
            ->firstOrFail();

        $mergedRequest = DB::transaction(function () use ($requestIds, $kasubbag): ModelsRequest {
            $requests = ModelsRequest::whereIn('request_id', $requestIds)
                ->where('type', 'umum')
                ->where('status', 'submitted')
                ->with('item')
                ->lockForUpdate()
                ->get();

            abort_unless($requests->count() === count(array_unique($requestIds)), 409);

            $items = $requests->flatMap->item
                ->filter(fn (RequestDetail $detail): bool => (int) $detail->quantity_requested > 0)
                ->groupBy('item_id')
                ->map(fn ($details): int => $details->sum('quantity_requested'));

            abort_unless($items->isNotEmpty(), 422, 'Tidak ada barang yang dapat digabung.');

            $mergedRequest = ModelsRequest::create([
                'user_id' => $kasubbag->user_id,
                'date' => now(),
                'type' => 'emergency',
                'status' => 'submitted',
                'request_number' => 'REQ-' . time() . '-' . Str::upper(Str::random(4)),
            ]);

            $now = now();
            RequestDetail::insert($items->map(fn (int $quantity, string $itemId): array => [
                'request_id' => $mergedRequest->request_id,
                'item_id' => $itemId,
                'quantity_requested' => $quantity,
                'quantity_approved' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());

            ModelsRequest::whereIn('request_id', $requests->pluck('request_id'))->delete();

            return $mergedRequest;
        });

        app(RequestDocumentGenerator::class)->generateNotaDinas($mergedRequest->fresh(['user', 'item.itemProposal']));

        return redirect()->route('request.index')->with('status', 'Permintaan umum berhasil digabung menjadi permintaan darurat.');
    }

    private function assertCanManageUmum(User $user): void
    {
        abort_unless(
            $user->isAdmin()
                || $user->hasRole(UserRole::PengelolaGudang)
                || $user->unit === 'Subbagian Umum dan TI',
            403,
        );
    }

    public function edit(ModelsRequest $request)
    {
        if ($request->hasItemProposals()) {
            return redirect()
                ->route('request.show', $request)
                ->with('error', 'Putuskan seluruh usulan barang terlebih dahulu sebelum mengubah permintaan.');
        }

        $request->load('item');
        $items = Item::where('is_active', true)->get();
        return view('pages.request.edit', [
            'title' => 'Ubah Kuantitas Permintaan Barang',
            'request' => $request,
            'items' => $items
        ]);
    }

    public function show(ModelsRequest $request, Request $httpRequest)
    {
        $viewer = $httpRequest->user();
        $request->load(['user', 'item']);
        $notaDinas = $request->requestDocuments()
            ->where('document_type', 'nota_dinas')
            ->latest('created_at')
            ->first();
        $bast = $request->requestDocuments()
            ->where('document_type', 'bast')
            ->latest('created_at')
            ->first();

        return view('pages.request.show', [
            'title' => 'Detail Permintaan',
            'requestData' => $request,
            'hasNotaDinas' => $notaDinas && Storage::disk('local')->exists($notaDinas->file_path),
            'hasBast' => $bast && Storage::disk('local')->exists($bast->file_path),
            'isKepalaUnit' => $viewer->hasRole(UserRole::KepalaUnit),
            'isPengelolaGudang' => $viewer->hasRole(UserRole::PengelolaGudang),
            'isAdmin' => $viewer->isAdmin(),
            'isKasubbag' => $viewer->hasRole(UserRole::KasubbagUmumTi),
        ]);
    }

    public function notaDinas(ModelsRequest $request)
    {
        $notaDinas = $request->requestDocuments()
            ->where('document_type', 'nota_dinas')
            ->with('user')
            ->latest('created_at')
            ->first();

        $viewer = auth()->user();
        $canView = $viewer->hasRole(UserRole::Admin, UserRole::KasubbagUmumTi)
            || $viewer->hasRole(UserRole::PengelolaGudang)
            || ($viewer->hasRole(UserRole::Pengguna)
                && $notaDinas?->user_id === $viewer->user_id)
            || ($viewer->hasRole(UserRole::KepalaUnit)
                && filled($viewer->unit)
                && $viewer->unit === $notaDinas?->user?->unit);

        abort_unless($canView, 403);

        abort_unless($notaDinas && Storage::disk('local')->exists($notaDinas->file_path), 404);

        return response()->file(Storage::disk('local')->path($notaDinas->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$notaDinas->document_number.'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function bast(ModelsRequest $request)
    {
        $bast = $request->requestDocuments()
            ->where('document_type', 'bast')
            ->with('user')
            ->latest('created_at')
            ->first();

        $viewer = auth()->user();
        $canView = $viewer->hasRole(UserRole::Admin, UserRole::KasubbagUmumTi)
            || $viewer->hasRole(UserRole::PengelolaGudang)
            || ($viewer->hasRole(UserRole::Pengguna) && $bast?->user_id === $viewer->user_id)
            || ($viewer->hasRole(UserRole::KepalaUnit)
                && filled($viewer->unit)
                && $viewer->unit === $bast?->user?->unit);

        abort_unless($canView, 403);
        abort_unless($bast && Storage::disk('local')->exists($bast->file_path), 404);

        return response()->file(Storage::disk('local')->path($bast->file_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$bast->document_number.'.pdf"',
        ]);
    }

    public function verifyNotaDinas(RequestDocument $requestDocument, string $token)
    {
        abort_unless(
            $requestDocument->document_type === 'nota_dinas'
                && filled($requestDocument->verification_token_hash)
                && hash_equals($requestDocument->verification_token_hash, hash('sha256', $token)),
            404,
        );

        $requestDocument->load(['request.user', 'approvedBy']);

        return view('pages.request.verify-nota-dinas', [
            'title' => 'Verifikasi Nota Dinas',
            'document' => $requestDocument,
        ]);
    }

    public function store(StorePermintaanBarangRequest $request, RequestProcurementService $requestProcurementService)
    {
        $validatedData = $request->validated();
        
        $itemRequest = DB::transaction(function () use ($request, $validatedData, $requestProcurementService) {
            $isEmergency = (bool) ($validatedData['emergency'] ?? false);

            $itemRequest = ModelsRequest::create([
                'user_id' => $isEmergency
                    ? $validatedData['requester_id']
                    : $request->user()->user_id,
                'date' => now(),
                'type' => $isEmergency ? 'emergency' : 'normal',
                'status' => 'submitted',
                'request_number' => 'REQ-' . time(),
            ]);

            // dd($validatedData['items']);

            foreach ($validatedData['items'] as $item) {
                $proposal = ($item['source'] ?? 'item') === 'proposal'
                    ? ItemProposal::create([
                        'item_name' => $item['item_name'],
                        'unit' => $item['unit'],
                        'purpose_note' => $item['purpose_note'],
                        'proposed_by' => $request->user()->user_id,
                        'status' => 'diajukan',
                    ])
                    : null;


                RequestDetail::query()->insert([
                    'request_id' => $itemRequest->request_id,
                    'item_id' => $proposal === null ? $item['item_id'] : null,
                    'item_proposal_id' => $proposal?->item_proposal_id,
                    'quantity_requested' => $item['qty'],
                    'quantity_approved' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $procurementRequest = $requestProcurementService->createForShortage($itemRequest);

            return [$itemRequest, $procurementRequest];
        });

        [$itemRequest, $procurementDraft] = $itemRequest;

        if ($itemRequest->type !== 'umum') {
            app(RequestDocumentGenerator::class)->generateNotaDinas($itemRequest->fresh(['user', 'item.itemProposal']));
        }

        AuditLogger::record(
            user: $request->user(),
            activity: "Membuat permintaan barang: {$itemRequest->request_number}",
            module: AuditModule::Permintaan,
            event: AuditEvent::Tambah,
            subject: $itemRequest,
        );
        NotificationScanner::catatPermintaanDiajukan($itemRequest->fresh('user'));
        $itemRequest->item()
            ->with('itemProposal')
            ->get()
            ->pluck('itemProposal')
            ->filter()
            ->each(fn (ItemProposal $proposal) => NotificationScanner::periksaUsulanBarang($proposal->fresh('proposedBy')));

        return redirect()->route('request.index')->with('status', 'Permintaan barang berhasil dibuat.');
    }

    public function update(UpdatePermintaanBarangRequest $httpRequest, ModelsRequest $request)
    {
        if ($request->hasItemProposals()) {
            return redirect()
                ->route('request.show', $request)
                ->with('error', 'Putuskan seluruh usulan barang terlebih dahulu sebelum mengubah permintaan.');
        }

        if ($request->status !== 'approved_by_head') {
            return redirect()
                ->route('request.index')
                ->with('error', 'Permintaan ini tidak dapat diubah.');
        }

        $validatedData = $httpRequest->validated();

        $error = DB::transaction(function () use ($request, $validatedData): ?string {
            $submittedItems = collect($validatedData['items'])
                ->keyBy('item_id');
            $existingDetails = $request->item()->get()->keyBy('item_id');

            foreach ($existingDetails as $itemId => $detail) {
                $approved = (int) ($submittedItems->get($itemId)['qty'] ?? 0);
                $item = Item::query()->lockForUpdate()->findOrFail($itemId);

                if ($approved > (int) $detail->quantity_requested) {
                    return 'Jumlah disetujui tidak boleh melebihi jumlah yang diminta.';
                }

                if ($approved > (int) $item->stock_current) {
                    return "Jumlah disetujui untuk {$item->item_name} tidak boleh melebihi stok gudang.";
                }

                $detail->update(['quantity_approved' => $approved]);
            }

            foreach ($submittedItems->reject(fn (array $item): bool => $existingDetails->has($item['item_id'])) as $item) {
                $masterItem = Item::query()->lockForUpdate()->findOrFail($item['item_id']);

                if ((int) $item['qty'] > (int) $masterItem->stock_current) {
                    return "Jumlah disetujui untuk {$masterItem->item_name} tidak boleh melebihi stok gudang.";
                }

                RequestDetail::create([
                    'request_id' => $request->request_id,
                    'item_id' => $item['item_id'],
                    'quantity_requested' => 0,
                    'quantity_approved' => $item['qty'],
                ]);
            }

            $request->update(['status' => 'processed_by_staff']);

            return null;
        });

        if ($error !== null) {
            return redirect()->back()->withInput()->with('error', $error);
        }

        AuditLogger::record(
            user: $httpRequest->user(),
            activity: "Mengubah permintaan barang: {$request->request_number} (status menjadi {$request->status})",
            module: AuditModule::Permintaan,
            event: AuditEvent::Ubah,
            subject: $request,
        );

        return redirect()
            ->route('request.index')
            ->with('status', 'Permintaan barang berhasil diperbarui.');
    }

    public function removeRejectedProposal(RequestDetail $requestDetail, Request $httpRequest)
    {
        $actor = $httpRequest->user();
        abort_unless(
            $actor->isAdmin()
                || $actor->hasRole(UserRole::KasubbagUmumTi, UserRole::PengelolaGudang),
            403,
        );

        $request = $requestDetail->request;
        abort_unless(
            $requestDetail->item_proposal_id !== null
                && $requestDetail->itemProposal?->status === ItemProposalStatus::Ditolak,
            404,
        );

        $requestDetail->delete();

        return redirect()
            ->route('request.show', $request)
            ->with('status', 'Usulan barang yang ditolak berhasil dihapus dari permintaan.');
    }

    public function respondByRole(
        ModelsRequest $request,
        Request $httpRequest,
        RequestWorkflowService $workflow,
    )
    {
        $action = $httpRequest->input('action');
        $user = $httpRequest->user();

        if (!in_array($action, ['approve', 'reject'])) {
            return redirect()->back()->with('error', 'Aksi tidak valid.');
        }

        try {
            $workflow->respond($request, $user, $action);
        } catch (AuthorizationException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (InsufficientStockException $exception) {
            return redirect()->back()->with(
                'error',
                "Stok {$exception->item->item_name} tidak cukup untuk diserahkan. "
                    ."Tersedia {$exception->available}, dibutuhkan {$exception->requested}.",
            );
        }

        if ($action === 'reject') {
            return redirect()->back()->with('status', 'Permintaan telah ditolak.');
        }

        return redirect()->route('request.index')->with('status', 'Permintaan berhasil diproses.');
    }
}
