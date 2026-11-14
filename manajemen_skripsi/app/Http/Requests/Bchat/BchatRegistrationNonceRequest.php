<?php

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatRegistrationNonceRequest extends FormRequest
{
    /**
     * Otorisasi request: diproteksi oleh middleware auth:sanctum.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi untuk request penerbitan registration nonce.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'step_up_token' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'device_uuid'   => ['required', 'string', 'uuid'],
            'public_key'    => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'step_up_token.required' => 'Step-up token wajib disertakan.',
            'step_up_token.regex'    => 'Format step-up token harus berupa 64 karakter heksadesimal.',
            'device_uuid.required'   => 'Device UUID wajib disertakan.',
            'device_uuid.uuid'       => 'Format Device UUID harus berupa UUID v4 yang valid.',
            'public_key.required'    => 'Public key perangkat wajib disertakan.',
            'public_key.regex'       => 'Format public key harus berupa 64 karakter heksadesimal (32 byte Ed25519).',
        ];
    }
}

