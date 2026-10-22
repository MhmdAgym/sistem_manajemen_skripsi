<?php

namespace App\Http\Requests\Admin;

use App\Constants\AcademicConstant;
use App\Models\PenugasanKaprodi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePenugasanKaprodiRequest extends FormRequest
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
            'dosen_id'                 => ['sometimes', 'required', 'integer', 'exists:dosen,id_dosen'],
            'prodi'                    => ['sometimes', 'required', 'string', Rule::in(AcademicConstant::ALL_PRODI)],
            'tanggal_mulai'            => ['sometimes', 'required', 'date'],
            'tanggal_selesai'          => ['nullable', 'date'],
            'is_aktif'                 => ['sometimes', 'boolean'],
            'gantikan_penugasan_aktif' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Validasi lanjutan lintas-field (memastikan tanggal selesai tidak sebelum tanggal mulai,
     * baik dari input baru maupun dari record lama di database).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $penugasanId = $this->route('id') ?? $this->route('penugasan_kaprodi');
            $penugasan = $penugasanId ? PenugasanKaprodi::find($penugasanId) : null;

            $tanggalMulai = $this->input('tanggal_mulai', $penugasan?->tanggal_mulai?->toDateString());
            $tanggalSelesai = $this->input('tanggal_selesai', $this->has('tanggal_selesai') ? $this->input('tanggal_selesai') : $penugasan?->tanggal_selesai?->toDateString());

            if ($tanggalMulai && $tanggalSelesai && $tanggalSelesai < $tanggalMulai) {
                $v->errors()->add('tanggal_selesai', 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai penugasan.');
            }
        });
    }

    /**
     * Pesan kesalahan khusus untuk validasi.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dosen_id.exists'                  => 'Dosen yang dipilih tidak valid atau tidak terdaftar.',
            'prodi.in'                         => 'Program studi tidak terdaftar dalam daftar prodi resmi universitas.',
            'tanggal_mulai.date'               => 'Format tanggal mulai penugasan tidak valid.',
            'tanggal_selesai.date'             => 'Format tanggal selesai penugasan tidak valid.',
            'is_aktif.boolean'                 => 'Status aktif harus berupa nilai boolean (true/false).',
            'gantikan_penugasan_aktif.boolean' => 'Flag pergantian penugasan harus berupa nilai boolean.',
        ];
    }
}
