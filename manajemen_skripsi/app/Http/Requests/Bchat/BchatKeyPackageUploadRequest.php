<?php

declare(strict_types=1);

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatKeyPackageUploadRequest extends FormRequest
{
    /**
     * Otorisasi request: diproteksi oleh middleware auth:sanctum.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan validasi untuk request upload KeyPackage B-CHAT E2EE.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'device_uuid'                  => ['required', 'string', 'uuid'],
            'packages'                     => ['required', 'array', 'min:1', 'max:10'],
            'packages.*.key_package_hash'  => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'packages.*.key_package_data'  => ['required', 'string', 'max:87384'],
        ];
    }

    /**
     * Pesan validasi kustom dalam bahasa Indonesia.
     */
    public function messages(): array
    {
        return [
            'device_uuid.required'                 => 'Device UUID wajib disertakan.',
            'device_uuid.uuid'                     => 'Format Device UUID harus berupa UUID v4 yang valid.',
            'packages.required'                    => 'Daftar KeyPackage wajib disertakan.',
            'packages.array'                       => 'Daftar KeyPackage harus berupa array.',
            'packages.min'                         => 'Minimal harus mengunggah 1 KeyPackage.',
            'packages.max'                         => 'Maksimal hanya dapat mengunggah 10 KeyPackage per batch.',
            'packages.*.key_package_hash.required' => 'KeyPackage hash wajib disertakan untuk setiap package.',
            'packages.*.key_package_hash.regex'    => 'KeyPackage hash harus berupa 64 karakter lowercase hexadecimal (SHA-256).',
            'packages.*.key_package_data.required' => 'KeyPackage data wajib disertakan untuk setiap package.',
            'packages.*.key_package_data.string'   => 'KeyPackage data harus berupa string terenkode Base64.',
            'packages.*.key_package_data.max'      => 'Ukuran KeyPackage data terenkode tidak boleh melebihi 87.384 karakter.',
        ];
    }
}

