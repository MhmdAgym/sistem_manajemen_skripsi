<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePenugasanPembimbingAdminRequest extends FormRequest
{
    /**
     * Tentukan apakah pengguna memiliki wewenang untuk membuat permintaan ini.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'admin';
    }

    /**
     * Aturan validasi penerbitan penugasan pembimbing skripsi baru oleh Administrator.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sk_dokumen_id'   => ['required', 'integer', 'exists:sk_skripsi_dokumen,id'],
            'pembimbing_ke'   => ['required', 'integer', Rule::in([1, 2])],
            'dosen_id'        => ['required', 'integer', 'exists:dosen,id_dosen'],
            'tanggal_mulai'   => ['required', 'date'],
            'tanggal_selesai' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
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
            'sk_dokumen_id.required'         => 'Dokumen SK Pembimbing Skripsi rujukan wajib dipilih.',
            'sk_dokumen_id.exists'           => 'Dokumen SK rujukan tidak ditemukan pada sistem.',
            'pembimbing_ke.required'         => 'Posisi pembimbing (Pembimbing 1 atau 2) wajib ditentukan.',
            'pembimbing_ke.in'               => 'Posisi pembimbing skripsi hanya diizinkan bernilai 1 atau 2.',
            'dosen_id.required'              => 'Dosen pembimbing yang ditugaskan wajib dipilih.',
            'dosen_id.exists'                => 'Dosen yang dipilih tidak terdaftar pada sistem.',
            'tanggal_mulai.required'         => 'Tanggal mulai penugasan wajib diisi.',
            'tanggal_mulai.date'             => 'Format tanggal mulai penugasan tidak valid.',
            'tanggal_selesai.date'           => 'Format tanggal selesai penugasan tidak valid.',
            'tanggal_selesai.after_or_equal' => 'Tanggal selesai tidak boleh lebih awal dari tanggal mulai penugasan.',
        ];
    }
}

