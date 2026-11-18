<?php

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatHistoryTransferUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'source_ephemeral_public_key' => ['required', 'string'],
            'snapshot_ciphertext'         => ['required', 'string'],
            'snapshot_hash_sha256'        => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/i'],
            'snapshot_iv'                 => ['sometimes', 'nullable', 'string', 'max:64'],
            'snapshot_auth_tag'           => ['sometimes', 'nullable', 'string', 'max:64'],
            'snapshot_metadata'           => ['sometimes', 'nullable', 'array'],
            'source_signature'            => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_ephemeral_public_key.required' => 'Kunci publik ephemeral sumber wajib disertakan.',
            'snapshot_ciphertext.required'         => 'Ciphertext snapshot riwayat wajib disertakan.',
            'snapshot_hash_sha256.required'        => 'Hash SHA-256 snapshot wajib disertakan.',
            'snapshot_hash_sha256.size'            => 'Hash SHA-256 wajib tepat 64 karakter heksadesimal.',
            'source_signature.required'            => 'Tanda tangan digital Ed25519 perangkat sumber wajib disertakan.',
        ];
    }
}

