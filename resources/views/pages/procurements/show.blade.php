@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $procurement->judul() }}</h2>
            <nav class="mt-1.5">
                <ol class="flex items-center gap-1.5">
                    <li>
                        <a href="{{ route('procurements.index') }}"
                            class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                            Realisasi Pengadaan
                            <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </li>
                    <li class="text-sm text-gray-800 dark:text-white/90">{{ $procurement->judul() }}</li>
                </ol>
            </nav>
        </div>

        <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $procurement->status->badgeClass() }}">
            {{ $procurement->status->label() }}
        </span>
    </div>

    <x-ui.flash />

    {{-- Dialog pembatalan, penolakan, penandaan belanja, dan nomor BAST dibuka
         kembali sendiri bila validasinya gagal, supaya pesan galatnya tidak
         tersembunyi di balik dialog tertutup.

         Formulir nomor BAST membuka PDF-nya di tab baru. Tab ini dimuat ulang
         begitu dikunjungi lagi, supaya nomor yang baru diisi ikut tampil. --}}
    <div class="grid grid-cols-12 gap-4 md:gap-6"
        x-data="{
            batalTerbuka: @js($errors->hasAny(['cancel_reason', 'cancel_note'])),
            tolakTerbuka: @js($errors->has('review_note')),
            belanjaTerbuka: @js($errors->hasAny(['purchased_at', 'photos', 'photos.*', 'purchase_note'])),
            nomorBastTerbuka: @js($errors->has('bast_number')),
            muatUlangSaatKembali() {
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') location.reload();
                });
            },
        }">
        {{-- Identitas realisasi --}}
        <div class="col-span-12 space-y-4 md:space-y-6 xl:col-span-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <dl class="space-y-3">
                    @foreach ([
                        'Usulan Asal' => $procurement->tanpaUsulan()
                            ? 'Langsung, tanpa usulan'
                            : ($procurement->procurementRequest?->judul() ?? '—'),
                        'Penyedia' => $procurement->supplier?->supplier_name ?? '—',
                        'Tanggal Realisasi' => $procurement->procurement_date->translatedFormat('d F Y'),
                        'Direalisasikan Oleh' => $procurement->user?->fullname ?? '—',
                        'Nilai Total' => 'Rp'.number_format($procurement->nilaiTotal(), 0, ',', '.'),
                    ] as $label => $value)
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                {{-- Keputusan Kasubbag Umum dan TI: dua tombol, tidak lebih
                     dari itu (ADR 0030). --}}
                @if ($procurement->isMenungguPersetujuan())
                    @can('setujui-pengadaan')
                        <form method="POST" action="{{ route('procurements.approve', $procurement) }}" class="mt-4">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                Setujui Realisasi
                            </button>
                        </form>

                        <button type="button" @click="tolakTerbuka = true"
                            class="text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 mt-3 flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition">
                            Tolak Realisasi
                        </button>
                    @endcan
                @endif

                {{-- Tahap belanja (ADR 0035): Pejabat Pengadaan menandai sudah
                     dibelanjakan beserta fotonya, dan baru setelah itu gudang
                     menunggu barangnya. Keterangannya hanya bagi yang tidak
                     memegang tombolnya, supaya gudang yang tidak melihat tombol
                     konfirmasi tahu sebabnya. --}}
                @if ($procurement->isProsesBelanja())
                    @cannot('kelola-pengadaan')
                        <p class="text-theme-xs bg-blue-light-50 text-blue-light-700 dark:bg-blue-light-500/15 dark:text-blue-light-400 mt-4 rounded-lg p-3">
                            Konfirmasi penerimaan terbuka setelah Pejabat Pengadaan menandai sudah dibelanjakan.
                        </p>
                    @endcannot

                    @can('kelola-pengadaan')
                        <button type="button" @click="belanjaTerbuka = true"
                            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 mt-4 flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                            Tandai Sudah Dibelanjakan
                        </button>
                    @endcan
                @endif

                @if ($procurement->isMenungguPenerimaan())
                    @can('kelola-gudang')
                        <a href="{{ route('procurement-receipts.create', $procurement) }}"
                            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 mt-4 flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                            Konfirmasi Penerimaan
                        </a>
                    @endcan
                @endif

                @if ($procurement->masihBerjalan())
                    {{-- Koreksi didahulukan atas pembatalan: salah ketik
                         diperbaiki di sini, bukan dengan membatalkan lalu
                         mengulang dari usulan baru (ADR 0025). Sejak ADR 0030
                         tombol ini merangkap "ajukan ulang" bagi realisasi yang
                         ditolak — menyimpan perubahan itulah pengajuannya. --}}
                    @can('kelola-pengadaan')
                        <a href="{{ route('procurements.edit', $procurement) }}"
                            class="mt-3 flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                            {{ $procurement->isDitolak() ? 'Perbaiki & Ajukan Ulang' : 'Ubah Realisasi' }}
                        </a>
                    @endcan

                    {{-- Jalan keluar bagi pengadaan yang tidak akan pernah
                         sampai ke penerimaan, mis. penyedia gagal kirim atau
                         penolakan yang tidak akan diperbaiki. Tanpa ini
                         realisasinya terkunci selamanya (ADR 0024). --}}
                    @can('kelola-pengadaan')
                        <button type="button" @click="batalTerbuka = true"
                            class="text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 mt-3 flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium ring-1 ring-inset transition">
                            Batalkan Realisasi
                        </button>
                    @endcan
                @endif
            </div>

            {{-- Dasar pengadaan, hanya pada realisasi langsung. Pada realisasi
                 berusulan tempatnya dokumen usulan itu sendiri, yang sudah
                 tertaut di kartu identitas di atas (ADR 0031). --}}
            @if ($procurement->tanpaUsulan())
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Dasar Pengadaan</h3>

                    <dl class="mt-3 space-y-3">
                        <div class="flex items-start justify-between gap-4">
                            <dt class="text-theme-sm text-gray-500 dark:text-gray-400">Alasan</dt>
                            <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">
                                {{ $procurement->basis_reason?->label() ?? '—' }}
                            </dd>
                        </div>
                    </dl>

                    @if ($procurement->basis_note)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $procurement->basis_note }}
                        </p>
                    @endif
                </div>
            @endif

            {{-- Hasil review Kasubbag Umum dan TI. Jejaknya dikosongkan setiap
                 kali realisasi diubah, jadi kartu ini hilang sendiri ketika
                 dokumennya kembali menunggu persetujuan (ADR 0030). --}}
            @if ($procurement->sudahDireview())
                <div @class([
                    'rounded-2xl border bg-white p-5 dark:bg-white/[0.03] md:p-6',
                    'border-error-200 dark:border-error-500/30' => $procurement->isDitolak(),
                    'border-gray-200 dark:border-gray-800' => ! $procurement->isDitolak(),
                ])>
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">
                        {{ $procurement->isDitolak() ? 'Ditolak' : 'Disetujui' }}
                    </h3>

                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            'Ditinjau Oleh' => $procurement->reviewedBy?->fullname ?? '—',
                            'Tanggal' => $procurement->reviewed_at?->translatedFormat('d F Y H:i') ?? '—',
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($procurement->review_note)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $procurement->review_note }}
                        </p>
                    @endif

                    {{-- Tanpa kalimat ini, halaman yang ditolak dan tidak punya
                         tombol "ajukan ulang" terbaca seperti bug. --}}
                    @if ($procurement->isDitolak())
                        <p class="text-theme-xs bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-400 mt-4 rounded-lg p-3">
                            Perbaiki lalu simpan untuk mengajukannya ulang ke Kasubbag Umum dan TI,
                            atau batalkan bila pengadaannya tidak jadi.
                        </p>
                    @endif
                </div>
            @endif

            {{-- Fakta belanja bertahan walau realisasinya diubah dan kembali
                 menunggu persetujuan: yang diulang penilaiannya, bukan
                 belanjanya (ADR 0035). --}}
            @if ($procurement->sudahDibelanjakan())
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Belanja</h3>

                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            'Tanggal Belanja' => $procurement->purchased_at->translatedFormat('d F Y'),
                            'Ditandai Oleh' => $procurement->purchasedBy?->fullname ?? '—',
                            'Foto Belanja' => ($jumlahFoto = $procurement->documents->where('type', \App\Enums\ProcurementDocumentType::FotoBelanja)->count()) > 0
                                ? $jumlahFoto.' foto'
                                : 'Belum ada',
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($procurement->purchase_note)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $procurement->purchase_note }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($procurement->isDibatalkan())
                <div class="border-error-200 dark:border-error-500/30 rounded-2xl border bg-white p-5 dark:bg-white/[0.03] md:p-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Pembatalan</h3>

                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            'Alasan' => $procurement->cancel_reason?->label() ?? '—',
                            'Dibatalkan Oleh' => $procurement->cancelledBy?->fullname ?? '—',
                            'Tanggal' => $procurement->cancelled_at?->translatedFormat('d F Y H:i') ?? '—',
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($procurement->cancel_note)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $procurement->cancel_note }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($procurement->isDiterima() && $procurement->receipt)
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Penerimaan (BAST)</h3>

                    <dl class="mt-3 space-y-3">
                        @foreach ([
                            'Nomor BAST' => $procurement->receipt->bast_number ?? 'Belum diisi',
                            'Diterima Oleh' => $procurement->receipt->receivedBy?->fullname ?? '—',
                            'Tanggal Diterima' => $procurement->receipt->received_at->translatedFormat('d F Y'),
                        ] as $label => $value)
                            <div class="flex items-start justify-between gap-4">
                                <dt class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="text-theme-sm text-right font-medium text-gray-800 dark:text-white/90">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($procurement->receipt->notes)
                        <p class="text-theme-xs mt-4 border-t border-gray-100 pt-4 text-gray-500 dark:border-gray-800 dark:text-gray-400">
                            {{ $procurement->receipt->notes }}
                        </p>
                    @endif

                    @if ($procurement->diterimaSebagian())
                        <p class="text-theme-xs bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400 mt-4 rounded-lg p-3">
                            Sebagian barang diterima kurang dari pesanan.
                        </p>
                    @endif

                    {{-- Dicetak untuk ditandatangani, lalu dipindai dan
                         diunggah kembali sebagai berkas BAST. Nomornya diisi
                         saat cetak pertama oleh salah satu penanda tangannya,
                         dan boleh dikoreksi sampai berkas bertanda tangan
                         diunggah (ADR 0042). --}}
                    @php
                        $kelasCetak = 'mt-4 flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]';
                    @endphp

                    @if ($procurement->receipt->bast_number !== null)
                        <a href="{{ route('procurements.bast', $procurement) }}" target="_blank" rel="noopener"
                            class="{{ $kelasCetak }}">
                            <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                <path d="M6 2.5A1.5 1.5 0 004.5 4v2h11V4A1.5 1.5 0 0014 2.5H6zM3 7.5A1.5 1.5 0 001.5 9v4A1.5 1.5 0 003 14.5h1v2A1.5 1.5 0 005.5 18h9a1.5 1.5 0 001.5-1.5v-2h1a1.5 1.5 0 001.5-1.5V9A1.5 1.5 0 0017 7.5H3zm2.5 6h9v3h-9v-3z" />
                            </svg>
                            Cetak BAST
                        </a>

                        @if ($procurement->nomorBastDapatDiubah())
                            @can('isi-nomor-bast')
                                <button type="button" @click="nomorBastTerbuka = true"
                                    class="text-theme-sm text-brand-500 hover:text-brand-600 dark:text-brand-400 mt-3 w-full text-center font-medium">
                                    Ubah Nomor BAST
                                </button>
                            @endcan
                        @endif
                    @else
                        @can('isi-nomor-bast')
                            <button type="button" @click="nomorBastTerbuka = true" class="{{ $kelasCetak }}">
                                <svg class="size-4 fill-current" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M6 2.5A1.5 1.5 0 004.5 4v2h11V4A1.5 1.5 0 0014 2.5H6zM3 7.5A1.5 1.5 0 001.5 9v4A1.5 1.5 0 003 14.5h1v2A1.5 1.5 0 005.5 18h9a1.5 1.5 0 001.5-1.5v-2h1a1.5 1.5 0 001.5-1.5V9A1.5 1.5 0 0017 7.5H3zm2.5 6h9v3h-9v-3z" />
                                </svg>
                                Cetak BAST
                            </button>
                        @else
                            <p class="text-theme-xs mt-4 text-gray-500 dark:text-gray-400">
                                BAST dapat dicetak setelah nomornya diisi.
                            </p>
                        @endcan
                    @endif
                </div>
            @endif
        </div>

        {{-- Rincian barang --}}
        <div class="col-span-12 space-y-4 md:space-y-6 xl:col-span-8">
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-5 py-5 sm:px-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Rincian Barang</h3>
                </div>

                <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                    <table class="w-full min-w-[720px]">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach (['Barang', 'Dipesan', 'Harga Satuan', 'Subtotal', 'Diterima'] as $heading)
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
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            Rp{{ number_format($detail->unit_price, 0, ',', '.') }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            Rp{{ number_format($detail->subtotal(), 0, ',', '.') }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            @if (! $detail->sudahDiterima())
                                                Belum diterima
                                            @else
                                                {{ number_format($detail->amount_received, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                                @if ($detail->kurangKirim() > 0)
                                                    <span class="text-warning-600 dark:text-warning-400 block">
                                                        kurang {{ number_format($detail->kurangKirim(), 0, ',', '.') }}
                                                    </span>
                                                @endif
                                            @endif
                                        </p>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Berkas pendukung. Tidak ada tautan langsung ke berkasnya:
                 disknya privat, unduhan lewat rute terjaga (ADR 0018). --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-5 sm:px-6">
                    <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Berkas Pendukung</h3>

                    @if ($procurement->isDiterima() && ! $procurement->bastSudahDiunggah())
                        <span class="text-theme-xs bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400 rounded-full px-2.5 py-1 font-medium">
                            BAST belum diunggah
                        </span>
                    @endif
                </div>

                <div class="border-t border-gray-100 dark:border-gray-800">
                    @forelse ($procurement->documents as $document)
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 last:border-b-0 sm:px-6 dark:border-gray-800">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-theme-xs inline-block rounded-full px-2.5 py-1 font-medium {{ $document->type->badgeClass() }}">
                                        {{ $document->type->label() }}
                                    </span>
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ $document->original_name }}
                                    </span>
                                </div>
                                <p class="text-theme-xs mt-1 text-gray-500 dark:text-gray-400">
                                    {{ $document->ukuranTerbaca() }} &middot; diunggah
                                    {{ $document->uploadedBy?->fullname ?? 'pengguna yang sudah tidak aktif' }},
                                    {{ $document->created_at->translatedFormat('d M Y H:i') }}
                                </p>
                                @if ($document->notes)
                                    <p class="text-theme-xs mt-1 text-gray-500 dark:text-gray-400">{{ $document->notes }}</p>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                {{-- Foto dan PDF dibuka di tab baru; tetap tercatat
                                     di log aktivitas seperti unduhan (ADR 0035). --}}
                                @if ($document->dapatDitampilkan())
                                    <a href="{{ route('procurement-documents.show', [$procurement, $document]) }}" target="_blank" rel="noopener"
                                        class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                        Lihat
                                    </a>
                                @endif

                                <a href="{{ route('procurement-documents.download', [$procurement, $document]) }}"
                                    class="text-theme-xs rounded-lg px-3 py-1.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Unduh
                                </a>

                                @if ($document->uploaded_by === auth()->user()->user_id || auth()->user()->isAdmin())
                                    <form method="POST"
                                        action="{{ route('procurement-documents.destroy', [$procurement, $document]) }}"
                                        onsubmit="return confirm('Hapus berkas {{ $document->original_name }}? Berkas ikut terhapus dari penyimpanan.')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                            class="text-theme-xs text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition">
                                            Hapus
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-theme-sm px-5 py-6 text-center text-gray-500 sm:px-6 dark:text-gray-400">
                            Belum ada berkas yang diunggah untuk pengadaan ini.
                        </p>
                    @endforelse
                </div>

                {{-- Kasubbag membaca berkasnya, tetapi tidak melampirkan bukti ke
                     realisasi yang ia nilai sendiri (ADR 0041). --}}
                @can('unggah-berkas-pengadaan')
                <form method="POST" action="{{ route('procurement-documents.store', $procurement) }}"
                    enctype="multipart/form-data"
                    class="grid grid-cols-1 gap-4 border-t border-gray-100 p-5 sm:grid-cols-2 dark:border-gray-800 sm:p-6">
                    @csrf

                    <x-form.select-field name="type" label="Jenis Dokumen" required
                        :options="\App\Enums\ProcurementDocumentType::options()" placeholder="Pilih jenis dokumen" />

                    <div>
                        <label for="file" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Berkas<span class="text-error-500">*</span>
                        </label>
                        <input type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif" required
                            class="focus:border-brand-300 focus:ring-brand-500/10 file:bg-brand-50 file:text-brand-600 dark:file:bg-brand-500/15 dark:file:text-brand-400 h-11 w-full rounded-lg border border-gray-300 bg-transparent text-sm text-gray-800 file:mr-4 file:h-full file:cursor-pointer file:rounded-l-lg file:border-0 file:px-4 file:text-sm file:font-medium focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90" />
                        @error('file')
                            <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <x-form.text-field name="notes" label="Keterangan"
                            placeholder="Mis. hasil pindai BAST bertanda tangan lengkap" />
                    </div>

                    <div class="sm:col-span-2 flex justify-end">
                        <button type="submit"
                            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                            Unggah Berkas
                        </button>
                    </div>
                </form>
                @endcan
            </div>
        </div>

        {{-- Konfirmasi penolakan. Alasannya teks bebas, bukan enum baku seperti
             pembatalan: daftar alasan penolakan yang sebenarnya dipakai di
             lapangan belum pernah ditanyakan (ADR 0030). --}}
        @if ($procurement->isMenungguPersetujuan())
            @can('setujui-pengadaan')
                <div x-show="tolakTerbuka" x-cloak @keydown.escape.window="tolakTerbuka = false"
                    class="fixed inset-0 z-99999 flex items-center justify-center p-5">
                    <div @click="tolakTerbuka = false" class="fixed inset-0 bg-gray-900/50"></div>

                    <div @click.stop class="relative w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-gray-900">
                        <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                            Tolak {{ $procurement->judul() }}?
                        </h3>
                        <form method="POST" action="{{ route('procurements.reject', $procurement) }}" class="mt-5">
                            @csrf
                            @method('PATCH')

                            <div>
                                <label for="review_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Alasan Penolakan<span class="text-error-500">*</span>
                                </label>
                                <textarea id="review_note" name="review_note" rows="3" required
                                    placeholder="Mis. harga satuan kertas A4 di atas harga pasar, mintakan penawaran pembanding dari penyedia lain"
                                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('review_note') }}</textarea>
                                @error('review_note')
                                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="mt-6 flex justify-end gap-3">
                                <button type="button" @click="tolakTerbuka = false"
                                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Tutup
                                </button>
                                <button type="submit"
                                    class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                    Ya, tolak
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan
        @endif

        {{-- Tandai sudah dibelanjakan. Foto wajib minimal satu, tetapi foto yang
             sudah diunggah lewat Berkas Pendukung ikut dihitung (ADR 0035). --}}
        @if ($procurement->isProsesBelanja())
            @can('kelola-pengadaan')
                @php
                    $fotoAda = $procurement->documents->where('type', \App\Enums\ProcurementDocumentType::FotoBelanja)->count();
                @endphp

                <div x-show="belanjaTerbuka" x-cloak @keydown.escape.window="belanjaTerbuka = false"
                    class="fixed inset-0 z-99999 flex items-center justify-center p-5">
                    <div @click="belanjaTerbuka = false" class="fixed inset-0 bg-gray-900/50"></div>

                    <div @click.stop class="relative w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-gray-900">
                        <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                            Tandai {{ $procurement->judul() }} sudah dibelanjakan?
                        </h3>
                        <form method="POST" action="{{ route('procurements.purchase', $procurement) }}"
                            enctype="multipart/form-data" class="mt-5 space-y-4">
                            @csrf
                            @method('PATCH')

                            <x-form.text-field name="purchased_at" label="Tanggal Belanja" type="date"
                                :value="now()->toDateString()" required />

                            <div>
                                <label for="photos" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{-- Bukan `Belanja@if`: Blade tidak mengenali direktif
                                         yang menempel pada huruf, sehingga `@endif`-nya yatim. --}}
                                    Foto Belanja{!! $fotoAda === 0 ? '<span class="text-error-500">*</span>' : '' !!}
                                </label>
                                <input type="file" id="photos" name="photos[]" multiple
                                    accept="image/jpeg,image/png,image/webp,.heic,.heif"
                                    @if ($fotoAda === 0) required @endif
                                    class="focus:border-brand-300 focus:ring-brand-500/10 file:bg-brand-50 file:text-brand-600 dark:file:bg-brand-500/15 dark:file:text-brand-400 h-11 w-full rounded-lg border border-gray-300 bg-transparent text-sm text-gray-800 file:mr-4 file:h-full file:cursor-pointer file:rounded-l-lg file:border-0 file:px-4 file:text-sm file:font-medium focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90" />
                                @if ($errors->has('photos') || $errors->has('photos.*'))
                                    <p class="text-theme-xs text-error-500 mt-1.5">
                                        {{ $errors->first('photos') ?: $errors->first('photos.*') }}
                                    </p>
                                @elseif ($fotoAda > 0)
                                    <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">
                                        Sudah ada {{ $fotoAda }} foto belanja.
                                    </p>
                                @endif
                            </div>

                            <div>
                                <label for="purchase_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Catatan
                                </label>
                                <textarea id="purchase_note" name="purchase_note" rows="3"
                                    placeholder="Mis. dibeli di Toko Sinar Jaya, dua rim kertas dibawa langsung, sisanya dikirim besok"
                                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('purchase_note') }}</textarea>
                                @error('purchase_note')
                                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="belanjaTerbuka = false"
                                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Tutup
                                </button>
                                <button type="submit"
                                    class="bg-brand-500 hover:bg-brand-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                    Ya, sudah dibelanjakan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan
        @endif

        {{-- Nomor BAST: diisi saat cetak pertama, lalu langsung dicetak. Tidak
             ada saran nomor — nomornya dari agenda kantor, dan saran yang
             keliru justru dipercaya petugas (ADR 0042). --}}
        @if ($procurement->isDiterima() && $procurement->nomorBastDapatDiubah())
            @can('isi-nomor-bast')
                <div x-show="nomorBastTerbuka" x-cloak @keydown.escape.window="nomorBastTerbuka = false"
                    class="fixed inset-0 z-99999 flex items-center justify-center p-5">
                    <div @click="nomorBastTerbuka = false" class="fixed inset-0 bg-gray-900/50"></div>

                    <div @click.stop class="relative w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-gray-900">
                        <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                            Cetak BAST {{ $procurement->judul() }}
                        </h3>
                        <form method="POST" action="{{ route('procurements.bast-number', $procurement) }}"
                            target="_blank" class="mt-5"
                            @submit="nomorBastTerbuka = false; muatUlangSaatKembali()">
                            @csrf
                            @method('PATCH')

                            <x-form.text-field name="bast_number" label="Nomor BAST" required
                                :value="$procurement->receipt->bast_number"
                                placeholder="mis. 001/UP_TUP/445237/{{ $procurement->receipt->received_at->year }}" />

                            <div class="mt-6 flex justify-end gap-3">
                                <button type="button" @click="nomorBastTerbuka = false"
                                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Tutup
                                </button>
                                <button type="submit"
                                    class="bg-brand-500 hover:bg-brand-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                    Simpan &amp; Cetak
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan
        @endif

        {{-- Konfirmasi pembatalan. Alasan baku berpasangan keterangan wajib,
             pola yang sama dengan penyesuaian selisih opname (ADR 0011). --}}
        @if ($procurement->masihBerjalan())
            @can('kelola-pengadaan')
                <div x-show="batalTerbuka" x-cloak @keydown.escape.window="batalTerbuka = false"
                    class="fixed inset-0 z-99999 flex items-center justify-center p-5">
                    <div @click="batalTerbuka = false" class="fixed inset-0 bg-gray-900/50"></div>

                    <div @click.stop class="relative w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-gray-900">
                        <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                            Batalkan {{ $procurement->judul() }}?
                        </h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                            Pembatalan tidak dapat diurungkan.
                            @unless ($procurement->tanpaUsulan())
                                <strong class="font-medium text-gray-700 dark:text-gray-300">Usulan asalnya tidak dapat direalisasikan ulang.</strong>
                            @endunless
                        </p>

                        <form method="POST" action="{{ route('procurements.cancel', $procurement) }}" class="mt-5">
                            @csrf
                            @method('PATCH')

                            <x-form.select-field name="cancel_reason" label="Alasan Pembatalan" required
                                :options="\App\Enums\AlasanPembatalan::options()" placeholder="Pilih alasan" />

                            <div class="mt-4">
                                <label for="cancel_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    Keterangan<span class="text-error-500">*</span>
                                </label>
                                <textarea id="cancel_note" name="cancel_note" rows="3" required
                                    placeholder="Mis. penyedia menyatakan stok kosong dan tidak sanggup mengirim sampai akhir bulan"
                                    class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('cancel_note') }}</textarea>
                                @error('cancel_note')
                                    <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="mt-6 flex justify-end gap-3">
                                <button type="button" @click="batalTerbuka = false"
                                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                    Tutup
                                </button>
                                <button type="submit"
                                    class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                                    Ya, batalkan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endcan
        @endif
    </div>
@endsection
