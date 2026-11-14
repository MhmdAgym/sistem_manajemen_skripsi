<?php

declare(strict_types=1);

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BchatCreateUploadSessionRequest extends FormRequest
{
    /**
     * Otorisasi didelegasikan ke Controller via BchatAttachmentPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi payload pembentukan sesi unggah lampiran E2EE.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'attachment_uuid' => [
                'required',
                'string',
                'size:36',
                'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            ],
            'total_chunks' => [
                'required',
                'integer',
                'min:1',
                'max:10',
            ],
            'ciphertext_bytes' => [
                'required',
                'integer',
                'min:29',
                'max:10000280', // 10.000.000 plaintext + 10 * 28 framing
            ],
            'plaintext_bytes' => [
                'required',
                'integer',
                'min:1',
                'max:10000000', // Kuota tunggal 10 MB desimal
            ],
            'file_hash_sha256' => [
                'nullable',
                'string',
                'size:64',
                'regex:/^[0-9a-f]{64}$/i',
            ],
        ];
    }

    /**
     * Validasi matematis konsistensi chunking dan overhead framing Format A.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $plaintextBytes = (int) $this->input('plaintext_bytes');
                $totalChunks = (int) $this->input('total_chunks');
                $ciphertextBytes = (int) $this->input('ciphertext_bytes');

                // 1. Validasi konsistensi chunk plaintext (1 MiB = 1.048.576 byte)
                $chunkSize = 1048576;
                $expectedChunks = (int) ceil($plaintextBytes / $chunkSize);
                if ($totalChunks !== $expectedChunks) {
                    $validator->errors()->add(
                        'total_chunks',
                        "Jumlah chunk ({$totalChunks}) tidak konsisten dengan ukuran plaintext ({$plaintextBytes} B, diharapkan {$expectedChunks} chunk)."
                    );
                }

                // 2. Validasi konsistensi overhead framing Format A (28 byte per chunk)
                $expectedCiphertextBytes = $plaintextBytes + ($totalChunks * 28);
                if ($ciphertextBytes !== $expectedCiphertextBytes) {
                    $validator->errors()->add(
                        'ciphertext_bytes',
                        "Ukuran ciphertext ({$ciphertextBytes} B) tidak konsisten dengan framing Format A (diharapkan {$expectedCiphertextBytes} B untuk {$totalChunks} chunk)."
                    );
                }
            },
        ];
    }
}

