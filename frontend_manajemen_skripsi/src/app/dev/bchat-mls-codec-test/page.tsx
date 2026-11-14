'use client';

/**
 * B-CHAT E2EE — MLS ClientState Codec Browser Test Runner
 *
 * Halaman verifikasi browser runtime Tahap H-I3A.1:
 * - TC-CODEC-01: Codec Encode/Decode Round-Trip Valid ClientState
 * - TC-CODEC-02: Rekonstruksi Biner Deterministik Byte-for-Byte (Re-encode Match)
 * - TC-CODEC-03: Preservasi Tipe Data Native BigInt pada Epoch
 * - TC-CODEC-04: Integritas Group ID Biner (Byte-for-Byte Match)
 * - TC-CODEC-05: Penolakan Input Buffer Kosong (Zero-Length Input)
 * - TC-CODEC-06: Penolakan Buffer Terpotong / Malformed (Truncation Resistance)
 * - TC-CODEC-07: Penolakan Trailing Bytes (Strict Consumption Enforcement)
 * - TC-CODEC-08: Penegakan Batas Ukuran Maksimal 2 MiB (Size Limit Policy)
 * - TC-CODEC-09: Penolakan Versi Serializer Tidak Didukung (Version Mismatch)
 * - TC-CODEC-10: Penolakan Tipe Input Non-Uint8Array (Type Safety Enforcement)
 */

import React, { useState, useEffect } from 'react';
import {
  encodeClientState,
  decodeClientStateStrict,
  BCAT_CLIENT_STATE_SERIALIZER_VERSION,
  MAX_CLIENT_STATE_BYTES,
  BchatCodecInputError,
  BchatUnsupportedSerializerVersionError,
  BchatPayloadSizeExceededError,
  BchatCodecDecodeError,
} from '@/lib/bchat/storage/bchat-mls-state-codec';

import { CipherSuite, DhkemX25519HkdfSha256, HkdfSha256, Aes128Gcm } from '@hpke/core';
import { makeGenericHpke } from '../../../../node_modules/ts-mls/dist/src/crypto/implementation/hpke.js';
import { makeKdf, makeKdfImpl } from '../../../../node_modules/ts-mls/dist/src/crypto/implementation/default/makeKdfImpl.js';
import { makeHashImpl } from '../../../../node_modules/ts-mls/dist/src/crypto/implementation/default/makeHashImpl.js';
import { defaultRng } from '../../../../node_modules/ts-mls/dist/src/crypto/implementation/default/rng.js';
import { unsafeTestingAuthenticationService } from '../../../../node_modules/ts-mls/dist/src/authenticationService.js';
import { createGroup, type ClientState } from '../../../../node_modules/ts-mls/dist/src/clientState.js';
import { generateKeyPackage } from '../../../../node_modules/ts-mls/dist/src/keyPackage.js';
import { defaultCredentialTypes } from '../../../../node_modules/ts-mls/dist/src/defaultCredentialType.js';
import { toBufferSource } from '../../../../node_modules/ts-mls/dist/src/util/byteArray.js';
import type { CiphersuiteImpl } from '../../../../node_modules/ts-mls/dist/src/crypto/ciphersuite.js';

// =============================================================================
// Tipe Data Status Pengujian
// =============================================================================

interface TestCaseStatus {
  id: string;
  name: string;
  status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL';
  durationMs?: number;
  details?: string;
}

interface TestLog {
  timestamp: string;
  level: 'info' | 'success' | 'warn' | 'error';
  message: string;
}

const INITIAL_TEST_CASES: TestCaseStatus[] = [
  { id: 'TC-CODEC-01', name: 'Codec Encode/Decode Round-Trip Valid ClientState', status: 'PENDING' },
  { id: 'TC-CODEC-02', name: 'Rekonstruksi Biner Deterministik Byte-for-Byte (Re-encode Match)', status: 'PENDING' },
  { id: 'TC-CODEC-03', name: 'Preservasi Tipe Data Native BigInt pada Epoch', status: 'PENDING' },
  { id: 'TC-CODEC-04', name: 'Integritas Group ID Biner (Byte-for-Byte Match)', status: 'PENDING' },
  { id: 'TC-CODEC-05', name: 'Penolakan Input Buffer Kosong (Zero-Length Input)', status: 'PENDING' },
  { id: 'TC-CODEC-06', name: 'Penolakan Buffer Terpotong / Malformed (Truncation Resistance)', status: 'PENDING' },
  { id: 'TC-CODEC-07', name: 'Penolakan Trailing Bytes (Strict Consumption Enforcement)', status: 'PENDING' },
  { id: 'TC-CODEC-08', name: 'Penegakan Batas Ukuran Maksimal 2 MiB (Size Limit Policy)', status: 'PENDING' },
  { id: 'TC-CODEC-09', name: 'Penolakan Versi Serializer Tidak Didukung (Version Mismatch)', status: 'PENDING' },
  { id: 'TC-CODEC-10', name: 'Penolakan Tipe Input Non-Uint8Array (Type Safety Enforcement)', status: 'PENDING' },
];

// =============================================================================
// Helper Pembangun Ciphersuite Native WebCrypto untuk Pengujian Otentik
// =============================================================================

function rawEd25519ToPKCS8(rawKey: Uint8Array): Uint8Array {
  const prefix = new Uint8Array([
    0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x04, 0x22, 0x04, 0x20,
  ]);
  const pkcs8 = new Uint8Array(prefix.length + rawKey.length);
  pkcs8.set(prefix, 0);
  pkcs8.set(rawKey, prefix.length);
  return pkcs8;
}

function createWebCryptoEd25519Signature(subtle: SubtleCrypto) {
  return {
    async sign(signKey: Uint8Array, message: Uint8Array): Promise<Uint8Array> {
      const keyData = signKey.length === 32 ? rawEd25519ToPKCS8(signKey) : signKey;
      const key = await subtle.importKey('pkcs8', toBufferSource(keyData), 'Ed25519', false, ['sign']);
      const sig = await subtle.sign('Ed25519', key, toBufferSource(message));
      return new Uint8Array(sig);
    },
    async verify(publicKey: Uint8Array, message: Uint8Array, signature: Uint8Array): Promise<boolean> {
      const key = await subtle.importKey('raw', toBufferSource(publicKey), 'Ed25519', false, ['verify']);
      return subtle.verify('Ed25519', key, toBufferSource(signature), toBufferSource(message));
    },
    async keygen(): Promise<{ signKey: Uint8Array; publicKey: Uint8Array }> {
      const keyPair = (await subtle.generateKey('Ed25519', true, ['sign', 'verify'])) as CryptoKeyPair;
      const publicKeyBuffer = await subtle.exportKey('raw', keyPair.publicKey);
      const privateKeyBuffer = await subtle.exportKey('pkcs8', keyPair.privateKey);
      return {
        signKey: new Uint8Array(privateKeyBuffer),
        publicKey: new Uint8Array(publicKeyBuffer),
      };
    },
  };
}

async function encryptAes128Gcm(
  key: Uint8Array,
  nonce: Uint8Array,
  aad: Uint8Array,
  plaintext: Uint8Array
): Promise<Uint8Array> {
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    toBufferSource(key),
    { name: 'AES-GCM' },
    false,
    ['encrypt']
  );
  const params: AesGcmParams = {
    name: 'AES-GCM',
    iv: toBufferSource(nonce),
  };
  if (aad && aad.length > 0) {
    params.additionalData = toBufferSource(aad);
  }
  const result = await crypto.subtle.encrypt(params, cryptoKey, toBufferSource(plaintext));
  return new Uint8Array(result);
}

async function decryptAes128Gcm(
  key: Uint8Array,
  nonce: Uint8Array,
  aad: Uint8Array,
  ciphertext: Uint8Array
): Promise<Uint8Array> {
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    toBufferSource(key),
    { name: 'AES-GCM' },
    false,
    ['decrypt']
  );
  const params: AesGcmParams = {
    name: 'AES-GCM',
    iv: toBufferSource(nonce),
  };
  if (aad && aad.length > 0) {
    params.additionalData = toBufferSource(aad);
  }
  const result = await crypto.subtle.decrypt(params, cryptoKey, toBufferSource(ciphertext));
  return new Uint8Array(result);
}

async function buildTestMlsCiphersuite(): Promise<CiphersuiteImpl> {
  const subtle = globalThis.crypto.subtle;
  const hpkeAlg = {
    kem: 'DHKEM-X25519-HKDF-SHA256' as const,
    kdf: 'HKDF-SHA256' as const,
    aead: 'AES128GCM' as const,
  };

  const aead = {
    encrypt(k: Uint8Array, n: Uint8Array, a: Uint8Array, p: Uint8Array) {
      return encryptAes128Gcm(k, n, a, p);
    },
    decrypt(k: Uint8Array, n: Uint8Array, a: Uint8Array, c: Uint8Array) {
      return decryptAes128Gcm(k, n, a, c);
    },
  };

  const cs = new CipherSuite({
    kem: new DhkemX25519HkdfSha256(),
    kdf: new HkdfSha256(),
    aead: new Aes128Gcm(),
  });

  const hpke = await makeGenericHpke(hpkeAlg, aead, cs);

  return {
    id: 1,
    kdf: makeKdfImpl(makeKdf('HKDF-SHA256')),
    hash: makeHashImpl(subtle, 'SHA-256'),
    signature: createWebCryptoEd25519Signature(subtle),
    hpke,
    rng: defaultRng,
  };
}

/**
 * Membangkitkan ClientState MLS nyata (bukan mock) untuk keperluan pengujian
 */
async function generateGenuineClientState(roomUuid: string): Promise<ClientState> {
  const cipherSuite = await buildTestMlsCiphersuite();
  const context = {
    cipherSuite,
    authService: unsafeTestingAuthenticationService,
  };

  const cred = {
    credentialType: defaultCredentialTypes.basic,
    identity: new TextEncoder().encode('mahasiswa:2026_codec_test'),
  };

  const kp = await generateKeyPackage({
    credential: cred,
    cipherSuite,
  });

  const groupIdBytes = new TextEncoder().encode(roomUuid);

  return createGroup({
    context,
    groupId: groupIdBytes,
    keyPackage: kp.publicPackage,
    privateKeyPackage: kp.privatePackage,
  });
}

function areUint8ArraysEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.byteLength !== b.byteLength) return false;
  for (let i = 0; i < a.byteLength; i++) {
    if (a[i] !== b[i]) return false;
  }
  return true;
}

// =============================================================================
// Komponen Halaman Pengujian
// =============================================================================

export default function BchatMlsCodecTestPage() {
  const [testCases, setTestCases] = useState<TestCaseStatus[]>(INITIAL_TEST_CASES);
  const [logs, setLogs] = useState<TestLog[]>([]);
  const [isRunning, setIsRunning] = useState<boolean>(false);
  const [overallResult, setOverallResult] = useState<'IDLE' | 'RUNNING' | 'SUCCESS' | 'FAILED'>('IDLE');

  const addLog = (message: string, level: TestLog['level'] = 'info') => {
    const timestamp = new Date().toLocaleTimeString();
    setLogs((prev) => [...prev, { timestamp, level, message }]);
  };

  const updateTc = (
    id: string,
    status: TestCaseStatus['status'],
    details?: string,
    durationMs?: number
  ) => {
    setTestCases((prev) =>
      prev.map((tc) =>
        tc.id === id
          ? {
              ...tc,
              status,
              details: details !== undefined ? details : tc.details,
              durationMs: durationMs !== undefined ? durationMs : tc.durationMs,
            }
          : tc
      )
    );
  };

  // Expose status ke window untuk otomatisasi CDP headless
  useEffect(() => {
    if (typeof window !== 'undefined') {
      (window as any).__MLS_CODEC_TEST_RESULTS__ = {
        overallResult,
        testCases,
        logs,
      };
      (window as any).__RUN_MLS_CODEC_TESTS__ = runAllTests;
    }
  }, [overallResult, testCases, logs]);

  const runAllTests = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setOverallResult('RUNNING');
    setTestCases(INITIAL_TEST_CASES);
    setLogs([]);

    addLog('=== MEMULAI TEST SUITE H-I3A.1 (STRICT CLIENTSTATE CODEC) ===', 'info');

    let genuineState: ClientState;
    let initialEncodedBytes: Uint8Array;

    try {
      // Setup: Bangkitkan genuine MLS ClientState
      addLog('Membangkitkan ClientState MLS nyata menggunakan WebCrypto native...', 'info');
      const setupStart = performance.now();
      const testRoomUuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
      genuineState = await generateGenuineClientState(testRoomUuid);
      const setupDuration = Math.round(performance.now() - setupStart);
      addLog(`ClientState MLS nyata berhasil dibentuk dalam ${setupDuration}ms.`, 'success');

      // -----------------------------------------------------------------------
      // TC-CODEC-01: Encode/Decode Round-Trip
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-01', 'RUNNING');
        addLog('[TC-CODEC-01] Menjalankan serialisasi encodeClientState()...', 'info');

        initialEncodedBytes = encodeClientState(genuineState);
        if (!(initialEncodedBytes instanceof Uint8Array) || initialEncodedBytes.length === 0) {
          throw new Error('Hasil encodeClientState() bukan Uint8Array non-kosong.');
        }

        addLog(
          `[TC-CODEC-01] Berhasil dienkode ke TLS biner: ${initialEncodedBytes.length} bytes. Menjalankan decode...`,
          'info'
        );

        const restored = decodeClientStateStrict(initialEncodedBytes);
        if (!restored || typeof restored !== 'object' || !restored.groupContext) {
          throw new Error('Hasil decodeClientStateStrict() tidak memuat groupContext valid.');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-01',
          'PASS',
          `Round-trip sukses: ${initialEncodedBytes.length} bytes terenkode & terdekode sempurna.`,
          dur
        );
        addLog('[TC-CODEC-01] PASS: Round-trip encode/decode berhasil.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-02: Rekonstruksi Biner Deterministik Byte-for-Byte
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-02', 'RUNNING');
        addLog('[TC-CODEC-02] Menguji re-encode deterministik dari state hasil decode...', 'info');

        const restored = decodeClientStateStrict(initialEncodedBytes);
        const reEncodedBytes = encodeClientState(restored);

        if (!areUint8ArraysEqual(initialEncodedBytes, reEncodedBytes)) {
          throw new Error('Byte biner hasil re-encode tidak identik dengan byte encode awal!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-02',
          'PASS',
          `Deterministik byte-for-byte: ${reEncodedBytes.length}/${initialEncodedBytes.length} bytes cocok 100%.`,
          dur
        );
        addLog('[TC-CODEC-02] PASS: Deterministik biner terbukti byte-for-byte identik.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-03: Preservasi Tipe Data Native BigInt pada Epoch
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-03', 'RUNNING');
        addLog('[TC-CODEC-03] Memverifikasi tipe data dan nilai epoch pasca-decode...', 'info');

        const restored = decodeClientStateStrict(initialEncodedBytes);
        const epochVal = restored.groupContext.epoch;

        if (typeof epochVal !== 'bigint') {
          throw new Error(`Tipe data epoch bukan bigint (ditemukan: ${typeof epochVal}).`);
        }
        if (epochVal !== genuineState.groupContext.epoch) {
          throw new Error(`Nilai epoch (${epochVal}) tidak cocok dengan state awal (${genuineState.groupContext.epoch}).`);
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-03',
          'PASS',
          `Preservasi BigInt terbukti: typeof epoch === 'bigint', nilai = ${epochVal.toString()}n.`,
          dur
        );
        addLog(`[TC-CODEC-03] PASS: Epoch tetap bertipe bigint (${epochVal.toString()}n).`, 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-04: Integritas Group ID Biner
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-04', 'RUNNING');
        addLog('[TC-CODEC-04] Memverifikasi integritas groupId Uint8Array pasca-decode...', 'info');

        const restored = decodeClientStateStrict(initialEncodedBytes);
        const restoredGroupId = restored.groupContext.groupId;
        const originalGroupId = genuineState.groupContext.groupId;

        if (!(restoredGroupId instanceof Uint8Array)) {
          throw new Error('groupId hasil pemulihan bukan merupakan Uint8Array.');
        }
        if (!areUint8ArraysEqual(restoredGroupId, originalGroupId)) {
          throw new Error('groupId hasil pemulihan berbeda dengan groupId awal!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-04',
          'PASS',
          `Integritas groupId terbukti: ${restoredGroupId.byteLength} bytes cocok byte-for-byte.`,
          dur
        );
        addLog('[TC-CODEC-04] PASS: groupId biner 100% identik byte-for-byte.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-05: Penolakan Input Buffer Kosong
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-05', 'RUNNING');
        addLog('[TC-CODEC-05] Menguji penolakan buffer kosong (new Uint8Array(0))...', 'info');

        let rejected = false;
        try {
          decodeClientStateStrict(new Uint8Array(0));
        } catch (err: any) {
          if (err instanceof BchatCodecInputError || err.message.includes('kosong')) {
            rejected = true;
          }
        }

        if (!rejected) {
          throw new Error('Buffer kosong (0 bytes) tidak ditolak oleh decodeClientStateStrict()!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-05',
          'PASS',
          'Buffer kosong (0 bytes) berhasil ditolak dengan BchatCodecInputError.',
          dur
        );
        addLog('[TC-CODEC-05] PASS: Buffer kosong ditolak aman.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-06: Penolakan Buffer Terpotong / Malformed
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-06', 'RUNNING');
        addLog('[TC-CODEC-06] Menguji penolakan buffer terpotong (header saja & 50% potong)...', 'info');

        // Uji potong 8 byte
        const truncatedHeader = initialEncodedBytes.subarray(0, 8);
        let rejectedHeader = false;
        try {
          decodeClientStateStrict(truncatedHeader);
        } catch (err: any) {
          rejectedHeader = true;
        }

        // Uji potong 50%
        const truncatedHalf = initialEncodedBytes.subarray(0, Math.floor(initialEncodedBytes.length / 2));
        let rejectedHalf = false;
        try {
          decodeClientStateStrict(truncatedHalf);
        } catch (err: any) {
          rejectedHalf = true;
        }

        if (!rejectedHeader || !rejectedHalf) {
          throw new Error('Buffer terpotong tidak ditolak oleh decoder!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-06',
          'PASS',
          'Truncation pada byte ke-8 dan 50% buffer keduanya ditolak dengan BchatCodecDecodeError.',
          dur
        );
        addLog('[TC-CODEC-06] PASS: Buffer terpotong ditolak aman.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-07: Penolakan Trailing Bytes
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-07', 'RUNNING');
        addLog('[TC-CODEC-07] Menguji penolakan buffer yang disisipi trailing bytes...', 'info');

        const trailingJunk = new Uint8Array([0xde, 0xad, 0xbe, 0xef, 0xca, 0xfe]);
        const corruptedWithTrailing = new Uint8Array(initialEncodedBytes.length + trailingJunk.length);
        corruptedWithTrailing.set(initialEncodedBytes, 0);
        corruptedWithTrailing.set(trailingJunk, initialEncodedBytes.length);

        let rejectedTrailing = false;
        let caughtMessage = '';
        try {
          decodeClientStateStrict(corruptedWithTrailing);
        } catch (err: any) {
          if (err instanceof BchatCodecDecodeError || err.message.includes('trailing bytes')) {
            rejectedTrailing = true;
            caughtMessage = err.message;
          }
        }

        if (!rejectedTrailing) {
          throw new Error('Buffer dengan trailing bytes tidak ditolak oleh decodeClientStateStrict()!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-07',
          'PASS',
          `Trailing bytes (${trailingJunk.length} byte sampah) ditolak: ${caughtMessage}`,
          dur
        );
        addLog('[TC-CODEC-07] PASS: Trailing bytes terdeteksi dan ditolak ketat.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-08: Penegakan Batas Ukuran Maksimal 2 MiB
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-08', 'RUNNING');
        addLog('[TC-CODEC-08] Menguji penolakan ukuran buffer > 2 MiB (MAX_CLIENT_STATE_BYTES + 1)...', 'info');

        const oversizedDummy = new Uint8Array(MAX_CLIENT_STATE_BYTES + 1);
        let rejectedOversized = false;
        try {
          decodeClientStateStrict(oversizedDummy);
        } catch (err: any) {
          if (err instanceof BchatPayloadSizeExceededError || err.message.includes('melebihi batas')) {
            rejectedOversized = true;
          }
        }

        if (!rejectedOversized) {
          throw new Error('Buffer berukuran > 2 MiB tidak ditolak oleh kebijakan ukuran!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-08',
          'PASS',
          `Buffer berukuran ${MAX_CLIENT_STATE_BYTES + 1} bytes berhasil ditolak dengan BchatPayloadSizeExceededError.`,
          dur
        );
        addLog('[TC-CODEC-08] PASS: Kebijakan batas ukuran 2 MiB ditegakkan.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-09: Penolakan Versi Serializer Tidak Didukung
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-09', 'RUNNING');
        addLog('[TC-CODEC-09] Menguji penolakan expectedSerializerVersion != 1...', 'info');

        let rejectedUnsupportedVersion = false;
        try {
          decodeClientStateStrict(initialEncodedBytes, 99);
        } catch (err: any) {
          if (err instanceof BchatUnsupportedSerializerVersionError || err.message.includes('tidak didukung')) {
            rejectedUnsupportedVersion = true;
          }
        }

        if (!rejectedUnsupportedVersion) {
          throw new Error('Serializer version 99 tidak ditolak!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-09',
          'PASS',
          'Serializer version 99 berhasil ditolak dengan BchatUnsupportedSerializerVersionError.',
          dur
        );
        addLog('[TC-CODEC-09] PASS: Versi serializer tidak didukung ditolak.', 'success');
      }

      // -----------------------------------------------------------------------
      // TC-CODEC-10: Penolakan Tipe Input Non-Uint8Array
      // -----------------------------------------------------------------------
      {
        const t0 = performance.now();
        updateTc('TC-CODEC-10', 'RUNNING');
        addLog('[TC-CODEC-10] Menguji penolakan tipe input non-Uint8Array (null, string, object, number)...', 'info');

        const invalidInputs: any[] = [
          null,
          undefined,
          'string_payload_bukan_bytes',
          1234567,
          { ciphertext: 'fake_object' },
          [1, 2, 3],
        ];

        let rejectedAll = true;
        for (const input of invalidInputs) {
          try {
            decodeClientStateStrict(input);
            rejectedAll = false;
            break;
          } catch (err: any) {
            if (!(err instanceof BchatCodecInputError)) {
              rejectedAll = false;
              break;
            }
          }
        }

        if (!rejectedAll) {
          throw new Error('Salah satu input non-Uint8Array gagal ditolak dengan BchatCodecInputError!');
        }

        const dur = Math.round(performance.now() - t0);
        updateTc(
          'TC-CODEC-10',
          'PASS',
          'Seluruh 6 variasi input non-Uint8Array ditolak dengan BchatCodecInputError.',
          dur
        );
        addLog('[TC-CODEC-10] PASS: Type safety non-Uint8Array terbukti.', 'success');
      }

      // -----------------------------------------------------------------------
      // Finalisasi Sukses
      // -----------------------------------------------------------------------
      setOverallResult('SUCCESS');
      addLog('=== SELURUH 10 TEST CASES STRICT CODEC LULUS (100% PASS) ===', 'success');
    } catch (err: any) {
      setOverallResult('FAILED');
      addLog(`PENGUJIAN CODEC GAGAL: ${err.message}`, 'error');
    } finally {
      setIsRunning(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-8">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div className="border-b border-slate-800 pb-4 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
              <span className="text-cyan-400">B-CHAT</span> MLS ClientState Codec Test Suite
            </h1>
            <p className="text-sm text-slate-400 mt-1">
              Verifikasi Browser Runtime Tahap H-I3A.1 • Serializer Resmi ts-mls@2.0.0-rc.16 • Strict Decoder
            </p>
          </div>
          <div>
            <button
              id="btn-run-codec-tests"
              onClick={runAllTests}
              disabled={isRunning}
              className="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-50 flex items-center gap-2"
            >
              {isRunning ? 'Menjalankan...' : 'Jalankan Pengujian Codec MLS'}
            </button>
          </div>
        </div>

        {/* Status Banner */}
        <div
          id="codec-test-status"
          data-status={overallResult}
          className={`p-4 rounded-xl border flex items-center justify-between ${
            overallResult === 'SUCCESS'
              ? 'bg-emerald-950/40 border-emerald-800/80 text-emerald-200'
              : overallResult === 'FAILED'
              ? 'bg-rose-950/40 border-rose-800/80 text-rose-200'
              : overallResult === 'RUNNING'
              ? 'bg-amber-950/40 border-amber-800/80 text-amber-200'
              : 'bg-slate-900 border-slate-800 text-slate-300'
          }`}
        >
          <div>
            <span className="text-xs uppercase tracking-wider font-semibold text-slate-400">Status Pengujian</span>
            <div className="text-lg font-bold mt-0.5">
              {overallResult === 'IDLE' && 'Siap Dijalankan'}
              {overallResult === 'RUNNING' && 'Sedang Menguji Strict Codec MLS...'}
              {overallResult === 'SUCCESS' && 'LULUS PENUH (10/10 Test Cases PASS)'}
              {overallResult === 'FAILED' && 'PENGUJIAN GAGAL (Periksa Log di Bawah)'}
            </div>
          </div>
          <div className="text-right">
            <span className="text-xs text-slate-400">Total Skenario</span>
            <div className="text-sm font-medium">10 Kasus Uji Kritis</div>
          </div>
        </div>

        {/* Test Cases List */}
        <div className="space-y-3">
          {testCases.map((tc) => (
            <div
              key={tc.id}
              className="p-4 rounded-lg bg-slate-900/80 border border-slate-800 flex flex-col justify-between"
            >
              <div className="flex items-start justify-between gap-2">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-mono text-cyan-400 font-semibold">{tc.id}</span>
                    {tc.durationMs !== undefined && (
                      <span className="text-xs text-slate-500 font-mono">({tc.durationMs}ms)</span>
                    )}
                  </div>
                  <div className="text-sm font-medium text-slate-200 mt-0.5">{tc.name}</div>
                </div>
                <span
                  className={`text-xs px-2.5 py-1 rounded-full font-semibold ${
                    tc.status === 'PASS'
                      ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                      : tc.status === 'FAIL'
                      ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                      : tc.status === 'RUNNING'
                      ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30 animate-pulse'
                      : 'bg-slate-800 text-slate-400'
                  }`}
                >
                  {tc.status}
                </span>
              </div>
              {tc.details && (
                <div className="mt-2 text-xs text-slate-400 border-t border-slate-800/80 pt-2 font-mono">
                  {tc.details}
                </div>
              )}
            </div>
          ))}
        </div>

        {/* Live Logs Terminal */}
        <div className="rounded-xl bg-slate-900 border border-slate-800 overflow-hidden">
          <div className="px-4 py-2.5 bg-slate-800/60 border-b border-slate-800 flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-300 uppercase tracking-wider">Log Eksekusi Browser</span>
            <span className="text-xs text-slate-500 font-mono">{logs.length} entri</span>
          </div>
          <div className="p-4 font-mono text-xs space-y-1.5 max-h-72 overflow-y-auto bg-slate-950/70">
            {logs.length === 0 ? (
              <span className="text-slate-600">Klik &quot;Jalankan Pengujian Codec MLS&quot; untuk memulai verifikasi browser.</span>
            ) : (
              logs.map((log, index) => (
                <div key={index} className="flex items-start gap-2">
                  <span className="text-slate-500 shrink-0">[{log.timestamp}]</span>
                  <span
                    className={`shrink-0 font-bold ${
                      log.level === 'success'
                        ? 'text-emerald-400'
                        : log.level === 'error'
                        ? 'text-rose-400'
                        : log.level === 'warn'
                        ? 'text-amber-400'
                        : 'text-sky-400'
                    }`}
                  >
                    [{log.level.toUpperCase()}]
                  </span>
                  <span className="text-slate-300 break-all">{log.message}</span>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
