@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Konfirmasi Penerimaan</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {{ $procurement->judul() }} &middot; {{ $procurement->supplier?->supplier_name }}
        </p>
    </div>

    <x-ui.flash />

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form action="{{ route('procurement-receipts.store', $procurement) }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-form.text-field name="received_at" label="Tanggal Diterima" type="date"
                    :value="now()->toDateString()" required />
            </div>

            <div class="mt-6">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Jumlah Diterima per Barang
                </label>

                <div class="custom-scrollbar max-w-full overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                    <table class="w-full min-w-[560px]">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach (['Barang', 'Dipesan', 'Diterima'] as $heading)
                                    <th class="px-5 py-3 text-left sm:px-6">
                                        <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($procurement->details as $detail)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                        </p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ number_format($detail->amount, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="0"
                                            name="received[{{ $detail->procurement_detail_id }}]"
                                            value="{{ old('received.'.$detail->procurement_detail_id, $detail->amount) }}"
                                            class="h-10 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                        @error('received.'.$detail->procurement_detail_id)
                                            <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6">
                <label for="notes" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Catatan
                </label>
                <textarea id="notes" name="notes" rows="3" placeholder="Mis. kondisi barang, alasan kekurangan kiriman"
                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('notes') }}</textarea>
                @error('notes')
                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('procurements.show', $procurement) }}"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                    Konfirmasi Penerimaan
                </button>
            </div>
        </form>
    </div>
@endsection
