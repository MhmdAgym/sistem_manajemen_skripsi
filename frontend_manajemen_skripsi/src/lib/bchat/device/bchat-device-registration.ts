/**
 * B-CHAT E2EE Protocol Layer — Device Registration & PoP Builder (I-02A)
 *
 * Implements:
 * - W3C WebCrypto Ed25519 non-extractable device identity keypair generation.
 * - Canonical 121-byte Proof-of-Possession (PoP) payload assembly (RFC 9420/UNUHA-PoP).
 * - Detached 64-byte Ed25519 signature computation.
 * - End-to-end Device Registration flow (Step-Up -> Nonce -> PoP -> Register).
 * - KEYSTORE durable persistence with strict 'staged' -> 'active' lifecycle transition.
 *
 * Security Invariants:
 * - CSPRNG-only: crypto.randomUUID() / crypto.getRandomValues(). Fail-closed without Math.random().
 * - Private CryptoKey is non-extractable (extractable: false) from inception.
 * - Private key is never serialized as raw bytes or string in runtime, logs, or storage.
 * - Durable storage in IndexedDB (KEYSTORE) occurs BEFORE any backend request.
 * - user_id is sourced strictly from backend Step-Up response, never accepted arbitrarily from caller.
 * - Zero logging of passwords, tokens, nonces, private keys, signatures, or PoP payloads.
 */

import {
  stepUpDevice,
  issueRegistrationNonce,
  registerDevice,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client'
import type {
  BchatDeviceRegisterWithReplacementRequest,
} from '@/lib/bchat/api/bchat-api-types'
import {
  BCAT_STORES,
  type KeystoreRecord,
} from '@/lib/bchat/storage/bchat-idb-schema'
import {
  withReadonlyTransaction,
  withReadwriteTransaction,
  idbGet,
  idbPut,
  BchatStorageError,
} from '@/lib/bchat/storage/bchat-idb-core'

// -----------------------------------------------------------------------------
// KONSTANTA SPESIFIKASI KANONIS POP (B-CHAT-PoP-V1)
// -----------------------------------------------------------------------------

export const POP_MAGIC_HEADER = 'UNUHA-BCHAT-DEVICE-POP-V1'
export const POP_MAGIC_HEADER_BYTES = 25
export const POP_PAYLOAD_TOTAL_BYTES = 121
export const POP_SIGNATURE_TOTAL_BYTES = 64
export const POP_PAYLOAD_HEX_CHARS = 242
export const POP_SIGNATURE_HEX_CHARS = 128

const MAX_UINT64 = BigInt('18446744073709551615')

// -----------------------------------------------------------------------------
// KELAS ERROR DOMAIN REGISTRASI PERANGKAT
// -----------------------------------------------------------------------------

export class BchatDeviceRegistrationError extends Error {
  readonly isBchatDeviceRegistrationError = true
  readonly errorCode: string
  readonly originalError?: unknown

  constructor(message: string, errorCode: string, originalError?: unknown) {
    super(message)
    this.name = 'BchatDeviceRegistrationError'
    this.errorCode = errorCode
    this.originalError = originalError
    Object.setPrototypeOf(this, BchatDeviceRegistrationError.prototype)
  }
}

// -----------------------------------------------------------------------------
// UTILITAS CSPRNG & ENKODING KANONIS (FAIL-CLOSED)
// -----------------------------------------------------------------------------

function getCryptoSubtle(): SubtleCrypto {
  if (typeof window !== 'undefined' && window.crypto?.subtle) {
    return window.crypto.subtle
  }
  if (typeof self !== 'undefined' && self.crypto?.subtle) {
    return self.crypto.subtle
  }
  throw new BchatDeviceRegistrationError(
    'W3C WebCrypto API (crypto.subtle) tidak tersedia pada runtime saat ini.',
    'WEBCRYPTO_UNAVAILABLE'
  )
}

function assertCsprngEntropy(): void {
  const hasCrypto =
    (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') ||
    (typeof window !== 'undefined' &&
      window.crypto &&
      typeof window.crypto.getRandomValues === 'function')
  if (!hasCrypto) {
    throw new BchatDeviceRegistrationError(
      'Sumber entropi CSPRNG (crypto.getRandomValues) tidak tersedia pada runtime saat ini.',
      'CSPRNG_UNAVAILABLE'
    )
  }
}

function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  const copy = new Uint8Array(bytes.byteLength)
  copy.set(bytes)
  return copy.buffer
}

export function generateSecureDeviceUuid(): string {
  assertCsprngEntropy()

  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID().toLowerCase()
  }
  if (
    typeof window !== 'undefined' &&
    window.crypto &&
    typeof window.crypto.randomUUID === 'function'
  ) {
    return window.crypto.randomUUID().toLowerCase()
  }

  // Fallback RFC 4122 v4 berbasis crypto.getRandomValues (Zero Math.random)
  const cryptoObj = typeof crypto !== 'undefined' ? crypto : window.crypto
  const bytes = new Uint8Array(16)
  cryptoObj.getRandomValues(bytes)

  bytes[6] = (bytes[6] & 0x0f) | 0x40 // Version 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80 // Variant 10xx

  return bytesToCanonicalHex(bytes).replace(
    /^([0-9a-f]{8})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{4})([0-9a-f]{12})$/,
    '$1-$2-$3-$4-$5'
  )
}

export function bytesToCanonicalHex(bytes: Uint8Array): string {
  let hex = ''
  for (let i = 0; i < bytes.length; i++) {
    hex += bytes[i].toString(16).padStart(2, '0')
  }
  return hex.toLowerCase()
}

export function canonicalHexToBytes(hex: string, expectedByteLength?: number): Uint8Array {
  const clean = hex.trim().toLowerCase()
  if (!/^[0-9a-f]*$/.test(clean) || clean.length % 2 !== 0) {
    throw new BchatDeviceRegistrationError(
      'Format input bukan merupakan lowercase hexadecimal kanonis.',
      'INVALID_HEX_ENCODING'
    )
  }
  if (expectedByteLength !== undefined && clean.length !== expectedByteLength * 2) {
    throw new BchatDeviceRegistrationError(
      `Panjang heksadesimal tidak valid: diharapkan ${expectedByteLength * 2} karakter (${expectedByteLength} byte), diterima ${clean.length} karakter.`,
      'INVALID_HEX_LENGTH'
    )
  }
  const bytes = new Uint8Array(clean.length / 2)
  for (let i = 0; i < clean.length; i += 2) {
    bytes[i / 2] = parseInt(clean.substring(i, i + 2), 16)
  }
  return bytes
}

export function canonicalUuidToBytes(uuid: string): Uint8Array {
  const clean = uuid.trim().toLowerCase()
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(clean)) {
    throw new BchatDeviceRegistrationError(
      'Format Device UUID harus berupa UUID v4 kanonis (36 karakter lowercase).',
      'INVALID_DEVICE_UUID'
    )
  }
  const noHyphens = clean.replace(/-/g, '')
  return canonicalHexToBytes(noHyphens, 16)
}

export function parseAndValidateUint64(
  val: string | number | bigint,
  fieldName: string
): bigint {
  let bi: bigint
  try {
    if (typeof val === 'number') {
      if (!Number.isInteger(val) || val < 0) {
        throw new Error(`${fieldName} harus berupa integer non-negatif.`)
      }
      bi = BigInt(val)
    } else if (typeof val === 'string') {
      const trimmed = val.trim()
      if (!/^\d+$/.test(trimmed)) {
        throw new Error(`${fieldName} harus berupa decimal string non-negatif valid.`)
      }
      bi = BigInt(trimmed)
    } else if (typeof val === 'bigint') {
      if (val < BigInt(0)) {
        throw new Error(`${fieldName} tidak boleh bernilai negatif.`)
      }
      bi = val
    } else {
      throw new Error(`Tipe ${fieldName} tidak didukung untuk uint64.`)
    }
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      `Validasi ${fieldName} sebagai uint64 gagal: ${(err as Error).message}`,
      'INVALID_UINT64_VALUE',
      err
    )
  }

  if (bi < BigInt(0) || bi > MAX_UINT64) {
    throw new BchatDeviceRegistrationError(
      `${fieldName} berada di luar batas unsigned 64-bit integer (0 s.d. 2^64 - 1).`,
      'UINT64_OVERFLOW'
    )
  }
  return bi
}

// -----------------------------------------------------------------------------
// VERIFIKASI DUKUNGAN WEBCRYPTO ED25519 (FAIL-CLOSED)
// -----------------------------------------------------------------------------

export async function assertWebCryptoEd25519Supported(): Promise<void> {
  const subtle = getCryptoSubtle()
  try {
    const testKeypair = (await subtle.generateKey(
      'Ed25519',
      false,
      ['sign']
    )) as CryptoKeyPair
    if (!testKeypair.privateKey || !testKeypair.publicKey) {
      throw new Error('Keypair Ed25519 tidak mengembalikan kunci privat dan publik valid.')
    }
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      'Peramban saat ini tidak mendukung algoritma Ed25519 pada W3C WebCrypto API. Harap perbarui peramban ke versi terbaru.',
      'ED25519_UNSUPPORTED',
      err
    )
  }
}

// -----------------------------------------------------------------------------
// PEMBANGKITAN DEVICE IDENTITY KEYPAIR (NON-EXTRACTABLE)
// -----------------------------------------------------------------------------

export interface GeneratedDeviceIdentity {
  privateKey: CryptoKey
  publicKeyBytes: Uint8Array
  publicKeyHex: string
  fingerprintHex: string
}

export async function generateDeviceIdentityKeypair(): Promise<GeneratedDeviceIdentity> {
  assertCsprngEntropy()
  const subtle = getCryptoSubtle()

  // 1. Pembangkitan Ed25519 dengan private key non-extractable
  let keypair: CryptoKeyPair
  try {
    keypair = (await subtle.generateKey('Ed25519', false, ['sign'])) as CryptoKeyPair
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      'Gagal membangkitkan pasangan kunci identitas Ed25519.',
      'KEYGEN_FAILED',
      err
    )
  }

  // 2. Ekspor raw 32 byte public key
  let pubKeyBuf: ArrayBuffer
  try {
    pubKeyBuf = await subtle.exportKey('raw', keypair.publicKey)
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      'Gagal mengekspor kunci publik Ed25519 format raw.',
      'PUBKEY_EXPORT_FAILED',
      err
    )
  }

  const publicKeyBytes = new Uint8Array(pubKeyBuf)
  if (publicKeyBytes.length !== 32) {
    throw new BchatDeviceRegistrationError(
      `Panjang kunci publik Ed25519 tidak valid: ${publicKeyBytes.length} byte (wajib tepat 32 byte).`,
      'INVALID_PUBKEY_LENGTH'
    )
  }
  const publicKeyHex = bytesToCanonicalHex(publicKeyBytes)

  // 3. Hitung SHA-256 fingerprint atas 32 byte biner kunci publik
  let hashBuf: ArrayBuffer
  try {
    hashBuf = await subtle.digest('SHA-256', toArrayBuffer(publicKeyBytes))
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      'Gagal menghitung fingerprint SHA-256 kunci publik.',
      'FINGERPRINT_FAILED',
      err
    )
  }

  const fingerprintHex = bytesToCanonicalHex(new Uint8Array(hashBuf))
  return {
    privateKey: keypair.privateKey,
    publicKeyBytes,
    publicKeyHex,
    fingerprintHex,
  }
}

// -----------------------------------------------------------------------------
// PENYUSUNAN POP BUFFER TEPAT 121 BYTE (RFC 9420/UNUHA-PoP-V1)
// -----------------------------------------------------------------------------

export interface PoPPayloadInput {
  userId: string | number | bigint
  deviceUuid: string
  nonceHex: string
  issuedAt: string | number | bigint
  publicKeyBytes: Uint8Array
}

export function buildDevicePoPPayload(input: PoPPayloadInput): Uint8Array {
  // 1. Magic Header 25 byte ASCII
  const magicBytes = new TextEncoder().encode(POP_MAGIC_HEADER)
  if (magicBytes.length !== POP_MAGIC_HEADER_BYTES) {
    throw new BchatDeviceRegistrationError(
      'Panjang Magic Header PoP harus tepat 25 byte.',
      'INVALID_MAGIC_LENGTH'
    )
  }

  // 2. user_id uint64 big-endian
  const userIdBigInt = parseAndValidateUint64(input.userId, 'user_id')

  // 3. device_uuid biner 16 byte
  const deviceUuidBytes = canonicalUuidToBytes(input.deviceUuid)

  // 4. nonce biner 32 byte
  const nonceBytes = canonicalHexToBytes(input.nonceHex, 32)

  // 5. issued_at uint64 big-endian
  const issuedAtBigInt = parseAndValidateUint64(input.issuedAt, 'issued_at')

  // 6. public_key biner 32 byte
  if (input.publicKeyBytes.length !== 32) {
    throw new BchatDeviceRegistrationError(
      'Panjang kunci publik biner pada payload PoP harus tepat 32 byte.',
      'INVALID_PUBKEY_LENGTH'
    )
  }

  const buffer = new ArrayBuffer(POP_PAYLOAD_TOTAL_BYTES)
  const view = new DataView(buffer)
  const uint8 = new Uint8Array(buffer)

  // Offset 0..24: Magic Header (25 byte)
  uint8.set(magicBytes, 0)

  // Offset 25..32: user_id uint64 Big-Endian (8 byte)
  view.setBigUint64(25, userIdBigInt, false)

  // Offset 33..48: device_uuid biner (16 byte)
  uint8.set(deviceUuidBytes, 33)

  // Offset 49..80: registration_nonce biner (32 byte)
  uint8.set(nonceBytes, 49)

  // Offset 81..88: issued_at uint64 Big-Endian (8 byte)
  view.setBigUint64(81, issuedAtBigInt, false)

  // Offset 89..120: public_key biner (32 byte)
  uint8.set(input.publicKeyBytes, 89)

  if (uint8.length !== POP_PAYLOAD_TOTAL_BYTES) {
    throw new BchatDeviceRegistrationError(
      `Panjang payload PoP yang dihasilkan (${uint8.length} byte) tidak memenuhi kontrak kanonis ${POP_PAYLOAD_TOTAL_BYTES} byte.`,
      'INVALID_PAYLOAD_LENGTH'
    )
  }

  return uint8
}

// -----------------------------------------------------------------------------
// PENANDATANGANAN POP ED25519 (DETACHED 64 BYTE)
// -----------------------------------------------------------------------------

export interface PoPSignatureResult {
  payloadBytes: Uint8Array
  payloadHex: string
  signatureBytes: Uint8Array
  signatureHex: string
}

export async function signDevicePoP(
  privateKey: CryptoKey,
  payloadBytes: Uint8Array
): Promise<PoPSignatureResult> {
  if (payloadBytes.length !== POP_PAYLOAD_TOTAL_BYTES) {
    throw new BchatDeviceRegistrationError(
      `Ukuran payload PoP untuk penandatanganan tidak valid: ${payloadBytes.length} byte (wajib 121 byte).`,
      'INVALID_PAYLOAD_LENGTH'
    )
  }

  const subtle = getCryptoSubtle()
  let sigBuf: ArrayBuffer
  try {
    sigBuf = await subtle.sign('Ed25519', privateKey, toArrayBuffer(payloadBytes))
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      'Gagal menandatangani payload PoP dengan private key Ed25519.',
      'SIGNING_FAILED',
      err
    )
  }

  const signatureBytes = new Uint8Array(sigBuf)
  if (signatureBytes.length !== POP_SIGNATURE_TOTAL_BYTES) {
    throw new BchatDeviceRegistrationError(
      `Panjang signature Ed25519 tidak valid: ${signatureBytes.length} byte (wajib tepat 64 byte).`,
      'INVALID_SIGNATURE_LENGTH'
    )
  }

  const payloadHex = bytesToCanonicalHex(payloadBytes)
  const signatureHex = bytesToCanonicalHex(signatureBytes)

  if (payloadHex.length !== POP_PAYLOAD_HEX_CHARS) {
    throw new BchatDeviceRegistrationError(
      `Panjang hex payload PoP tidak valid (${payloadHex.length} karakter, wajib 242).`,
      'INVALID_HEX_LENGTH'
    )
  }

  if (signatureHex.length !== POP_SIGNATURE_HEX_CHARS) {
    throw new BchatDeviceRegistrationError(
      `Panjang hex signature PoP tidak valid (${signatureHex.length} karakter, wajib 128).`,
      'INVALID_HEX_LENGTH'
    )
  }

  return {
    payloadBytes,
    payloadHex,
    signatureBytes,
    signatureHex,
  }
}

// -----------------------------------------------------------------------------
// MANAJEMEN KEYSTORE LOCAL (INDEXEDDB LIFECYCLE: staged -> active)
// -----------------------------------------------------------------------------

export function makeDeviceIdentityKeyId(deviceUuid: string): string {
  return `identity_key_${deviceUuid.toLowerCase().trim()}`
}

export async function saveStagedDeviceIdentityKey(
  deviceUuid: string,
  privateKey: CryptoKey,
  publicKeyHex: string,
  fingerprintHex: string
): Promise<void> {
  const keyId = makeDeviceIdentityKeyId(deviceUuid)
  const stagedRecord: KeystoreRecord = {
    key_id: keyId,
    key_version: 1,
    status: 'staged',
    algorithm: 'Ed25519',
    crypto_key: privateKey,
    created_at: Date.now(),
    metadata: {
      key_type: 'DEVICE_IDENTITY',
      device_uuid: deviceUuid.toLowerCase().trim(),
      public_key_hex: publicKeyHex,
      public_key_fingerprint: fingerprintHex,
      staged_at: Date.now(),
    },
  }

  try {
    await idbPut(BCAT_STORES.KEYSTORE, stagedRecord)
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      'Gagal menyimpan private key berstatus staged ke IndexedDB Keystore sebelum pendaftaran.',
      'KEYSTORE_PERSISTENCE_FAILED',
      err
    )
  }
}

export async function promoteStagedIdentityKeyToActive(
  deviceUuid: string,
  keyVersion: number,
  backendStatus: string,
  registeredAt: string
): Promise<void> {
  const keyId = makeDeviceIdentityKeyId(deviceUuid)

  await withReadwriteTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
    return new Promise<void>((resolve, reject) => {
      const store = getStore(BCAT_STORES.KEYSTORE)
      const getReq = store.get(keyId)

      getReq.onsuccess = () => {
        const record = getReq.result as KeystoreRecord | undefined
        if (!record) {
          return reject(
            new BchatDeviceRegistrationError(
              'Record identity key staged tidak ditemukan di Keystore untuk dipromosikan.',
              'STAGED_KEY_NOT_FOUND'
            )
          )
        }

        if (record.status !== 'staged') {
          return reject(
            new BchatDeviceRegistrationError(
              `Status identity key di Keystore bukan staged (saat ini: ${record.status}). Promosi dibatalkan.`,
              'INVALID_LIFECYCLE_STATE'
            )
          )
        }

        record.status = 'active'
        record.key_version = keyVersion
        record.metadata = {
          ...record.metadata,
          backend_status: backendStatus,
          registered_at: registeredAt,
          activated_at: Date.now(),
        }

        const putReq = store.put(record)
        putReq.onsuccess = () => resolve()
        putReq.onerror = () =>
          reject(
            new BchatDeviceRegistrationError(
              'Gagal memperbarui status identity key menjadi active di IndexedDB.',
              'KEYSTORE_PROMOTION_FAILED',
              putReq.error
            )
          )
      }

      getReq.onerror = () =>
        reject(
          new BchatDeviceRegistrationError(
            'Gagal membaca identity key staged dari IndexedDB saat promosi.',
            'KEYSTORE_READ_FAILED',
            getReq.error
          )
        )
    })
  })
}

export async function getActiveDeviceIdentityKey(
  deviceUuid: string
): Promise<KeystoreRecord | undefined> {
  const keyId = makeDeviceIdentityKeyId(deviceUuid)
  try {
    const record = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, keyId)
    if (record && record.status === 'active' && record.algorithm === 'Ed25519') {
      return record
    }
    return undefined
  } catch (err) {
    throw new BchatDeviceRegistrationError(
      'Gagal mengambil kunci identitas perangkat aktif dari Keystore.',
      'KEYSTORE_READ_FAILED',
      err
    )
  }
}

/**
 * Mencari identitas perangkat aktif yang tersimpan secara lokal pada Keystore IndexedDB.
 * Mengembalikan objek memuat canonical deviceUuid dan KeystoreRecord, atau null jika belum ada.
 */
export async function findActiveLocalDevice(): Promise<{
  deviceUuid: string
  record: KeystoreRecord
} | null> {
  return withReadonlyTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
    return new Promise((resolve, reject) => {
      const store = getStore(BCAT_STORES.KEYSTORE)
      const req = store.getAll()
      req.onsuccess = () => {
        const records = (req.result as KeystoreRecord[]) || []
        const activeIdentity = records.find(
          (r) =>
            r.algorithm === 'Ed25519' &&
            r.status === 'active' &&
            r.metadata?.key_type === 'DEVICE_IDENTITY'
        )
        if (activeIdentity && activeIdentity.metadata?.device_uuid) {
          resolve({
            deviceUuid: activeIdentity.metadata.device_uuid as string,
            record: activeIdentity,
          })
        } else {
          resolve(null)
        }
      }
      req.onerror = () =>
        reject(
          new BchatDeviceRegistrationError(
            'Gagal memeriksa daftar kunci pada Keystore.',
            'KEYSTORE_SCAN_FAILED',
            req.error
          )
        )
    })
  })
}

// -----------------------------------------------------------------------------
// ORKESTRASI END-TO-END REGISTRASI PERANGKAT (I-02A CORE)
// -----------------------------------------------------------------------------

export interface DeviceRegistrationOptions {
  password: string
  deviceName?: string | null
  existingDeviceUuid?: string | null
  /** Mode registrasi: 'ADD_DEVICE' (Multi-Device) atau 'MANAGED_REPLACEMENT' */
  registrationMode?: 'ADD_DEVICE' | 'MANAGED_REPLACEMENT'
  /** Replacement Protection — wajib jika ada active device di backend dan mode replacement */
  confirmReplacement?: boolean
  /** UUID perangkat aktif yang akan diganti, harus cocok dengan backend */
  expectedActiveDeviceUuid?: string | null
}

export interface DeviceRegistrationResult {
  deviceUuid: string
  deviceName: string
  keyVersion: number
  status: string
  publicKeyHex: string
  registeredAt: string
  isReplacement: boolean
}

/**
 * Menjalankan seluruh alur registrasi perangkat baru / replacement secara aman:
 * 1. Validasi WebCrypto Ed25519 support.
 * 2. Tetapkan Device UUID (CSPRNG).
 * 3. Pembangkitan Ed25519 non-extractable keypair.
 * 4. Simpan private key durable berstatus 'staged' ke IndexedDB.
 * 5. Panggil Step-Up re-auth (mengambil user_id & step_up_token).
 * 6. Minta Registration Nonce dari backend.
 * 7. Susun buffer kanonis PoP 121 byte & tandatangani (detached 64 byte).
 * 8. Daftarkan perangkat ke backend (POST /api/bchat/devices/register).
 * 9. Promosikan status kunci 'staged' -> 'active' di IndexedDB.
 */
export async function registerDeviceFlow(
  options: DeviceRegistrationOptions
): Promise<DeviceRegistrationResult> {
  const cleanPassword = options.password
  if (!cleanPassword || typeof cleanPassword !== 'string' || cleanPassword.trim() === '') {
    throw new BchatDeviceRegistrationError(
      'Password akun wajib diisi untuk re-autentikasi Step-Up registrasi perangkat.',
      'EMPTY_PASSWORD'
    )
  }

  // 1. Validasi WebCrypto Ed25519
  await assertWebCryptoEd25519Supported()

  // 2. Tentukan Device UUID
  const deviceUuid = options.existingDeviceUuid
    ? options.existingDeviceUuid.toLowerCase().trim()
    : generateSecureDeviceUuid()

  // Validasi format Device UUID
  canonicalUuidToBytes(deviceUuid)

  // 3. Pembangkitan Ed25519 non-extractable keypair
  const identity = await generateDeviceIdentityKeypair()

  // 4. Simpan private key durable berstatus 'staged' ke IndexedDB SEBELUM request jaringan
  await saveStagedDeviceIdentityKey(
    deviceUuid,
    identity.privateKey,
    identity.publicKeyHex,
    identity.fingerprintHex
  )

  // 5. Jalankan Step-Up re-authentication
  let stepUpRes
  try {
    stepUpRes = await stepUpDevice({
      password: cleanPassword,
      device_uuid: deviceUuid,
    })
  } catch (err) {
    // Key tetap 'staged' di IndexedDB; jangan hapus atau promosikan
    if (err instanceof BchatApiError) {
      throw err
    }
    throw new BchatDeviceRegistrationError(
      'Gagal melakukan Step-Up re-autentikasi.',
      'STEP_UP_FAILED',
      err
    )
  }

  // Validasi kecocokan respons Step-Up
  const serverUserId = stepUpRes.data.user_id
  if (!serverUserId || typeof serverUserId !== 'string' || !/^\d+$/.test(serverUserId)) {
    throw new BchatDeviceRegistrationError(
      'Respons Step-Up tidak memuat user_id decimal string yang valid.',
      'INVALID_STEP_UP_USER_ID'
    )
  }

  if (stepUpRes.data.device_uuid.toLowerCase() !== deviceUuid) {
    throw new BchatDeviceRegistrationError(
      'Device UUID pada respons Step-Up tidak cocok dengan perangkat target.',
      'STEP_UP_DEVICE_MISMATCH'
    )
  }

  const stepUpToken = stepUpRes.data.step_up_token

  // 6. Minta Registration Nonce
  let nonceRes
  try {
    nonceRes = await issueRegistrationNonce({
      step_up_token: stepUpToken,
      device_uuid: deviceUuid,
      public_key: identity.publicKeyHex,
    })
  } catch (err) {
    // Key tetap 'staged' di IndexedDB
    if (err instanceof BchatApiError) {
      throw err
    }
    throw new BchatDeviceRegistrationError(
      'Gagal meminta Registration Nonce dari server.',
      'NONCE_REQUEST_FAILED',
      err
    )
  }

  // Validasi nonce response
  if (nonceRes.data.device_uuid.toLowerCase() !== deviceUuid) {
    throw new BchatDeviceRegistrationError(
      'Device UUID pada respons nonce tidak cocok dengan perangkat target.',
      'NONCE_DEVICE_MISMATCH'
    )
  }

  if (
    nonceRes.data.public_key_fingerprint.toLowerCase() !==
    identity.fingerprintHex.toLowerCase()
  ) {
    throw new BchatDeviceRegistrationError(
      'Fingerprint kunci publik pada respons nonce tidak cocok dengan kunci lokal.',
      'FINGERPRINT_MISMATCH'
    )
  }

  const nonceUuid = nonceRes.data.nonce_uuid
  const registrationNonceHex = nonceRes.data.registration_nonce
  const serverIssuedAt = nonceRes.data.issued_at

  // 7. Bangun PoP Payload 121 byte & tandatangani (detached 64 byte)
  const popPayloadBytes = buildDevicePoPPayload({
    userId: serverUserId,
    deviceUuid,
    nonceHex: registrationNonceHex,
    issuedAt: serverIssuedAt,
    publicKeyBytes: identity.publicKeyBytes,
  })

  const popSignature = await signDevicePoP(identity.privateKey, popPayloadBytes)

  // 8. Kirim registrasi ke backend (POST /api/bchat/devices/register)
  let registerRes
  try {
    const registerPayload: BchatDeviceRegisterWithReplacementRequest = {
      nonce_uuid: nonceUuid,
      pop_payload: popSignature.payloadHex,
      pop_signature: popSignature.signatureHex,
      device_name: options.deviceName ?? 'Browser Device',
      registration_mode: options.registrationMode,
    }
    if (options.confirmReplacement) {
      registerPayload.confirm_replacement = true
      registerPayload.expected_active_device_uuid =
        options.expectedActiveDeviceUuid ?? undefined
    }
    registerRes = await registerDevice(registerPayload)
  } catch (err) {
    // Key tetap 'staged' di IndexedDB; TIDAK dihapus otomatis dan TIDAK dipromosikan
    if (err instanceof BchatApiError) {
      throw err
    }
    throw new BchatDeviceRegistrationError(
      'Permintaan registrasi perangkat ditolak oleh server atau mengalami kegagalan jaringan.',
      'DEVICE_REGISTRATION_FAILED',
      err
    )
  }

  // Validasi konfirmasi respons registrasi
  if (registerRes.data.device_uuid.toLowerCase() !== deviceUuid) {
    throw new BchatDeviceRegistrationError(
      'Device UUID konfirmasi backend tidak cocok dengan perangkat.',
      'CONFIRMATION_DEVICE_MISMATCH'
    )
  }

  if (registerRes.data.public_key.toLowerCase() !== identity.publicKeyHex.toLowerCase()) {
    throw new BchatDeviceRegistrationError(
      'Public key konfirmasi backend tidak cocok dengan public key lokal.',
      'CONFIRMATION_PUBKEY_MISMATCH'
    )
  }

  // 9. Promosikan status kunci 'staged' -> 'active' di IndexedDB
  try {
    await promoteStagedIdentityKeyToActive(
      deviceUuid,
      registerRes.data.key_version,
      registerRes.data.status,
      registerRes.data.registered_at
    )
  } catch (err) {
    // Registrasi di server sudah sukses, tetapi pembaruan lokal gagal.
    // Lemparkan error spesifik agar UI / rekonsiliasi dapat menyelesaikannya.
    throw new BchatDeviceRegistrationError(
      'Perangkat berhasil didaftarkan di backend, namun pembaruan status lokal di IndexedDB mengalami kegagalan. Diperlukan rekonsiliasi.',
      'PROMOTION_FAILED_AFTER_BACKEND_SUCCESS',
      err
    )
  }

  return {
    deviceUuid: registerRes.data.device_uuid,
    deviceName: registerRes.data.device_name,
    keyVersion: registerRes.data.key_version,
    status: registerRes.data.status,
    publicKeyHex: registerRes.data.public_key,
    registeredAt: registerRes.data.registered_at,
    isReplacement: registerRes.data.is_replacement,
  }
}
