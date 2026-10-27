{{-- Laporan pengadaan barang persediaan. Satu baris = satu barang pada satu
     realisasi, supaya laporan ini dapat dibaca sebagai daftar barang yang
     diadakan, bukan sekadar daftar dokumen. --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Laporan Pengadaan Barang Persediaan</title>
    @include('pdf._style')
</head>

<body>
    @php
        $periode = match (true) {
            filled($filters['from']) && filled($filters['to']) =>
                \Carbon\Carbon::parse($filters['from'])->translatedFormat('d F Y') . ' s.d. ' . \Carbon\Carbon::parse($filters['to'])->translatedFormat('d F Y'),
            filled($filters['from']) => 'Sejak ' . \Carbon\Carbon::parse($filters['from'])->translatedFormat('d F Y'),
            filled($filters['to']) => 'Sampai ' . \Carbon\Carbon::parse($filters['to'])->translatedFormat('d F Y'),
            default => 'Seluruh periode',
        };

        $status = \App\Enums\ProcurementStatus::tryFrom($filters['status'])?->label();

        $sumber = match ($filters['sumber'] ?? '') {
            'langsung' => 'Langsung, tanpa usulan',
            'usulan' => 'Dari usulan',
            default => null,
        };

        $baris = $procurements->sum(fn ($procurement) => $procurement->details->count());
        $nomor = 0;
    @endphp

    <div class="kop">
        <div class="lembaga">Badan Pemeriksa Keuangan Republik Indonesia</div>
        <div class="satker">Perwakilan Provinsi Lampung</div>
        <div class="judul">Laporan Pengadaan Barang Persediaan</div>
        <div class="periode">{{ $periode }}</div>
    </div>

    <table class="meta">
        @if ($status)
            <tr>
                <td class="label">Status</td>
                <td>: {{ $status }}</td>
            </tr>
        @endif
        @if ($sumber)
            <tr>
                <td class="label">Dasar Pengadaan</td>
                <td>: {{ $sumber }}</td>
            </tr>
        @endif
        @if ($namaPenyedia)
            <tr>
                <td class="label">Penyedia</td>
                <td>: {{ $namaPenyedia }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Jumlah Baris</td>
            <td>: {{ number_format($procurements->count(), 0, ',', '.') }} realisasi, {{ number_format($baris, 0, ',', '.') }} baris barang</td>
        </tr>
    </table>

    <table class="rekap">
        <tr>
            <td>
                <span class="label">Jumlah Realisasi</span>
                <span class="nilai">{{ number_format($summary['total'], 0, ',', '.') }}</span>
                @if ($summary['dibatalkan'] > 0)
                    <span class="label">{{ number_format($summary['dibatalkan'], 0, ',', '.') }} dibatalkan</span>
                @endif
                @if ($summary['ditolak'] > 0)
                    <span class="label">{{ number_format($summary['ditolak'], 0, ',', '.') }} ditolak</span>
                @endif
            </td>
            <td>
                <span class="label">Menunggu Persetujuan</span>
                <span class="nilai">{{ number_format($summary['menunggu_persetujuan'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Proses Belanja</span>
                <span class="nilai">{{ number_format($summary['proses_belanja'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Menunggu Penerimaan</span>
                <span class="nilai">{{ number_format($summary['menunggu'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Nilai Dipesan</span>
                <span class="nilai">Rp{{ number_format($summary['nilai'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Nilai Diterima</span>
                <span class="nilai masuk">Rp{{ number_format($summary['nilai_diterima'], 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 3%">No</th>
                <th style="width: 7%">Tanggal</th>
                <th style="width: 10%">Realisasi</th>
                <th style="width: 13%">Penyedia</th>
                <th style="width: 7%">Kode</th>
                <th style="width: 16%">Nama Barang</th>
                <th style="width: 6%" class="num">Pesan</th>
                <th style="width: 9%" class="num">Harga Satuan</th>
                <th style="width: 6%" class="num">Terima</th>
                <th style="width: 10%" class="num">Nilai Diterima</th>
                <th style="width: 13%">BAST</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($procurements as $procurement)
                @foreach ($procurement->details as $detail)
                    <tr>
                        <td class="center">{{ ++$nomor }}</td>
                        <td>{{ $procurement->procurement_date->format('d/m/Y') }}</td>
                        <td>
                            {{ $procurement->judul() }}
                            <br><em style="font-size: 8px; color: #667085;">{{ $procurement->status->label() }}</em>
                            <br><em style="font-size: 8px; color: #667085;">{{ $procurement->dasarRingkas() }}</em>
                        </td>
                        <td>{{ $procurement->supplier?->supplier_name ?? '-' }}</td>
                        <td>{{ $detail->item?->item_code ?? '-' }}</td>
                        <td>{{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}</td>
                        <td class="num">{{ number_format($detail->amount, 0, ',', '.') }} {{ $detail->item?->unit->label() }}</td>
                        <td class="num">Rp{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                        <td class="num {{ $detail->kurangKirim() > 0 ? 'keluar' : '' }}">
                            @if ($detail->sudahDiterima())
                                {{ number_format($detail->amount_received, 0, ',', '.') }}
                                @if ($detail->kurangKirim() > 0)
                                    <br><em style="font-size: 8px;">kurang {{ number_format($detail->kurangKirim(), 0, ',', '.') }}</em>
                                @endif
                            @else
                                &mdash;
                            @endif
                        </td>
                        <td class="num">
                            Rp{{ number_format(($detail->amount_received ?? 0) * $detail->unit_price, 0, ',', '.') }}
                        </td>
                        <td>
                            @if ($procurement->receipt)
                                {{-- Nomor BAST kosong sampai BAST dicetak (ADR 0042). --}}
                                {{ $procurement->receipt->bast_number ?? '-' }}
                                <br><em style="font-size: 8px; color: #667085;">{{ $procurement->receipt->received_at->format('d/m/Y') }}</em>
                            @else
                                &mdash;
                            @endif
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="11" class="kosong">Tidak ada pengadaan pada periode dan penyaring yang dipilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($summary['nilai_diterima'] < $summary['nilai'])
        <p style="font-size: 8.5px; color: #475467; margin-top: 8px;">
            Catatan: nilai diterima lebih kecil daripada nilai dipesan. Selisihnya berasal dari pengadaan yang
            barangnya datang kurang dari pesanan, dan dari pengadaan yang masih menunggu persetujuan maupun
            penerimaan. Stok bertambah sebanyak yang benar-benar diterima, bukan sebanyak yang dipesan.
        </p>
    @endif

    @if ($summary['dibatalkan'] > 0 || $summary['ditolak'] > 0)
        <p style="font-size: 8.5px; color: #475467; margin-top: 8px;">
            Catatan:
            {{ collect([
                $summary['dibatalkan'] > 0 ? number_format($summary['dibatalkan'], 0, ',', '.').' realisasi dibatalkan' : null,
                $summary['ditolak'] > 0 ? number_format($summary['ditolak'], 0, ',', '.').' realisasi ditolak' : null,
            ])->filter()->implode(' dan ') }}
            tetap tercantum pada tabel di atas beserta statusnya, tetapi tidak ikut dihitung pada rekap maupun
            nilai &mdash; pengadaannya tidak pernah menjadi transaksi.
        </p>
    @endif

    <table class="ttd">
        <tr>
            <td></td>
            <td class="kolom-ttd">
                Bandar Lampung, {{ now()->translatedFormat('d F Y') }}<br>
                Pejabat Pengadaan Barang/Jasa
                <div class="ruang"></div>
                <strong>{{ $dicetakOleh }}</strong>
            </td>
        </tr>
    </table>

    <div class="footer">
        Sistem Informasi Persediaan &mdash; BPK Perwakilan Provinsi Lampung
        <span class="kanan">
            Dicetak {{ now()->translatedFormat('d F Y H:i') }} oleh {{ $dicetakOleh }}
        </span>
    </div>
</body>

</html>
