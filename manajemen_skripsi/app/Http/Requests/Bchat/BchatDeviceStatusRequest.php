<?php

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatDeviceStatusRequest extends FormRequest
{
    /**
     * Otorisasi request: diproteksi oleh middleware auth:sanctum.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi untuk query status perangkat B-CHAT E2EE.
     * Parameter device_uuid bersifat opsional untuk rekonsiliasi staged key.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'device_uuid.uuid' => 'Format Device UUID harus berupa UUID v4 yang valid.',
        ];
    }
}

