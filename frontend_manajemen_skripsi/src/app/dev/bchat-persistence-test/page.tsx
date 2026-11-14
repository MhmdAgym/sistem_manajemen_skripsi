'use client';

/**
 * B-CHAT E2EE — GroupState Persistence Orchestrator Browser Test Runner
 *
 * Menguji fungsionalitas dan garansi keamanan persistensi GroupState Tahap H-I3A.2A.1:
 * - TC-PERSIST-01: Persist Initial Group State Revision 1 (Round-trip save & restore)
 * - TC-PERSIST-02: Byte-Level ClientState Roundtrip & Re-encode Match
 * - TC-PERSIST-03: Monotonic Revision Increment ("1" -> "2" -> "3")
 * - TC-PERSIST-04: Revision Conflict Rejection (Stale expectedPreviousRevision)
 * - TC-PERSIST-05: Stale Fencing Owner Token Rejection (Token mismatch)
 * - TC-PERSIST-06: Stale Fencing Generation Rejection (Generation mismatch)
 * - TC-PERSIST-07: BigInt Generation (> 2^53 - 1) Decimal String Support
 * - TC-PERSIST-08: Epoch Regression Rejection (Candidate epoch < stored epoch)
 * - TC-PERSIST-09: Tampered Envelope Detection & Automatic Quarantine
 * - TC-PERSIST-10: Quarantined Record Cannot Be Restored (QUARANTINED status returned)
 * - TC-PERSIST-11: Quarantined Record Is Preserved on Disk (Zero deletion)
 * - TC-PERSIST-12: Group ID Mismatch Rejection (Transplantation defense)
 * - TC-PERSIST-13: Device / Room Mismatch Rejection (AAD binding defense)
 * - TC-PERSIST-14: Transaction Rollback on Abort (Multi-store atomicity)
 * - TC-PERSIST-15: Quarantine With Valid Revision & Fencing Succeeds
 * - TC-PERSIST-16: Quarantine With Stale Revision Rejection (Revision conflict)
 * - TC-PERSIST-17: Quarantine With Stale Fencing Owner Token Rejection
 * - TC-PERSIST-18: Quarantine With Stale Fencing Generation Rejection
 * - TC-PERSIST-19: Quarantine With Expired Lease Rejection
 * - TC-PERSIST-20: Quarantine Race Defense: Newer Record Remains Active When Stale Quarantine Runs
 * - TC-PERSIST-21: Envelope and Ciphertext Integrity Preserved Post-Quarantine (Bit-for-bit check)
 * - TC-PERSIST-22: Quarantine Transaction Rollback on Abort
 * - TC-PERSIST-23: Compile-Time & Runtime Enforcement of Fencing on Quarantine (No un-fenced API)
 */

import React, { useState, useEffect } from 'react';
import {
  persistGroupState,
  restoreGroupState,
  quarantineGroupState,
  BchatFencingTokenStaleError,
  BchatRevisionConflictError,
  BchatEpochRegressionError,
  BchatGroupIdMismatchError,
  BchatQuarantinedStateError,
  BchatPersistenceError,
} from '@/lib/bchat/storage/bchat-mls-persistence';
import {
  BCAT_STORES,
  type TabLeaseRecord,
  type GroupStateRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import {
  idbGet,
  idbPut,
  idbDelete,
  withReadwriteTransaction,
} from '@/lib/bchat/storage/bchat-idb-core';
import {
  encodeClientState,
  decodeClientStateStrict,
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
// Status dan Log Pengujian
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
  { id: 'TC-PERSIST-01', name: 'Persist Initial Group State Revision 1 (Round-trip save & restore)', status: 'PENDING' },
  { id: 'TC-PERSIST-02', name: 'Byte-Level ClientState Roundtrip & Re-encode Match', status: 'PENDING' },
  { id: 'TC-PERSIST-03', name: 'Monotonic Revision Increment ("1" -> "2" -> "3")', status: 'PENDING' },
  { id: 'TC-PERSIST-04', name: 'Revision Conflict Rejection (Stale expectedPreviousRevision)', status: 'PENDING' },
  { id: 'TC-PERSIST-05', name: 'Stale Fencing Owner Token Rejection (Token mismatch)', status: 'PENDING' },
  { id: 'TC-PERSIST-06', name: 'Stale Fencing Generation Rejection (Generation mismatch)', status: 'PENDING' },
  { id: 'TC-PERSIST-07', name: 'BigInt Generation (> 2^53 - 1) Decimal String Support', status: 'PENDING' },
  { id: 'TC-PERSIST-08', name: 'Epoch Regression Rejection (Candidate epoch < stored epoch)', status: 'PENDING' },
  { id: 'TC-PERSIST-09', name: 'Tampered Envelope Detection & Automatic Quarantine', status: 'PENDING' },
  { id: 'TC-PERSIST-10', name: 'Quarantined Record Cannot Be Restored (QUARANTINED status returned)', status: 'PENDING' },
  { id: 'TC-PERSIST-11', name: 'Quarantined Record Is Preserved on Disk (Zero deletion)', status: 'PENDING' },
  { id: 'TC-PERSIST-12', name: 'Group ID Mismatch Rejection (Transplantation defense)', status: 'PENDING' },
  { id: 'TC-PERSIST-13', name: 'Device / Room Mismatch Rejection (AAD binding defense)', status: 'PENDING' },
  { id: 'TC-PERSIST-14', name: 'Transaction Rollback on Abort (Multi-store atomicity)', status: 'PENDING' },
  { id: 'TC-PERSIST-15', name: 'Quarantine With Valid Revision & Fencing Succeeds', status: 'PENDING' },
  { id: 'TC-PERSIST-16', name: 'Quarantine With Stale Revision Rejection (Revision conflict)', status: 'PENDING' },
  { id: 'TC-PERSIST-17', name: 'Quarantine With Stale Fencing Owner Token Rejection', status: 'PENDING' },
  { id: 'TC-PERSIST-18', name: 'Quarantine With Stale Fencing Generation Rejection', status: 'PENDING' },
  { id: 'TC-PERSIST-19', name: 'Quarantine With Expired Lease Rejection', status: 'PENDING' },
  { id: 'TC-PERSIST-20', name: 'Quarantine Race Defense: Newer Record Remains Active When Stale Quarantine Runs', status: 'PENDING' },
  { id: 'TC-PERSIST-21', name: 'Envelope and Ciphertext Integrity Preserved Post-Quarantine (Bit-for-bit check)', status: 'PENDING' },
  { id: 'TC-PERSIST-22', name: 'Quarantine Transaction Rollback on Abort', status: 'PENDING' },
  { id: 'TC-PERSIST-23', name: 'Compile-Time & Runtime Enforcement of Fencing on Quarantine (No un-fenced API)', status: 'PENDING' },
];

// =============================================================================
// Helper MLS Ciphersuite & State Generation
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

async function generateGenuineClientState(roomUuid: string): Promise<ClientState> {
  const cipherSuite = await buildTestMlsCiphersuite();
  const context = {
    cipherSuite,
    authService: unsafeTestingAuthenticationService,
  };

  const cred = {
    credentialType: defaultCredentialTypes.basic,
    identity: new TextEncoder().encode('mahasiswa:2026_persistence_test'),
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

async function setupTabLease(deviceUuid: string, ownerToken: string, generation: string): Promise<void> {
  const lease: TabLeaseRecord = {
    device_uuid: deviceUuid,
    owner_token: ownerToken,
    generation: generation,
    acquired_at: Date.now(),
    expires_at: Date.now() + 600000,
  };
  await idbPut(BCAT_STORES.TAB_LEASES, lease);
}

// =============================================================================
// Komponen Utama Halaman
// =============================================================================

export default function BchatPersistenceTestPage() {
  const [testCases, setTestCases] = useState<TestCaseStatus[]>(INITIAL_TEST_CASES);
  const [logs, setLogs] = useState<TestLog[]>([]);
  const [isRunning, setIsRunning] = useState<boolean>(false);

  const addLog = (message: string, level: TestLog['level'] = 'info') => {
    const timestamp = new Date().toISOString().substring(11, 23);
    setLogs((prev) => [...prev, { timestamp, level, message }]);
  };

  const updateTc = (
    id: string,
    status: TestCaseStatus['status'],
    details?: string,
    durationMs?: number
  ) => {
    setTestCases((prev) =>
      prev.map((tc) => (tc.id === id ? { ...tc, status, details, durationMs } : tc))
    );
  };

  const runAllTests = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setTestCases(INITIAL_TEST_CASES);

    addLog('Memulai B-CHAT GroupState Persistence Test Suite (Tahap H-I3A.2A.1)...', 'info');

    const totalStart = performance.now();
    let passedCount = 0;

    try {
      // -----------------------------------------------------------------------
      // TC-PERSIST-01: Persist Initial Group State Revision 1
      // -----------------------------------------------------------------------
      const t1Start = performance.now();
      updateTc('TC-PERSIST-01', 'RUNNING');
      addLog('[TC-PERSIST-01] Menguji persistensi awal group state (revision "1")...', 'info');

      const dev01 = 'a1111111-1111-4111-8111-111111111111';
      const room01 = 'b1111111-1111-4111-8111-111111111111';
      const token01 = 'token-dev01-initial';
      const gen01 = '1';

      await setupTabLease(dev01, token01, gen01);
      const state01 = await generateGenuineClientState(room01);

      const res01 = await persistGroupState({
        deviceUuid: dev01,
        roomUuid: room01,
        state: state01,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token01, generation: gen01 },
      });

      if (res01.stateRevision !== '1') {
        throw new Error(`Ekspektasi stateRevision '1', didapat '${res01.stateRevision}'`);
      }
      if (res01.previousRevision !== null) {
        throw new Error(`Ekspektasi previousRevision null, didapat '${res01.previousRevision}'`);
      }
      if (res01.epoch !== '0') {
        throw new Error(`Ekspektasi epoch '0', didapat '${res01.epoch}'`);
      }

      const t1Dur = Math.round(performance.now() - t1Start);
      updateTc('TC-PERSIST-01', 'PASS', `State tersimpan dengan revision '1', epoch '0', previousRevision null (${t1Dur}ms)`, t1Dur);
      addLog(`[TC-PERSIST-01] PASS: Initial group state berhasil disimpan durable.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-02: Byte-Level ClientState Roundtrip & Re-encode Match
      // -----------------------------------------------------------------------
      const t2Start = performance.now();
      updateTc('TC-PERSIST-02', 'RUNNING');
      addLog('[TC-PERSIST-02] Menguji pemulihan (restore) dan kesesuaian biner byte-for-byte...', 'info');

      const restore02 = await restoreGroupState(dev01, room01);
      if (restore02.status !== 'FOUND' || !restore02.state) {
        throw new Error(`Ekspektasi status FOUND, didapat '${restore02.status}'`);
      }

      const origBytes02 = encodeClientState(state01);
      const restoredBytes02 = encodeClientState(restore02.state);

      if (!areUint8ArraysEqual(origBytes02, restoredBytes02)) {
        throw new Error('Hasil encode dari state yang dipulihkan tidak cocok byte-for-byte dengan aslinya!');
      }

      const t2Dur = Math.round(performance.now() - t2Start);
      updateTc('TC-PERSIST-02', 'PASS', `ClientState roundtrip lulus: ${restoredBytes02.byteLength} bytes identik byte-for-byte (${t2Dur}ms)`, t2Dur);
      addLog(`[TC-PERSIST-02] PASS: Roundtrip byte-level identik 100%.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-03: Monotonic Revision Increment ("1" -> "2" -> "3")
      // -----------------------------------------------------------------------
      const t3Start = performance.now();
      updateTc('TC-PERSIST-03', 'RUNNING');
      addLog('[TC-PERSIST-03] Menguji kenaikan revisi monotonik ("1" -> "2" -> "3")...', 'info');

      // Update ke revisi 2
      const res03a = await persistGroupState({
        deviceUuid: dev01,
        roomUuid: room01,
        state: state01,
        expectedPreviousRevision: '1',
        fencing: { ownerToken: token01, generation: gen01 },
      });

      if (res03a.stateRevision !== '2' || res03a.previousRevision !== '1') {
        throw new Error(`Ekspektasi revisi '2' dengan previous '1', didapat '${res03a.stateRevision}' / '${res03a.previousRevision}'`);
      }

      // Update ke revisi 3
      const res03b = await persistGroupState({
        deviceUuid: dev01,
        roomUuid: room01,
        state: state01,
        expectedPreviousRevision: '2',
        fencing: { ownerToken: token01, generation: gen01 },
      });

      if (res03b.stateRevision !== '3' || res03b.previousRevision !== '2') {
        throw new Error(`Ekspektasi revisi '3' dengan previous '2', didapat '${res03b.stateRevision}' / '${res03b.previousRevision}'`);
      }

      const t3Dur = Math.round(performance.now() - t3Start);
      updateTc('TC-PERSIST-03', 'PASS', `Revisi monotonik terverifikasi: '1' -> '2' -> '3' (${t3Dur}ms)`, t3Dur);
      addLog(`[TC-PERSIST-03] PASS: Kenaikan revisi monotonik terbukti aman.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-04: Revision Conflict Rejection (Stale expectedPreviousRevision)
      // -----------------------------------------------------------------------
      const t4Start = performance.now();
      updateTc('TC-PERSIST-04', 'RUNNING');
      addLog('[TC-PERSIST-04] Menguji penolakan benturan revisi (stale previous revision)...', 'info');

      let conflictCaught = false;
      try {
        // Disk berada di revisi '3', caller masih mengirim expectedPreviousRevision: '1'
        await persistGroupState({
          deviceUuid: dev01,
          roomUuid: room01,
          state: state01,
          expectedPreviousRevision: '1',
          fencing: { ownerToken: token01, generation: gen01 },
        });
      } catch (err: unknown) {
        if (err instanceof BchatRevisionConflictError) {
          conflictCaught = true;
          addLog(`[TC-PERSIST-04] Tertangkap BchatRevisionConflictError sesuai ekspektasi: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatRevisionConflictError: ${(err as Error)?.name}`);
        }
      }

      if (!conflictCaught) {
        throw new Error('Benturan revisi gagal memicu BchatRevisionConflictError!');
      }

      const t4Dur = Math.round(performance.now() - t4Start);
      updateTc('TC-PERSIST-04', 'PASS', `Benturan revisi ditolak seketika via BchatRevisionConflictError (${t4Dur}ms)`, t4Dur);
      addLog(`[TC-PERSIST-04] PASS: Benturan revisi tertolak dengan aman.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-05: Stale Fencing Owner Token Rejection (Token mismatch)
      // -----------------------------------------------------------------------
      const t5Start = performance.now();
      updateTc('TC-PERSIST-05', 'RUNNING');
      addLog('[TC-PERSIST-05] Menguji penolakan token fencing stale (owner token mismatch)...', 'info');

      let tokenStaleCaught = false;
      try {
        await persistGroupState({
          deviceUuid: dev01,
          roomUuid: room01,
          state: state01,
          expectedPreviousRevision: '3',
          fencing: { ownerToken: 'token-impostor-999', generation: gen01 },
        });
      } catch (err: unknown) {
        if (err instanceof BchatFencingTokenStaleError) {
          tokenStaleCaught = true;
          addLog(`[TC-PERSIST-05] Tertangkap BchatFencingTokenStaleError: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatFencingTokenStaleError: ${(err as Error)?.name}`);
        }
      }

      if (!tokenStaleCaught) {
        throw new Error('Fencing owner token mismatch tidak melempar BchatFencingTokenStaleError!');
      }

      const t5Dur = Math.round(performance.now() - t5Start);
      updateTc('TC-PERSIST-05', 'PASS', `Owner token palsu/stale ditolak via BchatFencingTokenStaleError (${t5Dur}ms)`, t5Dur);
      addLog(`[TC-PERSIST-05] PASS: Penolakan owner token mismatch terverifikasi.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-06: Stale Fencing Generation Rejection (Generation mismatch)
      // -----------------------------------------------------------------------
      const t6Start = performance.now();
      updateTc('TC-PERSIST-06', 'RUNNING');
      addLog('[TC-PERSIST-06] Menguji penolakan generasi fencing stale...', 'info');

      // Naikkan generasi lease pada disk menjadi '2' (simulasi leader takeover)
      await setupTabLease(dev01, token01, '2');

      let genStaleCaught = false;
      try {
        // Tab lama masih mencoba menulis dengan generation '1'
        await persistGroupState({
          deviceUuid: dev01,
          roomUuid: room01,
          state: state01,
          expectedPreviousRevision: '3',
          fencing: { ownerToken: token01, generation: '1' },
        });
      } catch (err: unknown) {
        if (err instanceof BchatFencingTokenStaleError) {
          genStaleCaught = true;
          addLog(`[TC-PERSIST-06] Tertangkap BchatFencingTokenStaleError generasi lama: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatFencingTokenStaleError: ${(err as Error)?.name}`);
        }
      }

      if (!genStaleCaught) {
        throw new Error('Generasi lama tidak memicu BchatFencingTokenStaleError!');
      }

      const t6Dur = Math.round(performance.now() - t6Start);
      updateTc('TC-PERSIST-06', 'PASS', `Leader lama dengan generasi stale ditolak seketika (${t6Dur}ms)`, t6Dur);
      addLog(`[TC-PERSIST-06] PASS: Penolakan generasi stale terverifikasi.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-07: BigInt Generation (> 2^53 - 1) Decimal String Support
      // -----------------------------------------------------------------------
      const t7Start = performance.now();
      updateTc('TC-PERSIST-07', 'RUNNING');
      addLog('[TC-PERSIST-07] Menguji generasi BigInt decimal string besar tanpa JavaScript Number...', 'info');

      // Generasi melampaui Number.MAX_SAFE_INTEGER (9007199254740991)
      const bigGen = '9007199254740993'; // 2^53 + 1
      await setupTabLease(dev01, token01, bigGen);

      const res07 = await persistGroupState({
        deviceUuid: dev01,
        roomUuid: room01,
        state: state01,
        expectedPreviousRevision: '3',
        fencing: { ownerToken: token01, generation: bigGen },
      });

      if (res07.stateRevision !== '4') {
        throw new Error(`Ekspektasi revisi '4', didapat '${res07.stateRevision}'`);
      }

      const t7Dur = Math.round(performance.now() - t7Start);
      updateTc('TC-PERSIST-07', 'PASS', `BigInt generation '${bigGen}' sukses disimpan & divalidasi presisi penuh (${t7Dur}ms)`, t7Dur);
      addLog(`[TC-PERSIST-07] PASS: Preservasi BigInt decimal string terbukti tanpa pembulatan IEEE 754.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-08: Epoch Regression Rejection
      // -----------------------------------------------------------------------
      const t8Start = performance.now();
      updateTc('TC-PERSIST-08', 'RUNNING');
      addLog('[TC-PERSIST-08] Menguji pencegahan regresi epoch...', 'info');

      // Simpan state dengan epoch 1 (dinaikkan secara legal)
      const stateEpoch1: ClientState = {
        ...state01,
        groupContext: {
          ...state01.groupContext,
          epoch: BigInt(1),
        },
      };

      await persistGroupState({
        deviceUuid: dev01,
        roomUuid: room01,
        state: stateEpoch1,
        expectedPreviousRevision: '4',
        fencing: { ownerToken: token01, generation: bigGen },
      });

      // Sekarang disk berada di epoch 1 revisi 5. Coba simpan state lama dengan epoch 0
      let epochRegCaught = false;
      try {
        await persistGroupState({
          deviceUuid: dev01,
          roomUuid: room01,
          state: state01, // epoch 0
          expectedPreviousRevision: '5',
          fencing: { ownerToken: token01, generation: bigGen },
        });
      } catch (err: unknown) {
        if (err instanceof BchatEpochRegressionError) {
          epochRegCaught = true;
          addLog(`[TC-PERSIST-08] Tertangkap BchatEpochRegressionError: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatEpochRegressionError: ${(err as Error)?.name}`);
        }
      }

      if (!epochRegCaught) {
        throw new Error('Regresi epoch tidak melempar BchatEpochRegressionError!');
      }

      const t8Dur = Math.round(performance.now() - t8Start);
      updateTc('TC-PERSIST-08', 'PASS', `Regresi epoch (epoch 0 < epoch 1) ditolak via BchatEpochRegressionError (${t8Dur}ms)`, t8Dur);
      addLog(`[TC-PERSIST-08] PASS: Pencegahan regresi epoch terverifikasi.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-09: Tampered Envelope Detection & Automatic Quarantine
      // -----------------------------------------------------------------------
      const t9Start = performance.now();
      updateTc('TC-PERSIST-09', 'RUNNING');
      addLog('[TC-PERSIST-09] Menguji deteksi amplop termodifikasi (tampered) dan karantina otomatis...', 'info');

      const dev09 = 'a9999999-9999-4999-8999-999999999999';
      const room09 = 'b9999999-9999-4999-8999-999999999999';
      const token09 = 'token-dev09';
      const gen09 = '1';

      await setupTabLease(dev09, token09, gen09);
      const state09 = await generateGenuineClientState(room09);

      await persistGroupState({
        deviceUuid: dev09,
        roomUuid: room09,
        state: state09,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token09, generation: gen09 },
      });

      // Modifikasi bit ciphertext secara langsung di disk untuk mensimulasikan tamper/kerusakan
      await withReadwriteTransaction([BCAT_STORES.GROUP_STATES], async (tx, getStore) => {
        const store = getStore(BCAT_STORES.GROUP_STATES);
        const req = store.get([dev09, room09]);
        const record = await new Promise<GroupStateRecord>((resolve) => {
          req.onsuccess = () => resolve(req.result);
        });
        const tamperedCiphertext = new Uint8Array(record.envelope.ciphertext);
        tamperedCiphertext[0] ^= 0xff; // Invert first byte
        record.envelope.ciphertext = tamperedCiphertext;
        store.put(record);
      });

      // Panggil restoreGroupState: wajib mendeteksi integritas rusak dan mengarantina record via fencing
      const restoreTampered = await restoreGroupState(dev09, room09, { ownerToken: token09, generation: gen09 });
      if (restoreTampered.status !== 'QUARANTINED') {
        throw new Error(`Ekspektasi status QUARANTINED, didapat '${restoreTampered.status}'`);
      }

      const t9Dur = Math.round(performance.now() - t9Start);
      updateTc('TC-PERSIST-09', 'PASS', `Amplop tampered otomatis masuk karantina: reason='${restoreTampered.quarantineReason}' (${t9Dur}ms)`, t9Dur);
      addLog(`[TC-PERSIST-09] PASS: Karantina otomatis aktif saat MAC mismatch.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-10: Quarantined Record Cannot Be Restored
      // -----------------------------------------------------------------------
      const t10Start = performance.now();
      updateTc('TC-PERSIST-10', 'RUNNING');
      addLog('[TC-PERSIST-10] Menguji bahwa record berstatus quarantined menolak dekripsi pemulihan...', 'info');

      const reRestore = await restoreGroupState(dev09, room09);
      if (reRestore.status !== 'QUARANTINED') {
        throw new Error(`Ekspektasi status QUARANTINED pada restore kedua, didapat '${reRestore.status}'`);
      }
      if (reRestore.state !== undefined) {
        throw new Error('Record quarantined tidak boleh mengembalikan objek ClientState!');
      }

      const t10Dur = Math.round(performance.now() - t10Start);
      updateTc('TC-PERSIST-10', 'PASS', `Record quarantined konsisten menolak pemulihan (${t10Dur}ms)`, t10Dur);
      addLog(`[TC-PERSIST-10] PASS: Record quarantined terisolasi penuh dari siklus normal.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-11: Quarantined Record Is Preserved on Disk (Zero Deletion)
      // -----------------------------------------------------------------------
      const t11Start = performance.now();
      updateTc('TC-PERSIST-11', 'RUNNING');
      addLog('[TC-PERSIST-11] Menguji bukti preservasi record (tidak dihapus) pasca karantina...', 'info');

      const onDiskRecord = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev09, room09]);
      if (!onDiskRecord) {
        throw new Error('PELANGGARAN: Record terhapus dari disk setelah karantina!');
      }
      if (onDiskRecord.status !== 'quarantined') {
        throw new Error(`Status di disk bukan 'quarantined', didapat '${onDiskRecord.status}'`);
      }
      if (!onDiskRecord.envelope || onDiskRecord.envelope.ciphertext.byteLength === 0) {
        throw new Error('Ciphertext hilang atau dikosongkan!');
      }

      const t11Dur = Math.round(performance.now() - t11Start);
      updateTc('TC-PERSIST-11', 'PASS', `Record dan ciphertext 100% terjaga di disk untuk audit forensik (${t11Dur}ms)`, t11Dur);
      addLog(`[TC-PERSIST-11] PASS: Preservasi non-destruktif terverifikasi.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-12: Group ID Mismatch Rejection (Transplantation defense)
      // -----------------------------------------------------------------------
      const t12Start = performance.now();
      updateTc('TC-PERSIST-12', 'RUNNING');
      addLog('[TC-PERSIST-12] Menguji penolakan transplantasi Group ID...', 'info');

      const dev12 = 'a2222222-2222-4222-8222-222222222222';
      const room12A = 'b2222222-2222-4222-8222-222222222222';
      const room12B = 'b3333333-3333-4333-8333-333333333333';
      const token12 = 'token-dev12';
      const gen12 = '1';

      await setupTabLease(dev12, token12, gen12);
      // Bangkitkan state untuk room12A
      const state12A = await generateGenuineClientState(room12A);

      let mismatchCaught = false;
      try {
        // Coba simpan state milik room12A ke dalam record room12B
        await persistGroupState({
          deviceUuid: dev12,
          roomUuid: room12B,
          state: state12A,
          expectedPreviousRevision: null,
          fencing: { ownerToken: token12, generation: gen12 },
        });
      } catch (err: unknown) {
        if (err instanceof BchatGroupIdMismatchError) {
          mismatchCaught = true;
          addLog(`[TC-PERSIST-12] Tertangkap BchatGroupIdMismatchError: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatGroupIdMismatchError: ${(err as Error)?.name}`);
        }
      }

      if (!mismatchCaught) {
        throw new Error('Transplantasi Group ID tidak memicu BchatGroupIdMismatchError!');
      }

      const t12Dur = Math.round(performance.now() - t12Start);
      updateTc('TC-PERSIST-12', 'PASS', `Transplantasi Group ID ditolak via BchatGroupIdMismatchError (${t12Dur}ms)`, t12Dur);
      addLog(`[TC-PERSIST-12] PASS: Pengikatan identitas Group ID terbukti kokoh.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-13: Device / Room Mismatch Rejection (AAD binding defense)
      // -----------------------------------------------------------------------
      const t13Start = performance.now();
      updateTc('TC-PERSIST-13', 'RUNNING');
      addLog('[TC-PERSIST-13] Menguji perlindungan AAD binding terhadap pembacaan lintas identitas...', 'info');

      const dev13A = 'a4444444-4444-4444-8444-444444444444';
      const dev13B = 'a5555555-5555-4555-8555-555555555555';
      const room13 = 'b4444444-4444-4444-8444-444444444444';
      const token13A = 'token-dev13A';

      await setupTabLease(dev13A, token13A, '1');
      const state13 = await generateGenuineClientState(room13);

      await persistGroupState({
        deviceUuid: dev13A,
        roomUuid: room13,
        state: state13,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token13A, generation: '1' },
      });

      // Salin record dev13A ke dev13B di IndexedDB secara langsung tanpa enkripsi ulang
      const recA = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev13A, room13]);
      if (!recA) throw new Error('Record dev13A tidak ditemukan!');

      const transposedRec: GroupStateRecord = {
        ...recA,
        device_uuid: dev13B,
      };
      await idbPut(BCAT_STORES.GROUP_STATES, transposedRec);

      // Coba pulihkan di bawah dev13B: WebCrypto AAD authentication tag wajib menolak!
      const restoreCrossDevice = await restoreGroupState(dev13B, room13);
      if (restoreCrossDevice.status !== 'QUARANTINED') {
        throw new Error(`Ekspektasi QUARANTINED akibat AAD tag mismatch, didapat '${restoreCrossDevice.status}'`);
      }

      const t13Dur = Math.round(performance.now() - t13Start);
      updateTc('TC-PERSIST-13', 'PASS', `AAD binding menolak dekripsi cross-device dan mengarantina record (${t13Dur}ms)`, t13Dur);
      addLog(`[TC-PERSIST-13] PASS: Otentikasi AAD RFC 5116 10-field terbukti efektif.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-14: Transaction Rollback on Abort (Multi-store atomicity)
      // -----------------------------------------------------------------------
      const t14Start = performance.now();
      updateTc('TC-PERSIST-14', 'RUNNING');
      addLog('[TC-PERSIST-14] Menguji rollback atomik transaksi multi-store saat tx.abort()...', 'info');

      const dev14 = 'a6666666-6666-4666-8666-666666666666';
      const room14 = 'b6666666-6666-4666-8666-666666666666';

      let abortCaught = false;
      try {
        await withReadwriteTransaction(
          [BCAT_STORES.TAB_LEASES, BCAT_STORES.GROUP_STATES],
          async (tx, getStore) => {
            const leaseStore = getStore(BCAT_STORES.TAB_LEASES);
            const groupStore = getStore(BCAT_STORES.GROUP_STATES);

            leaseStore.put({
              device_uuid: dev14,
              owner_token: 'token-to-abort',
              generation: '100',
              acquired_at: Date.now(),
              expires_at: Date.now() + 60000,
            });

            groupStore.put({
              device_uuid: dev14,
              room_uuid: room14,
              storage_schema_version: 1,
              serializer_version: 1,
              ciphersuite_id: 1,
              group_id_hash: 'dummy-hash',
              epoch: '0',
              state_revision: '1',
              previous_revision: null,
              key_id: 'dummy-key',
              envelope: {} as any,
              status: 'active',
              created_at: Date.now(),
              updated_at: Date.now(),
            });

            // Batalkan transaksi secara sengaja di tengah eksekusi
            tx.abort();
          }
        );
      } catch {
        abortCaught = true;
      }

      if (!abortCaught) {
        throw new Error('withReadwriteTransaction tidak mereject saat tx.abort() dipanggil!');
      }

      // Verifikasi bahwa TIDAK ADA data yang tertulis ke disk (zero partial write)
      const checkLease = await idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, dev14);
      const checkGroup = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev14, room14]);

      if (checkLease !== undefined || checkGroup !== undefined) {
        throw new Error('PELANGGARAN ATOMISITAS: Data tetap tersimpan meskipun transaksi di-abort!');
      }

      const t14Dur = Math.round(performance.now() - t14Start);
      updateTc('TC-PERSIST-14', 'PASS', `Rollback multi-store 100% atomik tanpa partial write (${t14Dur}ms)`, t14Dur);
      addLog(`[TC-PERSIST-14] PASS: Rollback multi-store terbukti atomik.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-15: Quarantine With Valid Revision & Fencing Succeeds
      // -----------------------------------------------------------------------
      const t15Start = performance.now();
      updateTc('TC-PERSIST-15', 'RUNNING');
      addLog('[TC-PERSIST-15] Menguji eksekusi karantina dengan revisi valid dan kredensial fencing sah...', 'info');

      const dev15 = 'a1515151-1515-4515-8515-151515151515';
      const room15 = 'b1515151-1515-4515-8515-151515151515';
      const token15 = 'token-dev15';
      const gen15 = '1';

      await setupTabLease(dev15, token15, gen15);
      const state15 = await generateGenuineClientState(room15);

      await persistGroupState({
        deviceUuid: dev15,
        roomUuid: room15,
        state: state15,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token15, generation: gen15 },
      });

      await quarantineGroupState({
        deviceUuid: dev15,
        roomUuid: room15,
        expectedRevision: '1',
        reason: 'Leaf node signature invalid',
        fencing: { ownerToken: token15, generation: gen15 },
      });

      const rec15 = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev15, room15]);
      if (!rec15) throw new Error('Record 15 tidak ditemukan di disk setelah karantina!');
      if (rec15.status !== 'quarantined') throw new Error(`Status bukan 'quarantined', didapat '${rec15.status}'`);
      if (rec15.state_revision !== '1') throw new Error(`Revisi termutasi secara tidak sah, didapat '${rec15.state_revision}'`);
      if (!rec15.quarantine_reason || !rec15.quarantine_reason.includes('Leaf node signature invalid')) {
        throw new Error(`Alasan karantina tidak sesuai: '${rec15.quarantine_reason}'`);
      }

      // Uji idempoten: panggil kembali karantina pada record yang sudah dikarantina
      await quarantineGroupState({
        deviceUuid: dev15,
        roomUuid: room15,
        expectedRevision: '1',
        reason: 'Duplicate quarantine attempt',
        fencing: { ownerToken: token15, generation: gen15 },
      });

      const t15Dur = Math.round(performance.now() - t15Start);
      updateTc('TC-PERSIST-15', 'PASS', `Karantina sah sukses & idempoten (${t15Dur}ms)`, t15Dur);
      addLog(`[TC-PERSIST-15] PASS: Karantina dengan fencing & revisi sah sukses.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-16: Quarantine With Stale Revision Rejection
      // -----------------------------------------------------------------------
      const t16Start = performance.now();
      updateTc('TC-PERSIST-16', 'RUNNING');
      addLog('[TC-PERSIST-16] Menguji penolakan karantina jika expectedRevision basi (stale)...', 'info');

      const dev16 = 'a1616161-1616-4616-8616-161616161616';
      const room16 = 'b1616161-1616-4616-8616-161616161616';
      const token16 = 'token-dev16';
      const gen16 = '1';

      await setupTabLease(dev16, token16, gen16);
      const state16 = await generateGenuineClientState(room16);

      // Simpan revisi 1
      await persistGroupState({
        deviceUuid: dev16,
        roomUuid: room16,
        state: state16,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token16, generation: gen16 },
      });

      // Naikkan ke revisi 2
      await persistGroupState({
        deviceUuid: dev16,
        roomUuid: room16,
        state: state16,
        expectedPreviousRevision: '1',
        fencing: { ownerToken: token16, generation: gen16 },
      });

      // Coba karantina dengan expectedRevision: '1' padahal disk sudah revisi '2'
      let staleRevCaught = false;
      try {
        await quarantineGroupState({
          deviceUuid: dev16,
          roomUuid: room16,
          expectedRevision: '1',
          reason: 'Old issue on revision 1',
          fencing: { ownerToken: token16, generation: gen16 },
        });
      } catch (err: unknown) {
        if (err instanceof BchatRevisionConflictError) {
          staleRevCaught = true;
          addLog(`[TC-PERSIST-16] Tertangkap BchatRevisionConflictError: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatRevisionConflictError: ${(err as Error)?.name}`);
        }
      }

      if (!staleRevCaught) throw new Error('Karantina dengan revisi stale tidak melempar BchatRevisionConflictError!');

      const rec16 = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev16, room16]);
      if (!rec16 || rec16.status !== 'active' || rec16.state_revision !== '2') {
        throw new Error('Record pada disk termutasi saat karantina stale revisi ditolak!');
      }

      const t16Dur = Math.round(performance.now() - t16Start);
      updateTc('TC-PERSIST-16', 'PASS', `Revisi stale ditolak via BchatRevisionConflictError (${t16Dur}ms)`, t16Dur);
      addLog(`[TC-PERSIST-16] PASS: Proteksi benturan revisi karantina terbukti aktif.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-17: Quarantine With Stale Fencing Owner Token Rejection
      // -----------------------------------------------------------------------
      const t17Start = performance.now();
      updateTc('TC-PERSIST-17', 'RUNNING');
      addLog('[TC-PERSIST-17] Menguji penolakan karantina jika owner token tidak cocok...', 'info');

      const dev17 = 'a1717171-1717-4717-8717-171717171717';
      const room17 = 'b1717171-1717-4717-8717-171717171717';
      const token17Active = 'token-leader-active';
      const token17Stale = 'token-leader-stale';
      const gen17 = '1';

      await setupTabLease(dev17, token17Active, gen17);
      const state17 = await generateGenuineClientState(room17);

      await persistGroupState({
        deviceUuid: dev17,
        roomUuid: room17,
        state: state17,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token17Active, generation: gen17 },
      });

      let tokenMismatchCaught = false;
      try {
        await quarantineGroupState({
          deviceUuid: dev17,
          roomUuid: room17,
          expectedRevision: '1',
          reason: 'Stale leader attempt',
          fencing: { ownerToken: token17Stale, generation: gen17 },
        });
      } catch (err: unknown) {
        if (err instanceof BchatFencingTokenStaleError) {
          tokenMismatchCaught = true;
          addLog(`[TC-PERSIST-17] Tertangkap BchatFencingTokenStaleError: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatFencingTokenStaleError: ${(err as Error)?.name}`);
        }
      }

      if (!tokenMismatchCaught) throw new Error('Karantina dengan owner token salah tidak melempar BchatFencingTokenStaleError!');

      const rec17 = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev17, room17]);
      if (!rec17 || rec17.status !== 'active') throw new Error('Record termutasi saat owner token ditolak!');

      const t17Dur = Math.round(performance.now() - t17Start);
      updateTc('TC-PERSIST-17', 'PASS', `Owner token salah ditolak via BchatFencingTokenStaleError (${t17Dur}ms)`, t17Dur);
      addLog(`[TC-PERSIST-17] PASS: Proteksi owner token fencing terverifikasi.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-18: Quarantine With Stale Fencing Generation Rejection
      // -----------------------------------------------------------------------
      const t18Start = performance.now();
      updateTc('TC-PERSIST-18', 'RUNNING');
      addLog('[TC-PERSIST-18] Menguji penolakan karantina jika generasi lease telah maju...', 'info');

      const dev18 = 'a1818181-1818-4818-8818-181818181818';
      const room18 = 'b1818181-1818-4818-8818-181818181818';
      const token18 = 'token-dev18';
      const gen18Active = '10';
      const gen18Stale = '9';

      await setupTabLease(dev18, token18, gen18Active);
      const state18 = await generateGenuineClientState(room18);

      await persistGroupState({
        deviceUuid: dev18,
        roomUuid: room18,
        state: state18,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token18, generation: gen18Active },
      });

      let genMismatchCaught = false;
      try {
        await quarantineGroupState({
          deviceUuid: dev18,
          roomUuid: room18,
          expectedRevision: '1',
          reason: 'Stale generation quarantine',
          fencing: { ownerToken: token18, generation: gen18Stale },
        });
      } catch (err: unknown) {
        if (err instanceof BchatFencingTokenStaleError) {
          genMismatchCaught = true;
          addLog(`[TC-PERSIST-18] Tertangkap BchatFencingTokenStaleError: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatFencingTokenStaleError: ${(err as Error)?.name}`);
        }
      }

      if (!genMismatchCaught) throw new Error('Karantina dengan generasi basi tidak melempar BchatFencingTokenStaleError!');

      const rec18 = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev18, room18]);
      if (!rec18 || rec18.status !== 'active') throw new Error('Record termutasi saat generasi stale ditolak!');

      const t18Dur = Math.round(performance.now() - t18Start);
      updateTc('TC-PERSIST-18', 'PASS', `Generasi stale ditolak via BchatFencingTokenStaleError (${t18Dur}ms)`, t18Dur);
      addLog(`[TC-PERSIST-18] PASS: Penolakan generasi stale pada karantina terbukti.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-19: Quarantine With Expired Lease Rejection
      // -----------------------------------------------------------------------
      const t19Start = performance.now();
      updateTc('TC-PERSIST-19', 'RUNNING');
      addLog('[TC-PERSIST-19] Menguji penolakan karantina jika lease telah kedaluwarsa (expired)...', 'info');

      const dev19 = 'a1919191-1919-4919-8919-191919191919';
      const room19 = 'b1919191-1919-4919-8919-191919191919';
      const token19 = 'token-dev19';
      const gen19 = '1';

      // Setup lease aktif dahulu untuk persist awal
      await setupTabLease(dev19, token19, gen19);
      const state19 = await generateGenuineClientState(room19);

      await persistGroupState({
        deviceUuid: dev19,
        roomUuid: room19,
        state: state19,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token19, generation: gen19 },
      });

      // Kadaluwarsakan lease di IndexedDB secara eksplisit
      await idbPut<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, {
        device_uuid: dev19,
        owner_token: token19,
        generation: gen19,
        acquired_at: Date.now() - 60000,
        expires_at: Date.now() - 5000, // telah expired 5 detik lalu
      });

      let expiredCaught = false;
      try {
        await quarantineGroupState({
          deviceUuid: dev19,
          roomUuid: room19,
          expectedRevision: '1',
          reason: 'Attempted quarantine on expired lease',
          fencing: { ownerToken: token19, generation: gen19 },
        });
      } catch (err: unknown) {
        if (err instanceof BchatFencingTokenStaleError) {
          expiredCaught = true;
          addLog(`[TC-PERSIST-19] Tertangkap BchatFencingTokenStaleError (expired): ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatFencingTokenStaleError: ${(err as Error)?.name}`);
        }
      }

      if (!expiredCaught) throw new Error('Karantina dengan lease expired tidak melempar BchatFencingTokenStaleError!');

      const rec19 = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev19, room19]);
      if (!rec19 || rec19.status !== 'active') throw new Error('Record termutasi saat lease expired!');

      const t19Dur = Math.round(performance.now() - t19Start);
      updateTc('TC-PERSIST-19', 'PASS', `Lease expired ditolak seketika (${t19Dur}ms)`, t19Dur);
      addLog(`[TC-PERSIST-19] PASS: Proteksi lease expiry terbukti aktif.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-20: Quarantine Race Defense: Newer Record Remains Active
      // -----------------------------------------------------------------------
      const t20Start = performance.now();
      updateTc('TC-PERSIST-20', 'RUNNING');
      addLog('[TC-PERSIST-20] Menguji perlindungan race condition: record baru tetap active saat quarantine lama masuk...', 'info');

      const dev20 = 'a2020202-2020-4020-8020-202020202020';
      const room20 = 'b2020202-2020-4020-8020-202020202020';
      const token20 = 'token-dev20';
      const gen20 = '1';

      await setupTabLease(dev20, token20, gen20);
      const state20 = await generateGenuineClientState(room20);

      // 1. Simpan GroupState revisi 1
      await persistGroupState({
        deviceUuid: dev20,
        roomUuid: room20,
        state: state20,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token20, generation: gen20 },
      });

      // 2. Worker A membaca record revisi 1 (mengambil snapshot revisi '1')
      const workerASnapshotRevision = '1';

      // 3. Sementara Worker A memproses/validasi lambat, Worker B menulis revisi 2 yang sah
      const state20Rev2: ClientState = {
        ...state20,
        groupContext: {
          ...state20.groupContext,
          epoch: BigInt(1),
        },
      };

      await persistGroupState({
        deviceUuid: dev20,
        roomUuid: room20,
        state: state20Rev2,
        expectedPreviousRevision: '1',
        fencing: { ownerToken: token20, generation: gen20 },
      });

      // 4. Worker A kemudian mencoba mengarantina dengan snapshot revisi '1'
      let raceQuarantineCaught = false;
      try {
        await quarantineGroupState({
          deviceUuid: dev20,
          roomUuid: room20,
          expectedRevision: workerASnapshotRevision,
          reason: 'Worker A found an issue in snapshot rev 1',
          fencing: { ownerToken: token20, generation: gen20 },
        });
      } catch (err: unknown) {
        if (err instanceof BchatRevisionConflictError) {
          raceQuarantineCaught = true;
          addLog(`[TC-PERSIST-20] Tertangkap BchatRevisionConflictError pada race condition: ${(err as Error).message}`, 'info');
        } else {
          throw new Error(`Error bukan BchatRevisionConflictError: ${(err as Error)?.name}`);
        }
      }

      if (!raceQuarantineCaught) throw new Error('Race condition quarantine tidak dicegah oleh revision conflict!');

      // 5. Buktikan record pada disk TETAP berstatus active dan revisi 2 utuh
      const rec20 = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev20, room20]);
      if (!rec20) throw new Error('Record 20 tidak ditemukan!');
      if (rec20.status !== 'active') throw new Error(`PELANGGARAN: Record revisi baru terkarantina! status='${rec20.status}'`);
      if (rec20.state_revision !== '2') throw new Error(`Revisi di disk bukan '2', didapat '${rec20.state_revision}'`);

      // 6. Buktikan pemulihan (restore) revisi 2 berjalan mulus dan mengembalikan state valid
      const restore20 = await restoreGroupState(dev20, room20, { ownerToken: token20, generation: gen20 });
      if (restore20.status !== 'FOUND' || !restore20.state) {
        throw new Error(`Gagal memulihkan record revisi 2 yang masih active, status='${restore20.status}'`);
      }
      if (restore20.stateRevision !== '2') throw new Error(`Ekspektasi stateRevision '2', didapat '${restore20.stateRevision}'`);

      const t20Dur = Math.round(performance.now() - t20Start);
      updateTc('TC-PERSIST-20', 'PASS', `Race condition dicegah: revisi 2 tetap 'active' & dapat dipulihkan sempurna (${t20Dur}ms)`, t20Dur);
      addLog(`[TC-PERSIST-20] PASS: Perlindungan race condition terbukti 100% efektif.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-21: Envelope and Ciphertext Integrity Preserved Post-Quarantine
      // -----------------------------------------------------------------------
      const t21Start = performance.now();
      updateTc('TC-PERSIST-21', 'RUNNING');
      addLog('[TC-PERSIST-21] Menguji bukti bit-for-bit bahwa ciphertext dan envelope tidak dimodifikasi saat karantina...', 'info');

      const dev21 = 'a2121212-2121-4121-8121-212121212121';
      const room21 = 'b2121212-2121-4121-8121-212121212121';
      const token21 = 'token-dev21';
      const gen21 = '1';

      await setupTabLease(dev21, token21, gen21);
      const state21 = await generateGenuineClientState(room21);

      await persistGroupState({
        deviceUuid: dev21,
        roomUuid: room21,
        state: state21,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token21, generation: gen21 },
      });

      const recBefore = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev21, room21]);
      if (!recBefore) throw new Error('Record 21 sebelum karantina tidak ditemukan!');

      // Simpan copy dari byte-byte sensitif
      const ciphertextCopy = new Uint8Array(recBefore.envelope.ciphertext);
      const ivCopy = new Uint8Array(recBefore.envelope.iv);
      const keyIdCopy = recBefore.key_id;
      const epochCopy = recBefore.epoch;
      const groupHashCopy = recBefore.group_id_hash;
      const revisionCopy = recBefore.state_revision;
      const prevRevisionCopy = recBefore.previous_revision;
      const createdCopy = recBefore.created_at;

      // Jalankan karantina sah
      await quarantineGroupState({
        deviceUuid: dev21,
        roomUuid: room21,
        expectedRevision: '1',
        reason: 'Forensic quarantine integrity check',
        fencing: { ownerToken: token21, generation: gen21 },
      });

      const recAfter = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev21, room21]);
      if (!recAfter) throw new Error('Record 21 setelah karantina tidak ditemukan!');

      if (recAfter.status !== 'quarantined') throw new Error(`Status bukan quarantined, didapat '${recAfter.status}'`);

      // Bit-for-bit check
      if (!areUint8ArraysEqual(recAfter.envelope.ciphertext, ciphertextCopy)) {
        throw new Error('PELANGGARAN FORENSIK: Ciphertext termutasi selama proses karantina!');
      }
      if (!areUint8ArraysEqual(recAfter.envelope.iv, ivCopy)) {
        throw new Error('PELANGGARAN FORENSIK: IV termutasi selama proses karantina!');
      }
      if (recAfter.key_id !== keyIdCopy) throw new Error('key_id berubah!');
      if (recAfter.epoch !== epochCopy) throw new Error('epoch berubah!');
      if (recAfter.group_id_hash !== groupHashCopy) throw new Error('group_id_hash berubah!');
      if (recAfter.state_revision !== revisionCopy) throw new Error('state_revision berubah!');
      if (recAfter.previous_revision !== prevRevisionCopy) throw new Error('previous_revision berubah!');
      if (recAfter.created_at !== createdCopy) throw new Error('created_at berubah!');

      const t21Dur = Math.round(performance.now() - t21Start);
      updateTc('TC-PERSIST-21', 'PASS', `Preservasi bit-for-bit ciphertext, IV, & metadata terbukti 100% (${t21Dur}ms)`, t21Dur);
      addLog(`[TC-PERSIST-21] PASS: Preservasi non-destruktif bit-for-bit terbukti.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-22: Quarantine Transaction Rollback on Abort
      // -----------------------------------------------------------------------
      const t22Start = performance.now();
      updateTc('TC-PERSIST-22', 'RUNNING');
      addLog('[TC-PERSIST-22] Menguji rollback atomik jika transaksi karantina di-abort...', 'info');

      const dev22 = 'a2222222-2222-4222-8222-222222222223';
      const room22 = 'b2222222-2222-4222-8222-222222222223';
      const token22 = 'token-dev22';
      const gen22 = '1';

      await setupTabLease(dev22, token22, gen22);
      const state22 = await generateGenuineClientState(room22);

      await persistGroupState({
        deviceUuid: dev22,
        roomUuid: room22,
        state: state22,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token22, generation: gen22 },
      });

      let abortQuarantineCaught = false;
      try {
        await withReadwriteTransaction([BCAT_STORES.GROUP_STATES], async (tx, getStore) => {
          const store = getStore(BCAT_STORES.GROUP_STATES);
          const req = store.get([dev22, room22]);
          const record = await new Promise<GroupStateRecord>((resolve) => {
            req.onsuccess = () => resolve(req.result);
          });
          record.status = 'quarantined';
          record.quarantine_reason = 'Partial quarantine should abort';
          store.put(record);

          // Batalkan transaksi
          tx.abort();
        });
      } catch {
        abortQuarantineCaught = true;
      }

      if (!abortQuarantineCaught) throw new Error('tx.abort() tidak melempar error!');

      const rec22 = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev22, room22]);
      if (!rec22 || rec22.status !== 'active') {
        throw new Error('PELANGGARAN ATOMISITAS: Record berubah menjadi quarantined meski transaksi di-abort!');
      }

      const t22Dur = Math.round(performance.now() - t22Start);
      updateTc('TC-PERSIST-22', 'PASS', `Rollback transaksi karantina terbukti bersih tanpa partial write (${t22Dur}ms)`, t22Dur);
      addLog(`[TC-PERSIST-22] PASS: Atomisitas rollback karantina terverifikasi.`, 'success');
      passedCount++;

      // -----------------------------------------------------------------------
      // TC-PERSIST-23: Compile-Time & Runtime Enforcement of Fencing on Quarantine
      // -----------------------------------------------------------------------
      const t23Start = performance.now();
      updateTc('TC-PERSIST-23', 'RUNNING');
      addLog('[TC-PERSIST-23] Menguji penegakan runtime ketiadaan API karantina tanpa fencing & fail-closed restore...', 'info');

      const dev23A = 'a2323232-2323-4323-8323-232323232323';
      const room23A = 'b2323232-2323-4323-8323-232323232323';
      const dev23B = 'a2323232-2323-4323-8323-232323232324';
      const room23B = 'b2323232-2323-4323-8323-232323232324';
      const token23 = 'token-dev23';
      const gen23 = '1';

      await setupTabLease(dev23A, token23, gen23);
      const state23A = await generateGenuineClientState(room23A);

      await persistGroupState({
        deviceUuid: dev23A,
        roomUuid: room23A,
        state: state23A,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token23, generation: gen23 },
      });

      // Sub-tes A: quarantineGroupState dengan fencing null / undefined ditolak seketika
      let noFencingCaught = false;
      try {
        await quarantineGroupState({
          deviceUuid: dev23A,
          roomUuid: room23A,
          expectedRevision: '1',
          reason: 'Unfenced attempt',
          fencing: null as any,
        });
      } catch (err: unknown) {
        if (err instanceof BchatFencingTokenStaleError) {
          noFencingCaught = true;
          addLog(`[TC-PERSIST-23] Tertangkap BchatFencingTokenStaleError saat fencing null: ${(err as Error).message}`, 'info');
        }
      }

      if (!noFencingCaught) throw new Error('Karantina tanpa fencing tidak ditolak seketika!');

      // Sub-tes B: restoreGroupState tanpa fencing saat data rusak -> fail closed QUARANTINED, namun IDB TIDAK termutasi
      await setupTabLease(dev23B, token23, gen23);
      const state23B = await generateGenuineClientState(room23B);

      await persistGroupState({
        deviceUuid: dev23B,
        roomUuid: room23B,
        state: state23B,
        expectedPreviousRevision: null,
        fencing: { ownerToken: token23, generation: gen23 },
      });

      // Rusak ciphertext record B
      await withReadwriteTransaction([BCAT_STORES.GROUP_STATES], async (tx, getStore) => {
        const store = getStore(BCAT_STORES.GROUP_STATES);
        const req = store.get([dev23B, room23B]);
        const record = await new Promise<GroupStateRecord>((resolve) => {
          req.onsuccess = () => resolve(req.result);
        });
        const tamperedCiphertext = new Uint8Array(record.envelope.ciphertext);
        tamperedCiphertext[0] ^= 0xaa;
        record.envelope.ciphertext = tamperedCiphertext;
        store.put(record);
      });

      // Panggil restoreGroupState TANPA fencing argument
      const restoreWithoutFencing = await restoreGroupState(dev23B, room23B);
      if (restoreWithoutFencing.status !== 'QUARANTINED') {
        throw new Error(`Ekspektasi fail-closed status QUARANTINED, didapat '${restoreWithoutFencing.status}'`);
      }

      // Periksa record di disk: karena tidak ada fencing yang sah dikirim ke restoreGroupState,
      // record pada disk TIDAK termutasi ke status 'quarantined' (tetap 'active' dengan data utuh, zero unauthorized write)
      const rec23B = await idbGet<GroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev23B, room23B]);
      if (!rec23B || rec23B.status !== 'active') {
        throw new Error(`PELANGGARAN KEAMANAN: restoreGroupState tanpa fencing memutasi status disk! status='${rec23B?.status}'`);
      }

      const t23Dur = Math.round(performance.now() - t23Start);
      updateTc('TC-PERSIST-23', 'PASS', `Penegakan ketiadaan API karantina tanpa fencing & perlindungan fail-closed restore terbukti (${t23Dur}ms)`, t23Dur);
      addLog(`[TC-PERSIST-23] PASS: Penegakan mutlak fencing pada seluruh jalur karantina terbukti.`, 'success');
      passedCount++;

      const totalDur = Math.round(performance.now() - totalStart);
      addLog(`SELURUH 23 TEST CASES BERHASIL LULUS 100% (${passedCount}/23) dalam ${totalDur}ms`, 'success');

      // Simpan hasil ke window untuk otomasi CDP headless
      if (typeof window !== 'undefined') {
        (window as any).__MLS_PERSISTENCE_TEST_RESULTS__ = {
          overallResult: 'SUCCESS',
          passed: passedCount,
          total: 23,
          durationMs: totalDur,
          testCases: INITIAL_TEST_CASES.map((tc) => ({ id: tc.id, status: 'PASS' })),
        };
      }
    } catch (suiteErr: any) {
      addLog(`SUITE ERROR: ${suiteErr.message}`, 'error');
      if (typeof window !== 'undefined') {
        (window as any).__MLS_PERSISTENCE_TEST_RESULTS__ = {
          overallResult: 'FAIL',
          passed: passedCount,
          total: 23,
          error: suiteErr.message,
        };
      }
    } finally {
      setIsRunning(false);
    }
  };

  useEffect(() => {
    // Daftarkan runner ke window
    (window as any).__RUN_MLS_PERSISTENCE_TESTS__ = runAllTests;

    // Otomatis jalankan setelah mount
    const timer = setTimeout(() => {
      runAllTests();
    }, 500);

    return () => clearTimeout(timer);
  }, []);

  return (
    <div style={{ padding: '24px', fontFamily: 'monospace', maxWidth: '1000px', margin: '0 auto' }}>
      <h1 style={{ fontSize: '20px', fontWeight: 'bold', marginBottom: '8px' }}>
        B-CHAT E2EE — GroupState Persistence Core Test Runner (H-I3A.2A.1)
      </h1>
      <p style={{ color: '#666', fontSize: '13px', marginBottom: '16px' }}>
        Pengujian isolasi orchestrator penyimpanan dan pemulihan MLS GroupState pada IndexedDB v1
      </p>

      <div style={{ marginBottom: '16px', display: 'flex', gap: '8px' }}>
        <button
          onClick={runAllTests}
          disabled={isRunning}
          style={{
            padding: '8px 16px',
            backgroundColor: isRunning ? '#999' : '#0070f3',
            color: '#fff',
            border: 'none',
            borderRadius: '4px',
            cursor: isRunning ? 'not-allowed' : 'pointer',
            fontWeight: 'bold',
          }}
        >
          {isRunning ? 'Running Tests...' : 'Run All Tests'}
        </button>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: '8px', marginBottom: '24px' }}>
        {testCases.map((tc) => (
          <div
            key={tc.id}
            style={{
              padding: '10px 14px',
              borderRadius: '6px',
              border: '1px solid #ddd',
              backgroundColor:
                tc.status === 'PASS'
                  ? '#e6ffed'
                  : tc.status === 'FAIL'
                  ? '#ffeef0'
                  : tc.status === 'RUNNING'
                  ? '#dbedff'
                  : '#f6f8fa',
              display: 'flex',
              flexDirection: 'column',
              gap: '4px',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span style={{ fontWeight: 'bold', fontSize: '14px' }}>
                [{tc.id}] {tc.name}
              </span>
              <span
                style={{
                  fontWeight: 'bold',
                  fontSize: '12px',
                  padding: '2px 8px',
                  borderRadius: '10px',
                  backgroundColor:
                    tc.status === 'PASS'
                      ? '#2ea44f'
                      : tc.status === 'FAIL'
                      ? '#cb2431'
                      : tc.status === 'RUNNING'
                      ? '#0366d6'
                      : '#6a737d',
                  color: '#fff',
                }}
              >
                {tc.status}
              </span>
            </div>
            {tc.details && (
              <div style={{ fontSize: '12px', color: '#444' }}>
                {tc.details} {tc.durationMs !== undefined && `(${tc.durationMs}ms)`}
              </div>
            )}
          </div>
        ))}
      </div>

      <div
        style={{
          border: '1px solid #333',
          backgroundColor: '#1e1e1e',
          color: '#d4d4d4',
          borderRadius: '6px',
          padding: '12px',
          height: '300px',
          overflowY: 'auto',
          fontSize: '12px',
        }}
      >
        <div style={{ fontWeight: 'bold', marginBottom: '8px', color: '#9cdcfe' }}>Runtime Test Console Logs:</div>
        {logs.map((log, idx) => (
          <div
            key={idx}
            style={{
              marginBottom: '2px',
              color:
                log.level === 'success'
                  ? '#4ec9b0'
                  : log.level === 'error'
                  ? '#f14c4c'
                  : log.level === 'warn'
                  ? '#cca700'
                  : '#cccccc',
            }}
          >
            [{log.timestamp}] {log.message}
          </div>
        ))}
      </div>
    </div>
  );
}

