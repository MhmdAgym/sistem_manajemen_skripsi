<?php

namespace App\Constants;

class AcademicConstant
{
    /**
     * Pemetaan Fakultas ke Program Studi resmi di lingkungan Universitas Nurul Huda (UNUHA).
     */
    public const FAKULTAS_PRODI = [
        'Fakultas Agama Islam' => [
            'Pendidikan Agama Islam',
            'Pendidikan Guru Madrasah Ibtidaiyah',
            'Pendidikan Bahasa Arab',
        ],
        'Fakultas Ilmu Pendidikan' => [
            'Pendidikan Fisika',
            'Pendidikan Ekonomi',
            'Pendidikan Bahasa Inggris',
            'Pendidikan Bahasa dan Sastra Indonesia',
            'Pendidikan Teknologi Informasi',
        ],
        'Fakultas Sains dan Teknologi' => [
            'Informatika',
            'Matematika',
            'Sains Pertanian',
        ],
    ];

    /**
     * Daftar 11 Program Studi resmi sebagai single source of truth untuk validasi sistem.
     */
    public const ALL_PRODI = [
        'Pendidikan Agama Islam',
        'Pendidikan Guru Madrasah Ibtidaiyah',
        'Pendidikan Bahasa Arab',
        'Pendidikan Fisika',
        'Pendidikan Ekonomi',
        'Pendidikan Bahasa Inggris',
        'Pendidikan Bahasa dan Sastra Indonesia',
        'Pendidikan Teknologi Informasi',
        'Informatika',
        'Matematika',
        'Sains Pertanian',
    ];
}
