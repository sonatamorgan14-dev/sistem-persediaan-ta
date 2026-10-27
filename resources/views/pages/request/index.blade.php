@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Daftar Permintaan Barang</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                @if (!$isUser)
                    Kelola dan pantau permintaan barang yang diajukan oleh unit kerja.
                @else
                    Pantau status permintaan barang yang telah diajukan.
                @endif
            </p>
        </div>

        <a href="{{ route('request.create') }}"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
            <svg class="size-5 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M10 3.25a.75.75 0 01.75.75v5.25H16a.75.75 0 010 1.5h-5.25V16a.75.75 0 01-1.5 0v-5.25H4a.75.75 0 010-1.5h5.25V4a.75.75 0 01.75-.75z" />
            </svg>
            Buat Permintaan
        </a>
    </div>
    <x-ui.flash />

    @if (!$isUser && !$isKepalaUnit)
        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3 md:gap-6">
            @foreach ([
                ['label' => 'Permintaan Diproses', 'value' => $summary['pending'], 'class' => 'text-gray-600 dark:text-orange-400'],
                ['label' => 'Permintaan Bulan Ini', 'value' => $summary['bulan_ini'], 'class' => 'text-gray-600 dark:text-white/90'],
                ['label' => 'Permintaan Selesai Bulan Ini', 'value' => $summary['selesai_bulan_ini'], 'class' => 'text-gray-600 dark:text-success-500'],
                ] as $card)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                    <h4 class="text-title-sm mt-2 font-bold {{ $card['class'] }}">{{ $card['value'] }}</h4>
                </div>
            @endforeach
        </div>
    @endif
        
    <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <form method="GET" action="{{ route('request.index') }}"
            class="grid grid-cols-1 gap-4 border-b border-gray-100 p-5 sm:grid-cols-2 xl:grid-cols-5 dark:border-gray-800 sm:p-6">
            <x-form.text-field name="q" label="Cari" :value="$keyword" placeholder="Nomor atau nama pemohon" />

            <x-form.select-field name="type" label="Tipe" :options="['normal' => 'Normal', 'emergency' => 'Darurat']"
                :selected="$type" placeholder="Semua tipe" />

            <x-form.select-field name="status" label="Status"
                :options="Arr::except(\App\Models\Request::STATUS_LABELS, 'merged')"
                :selected="$status" placeholder="Semua status" />

            <x-form.date-picker name="date_from" label="Tanggal Mulai" placeholder="Pilih tanggal mulai"
                :defaultDate="$dateFrom" dateFormat="Y-m-d" />

            <x-form.date-picker name="date_to" label="Tanggal Akhir" placeholder="Pilih tanggal akhir"
                :defaultDate="$dateTo" dateFormat="Y-m-d" />

            <div class="flex items-end gap-3 sm:col-span-2 xl:col-span-6">
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                    Terapkan
                </button>
                @if ($keyword !== '' || $type !== '' || $status !== '' || $dateFrom !== '' || $dateTo !== '')
                    <a href="{{ route('request.index') }}"
                        class="text-theme-sm inline-flex items-center rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        <div>
            <div class="custom-scrollbar max-w-full overflow-x-auto">
                <table class="w-full min-w-[900px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Nomor Permintaan', 'Nama Pemohon', 'Tanggal', 'Tipe', 'Status', 'Aksi'] as $heading)
                                <th class="px-5 py-3 text-left sm:px-6">
                                    <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($requests as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $row->request_number }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="text-theme-sm text-gray-700 dark:text-gray-300">{{ $row->user?->fullname ?? '-' }}</span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $row->date?->translatedFormat('d M Y') ?? '-' }}</p>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ ucfirst($row->type) }}</span>
                                </td>
                                <td class="px-5 py-4 sm:px-6">
                                    <span @class([
                                        'text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium',
                                        'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500' => in_array($row->status, ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance'], true),
                                        'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500' => $row->status === 'rejected',
                                        'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500' => $row->status === 'completed',
                                    ])>
                                        {{ $row->statusLabel() }}
                                    </span>
                                </td>
                                <td class="flex flex-wrap items-center gap-3 px-5 py-4 sm:px-6">
                                    <a href="{{ route('request.show', $row) }}"
                                        class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                        Detail
                                    </a>

                                    @if ($row->status === 'approved_by_head' && $row->type !== 'emergency' && ($isPengelolaGudang || $isAdmin))
                                        <a href="{{ route('request.edit', $row) }}"
                                            class="text-theme-xs text-warning-600 dark:text-warning-500 ring-warning-200 dark:ring-warning-500/30 hover:bg-warning-50 dark:hover:bg-warning-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                            Ubah Kuantitas
                                        </a>
                                    @endif

                                    @php
                                        $isEmergencyReadyToComplete = $row->type === 'emergency' && $row->status === 'approved_by_head';
                                        $hasItemProposals = $row->item->contains(fn ($detail) => $detail->item_proposal_id !== null);
                                        $isKasubbagKepalaUnit = $isKasubbag
                                            && auth()->user()->unit === \App\Enums\UnitKerja::SubbagianUmumTi;
                                        $canRespond = ($row->status === 'submitted' && ($isKepalaUnit || $isKasubbagKepalaUnit)) ||
                                                    (in_array($row->status, ['approved_by_head', 'in_issuance'], true) && $isPengelolaGudang) ||
                                                    ($isAdmin && in_array($row->status, ['submitted', 'approved_by_head', 'processed_by_staff', 'in_issuance'], true)) ||
                                                    ($isKasubbag && (in_array($row->status, ['processed_by_staff', 'in_issuance'], true) || $isEmergencyReadyToComplete));
                                        $canRespond = $canRespond && (! $hasItemProposals || ($isAdmin && $row->status === 'submitted'));
                                        $revertTo = $isAdmin ? $row->previousStatus() : null;
                                    @endphp

                                    @if ($canRespond)
                                        @php
                                            $approveLabel = $isEmergencyReadyToComplete || $row->status === 'in_issuance' ? 'Selesaikan' : ($row->status === 'processed_by_staff' ? 'Mulai Pengeluaran' : 'Setujui');
                                        @endphp
                                        <!-- Tombol Setujui -->
                                        @if ($row->status === 'approved_by_head')
                                            {{-- Nota Dinas harus diberi nomor sebelum diproses lebih lanjut. --}}
                                            <button type="button" x-data
                                                @click="$dispatch('open-nd-number-modal', @js(['id' => $row->request_id, 'number' => $row->request_number, 'preview' => $row->documentNumber('ND'), 'sequence' => $row->ndSequence(), 'label' => $approveLabel]))"
                                                class="text-theme-xs text-success-600 dark:text-success-500 ring-success-200 dark:ring-success-500/30 hover:bg-success-50 dark:hover:bg-success-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                                {{ $approveLabel }}
                                            </button>
                                        @else
                                            <form action="{{ route('request.respond', ['request' => $row, 'action' => 'approve']) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit"
                                                    class="text-theme-xs text-success-600 dark:text-success-500 ring-success-200 dark:ring-success-500/30 hover:bg-success-50 dark:hover:bg-success-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                                    {{ $approveLabel }}
                                                </button>
                                            </form>
                                        @endif

                                        <!-- Tombol Tolak -->
                                        @unless ($isEmergencyReadyToComplete)
                                            <form action="{{ route('request.respond', ['request' => $row, 'action' => 'reject']) }}" method="POST" class="inline">
                                                @csrf
                                                <button type="submit" 
                                                    class="text-theme-xs text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                                    Tolak
                                                </button>
                                            </form>
                                        @endunless
                                    @endif

                                    @if ($revertTo)
                                        <button type="button" x-data
                                            @click="$dispatch('open-revert-modal', @js(['id' => $row->request_id, 'number' => $row->request_number, 'from' => $row->status, 'to' => $revertTo]))"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                            Kembalikan Status
                                        </button>
                                    @endif

                                    @if ($hasItemProposals && ($isAdmin || $isPengelolaGudang))
                                        <a href="{{ route('item-proposals.index') }}"
                                            class="text-theme-xs order-last text-warning-600 underline decoration-warning-300 underline-offset-2 hover:text-warning-700 dark:text-warning-400 dark:decoration-warning-500/50 dark:hover:text-warning-300">
                                            Selesaikan usulan barang
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center sm:px-6">
                                    <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                        Tidak ada barang yang cocok dengan penyaring saat ini.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $requests->links('vendor.pagination.tailadmin') }}
        </div>
    </div>

    @if ($isAdmin)
        @include('pages.request._revert-status-modal')
    @endif

    @if ($isAdmin || $isPengelolaGudang || $isKasubbag)
        @include('pages.request._nd-number-modal')
    @endif
@endsection
