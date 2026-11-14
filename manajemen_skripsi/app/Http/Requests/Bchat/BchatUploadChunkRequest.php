<?php

declare(strict_types=1);

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatUploadChunkRequest extends FormRequest
{
    /**
     * Otorisasi didelegasikan ke Controller via BchatAttachmentPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi opsional untuk query/header upload chunk biner.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chunk_hash_sha256' => [
                'nullable',
                'string',
                'size:64',
                'regex:/^[0-9a-f]{64}$/i',
            ],
        ];
    }
}

