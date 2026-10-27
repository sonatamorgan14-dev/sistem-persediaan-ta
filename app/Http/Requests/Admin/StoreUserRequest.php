<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
        return [
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:'.UserFormRules::USERNAME_REGEX, Rule::unique('users', 'username')],
            'fullname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'unit' => ['required', 'string', 'max:255'],
            'nip' => ['nullable', 'digits:18'],
            'position' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
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
