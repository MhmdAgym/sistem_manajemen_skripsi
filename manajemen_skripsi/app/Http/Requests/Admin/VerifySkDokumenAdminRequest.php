<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifySkDokumenAdminRequest extends FormRequest
{
    /**
     * Tentukan apakah pengguna memiliki wewenang untuk membuat permintaan ini.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'admin';
    }

    /**
     * Aturan validasi verifikasi administratif berkas SK oleh Administrator.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status_dokumen' => [
                'required',
                'string',
                Rule::in(['TERVERIFIKASI', 'PERLU_PERBAIKAN', 'DITOLAK']),
            ],
            'catatan_admin'  => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Pesan kesalahan khusus untuk validasi input.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status_dokumen.required' => 'Keputusan verifikasi dokumen SK wajib dipilih.',
            'status_dokumen.in'       => 'Keputusan verifikasi harus berupa TERVERIFIKASI, PERLU_PERBAIKAN, atau DITOLAK.',
            'catatan_admin.max'       => 'Catatan verifikasi maksimal 1000 karakter.',
        ];
    }
}

