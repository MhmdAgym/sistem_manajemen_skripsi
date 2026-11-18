<?php

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatHistoryTransferRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'source_device_uuid'             => ['required', 'string', 'uuid'],
            'key_exchange_algorithm'         => ['sometimes', 'string', 'max:32'],
            'recipient_ephemeral_public_key' => ['required', 'string'],
            'transfer_nonce'                 => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/i'],
            'request_signature'              => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_device_uuid.required'             => 'UUID perangkat sumber wajib diisi.',
            'source_device_uuid.uuid'                 => 'Format UUID perangkat sumber tidak valid.',
            'recipient_ephemeral_public_key.required' => 'Kunci publik ephemeral penerima wajib disertakan.',
            'transfer_nonce.required'                 => 'Transfer nonce wajib disertakan.',
            'transfer_nonce.size'                     => 'Transfer nonce wajib tepat 64 karakter heksadesimal (32 byte).',
            'request_signature.required'              => 'Tanda tangan digital Ed25519 permintaan transfer wajib disertakan.',
        ];
    }
}

