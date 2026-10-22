<?php

namespace App\Http\Requests\Admin;

use App\Constants\AcademicConstant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePenugasanKaprodiRequest extends FormRequest
{
    /**
     * Tentukan apakah pengguna memiliki wewenang untuk membuat permintaan ini.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'admin';
    }

    /**
     * Dapatkan aturan validasi yang berlaku untuk permintaan ini.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dosen_id'                 => ['required', 'integer', 'exists:dosen,id_dosen'],
            'prodi'                    => ['required', 'string', Rule::in(AcademicConstant::ALL_PRODI)],
            'tanggal_mulai'            => ['required', 'date'],
            'tanggal_selesai'          => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'is_aktif'                 => ['nullable', 'boolean'],
            'gantikan_penugasan_aktif' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Pesan kesalahan khusus untuk validasi.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dosen_id.required'                => 'Dosen wajib dipilih.',
            'dosen_id.exists'                  => 'Dosen yang dipilih tidak valid atau tidak terdaftar.',
            'prodi.required'                   => 'Program studi wajib dipilih.',
            'prodi.in'                         => 'Program studi tidak terdaftar dalam daftar prodi resmi universitas.',
            'tanggal_mulai.required'           => 'Tanggal mulai penugasan wajib diisi.',
            'tanggal_mulai.date'               => 'Format tanggal mulai penugasan tidak valid.',
            'tanggal_selesai.date'             => 'Format tanggal selesai penugasan tidak valid.',
            'tanggal_selesai.after_or_equal'   => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai penugasan.',
            'is_aktif.boolean'                 => 'Status aktif harus berupa nilai boolean (true/false).',
            'gantikan_penugasan_aktif.boolean' => 'Flag pergantian penugasan harus berupa nilai boolean.',
        ];
    }
}
