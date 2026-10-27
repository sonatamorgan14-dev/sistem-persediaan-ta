<?php

namespace App\Http\Requests\Request;

use App\Enums\UserRole;
use App\Models\Request as ModelsRequest;

class UpdatePermintaanBarangRequest extends StorePermintaanBarangRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $request = $this->route('request');

        return $user !== null
            && $request instanceof ModelsRequest
            && $request->status === 'approved_by_head'
            && $user->hasRole(UserRole::PengelolaGudang, UserRole::Admin, UserRole::KasubbagUmumTi);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'nd_sequence' => ['required', 'regex:'.ModelsRequest::ND_SEQUENCE_PATTERN],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['nd_sequence' => trim((string) $this->input('nd_sequence'))]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'nd_sequence' => 'nomor urut Nota Dinas',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'nd_sequence.required' => 'Nomor urut Nota Dinas wajib diisi.',
            'nd_sequence.regex' => 'Nomor urut Nota Dinas hanya berupa angka, paling banyak 6 digit.',
        ];
    }
}
