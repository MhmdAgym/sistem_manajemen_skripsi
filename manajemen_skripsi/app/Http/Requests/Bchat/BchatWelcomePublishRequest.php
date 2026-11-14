<?php

declare(strict_types=1);

namespace App\Http\Requests\Bchat;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class BchatWelcomePublishRequest extends FormRequest
{
    /**
     * Batas maksimum ukuran binary Welcome message: 256 KiB = 262.144 byte (RFC 9420).
     */
    public const MAX_BINARY_PAYLOAD_BYTES = 262144;

    /**
     * Batas maksimum panjang string Base64 kanonikal (RFC 4648) untuk 262.144 byte binary:
     * ceil(262144 / 3) * 4 = 87382 * 4 = 349.528 karakter.
     * Digunakan sebagai pre-decode guard untuk mencegah serangan alokasi memori berlebih (memory exhaustion).
     */
    public const MAX_BASE64_STRING_LENGTH = 349528;

    /**
     * Otorisasi request: diproteksi oleh middleware auth:sanctum dan verifikasi keanggotaan di BchatWelcomeService.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi payload HTTP publikasi Welcome E2EE.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_device_uuid' => [
                'required',
                'string',
                'uuid',
            ],
            'key_package_hash' => [
                'required',
                'string',
                'regex:/^[0-9a-fA-F]{64}$/',
            ],
            'group_id_hash' => [
                'required',
                'string',
                'regex:/^[0-9a-fA-F]{64}$/',
            ],
            'epoch' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== 1 && $value !== '1') {
                        $fail('Epoch target Welcome pertama wajib tepat bernilai 1.');
                    }
                },
            ],
            'welcome_message' => [
                'required',
                'string',
                'max:' . self::MAX_BASE64_STRING_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (!is_string($value) || trim($value) === '') {
                        $fail('Payload Welcome message tidak boleh kosong.');
                        return;
                    }

                    // Strict decoding Base64 RFC 4648
                    $decoded = base64_decode($value, true);
                    if ($decoded === false) {
                        $fail('Format Base64 Welcome message tidak valid.');
                        return;
                    }

                    // Validasi non-empty decoded binary
                    $decodedLength = strlen($decoded);
                    if ($decodedLength === 0) {
                        $fail('Payload Welcome message setelah didekode tidak boleh kosong.');
                        return;
                    }

                    // Validasi batas binary payload maksimum (256 KiB)
                    if ($decodedLength > self::MAX_BINARY_PAYLOAD_BYTES) {
                        $fail('Ukuran binary payload Welcome tidak boleh melebihi ' . number_format(self::MAX_BINARY_PAYLOAD_BYTES, 0, ',', '.') . ' byte (256 KiB).');
                        return;
                    }

                    // Enforce canonical Base64 encoding (mencegah non-canonical padding / bit sisa / hidden payload)
                    if (base64_encode($decoded) !== $value) {
                        $fail('Encoding Base64 Welcome message tidak kanonis.');
                        return;
                    }
                },
            ],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'recipient_device_uuid.required' => 'Recipient Device UUID wajib disertakan.',
            'recipient_device_uuid.string'   => 'Format Recipient Device UUID harus berupa string.',
            'recipient_device_uuid.uuid'     => 'Format Recipient Device UUID harus berupa UUID v4 yang valid.',
            'key_package_hash.required'      => 'KeyPackage hash wajib disertakan.',
            'key_package_hash.string'        => 'KeyPackage hash harus berupa string heksadesimal 64 karakter.',
            'key_package_hash.regex'         => 'KeyPackage hash harus berupa string heksadesimal 64 karakter (SHA-256).',
            'group_id_hash.required'         => 'Group ID hash wajib disertakan.',
            'group_id_hash.string'           => 'Group ID hash harus berupa string heksadesimal 64 karakter.',
            'group_id_hash.regex'            => 'Group ID hash harus berupa string heksadesimal 64 karakter (SHA-256).',
            'epoch.required'                 => 'Epoch target Welcome wajib disertakan.',
            'welcome_message.required'       => 'Payload Welcome message wajib disertakan.',
            'welcome_message.string'         => 'Payload Welcome message harus berupa string Base64.',
            'welcome_message.max'            => 'Panjang karakter string Base64 Welcome message melebihi batas maksimum 349.528 karakter (256 KiB binary).',
        ];
    }
}

