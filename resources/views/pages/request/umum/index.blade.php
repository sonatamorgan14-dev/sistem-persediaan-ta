@extends('layouts.app')

@section('content')
    <div x-data="umumRequestsPage()">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Daftar Permintaan Umum</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Daftar barang umum beserta peminta dari unit kerja.</p>
        </div>
        <a href="{{ route('request.umum.create') }}" class="rounded-lg bg-brand-500 px-4 py-3 text-sm font-medium text-white hover:bg-brand-600">
            Buat Permintaan Umum
        </a>
    </div>

    <x-ui.flash />

    @unless ($isHistory)
    <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Menunggu diproses</p>
            <p class="mt-2 text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $requests->where('status', 'submitted')->count() }}</p>
        </div>
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <p class="text-sm text-gray-500 dark:text-gray-400">Dipilih untuk digabung</p>
            <p class="mt-2 text-2xl font-semibold text-brand-600" x-text="selectedRequests.length">0</p>
        </div>
        <div class="rounded-2xl border border-brand-100 bg-brand-50 p-5 dark:border-brand-500/20 dark:bg-brand-500/10">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-brand-700 dark:text-brand-300">Aksi penerimaan</p>
                    <p class="mt-1 text-xs text-brand-600/80 dark:text-brand-300/80">Gabungkan permintaan terpilih menjadi satu.</p>
                </div>
                <button type="button" @click="openMergeModal" :disabled="selectedRequests.length === 0"
                    class="shrink-0 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                    Gabungkan
                </button>
            </div>
        </div>
    </div>
    @endunless

    <form id="merge-form" method="POST" action="{{ route('request.umum.merge') }}" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-white/[0.03]">
        @csrf
        <template x-for="requestId in selectedRequests" :key="requestId">
            <input type="hidden" name="request_ids[]" :value="requestId">
        </template>
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $isHistory ? 'Histori Permintaan Umum' : 'Antrean Permintaan Umum' }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $isHistory ? 'Permintaan umum yang sudah digabung menjadi permintaan darurat.' : 'Permintaan ditolak tetap tersimpan sebagai riwayat.' }}
                </p>
            </div>
            @if ($isHistory)
                <a href="{{ route('request.umum.index') }}"
                    class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Kembali ke Antrean
                </a>
            @else
                <a href="{{ route('request.umum.index', ['view' => 'histori']) }}"
                    class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-brand-600 ring-1 ring-brand-200 ring-inset transition hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-500/30 dark:hover:bg-brand-500/10">
                    Histori
                </a>
            @endif
        </div>
        <div class="custom-scrollbar max-w-full overflow-x-auto">
            <table class="w-full min-w-[1100px]">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.02]">
                        @unless ($isHistory)
                            <th class="w-16 px-5 py-3 text-left sm:px-6">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)" aria-label="Pilih semua permintaan submitted"
                                    class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                            </th>
                        @endunless
                        @foreach (['Nomor Permintaan', 'Peminta', 'Unit', 'Barang yang Diminta', 'Tanggal', 'Status', $isHistory ? 'Digabung ke' : 'Aksi'] as $heading)
                            <th class="px-5 py-3 text-left sm:px-6">
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($requests as $request)
                        <tr class="border-b border-gray-100 align-top transition-colors last:border-0 hover:bg-gray-50/60 dark:border-gray-800 dark:hover:bg-white/[0.02]">
                            @unless ($isHistory)
                                <td class="px-5 py-4 sm:px-6">
                                    @if ($request->status === 'submitted')
                                        <input type="checkbox" value="{{ $request->request_id }}" x-model="selectedRequests" class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                                    @endif
                                </td>
                            @endunless
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-sm font-semibold text-gray-800 dark:text-white/90">{{ $request->request_number }}</p>
                                <p class="mt-1 text-xs text-gray-400">{{ $request->item->count() }} jenis barang</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $request->user?->fullname ?? '-' }}</p>
                                <p class="mt-1 text-xs text-gray-400">{{ $request->user?->username ? '@'.$request->user->username : 'Pengguna' }}</p>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="inline-flex max-w-[190px] rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">
                                    {{ $request->user?->unit?->label() ?? '-' }}
                                </span>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <ul class="max-w-[260px] space-y-2 text-sm text-gray-700 dark:text-gray-300">
                                    @foreach ($request->item as $detail)
                                        <li class="flex items-center justify-between gap-3">
                                            <span class="truncate">{{ $detail->item?->item_name ?? '-' }}</span>
                                            <span class="shrink-0 rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $detail->quantity_requested }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                            <td class="px-5 py-4 sm:px-6">
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $request->date?->translatedFormat('d M Y') ?? '-' }}</p>
                                <p class="mt-1 text-xs text-gray-400">{{ $request->created_at?->format('H:i') }}</p>
                            </td>
                            <td class="px-5 py-4 text-sm sm:px-6">
                                @php
                                    [$badgeClass, $dotClass] = match ($request->status) {
                                        'rejected' => ['bg-error-50 text-error-600 dark:bg-error-500/10 dark:text-error-400', 'bg-error-500'],
                                        'merged' => ['bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300', 'bg-brand-500'],
                                        default => ['bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400', 'bg-success-500'],
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">
                                    <span class="size-1.5 rounded-full {{ $dotClass }}"></span>
                                    {{ $request->statusLabel() }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-sm sm:px-6">
                                @if ($isHistory)
                                    @if ($request->mergedInto)
                                        <a href="{{ route('request.show', $request->mergedInto) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-400">
                                            {{ $request->mergedInto->request_number }}
                                        </a>
                                        <p class="mt-1 text-xs text-gray-400">{{ $request->updated_at?->translatedFormat('d M Y H:i') }}</p>
                                    @else
                                        <span class="text-gray-400">Permintaan hasil gabungan sudah dihapus</span>
                                    @endif
                                @elseif ($request->status === 'submitted')
                                    <div class="flex flex-wrap gap-3">
                                        <a href="{{ route('request.umum.edit', $request) }}"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-warning-600 ring-1 ring-warning-200 ring-inset transition hover:bg-warning-50 dark:text-warning-500 dark:ring-warning-500/30 dark:hover:bg-warning-500/10">
                                            Ubah permintaan
                                        </a>
                                        <button type="button" @click="openRejectModal('{{ $request->request_id }}', @js($request->request_number))"
                                            class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-error-600 ring-1 ring-error-200 ring-inset transition hover:bg-error-50 dark:text-error-500 dark:ring-error-500/30 dark:hover:bg-error-500/10">
                                            Tolak permintaan
                                        </button>
                                    </div>
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $isHistory ? 7 : 8 }}" class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400 sm:px-6">
                                {{ $isHistory ? 'Belum ada permintaan umum yang digabungkan.' : 'Belum ada permintaan umum.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($requests->hasPages())
            <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800 sm:px-6">
                {{ $requests->links() }}
            </div>
        @endif
    </form>

    <x-ui.modal x-data="{ open: false }" @open-merge-modal.window="open = true" :isOpen="false" class="max-w-[520px]">
        <div class="p-6 sm:p-8">
            <p class="text-sm font-medium text-brand-600">Konfirmasi penerimaan</p>
            <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">Gabungkan permintaan terpilih?</h3>
            <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">Barang yang sama akan dijumlahkan. Permintaan umum asal akan dipindahkan ke Histori dan sistem membuat satu permintaan emergency berstatus submitted.</p>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
                <button type="submit" form="merge-form" @click="open = false" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Ya, Gabungkan</button>
            </div>
        </div>
    </x-ui.modal>

    <x-ui.modal x-data="{ open: false }" @open-reject-modal.window="open = true" :isOpen="false" class="max-w-[520px]">
        <div class="p-6 sm:p-8">
            <p class="text-sm font-medium text-error-500">Konfirmasi penolakan</p>
            <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">Tolak permintaan ini?</h3>
            <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">Permintaan <span class="font-medium text-gray-700 dark:text-gray-200" x-text="rejectRequestNumber"></span> akan tetap tampil sebagai riwayat dengan status Ditolak.</p>
            <form method="POST" :action="`/permintaan-umum/${rejectRequestId}/reject`" class="mt-6 flex justify-end gap-3">
                @csrf
                <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
                <button type="submit" class="rounded-lg bg-error-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-error-600">Ya, Tolak</button>
            </form>
        </div>
    </x-ui.modal>
    </div>

    <script>
        function umumRequestsPage() {
            return {
                selectedRequests: [],
                rejectRequestId: '',
                rejectRequestNumber: '',
                openMergeModal() {
                    if (this.selectedRequests.length > 0) {
                        this.$dispatch('open-merge-modal');
                    }
                },
                openRejectModal(requestId, requestNumber) {
                    this.rejectRequestId = requestId;
                    this.rejectRequestNumber = requestNumber;
                    this.$dispatch('open-reject-modal');
                },
            };
        }
    </script>
@endsection
