/**
 * B-CHAT E2EE — Strict MLS ClientState Codec Engine
 *
 * Mengimplementasikan serialisasi dan pemulihan ClientState menggunakan serializer
 * resmi ts-mls@2.0.0-rc.16 (RFC 9420 TLS Syntax Presentation Language §13):
 * - clientStateEncoder: mengubah ClientState menjadi buffer biner Uint8Array.
 * - clientStateDecoder: strict tuple decoding dengan verifikasi konsumsi byte penuh.
 * - Penolakan mutlak trailing bytes, buffer kosong, malformed, atau melebihi batas 2 MiB.
 * - Validasi ketat terhadap versi serializer kanonikal dan struktur minimum state.
 * - Kebijakan MUST NOT ZERO terhadap buffer consumed dan larangan serialisasi JSON.
 */

import {
  clientStateEncoder,
  clientStateDecoder,
  type ClientState,
} from '../../../../node_modules/ts-mls/dist/src/clientState.js';
import { encode } from '../../../../node_modules/ts-mls/dist/src/codec/tlsEncoder.js';
import { BchatStorageError } from './bchat-idb-core';
import { DEFAULT_SERIALIZER_VERSION } from './bchat-storage-aad';

// =============================================================================
// Konstanta Baku Codec ClientState
// =============================================================================

/**
 * Versi serializer baku untuk serialisasi ClientState (Kanonis: 1)
 */
export const BCAT_CLIENT_STATE_SERIALIZER_VERSION: number = DEFAULT_SERIALIZER_VERSION;

/**
 * Batas ukuran maksimal buffer biner ClientState: 2.097.152 bytes (2 MiB)
 */
export const MAX_CLIENT_STATE_BYTES = 2_097_152; // 2 * 1024 * 1024 bytes (2 MiB)

// =============================================================================
// Hierarki Kelas Error Terstruktur
// =============================================================================

export class BchatCodecError extends BchatStorageError {
  constructor(message: string, code = 'CODEC_ERROR', originalError?: unknown) {
    super(message, code, originalError);
    this.name = 'BchatCodecError';
  }
}

export class BchatCodecInputError extends BchatCodecError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'CODEC_INVALID_INPUT', originalError);
    this.name = 'BchatCodecInputError';
  }
}

export class BchatUnsupportedSerializerVersionError extends BchatCodecError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'UNSUPPORTED_SERIALIZER_VERSION', originalError);
    this.name = 'BchatUnsupportedSerializerVersionError';
  }
}

export class BchatPayloadSizeExceededError extends BchatCodecError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'PAYLOAD_SIZE_EXCEEDED', originalError);
    this.name = 'BchatPayloadSizeExceededError';
  }
}

export class BchatCodecDecodeError extends BchatCodecError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'CODEC_DECODE_FAILED', originalError);
    this.name = 'BchatCodecDecodeError';
  }
}

export class BchatStateStructureError extends BchatCodecError {
  constructor(message: string, originalError?: unknown) {
    super(message, 'INVALID_STATE_STRUCTURE', originalError);
    this.name = 'BchatStateStructureError';
  }
}

// =============================================================================
// Operasi Encode ClientState
// =============================================================================

/**
 * Memvalidasi apakah objek yang diberikan memiliki bentuk dasar ClientState sebelum di-encode
 */
function validateClientStateCandidate(candidate: unknown): asserts candidate is ClientState {
  if (!candidate || typeof candidate !== 'object') {
    throw new BchatCodecInputError('Input ClientState tidak boleh null atau bukan objek.');
  }

  const state = candidate as Record<string, unknown>;

  if (!state.groupContext || typeof state.groupContext !== 'object') {
    throw new BchatCodecInputError("ClientState tidak memiliki properti 'groupContext' yang valid.");
  }

  const gc = state.groupContext as Record<string, unknown>;

  if (!(gc.groupId instanceof Uint8Array)) {
    throw new BchatCodecInputError("Field 'groupContext.groupId' pada ClientState wajib bertipe Uint8Array.");
  }

  if (typeof gc.epoch !== 'bigint') {
    throw new BchatCodecInputError("Field 'groupContext.epoch' pada ClientState wajib bertipe bigint.");
  }

  if (!state.ratchetTree || typeof state.ratchetTree !== 'object') {
    throw new BchatCodecInputError("ClientState tidak memiliki properti 'ratchetTree' yang valid.");
  }
}

/**
 * Mengenkode objek ClientState MLS menjadi buffer biner Uint8Array murni
 * menggunakan serializer resmi ts-mls (clientStateEncoder).
 *
 * @param state Objek ClientState valid yang dihasilkan oleh operasi grup MLS
 * @returns Buffer biner Uint8Array hasil serialisasi TLS Presentation Language
 */
export function encodeClientState(state: ClientState): Uint8Array {
  // 1. Validasi struktur kandidat state
  validateClientStateCandidate(state);

  // 2. Serialisasi menggunakan encoder resmi ts-mls
  let encodedBytes: Uint8Array;
  try {
    encodedBytes = encode(clientStateEncoder, state);
  } catch (err: any) {
    throw new BchatCodecError(
      `Gagal mengenkode ClientState dengan ts-mls clientStateEncoder: ${err?.message || 'Unknown error'}`,
      'ENCODE_FAILED',
      err
    );
  }

  // 3. Verifikasi tipe keluaran
  if (!(encodedBytes instanceof Uint8Array)) {
    throw new BchatCodecError(
      'Hasil serialisasi clientStateEncoder bukan merupakan Uint8Array.',
      'INVALID_ENCODER_OUTPUT'
    );
  }

  // 4. Verifikasi batas ukuran kebijakan aplikasi (2 MiB)
  if (encodedBytes.length > MAX_CLIENT_STATE_BYTES) {
    throw new BchatPayloadSizeExceededError(
      `Ukuran hasil serialisasi ClientState (${encodedBytes.length} bytes) melebihi batas kebijakan (${MAX_CLIENT_STATE_BYTES} bytes).`
    );
  }

  // MUST NOT ZERO: buffer dikembalikan tanpa memutasi atau mengosongkan byte internal
  return encodedBytes;
}

// =============================================================================
// Operasi Strict Decode ClientState
// =============================================================================

/**
 * Memvalidasi keabsahan struktur minimum ClientState pasca-pemulihan biner
 */
function validateDecodedClientState(decoded: unknown): asserts decoded is ClientState {
  if (!decoded || typeof decoded !== 'object') {
    throw new BchatStateStructureError('Hasil dekode ClientState bernilai null atau bukan objek.');
  }

  const state = decoded as Record<string, unknown>;

  // 1. groupContext tersedia
  if (!state.groupContext || typeof state.groupContext !== 'object') {
    throw new BchatStateStructureError("Hasil dekode tidak memiliki field 'groupContext' yang valid.");
  }

  const gc = state.groupContext as Record<string, unknown>;

  // 2. groupContext.groupId adalah Uint8Array
  if (!(gc.groupId instanceof Uint8Array)) {
    throw new BchatStateStructureError(
      "Field 'groupContext.groupId' hasil pemulihan bukan merupakan Uint8Array."
    );
  }

  // 3. groupContext.epoch adalah bigint
  if (typeof gc.epoch !== 'bigint') {
    throw new BchatStateStructureError(
      `Field 'groupContext.epoch' hasil pemulihan bukan merupakan bigint (terdeteksi: ${typeof gc.epoch}).`
    );
  }

  // 4. ratchetTree tersedia
  if (!state.ratchetTree || typeof state.ratchetTree !== 'object') {
    throw new BchatStateStructureError("Hasil dekode tidak memiliki field 'ratchetTree' yang valid.");
  }
}

/**
 * Memulihkan objek ClientState dari buffer biner secara ketat (Strict Decoding).
 *
 * Persyaratan Keamanan:
 * - Menolak input bukan Uint8Array atau input kosong.
 * - Menolak input yang melebihi batas 2.097.152 bytes (2 MiB).
 * - Menolak versi serializer yang tidak didukung.
 * - Memeriksa bahwa decoder mengonsumsi byte secara penuh (bytesConsumed === buffer.length).
 * - Menolak trailing bytes (apapun byte tambahan di belakang payload valid).
 * - Menolak buffer terpotong atau malformed (decoder mengembalikan undefined).
 * - Memvalidasi integritas struktur ClientState hasil dekode (groupContext, groupId, epoch bigint, ratchetTree).
 *
 * @param bytes Buffer data biner yang akan didekode
 * @param expectedSerializerVersion Versi serializer yang diharapkan (default: 1)
 * @returns Objek ClientState tervalidasi yang siap digunakan oleh MLS Worker
 */
export function decodeClientStateStrict(
  bytes: unknown,
  expectedSerializerVersion: number = BCAT_CLIENT_STATE_SERIALIZER_VERSION
): ClientState {
  // 1. Validasi versi serializer
  if (
    typeof expectedSerializerVersion !== 'number' ||
    !Number.isInteger(expectedSerializerVersion) ||
    expectedSerializerVersion !== BCAT_CLIENT_STATE_SERIALIZER_VERSION
  ) {
    throw new BchatUnsupportedSerializerVersionError(
      `Versi serializer ${String(expectedSerializerVersion)} tidak didukung (didukung: ${BCAT_CLIENT_STATE_SERIALIZER_VERSION}).`
    );
  }

  // 2. Validasi tipe data input (wajib Uint8Array)
  if (!(bytes instanceof Uint8Array)) {
    throw new BchatCodecInputError(
      `Input buffer ClientState harus bertipe Uint8Array (diterima: ${bytes === null ? 'null' : typeof bytes}).`
    );
  }

  // 3. Menolak input buffer kosong (0 bytes)
  if (bytes.length === 0) {
    throw new BchatCodecInputError('Input buffer ClientState kosong (0 bytes). Decoding ditolak.');
  }

  // 4. Menegakkan batas ukuran kebijakan aplikasi (2 MiB)
  if (bytes.length > MAX_CLIENT_STATE_BYTES) {
    throw new BchatPayloadSizeExceededError(
      `Ukuran buffer masukan (${bytes.length} bytes) melebihi batas kebijakan aplikasi (${MAX_CLIENT_STATE_BYTES} bytes).`
    );
  }

  // 5. Eksekusi decoder tuple resmi ts-mls: (buffer, offset) => [T, bytesConsumed] | undefined
  let decodeResult: [ClientState, number] | undefined;
  try {
    decodeResult = clientStateDecoder(bytes, 0);
  } catch (err: any) {
    throw new BchatCodecDecodeError(
      `Decoder ts-mls clientStateDecoder melempar exception saat memproses buffer: ${err?.message || 'Unknown error'}`,
      err
    );
  }

  // 6. Validasi hasil pemanggilan decoder
  if (decodeResult === undefined) {
    throw new BchatCodecDecodeError(
      'Gagal mendekode buffer biner ClientState: decoder mengembalikan undefined (buffer terpotong, korup, atau bukan TLS ClientState valid).'
    );
  }

  const [decodedState, bytesConsumed] = decodeResult;

  // 7. Menolak jika decoder mengonsumsi nol byte
  if (typeof bytesConsumed !== 'number' || bytesConsumed <= 0) {
    throw new BchatCodecDecodeError(
      `Decoder melaporkan jumlah byte terkonsumsi tidak valid: ${String(bytesConsumed)}.`
    );
  }

  // 8. Menolak trailing bytes: seluruh byte buffer wajib terkonsumsi secara presisi
  if (bytesConsumed !== bytes.length) {
    throw new BchatCodecDecodeError(
      `Terdeteksi trailing bytes pada buffer masukan: decoder mengonsumsi ${bytesConsumed} bytes dari total ${bytes.length} bytes.`
    );
  }

  // 9. Validasi integritas struktur minimum ClientState
  validateDecodedClientState(decodedState);

  // 10. Kembalikan state hasil pemulihan yang aman
  return decodedState;
}

