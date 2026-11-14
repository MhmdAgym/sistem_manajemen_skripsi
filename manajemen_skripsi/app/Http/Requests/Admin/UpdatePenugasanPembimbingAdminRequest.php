<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePenugasanPembimbingAdminRequest extends FormRequest
{
    /**
     * Tentukan apakah pengguna memiliki wewenang untuk membuat permintaan ini.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'admin';
    }

    /**
     * Aturan validasi pembaruan status penugasan pembimbing oleh Administrator.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status_penugasan' => [
                'required',
                'string',
                Rule::in(['AKTIF', 'DIGANTI', 'DIBATALKAN']),
            ],
            'tanggal_selesai'  => ['nullable', 'date'],
            'alasan'           => ['nullable', 'string', 'max:1000'],
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
            'status_penugasan.required' => 'Status penugasan pembimbing wajib dipilih.',
            'status_penugasan.in'       => 'Status penugasan harus berupa AKTIF, DIGANTI, atau DIBATALKAN.',
            'tanggal_selesai.date'      => 'Format tanggal selesai tidak valid.',
            'alasan.max'                => 'Alasan pembaruan status maksimal 1000 karakter.',
        ];
    }
}

