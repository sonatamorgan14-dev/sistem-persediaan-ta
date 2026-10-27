<?php

namespace App\Http\Requests\Admin;

use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kelola-pengguna') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->targetUser()->user_id;

        return [
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:'.UserFormRules::USERNAME_REGEX, Rule::unique('users', 'username')->ignore($id, 'user_id')],
            'fullname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id, 'user_id')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'unit' => ['required', Rule::enum(UnitKerja::class)],
            'nip' => ['nullable', 'digits:18'],
            'position' => ['nullable', 'string', 'max:255'],
            // Kosongkan bila kata sandi tidak diubah.
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'is_active' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // NIP lazim ditulis berkelompok ("19850614 200312 1 001"); yang
        // disimpan hanya 18 digitnya.
        $nip = preg_replace('/\s+/', '', (string) $this->input('nip'));

        $this->merge([
            'username' => trim((string) $this->input('username')),
            'nip' => $nip === '' ? null : $nip,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /**
     * Admin tidak boleh mengubah peran atau menonaktifkan akunnya sendiri,
     * karena berisiko mengunci dirinya keluar dari sistem.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->targetUser()->is($this->user())) {
                return;
            }

            if ($this->input('role') !== $this->user()->role->value) {
                $validator->errors()->add('role', 'Anda tidak dapat mengubah peran akun Anda sendiri.');
            }

            if (! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
            }
        });
    }

    private function targetUser(): User
    {
        return $this->route('user');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return UserFormRules::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return UserFormRules::messages();
    }
}
