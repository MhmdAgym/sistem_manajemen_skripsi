<?php

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatStepUpRequest extends FormRequest
{
    /**
     * Otorisasi request: diproteksi oleh middleware auth:sanctum.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi untuk request step-up password re-auth.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password'    => ['required', 'string'],
            'device_uuid' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'password.required'    => 'Password akun wajib diisi untuk re-autentikasi.',
            'device_uuid.required' => 'Device UUID wajib diisi.',
            'device_uuid.uuid'     => 'Format Device UUID harus berupa UUID v4 yang valid.',
        ];
    }
}

