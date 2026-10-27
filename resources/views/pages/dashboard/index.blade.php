@extends('layouts.app')

@php
    // Kelas warna ditulis utuh, bukan dirangkai dari nama warna, supaya
    // terbaca pemindai Tailwind. Kunci = `warna` dari DashboardController.
    $chipUbin = [
        'sky' => 'bg-sky-500',
        'brand' => 'bg-brand-500',
        'success' => 'bg-success-500',
        'orange' => 'bg-orange-500',
        'purple' => 'bg-purple-500',
        'amber' => 'bg-amber-500',
        'pink' => 'bg-pink-500',
        'teal' => 'bg-teal-500',
        'rose' => 'bg-rose-500',
        'gray' => 'bg-gray-500 dark:bg-gray-600',
    ];

    $chipTugas = [
        'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
        'purple' => 'bg-purple-50 text-purple-600 dark:bg-purple-500/15 dark:text-purple-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        'pink' => 'bg-pink-50 text-pink-600 dark:bg-pink-500/15 dark:text-pink-400',
        'orange' => 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-400',
        'error' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
    ];

    // Isi bilah memakai warna status; lintasannya tingkat yang lebih terang
    // dari warna yang sama, sehingga barang habis (isi nol) tetap terbaca merah.
    $warnaStok = [
        'habis' => ['isi' => 'bg-error-500', 'lintasan' => 'bg-error-100 dark:bg-error-500/20'],
        'menipis' => ['isi' => 'bg-warning-500', 'lintasan' => 'bg-warning-100 dark:bg-warning-500/20'],
        'aman' => ['isi' => 'bg-success-500', 'lintasan' => 'bg-success-100 dark:bg-success-500/20'],
        'berlebih' => ['isi' => 'bg-brand-500', 'lintasan' => 'bg-brand-100 dark:bg-brand-500/20'],
    ];

    $kartu = 'rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]';
    $judulKartu = 'text-lg font-semibold text-gray-800 dark:text-white/90';
    $rupiah = fn (int $nilai) => 'Rp'.number_format($nilai, 0, ',', '.');
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">
                {{ $salam }}, {{ $user->fullname }}
            </h2>
            <span class="text-theme-xs mt-2 inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $user->role->badgeClass() }}">
                {{ $user->role->label() }}
            </span>
        </div>
        <p class="text-theme-sm text-gray-500 dark:text-gray-400">{{ now()->translatedFormat('l, j F Y') }}</p>
    </div>

    {{-- Akses cepat --}}
    <nav aria-label="Akses cepat" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-[repeat(auto-fill,minmax(12rem,1fr))] md:gap-4">
        @foreach ($aksesCepat as $ubin)
            <a href="{{ $ubin['url'] }}"
                class="hover:shadow-theme-sm flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-3 transition hover:border-gray-300 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700 dark:hover:bg-white/[0.05]">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl text-white {{ $chipUbin[$ubin['warna']] }}">
                    <x-ui.icon :name="$ubin['ikon']" class="size-5" />
                </span>
                <span class="text-theme-sm leading-snug font-medium text-gray-800 dark:text-white/90">{{ $ubin['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
        {{-- Perlu ditindaklanjuti --}}
        <section @class([$kartu, 'xl:col-span-2' => $stok !== null, 'xl:col-span-5' => $stok === null])>
            <h3 class="{{ $judulKartu }}">Perlu Ditindaklanjuti</h3>

            @if ($tugas === [])
                <div class="flex flex-col items-center py-10 text-center">
                    <span class="bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500 flex size-12 items-center justify-center rounded-full">
                        <x-ui.icon name="check-circle" class="size-6" />
                    </span>
                    <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Tidak ada yang perlu ditindaklanjuti.</p>
                </div>
            @else
                <ul class="-mx-2 mt-4 space-y-1">
                    @foreach ($tugas as $baris)
                        <li>
                            <a href="{{ $baris['url'] }}"
                                class="group flex items-center gap-3 rounded-xl px-2 py-2.5 transition hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg {{ $chipTugas[$baris['warna']] }}">
                                    <x-ui.icon :name="$baris['ikon']" class="size-[18px]" />
                                </span>
                                <span class="text-theme-sm flex-1 text-gray-700 dark:text-gray-300">{{ $baris['label'] }}</span>
                                <span class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $baris['jumlah'] }}</span>
                                <x-ui.icon name="chevron-right" class="size-4 text-gray-400 transition group-hover:translate-x-0.5" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Kondisi stok --}}
        @if ($stok !== null)
            <section class="{{ $kartu }} xl:col-span-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="{{ $judulKartu }}">Kondisi Stok</h3>
                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $stok['total'] }} barang aktif</span>
                </div>

                @if ($stok['total'] > 0)
                    <div class="mt-5 flex h-3 w-full gap-0.5 overflow-hidden rounded-full" role="img"
                        aria-label="{{ collect($stok['sebaran'])->map(fn ($s) => $s['status']->label().' '.$s['jumlah'])->join(', ') }}">
                        @foreach ($stok['sebaran'] as $sebaran)
                            @if ($sebaran['jumlah'] > 0)
                                <div class="h-full min-w-1 {{ $warnaStok[$sebaran['status']->value]['isi'] }}" style="width: {{ $sebaran['persen'] }}%"></div>
                            @endif
                        @endforeach
                    </div>
                @endif

                <ul class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($stok['sebaran'] as $sebaran)
                        @php $tag = $sebaran['url'] ? 'a' : 'div'; @endphp
                        <li>
                            <{{ $tag }} @if ($sebaran['url']) href="{{ $sebaran['url'] }}" @endif
                                @class(['block rounded-lg px-2 py-1.5', 'transition hover:bg-gray-50 dark:hover:bg-white/[0.03]' => $sebaran['url']])>
                                <span class="text-theme-sm flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                    <span class="size-2.5 rounded-full {{ $warnaStok[$sebaran['status']->value]['isi'] }}"></span>
                                    {{ $sebaran['status']->label() }}
                                </span>
                                <span class="mt-0.5 block text-xl font-semibold text-gray-800 dark:text-white/90">{{ $sebaran['jumlah'] }}</span>
                            </{{ $tag }}>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-5 border-t border-gray-100 pt-5 dark:border-gray-800">
                    <h4 class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Perlu diisi ulang</h4>

                    @if ($stok['kritis']->isEmpty())
                        <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Semua stok di atas minimum.</p>
                    @else
                        <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($stok['kritis'] as $item)
                                @php
                                    $warna = $warnaStok[$item->stockStatus()->value];
                                    $persen = $item->stock_min > 0 ? min(100, $item->stock_current / $item->stock_min * 100) : 0;
                                    $tag = $stok['bertaut'] ? 'a' : 'div';
                                @endphp
                                <li>
                                    <{{ $tag }} @if ($stok['bertaut']) href="{{ route('items.show', $item) }}" @endif
                                        @class([
                                            'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 py-3 sm:grid-cols-[minmax(0,1fr)_9rem_6.5rem]',
                                            '-mx-2 rounded-lg px-2 transition hover:bg-gray-50 dark:hover:bg-white/[0.03]' => $stok['bertaut'],
                                        ])>
                                        <span class="min-w-0">
                                            <span class="text-theme-sm block truncate font-medium text-gray-800 dark:text-white/90">{{ $item->item_name }}</span>
                                            <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $item->item_code }}</span>
                                        </span>
                                        <span class="order-last col-span-2 block h-1.5 overflow-hidden rounded-full sm:order-none sm:col-span-1 {{ $warna['lintasan'] }}">
                                            <span class="block h-full rounded-full {{ $warna['isi'] }}" style="width: {{ $persen }}%"></span>
                                        </span>
                                        <span class="text-theme-sm text-right whitespace-nowrap text-gray-700 tabular-nums dark:text-gray-300">
                                            {{ $item->stock_current }} / {{ $item->stock_min }}
                                            <span class="text-gray-500 dark:text-gray-400">{{ $item->unit->label() }}</span>
                                        </span>
                                    </{{ $tag }}>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endif
    </div>

    {{-- Alur permintaan: cakupannya mengikuti daftar permintaan, jadi
         Pengguna hanya melihat miliknya dan Kepala Unit hanya unitnya. --}}
    @include('pages.dashboard._alur', ['judul' => 'Alur Permintaan', 'tahapan' => $alurPermintaan])

    {{-- Alur pengadaan --}}
    @if ($alur !== null)
        @include('pages.dashboard._alur', ['judul' => 'Alur Pengadaan', 'tahapan' => $alur])
    @endif

    {{-- Grafik --}}
    @if ($grafikMutasi !== null || $grafikNilai !== null)
        <div @class(['mt-6 grid grid-cols-1 gap-6', 'xl:grid-cols-2' => $grafikMutasi !== null && $grafikNilai !== null])>
            @if ($grafikMutasi !== null)
                <section class="{{ $kartu }}">
                    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                        <div>
                            <h3 class="{{ $judulKartu }}">Mutasi Stok</h3>
                            <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">{{ $grafikMutasi['total'] }} transaksi, 6 bulan terakhir</p>
                        </div>
                        <ul class="text-theme-sm flex items-center gap-4 text-gray-600 dark:text-gray-300">
                            <li class="flex items-center gap-1.5"><span class="bg-brand-500 size-2.5 rounded-sm"></span>Masuk</li>
                            <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-[#eb6834] dark:bg-[#d95926]"></span>Keluar</li>
                        </ul>
                    </div>

                    @if ($grafikMutasi['total'] === 0)
                        <p class="text-theme-sm py-20 text-center text-gray-500 dark:text-gray-400">Belum ada mutasi.</p>
                    @else
                        <div class="-ml-3 mt-2" data-grafik-dasbor="mutasi" data-grafik="{{ json_encode($grafikMutasi) }}"></div>
                        <table class="sr-only">
                            <caption>Jumlah transaksi mutasi stok per bulan</caption>
                            <thead><tr><th scope="col">Bulan</th><th scope="col">Masuk</th><th scope="col">Keluar</th></tr></thead>
                            <tbody>
                                @foreach ($grafikMutasi['bulanPanjang'] as $i => $bulan)
                                    <tr><th scope="row">{{ $bulan }}</th><td>{{ $grafikMutasi['masuk'][$i] }}</td><td>{{ $grafikMutasi['keluar'][$i] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            @endif

            @if ($grafikNilai !== null)
                <section class="{{ $kartu }}">
                    <h3 class="{{ $judulKartu }}">Nilai Pengadaan Diterima</h3>
                    <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">{{ $rupiah($grafikNilai['total']) }}, 6 bulan terakhir</p>

                    @if ($grafikNilai['total'] === 0)
                        <p class="text-theme-sm py-20 text-center text-gray-500 dark:text-gray-400">Belum ada barang diterima.</p>
                    @else
                        <div class="-ml-3 mt-2" data-grafik-dasbor="nilai" data-grafik="{{ json_encode($grafikNilai) }}"></div>
                        <table class="sr-only">
                            <caption>Nilai pengadaan diterima per bulan</caption>
                            <thead><tr><th scope="col">Bulan</th><th scope="col">Nilai</th></tr></thead>
                            <tbody>
                                @foreach ($grafikNilai['bulanPanjang'] as $i => $bulan)
                                    <tr><th scope="row">{{ $bulan }}</th><td>{{ $rupiah($grafikNilai['nilai'][$i]) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
            @endif
        </div>
    @endif
@endsection
