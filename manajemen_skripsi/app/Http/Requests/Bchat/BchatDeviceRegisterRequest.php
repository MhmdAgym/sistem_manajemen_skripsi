<?php

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatDeviceRegisterRequest extends FormRequest
{
    /**
     * Otorisasi request: diproteksi oleh middleware auth:sanctum.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi untuk request registrasi perangkat B-CHAT E2EE.
     *
     * Field tambahan v2 (Replacement Protection):
     * - confirm_replacement: boolean wajib true apabila pengguna bermaksud mengganti perangkat aktif.
     * - expected_active_device_uuid: UUID perangkat aktif yang diharapkan untuk diganti (diperlukan
     *   apabila confirm_replacement = true agar backend dapat memvalidasi kecocokan saat ini).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nonce_uuid'                  => ['required', 'string', 'uuid'],
            'pop_payload'                 => ['required', 'string', 'regex:/^[0-9a-f]{242}$/'],
            'pop_signature'               => ['required', 'string', 'regex:/^[0-9a-f]{128}$/'],
            'device_name'                 => ['nullable', 'string', 'max:100'],
            // Replacement confirmation fields (opsional pada registrasi pertama)
            'confirm_replacement'         => ['nullable', 'boolean'],
            'expected_active_device_uuid' => ['nullable', 'string', 'uuid'],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'nonce_uuid.required'                  => 'Nonce UUID wajib disertakan.',
            'nonce_uuid.uuid'                      => 'Format Nonce UUID harus berupa UUID v4 yang valid.',
            'pop_payload.required'                 => 'Payload Proof-of-Possession (PoP) wajib disertakan.',
            'pop_payload.regex'                    => 'Format payload PoP harus berupa 242 karakter lowercase hexadecimal (121 byte).',
            'pop_signature.required'               => 'Signature Proof-of-Possession (PoP) wajib disertakan.',
            'pop_signature.regex'                  => 'Format signature PoP harus berupa 128 karakter lowercase hexadecimal (64 byte).',
            'device_name.max'                      => 'Nama perangkat tidak boleh melebihi 100 karakter.',
            'confirm_replacement.boolean'          => 'confirm_replacement harus berupa nilai boolean.',
            'expected_active_device_uuid.uuid'     => 'Format expected_active_device_uuid harus berupa UUID v4 yang valid.',
        ];
    }
}
