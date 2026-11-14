<?php

namespace App\Http\Requests\Mahasiswa;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UpdateSkDokumenMahasiswaRequest extends FormRequest
{
    /**
     * Tentukan apakah pengguna memiliki wewenang untuk membuat permintaan ini.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'mahasiswa';
    }

    /**
     * Aturan validasi revisi/pembaruan dokumen SK oleh mahasiswa.
     * Berkas fisik bersifat opsional pada tingkat FormRequest (diverifikasi di controller),
     * namun jika ada wajib PDF dengan ukuran maksimal tepat 10.000.000 byte desimal (10 MB).
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file_sk' => [
                'nullable',
                'file',
                'mimes:pdf',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && $value->isValid() && $value->getSize() > 10_000_000) {
                        $fail('Ukuran berkas revisi SK tidak boleh melebihi 10.000.000 byte (10 MB).');
                    }
                },
            ],
            'nomor_sk'          => ['required', 'string', 'max:100'],
            'tanggal_sk'        => ['required', 'date'],
            'dosen_1_usulan_id' => ['required', 'integer', 'exists:dosen,id_dosen'],
            'dosen_2_usulan_id' => ['nullable', 'integer', 'exists:dosen,id_dosen', 'different:dosen_1_usulan_id'],
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
            'file_sk.file'               => 'Berkas revisi yang diunggah harus berupa file yang valid.',
            'file_sk.mimes'              => 'Format berkas revisi SK harus berupa dokumen PDF (.pdf).',
            'nomor_sk.required'          => 'Nomor resmi SK wajib diisi sesuai lembar fisik SK.',
            'nomor_sk.max'               => 'Nomor resmi SK tidak boleh melebihi 100 karakter.',
            'tanggal_sk.required'        => 'Tanggal penetapan SK wajib diisi.',
            'tanggal_sk.date'            => 'Format tanggal penetapan SK tidak valid.',
            'dosen_1_usulan_id.required' => 'Dosen Pembimbing Utama (Pembimbing 1) wajib dipilih.',
            'dosen_1_usulan_id.exists'   => 'Dosen Pembimbing 1 yang dipilih tidak terdaftar di sistem.',
            'dosen_2_usulan_id.exists'   => 'Dosen Pembimbing 2 yang dipilih tidak terdaftar di sistem.',
            'dosen_2_usulan_id.different'=> 'Dosen Pembimbing 2 tidak boleh sama dengan Dosen Pembimbing 1.',
        ];
    }
}

