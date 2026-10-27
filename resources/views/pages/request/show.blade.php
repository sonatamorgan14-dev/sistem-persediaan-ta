@extends('layouts.app')

@section('content')
    @php
        $isEmergencyReadyToComplete = $requestData->type === 'emergency' && $requestData->status === 'approved_by_head';
        $hasItemProposals = $requestData->hasItemProposals();
        $canEditQuantity = $requestData->status === 'approved_by_head'
            && $requestData->type !== 'emergency'
            && ($isPengelolaGudang || $isAdmin)
            && ! $hasItemProposals;
        $isKasubbagKepalaUnit = $isKasubbag
            && auth()->user()->unit === 'Subbagian Umum dan TI';
        $canRespond = ($requestData->status === 'submitted' && ($isKepalaUnit || $isKasubbagKepalaUnit)) ||
                    (in_array($requestData->status, ['approved_by_head', 'in_issuance'], true) && $isPengelolaGudang) ||
                    ($isAdmin && in_array($requestData->status, ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance'], true)) ||
                    ($isKasubbag && (in_array($requestData->status, ['processed_by_staff', 'in_issuance'], true) || $isEmergencyReadyToComplete));
        $canRespond = $canRespond && (! $hasItemProposals || ($isAdmin && $requestData->status === 'submitted'));
        $revertTo = $isAdmin ? $requestData->previousStatus() : null;
        $statusLabels = [
            'submitted' => 'Terkirim',
            'approved_by_head' => 'Disetujui Kepala Unit',
            'processed_by_staff' => 'Disetujui Staff Umum',
            'in_issuance' => 'Dalam Proses Pengeluaran',
            'completed' => 'Selesai',
            'rejected' => 'Ditolak',
        ];
    @endphp



    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Detail Permintaan</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('request.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Daftar Permintaan
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $requestData->request_number }}</li>
                </ol>
            </nav>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($hasNotaDinas)
                <a href="{{ route('request.nota-dinas', $requestData) }}" target="_blank" rel="noopener"
                    class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
                    Lihat Nota Dinas PDF
                </a>
            @endif
            @if ($hasBast)
                <a href="{{ route('request.bast', $requestData) }}" target="_blank" rel="noopener"
                    class="rounded-lg bg-success-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-success-600">
                    Lihat BAST PDF
                </a>
            @endif
        </div>
    </div>

    <x-ui.flash />


    @if ($canRespond || $canEditQuantity || $revertTo)
        <div class="mt-6  p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-end gap-3">
                @if ($revertTo)
                    <button type="button" x-data
                        @click="$dispatch('open-revert-modal', @js(['id' => $requestData->request_id, 'number' => $requestData->request_number, 'from' => $requestData->status, 'to' => $revertTo]))"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Kembalikan Status
                    </button>
                @endif

                @if ($canEditQuantity)
                    <a href="{{ route('request.edit', $requestData) }}"
                        class="text-theme-sm text-warning-600 dark:text-warning-500 ring-warning-200 dark:ring-warning-500/30 hover:bg-warning-50 dark:hover:bg-warning-500/10 inline-flex items-center rounded-lg px-4 py-2.5 font-medium ring-1 ring-inset transition">
                        Ubah Kuantitas
                    </a>
                @endif

                @if ($canRespond)
                    <form action="{{ route('request.respond', ['request' => $requestData, 'action' => 'approve']) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit"
                            class="bg-success-500 hover:bg-success-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                            {{ $isEmergencyReadyToComplete || $requestData->status === 'in_issuance' ? 'Selesaikan' : ($requestData->status === 'processed_by_staff' ? 'Mulai Pengeluaran' : 'Setujui') }}
                        </button>
                    </form>

                    @unless ($isEmergencyReadyToComplete)
                        <form action="{{ route('request.respond', ['request' => $requestData, 'action' => 'reject']) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit"
                                class="text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition">
                                Tolak
                            </button>
                        </form>
                    @endunless
                @endif
            </div>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-6 dark:border-gray-800 dark:bg-white/[0.03]">
        <dl class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Nomor Permintaan</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->request_number }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Nama Pemohon</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->user?->fullname ?? '-' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Tanggal</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->date?->translatedFormat('d F Y') ?? '-' }}</dd></div>
            <div><dt class="text-sm text-gray-500 dark:text-gray-400">Tipe</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white/90">{{ $requestData->type === 'emergency' ? 'Darurat' : 'Normal' }}</dd></div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">Status</dt>
                <dd>
                    <span @class([
                        'text-theme-xs mt-1 inline-block rounded-full px-2.5 py-1 font-medium',
                        'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-500' => $requestData->status === 'submitted',
                        'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500' => !in_array($requestData->status, ['submitted', 'rejected', 'completed'], true),
                        'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500' => $requestData->status === 'rejected',
                        'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $requestData->status === 'completed',
                    ])>
                        {{ $statusLabels[$requestData->status] ?? ucfirst(str_replace('_', ' ', $requestData->status)) }}
                    </span>
                </dd>
            </div>
        </dl>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-800">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Barang yang Diminta</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Kode Barang</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400">Nama Barang</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requestData->item as $detail)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code ?? ($detail->itemProposal ? 'Usulan baru' : '-') }}</td>
                        <td class="px-6 py-4 text-sm font-medium text-gray-800 dark:text-white/90">
                            {{ $detail->item?->item_name ?? $detail->itemProposal?->item_name ?? '-' }}
                            @if ($detail->itemProposal)
                                <span class="ml-2 rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
                                    Usulan {{ $detail->itemProposal->status?->label() ?? $detail->itemProposal->status }}
                                </span>
                                @if ($detail->itemProposal->isDitolak() && ($isAdmin || $isKasubbag || $isPengelolaGudang))
                                    <form method="POST" action="{{ route('request.proposal-detail.remove', [$requestData, $detail]) }}" class="mt-2">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-medium text-error-600 hover:text-error-700 dark:text-error-400">
                                            Hapus dari permintaan
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </td>
                        <td class="px-6 py-4 text-right text-sm text-gray-700 dark:text-gray-300">{{ $detail->quantity_approved ? number_format($detail->quantity_approved, 0, ',', '.') : number_format($detail->quantity_requested, 0, ',', '.') }}</td>
                    </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada barang dalam permintaan ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($revertTo)
        @include('pages.request._revert-status-modal')
    @endif
@endsection