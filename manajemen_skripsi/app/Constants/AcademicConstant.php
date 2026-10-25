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

    /**
     * Alias singkatan fakultas untuk kompatibilitas.
     */
    public const FAKULTAS_ALIASES = [
        'FAI' => 'Fakultas Agama Islam',
        'FIP' => 'Fakultas Ilmu Pendidikan',
        'FST' => 'Fakultas Sains dan Teknologi',
    ];

    /**
     * Mengambil daftar nama resmi seluruh fakultas.
     */
    public static function getFakultasList(): array
    {
        return array_keys(self::FAKULTAS_PRODI);
    }

    /**
     * Normalisasi nama fakultas jika berupa singkatan atau memiliki spasi berlebih.
     */
    public static function normalizeFakultas(?string $fakultas): ?string
    {
        if ($fakultas === null || trim($fakultas) === '') {
            return null;
        }

        $trimmed = trim($fakultas);
        return self::FAKULTAS_ALIASES[$trimmed] ?? $trimmed;
    }

    /**
     * Mengambil daftar prodi resmi untuk fakultas tertentu.
     */
    public static function getProdiForFakultas(?string $fakultas): array
    {
        $normalized = self::normalizeFakultas($fakultas);
        if (!$normalized) {
            return [];
        }

        return self::FAKULTAS_PRODI[$normalized] ?? [];
    }

    /**
     * Menemukan nama fakultas induk dari sebuah program studi.
     */
    public static function getFakultasForProdi(?string $prodi): ?string
    {
        if ($prodi === null || trim($prodi) === '') {
            return null;
        }

        $trimmed = trim($prodi);
        foreach (self::FAKULTAS_PRODI as $fakultas => $prodis) {
            if (in_array($trimmed, $prodis, true)) {
                return $fakultas;
            }
        }

        return null;
    }

    /**
     * Memvalidasi apakah pasangan fakultas dan program studi sah secara akademik.
     */
    public static function isValidPair(?string $fakultas, ?string $prodi): bool
    {
        if (empty($fakultas) || empty($prodi)) {
            return false;
        }

        $validProdis = self::getProdiForFakultas($fakultas);
        return in_array(trim($prodi), $validProdis, true);
    }
}
