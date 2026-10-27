<?php

namespace App\Http\Requests\Pengadaan;

use App\Models\Procurement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nomor BAST pengadaan, diisi saat BAST dicetak pertama kali.
 *
 * Formatnya tidak divalidasi: nomornya berasal dari agenda kantor, dan
 * formatnya bergantung pada mekanisme pembayaran (mis. UP/TUP), bukan pada
 * sistem ini. Yang dijaga hanya keunikannya — dua BAST bernomor sama adalah
 * kesalahan administrasi yang nyata. Lihat ADR 0042.
 */
class UpdateBastNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('isi-nomor-bast') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $procurement = $this->route('procurement');

        return [
            'bast_number' => [
                'required', 'string', 'max:100',
                Rule::unique('procurement_receipts', 'bast_number')->ignore(
                    $procurement instanceof Procurement ? $procurement->receipt?->procurement_receipt_id : null,
                    'procurement_receipt_id',
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bast_number' => 'nomor BAST',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bast_number.required' => 'Nomor BAST wajib diisi.',
            'bast_number.unique' => 'Nomor BAST sudah dipakai penerimaan lain.',
        ];
    }
}
