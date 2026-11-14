<?php

declare(strict_types=1);

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BchatSendMessageRequest extends FormRequest
{
    /**
     * Otorisasi didelegasikan ke Controller via BchatMessagePolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi payload pengiriman ciphertext pesan E2EE.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message_uuid' => [
                'required',
                'string',
                'size:36',
                'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            ],
            'epoch' => [
                'required',
                'integer',
                'min:1',
            ],
            'generation' => [
                'required',
                'integer',
                'min:0',
            ],
            'content_type' => [
                'required',
                'integer',
                'in:1,2,3',
            ],
            'ciphertext_payload' => [
                'required',
                'string',
            ],
            'payload_hash_sha256' => [
                'required',
                'string',
                'size:64',
                'regex:/^[0-9a-f]{64}$/i',
            ],
            'client_sent_at' => [
                'required',
                'date',
            ],
        ];
    }

    /**
     * Validasi lanjutan: decoding Base64 ketat dan verifikasi hash SHA-256 fisik payload.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $ciphertextBase64 = (string) $this->input('ciphertext_payload');
                $decoded = base64_decode($ciphertextBase64, true);

                if ($decoded === false || strlen($decoded) === 0) {
                    $validator->errors()->add(
                        'ciphertext_payload',
                        'Payload ciphertext bukan string Base64 yang valid atau kosong.'
                    );
                    return;
                }

                $expectedHash = strtolower((string) $this->input('payload_hash_sha256'));
                $actualHash = hash('sha256', $decoded);

                if (!hash_equals($expectedHash, $actualHash)) {
                    $validator->errors()->add(
                        'payload_hash_sha256',
                        'Hash SHA-256 tidak cocok dengan konten payload ciphertext (integritas gagal).'
                    );
                }
            },
        ];
    }

    /**
     * Kustomisasi pesan error validasi bahasa Indonesia.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message_uuid.required' => 'Message UUID wajib diisi.',
            'message_uuid.regex'    => 'Format Message UUID wajib berupa UUID v4 kanonis.',
            'epoch.required'        => 'Epoch wajib diisi.',
            'epoch.min'             => 'Epoch minimal bernilai 1.',
            'generation.required'   => 'Generation ratchet wajib diisi.',
            'content_type.in'       => 'Content type tidak valid (1: TEXT, 2: ATTACHMENT_MANIFEST, 3: CONTROL_RECEIPT).',
            'ciphertext_payload.required' => 'Payload ciphertext wajib diisi.',
            'payload_hash_sha256.required' => 'Payload hash SHA-256 wajib diisi.',
            'payload_hash_sha256.regex' => 'Format hash SHA-256 wajib berupa 64 karakter heksadesimal lowercase.',
            'client_sent_at.required' => 'Waktu pengiriman klien wajib diisi.',
            'client_sent_at.date'   => 'Format waktu pengiriman klien tidak valid.',
        ];
    }
}

