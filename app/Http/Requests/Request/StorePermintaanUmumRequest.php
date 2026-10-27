<?php

namespace App\Http\Requests\Request;

use App\Enums\UnitKerja;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePermintaanUmumRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->unit === UnitKerja::SubbagianUmumTi) || $user->isAdmin()
                || $user->hasRole(UserRole::PengelolaGudang);
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'uuid', Rule::exists('items', 'item_id')->where('is_active', true)],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = collect($this->input('items', []))->pluck('item_id');

            if ($items->duplicates()->isNotEmpty()) {
                $validator->errors()->add('items', 'Barang yang sama hanya boleh dimasukkan satu kali.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'items' => 'daftar barang',
            'items.*.item_id' => 'barang',
            'items.*.qty' => 'jumlah barang',
        ];
    }
}
