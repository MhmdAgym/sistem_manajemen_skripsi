/**
 * Single source of truth untuk Fakultas dan Program Studi di Universitas Nurul Huda (UNUHA).
 * Selaras dengan AcademicConstant.php pada backend.
 */

export const FAKULTAS_LIST = [
  "Fakultas Agama Islam",
  "Fakultas Ilmu Pendidikan",
  "Fakultas Sains dan Teknologi",
] as const;

export type Fakultas = typeof FAKULTAS_LIST[number];

export const FAKULTAS_PRODI: Record<string, string[]> = {
  "Fakultas Agama Islam": [
    "Pendidikan Agama Islam",
    "Pendidikan Guru Madrasah Ibtidaiyah",
    "Pendidikan Bahasa Arab",
  ],
  "Fakultas Ilmu Pendidikan": [
    "Pendidikan Fisika",
    "Pendidikan Ekonomi",
    "Pendidikan Bahasa Inggris",
    "Pendidikan Bahasa dan Sastra Indonesia",
    "Pendidikan Teknologi Informasi",
  ],
  "Fakultas Sains dan Teknologi": [
    "Informatika",
    "Matematika",
    "Sains Pertanian",
  ],
};

export const ALL_PRODI: string[] = Object.values(FAKULTAS_PRODI).flat();

export const FAKULTAS_ALIASES: Record<string, string> = {
  FAI: "Fakultas Agama Islam",
  FIP: "Fakultas Ilmu Pendidikan",
  FST: "Fakultas Sains dan Teknologi",
};

/**
 * Normalisasi nama fakultas jika menggunakan singkatan (misal FAI -> Fakultas Agama Islam).
 */
export function normalizeFakultas(fakultas?: string | null): string {
  if (!fakultas) return "";
  const trimmed = fakultas.trim();
  return FAKULTAS_ALIASES[trimmed] || trimmed;
}

/**
 * Mengambil daftar program studi yang valid untuk fakultas tertentu.
 */
export function getProdiByFakultas(fakultas?: string | null): string[] {
  const normalized = normalizeFakultas(fakultas);
  return FAKULTAS_PRODI[normalized] || [];
}

/**
 * Menemukan fakultas induk dari sebuah program studi.
 */
export function getFakultasByProdi(prodi?: string | null): string | null {
  if (!prodi) return null;
  const trimmed = prodi.trim();
  for (const [fak, prodis] of Object.entries(FAKULTAS_PRODI)) {
    if (prodis.includes(trimmed)) return fak;
  }
  return null;
}

/**
 * Memeriksa apakah pasangan Fakultas dan Program Studi valid dan saling berelasi secara sah.
 */
export function isValidFakultasProdi(
  fakultas?: string | null,
  prodi?: string | null
): boolean {
  if (!fakultas || !prodi) return false;
  const prodis = getProdiByFakultas(fakultas);
  return prodis.includes(prodi.trim());
}
