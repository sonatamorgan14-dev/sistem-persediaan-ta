<?php

namespace App\Http\Requests\Pengadaan;

use App\Models\Procurement;
use App\Models\ProcurementDetail;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Konfirmasi penerimaan barang pengadaan oleh Pengelola Gudang.
 *
 * `received.*` tidak dibatasi maksimum terhadap jumlah dipesan — penyedia
 * yang mengirim lebih banyak dari pesanan bukan kasus yang dicegah ADR 0017,
 * yang mengatur kekurangan kiriman. Hanya kiriman kurang yang jadi perhatian
 * di sana, dan itu sudah tertangkap lewat `ProcurementDetail::kurangKirim()`.
 *
 * Sebaliknya, **kelengkapan barisnya wajib**: setiap rincian realisasi harus
 * punya angka. Alasannya sama persis dengan alasan ADR 0021 mewajibkan
 * formulir realisasi mencakup seluruh rincian usulan yang disetujui —
 * penerimaan adalah peristiwa sekali jadi tanpa rute ubah, sehingga baris yang
 * hilang dari kiriman akan terbaca sebagai "nol diterima" secara permanen.
 *
 * Nomor BAST tidak diminta di sini: nomornya diisi saat BAST dicetak pertama
 * kali, lewat `UpdateBastNumberRequest` (ADR 0042).
 */
class ConfirmProcurementReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kelola-gudang') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'received_at' => ['required', 'date', 'before_or_equal:today'],
            'received' => ['required', 'array', 'min:1'],
            'received.*' => ['required', 'integer', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $procurement = $this->route('procurement');

            if (! $procurement instanceof Procurement) {
                return;
            }

            $dikirim = array_keys((array) $this->input('received', []));

            $hilang = $procurement->details
                ->reject(fn (ProcurementDetail $detail) => in_array($detail->procurement_detail_id, $dikirim, true));

            if ($hilang->isEmpty()) {
                return;
            }

            $validator->errors()->add('received', sprintf(
                'Jumlah diterima wajib diisi untuk seluruh barang. Belum terisi: %s.',
                $hilang->map(fn (ProcurementDetail $detail) => $detail->item?->item_name ?? 'barang tidak dikenal')
                    ->implode(', '),
            ));
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'received_at' => 'tanggal penerimaan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'received_at.before_or_equal' => 'Tanggal penerimaan tidak boleh melebihi hari ini.',
            'received.required' => 'Isi jumlah diterima untuk setiap barang.',
        ];
    }
}
