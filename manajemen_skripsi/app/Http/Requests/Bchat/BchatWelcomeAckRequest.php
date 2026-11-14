<?php

declare(strict_types=1);

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatWelcomeAckRequest extends FormRequest
{
    /**
     * Otorisasi request: diproteksi oleh middleware auth:sanctum dan policy di controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi input untuk Welcome ACK.
     *
     * Zero Trust:
     * - Klien HANYA mengirim challenge_uuid dan signature digital detached Ed25519.
     * - Klien DILARANG mengirim payload, public_key, user_id, epoch, atau identitas lain.
     * - Signature wajib berupa Base64 RFC 4648 standar dengan encoding kanonis (tepat 64 byte saat didekode).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'challenge_uuid' => ['required', 'string', 'uuid'],
            'signature'      => [
                'required',
                'string',
                'regex:/^[A-Za-z0-9+\/]{86}==$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_string($value)) {
                        $fail('Signature harus berupa string.');
                        return;
                    }

                    // Strict decoding Base64 RFC 4648
                    $decoded = base64_decode($value, true);
                    if ($decoded === false) {
                        $fail('Format Base64 signature tidak valid.');
                        return;
                    }

                    // Enforce decoding length strictly 64 bytes
                    if (strlen($decoded) !== 64) {
                        $fail('Panjang binary signature setelah didekode harus tepat 64 byte.');
                        return;
                    }

                    // Enforce canonical Base64 encoding (mencegah non-canonical padding / bit sisa)
                    if (base64_encode($decoded) !== $value) {
                        $fail('Representasi Base64 signature tidak kanonis.');
                        return;
                    }
                },
            ],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'challenge_uuid.required' => 'Challenge UUID wajib disertakan.',
            'challenge_uuid.uuid'     => 'Format Challenge UUID harus berupa UUID v4 yang valid.',
            'signature.required'      => 'Tanda tangan digital Welcome ACK (signature) wajib disertakan.',
            'signature.regex'         => 'Format signature harus berupa string Base64 RFC 4648 kanonikal 88 karakter dengan akhiran "==".',
        ];
    }
}

