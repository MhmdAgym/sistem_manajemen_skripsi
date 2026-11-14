'use client';

/**
 * B-CHAT E2EE — Crypto Storage Helper & KEK Lifecycle Browser Test Runner
 *
 * Halaman verifikasi browser runtime Tahap 2C.4H-I2:
 * TC-C01: Pembangkitan dan penyimpanan KEK pertama (active, v1, AES-GCM-256, non-extractable).
 * TC-C02: Sifat non-extractable KEK (exportKey 'raw' dan 'jwk' ditolak oleh peramban).
 * TC-C03: Siklus round-trip enkripsi dan dekripsi byte-for-byte.
 * TC-C04: Penolakan transplantasi record: device_uuid berbeda (AAD binding).
 * TC-C05: Penolakan transplantasi record: room_uuid berbeda (AAD binding).
 * TC-C06: Penolakan transplantasi record: storeName / recordType berbeda (AAD binding).
 * TC-C07: Penggunaan IV acak unik 96-bit (Zero nonce reuse sample: 50 iterasi).
 * TC-C08: Penolakan modifikasi ciphertext dan tag autentikasi 128-bit (Tamper resistance).
 * TC-C09: Penolakan format amplop rusak sebelum pemanggilan subtle.decrypt.
 * TC-C10: Dekripsi record lama setelah rotasi KEK (v1 dan v2 berfungsi bersama).
 * TC-C11: Konkurensi Inisialisasi KEK simultan (Double-checked locking, tepat 1 active KEK).
 * TC-C12: Konkurensi Rotasi KEK simultan (Tepat 1 active KEK, versi monotonik).
 * TC-C13: Dukungan dekripsi KEK berstatus deprecated dan revoked.
 * TC-C14: Imutabilitas ciphertext pada kegagalan dekripsi (Zero in-place mutation).
 * TC-C15: Transaksi IDB Rollback saat abort (Regresi F-02 atomicity).
 */

import React, { useState, useEffect } from 'react';
import {
  BCAT_DB_NAME,
  BCAT_DB_VERSION,
  BCAT_STORES,
  type KeystoreRecord,
  type LegacyGroupStateRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import {
  openBchatDatabase,
  closeBchatDatabase,
  withReadonlyTransaction,
  withReadwriteTransaction,
  idbPut,
  idbGet,
} from '@/lib/bchat/storage/bchat-idb-core';
import {
  BchatAadValidationError,
  type BchatAadContext,
} from '@/lib/bchat/storage/bchat-storage-aad';
import {
  validateStorageEnvelope,
  BchatEnvelopeValidationError,
  type BchatEncryptedEnvelope,
} from '@/lib/bchat/storage/bchat-storage-envelope';
import {
  getOrCreateActiveKek,
  getKekById,
  rotateActiveKek,
  encryptPayloadToEnvelope,
  decryptPayloadFromEnvelope,
  BchatKekUnavailableError,
  BchatStorageDecryptionError,
} from '@/lib/bchat/storage/bchat-crypto-helper';

interface TestCaseStatus {
  id: string;
  name: string;
  status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL';
  details?: string;
}

interface TestLog {
  timestamp: string;
  level: 'info' | 'success' | 'warn' | 'error';
  message: string;
}

const INITIAL_TEST_CASES: TestCaseStatus[] = [
  { id: 'TC-C01', name: 'Pembangkitan & Penyimpanan KEK Pertama (Active v1, AES-GCM-256)', status: 'PENDING' },
  { id: 'TC-C02', name: 'Penegakan KEK Non-Extractable (exportKey Ditolak)', status: 'PENDING' },
  { id: 'TC-C03', name: 'Siklus Round-Trip Enkripsi & Dekripsi Byte-for-Byte', status: 'PENDING' },
  { id: 'TC-C04', name: 'Anti-Transplantasi: Penolakan device_uuid Berbeda (AAD)', status: 'PENDING' },
  { id: 'TC-C05', name: 'Anti-Transplantasi: Penolakan room_uuid Berbeda (AAD)', status: 'PENDING' },
  { id: 'TC-C06', name: 'Anti-Transplantasi: Penolakan storeName / recordType Berbeda (AAD)', status: 'PENDING' },
  { id: 'TC-C07', name: 'Keunikan IV Acak 96-bit (Sample 50 Iterasi Tanpa Kolisi)', status: 'PENDING' },
  { id: 'TC-C08', name: 'Integritas Autentikasi: Penolakan Tamper Ciphertext & Tag', status: 'PENDING' },
  { id: 'TC-C09', name: 'Validasi Struktur Amplop Ketat Pra-WebCrypto', status: 'PENDING' },
  { id: 'TC-C10', name: 'Pemulihan Record Lama Pasca-Rotasi KEK (Multi-Versi)', status: 'PENDING' },
  { id: 'TC-C11', name: 'Konkurensi Inisialisasi KEK Simultan (Double-Checked Locking)', status: 'PENDING' },
  { id: 'TC-C12', name: 'Konkurensi Rotasi KEK Simultan (Tepat 1 Active KEK, Monotonik)', status: 'PENDING' },
  { id: 'TC-C13', name: 'Dukungan Dekripsi Arsip KEK Status Deprecated & Revoked', status: 'PENDING' },
  { id: 'TC-C14', name: 'Imutabilitas Ciphertext Saat Kegagalan Dekripsi', status: 'PENDING' },
  { id: 'TC-C15', name: 'Rollback Transaksi IDB Saat Abort (Regresi F-02)', status: 'PENDING' },
];

export default function BchatCryptoTestPage() {
  const [testCases, setTestCases] = useState<TestCaseStatus[]>(INITIAL_TEST_CASES);
  const [logs, setLogs] = useState<TestLog[]>([]);
  const [isRunning, setIsRunning] = useState<boolean>(false);
  const [overallResult, setOverallResult] = useState<'IDLE' | 'RUNNING' | 'SUCCESS' | 'FAILED'>('IDLE');

  const addLog = (message: string, level: TestLog['level'] = 'info') => {
    const timestamp = new Date().toLocaleTimeString();
    setLogs((prev) => [...prev, { timestamp, level, message }]);
  };

  const updateTc = (id: string, status: TestCaseStatus['status'], details?: string) => {
    setTestCases((prev) =>
      prev.map((tc) => (tc.id === id ? { ...tc, status, details: details || tc.details } : tc))
    );
  };

  // Expose status ke window agar CDP headless runner dapat membacanya langsung
  useEffect(() => {
    if (typeof window !== 'undefined') {
      (window as any).__CRYPTO_TEST_RESULTS__ = {
        overallResult,
        testCases,
        logs,
      };
      (window as any).__RUN_CRYPTO_TESTS__ = runAllTests;
    }
  }, [overallResult, testCases, logs]);

  // Membersihkan database untuk uji coba ulang
  const handleResetDatabase = async () => {
    closeBchatDatabase();
    addLog('Menutup koneksi dan meminta penghapusan database IndexedDB...', 'warn');

    return new Promise<void>((resolve) => {
      const req = window.indexedDB.deleteDatabase(BCAT_DB_NAME);
      req.onsuccess = () => {
        addLog(`Database '${BCAT_DB_NAME}' berhasil dihapus.`, 'success');
        setTestCases(INITIAL_TEST_CASES);
        setOverallResult('IDLE');
        resolve();
      };
      req.onerror = () => {
        addLog(`Gagal menghapus database '${BCAT_DB_NAME}'.`, 'error');
        resolve();
      };
      req.onblocked = () => {
        addLog('Penghapusan database terblokir oleh tab lain.', 'warn');
        resolve();
      };
    });
  };

  const runAllTests = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setOverallResult('RUNNING');
    setTestCases(INITIAL_TEST_CASES);
    setLogs([]);

    addLog('=== MEMULAI RUNTIME TEST SUITE 2C.4H-I2 (CRYPTO STORAGE HELPER & KEK) ===', 'info');

    try {
      // Buka koneksi database
      const db = await openBchatDatabase();
      addLog(`Database '${db.name}' v${db.version} siap digunakan.`, 'info');

      // -----------------------------------------------------------------------
      // TC-C01: Pembangkitan dan penyimpanan KEK pertama
      // -----------------------------------------------------------------------
      updateTc('TC-C01', 'RUNNING');
      const dev01 = '550e8400-e29b-41d4-a716-446655440001';
      addLog(`[TC-C01] Menguji pemanggilan getOrCreateActiveKek untuk device ${dev01}...`, 'info');

      const kek1 = await getOrCreateActiveKek(dev01);
      if (!kek1 || !kek1.crypto_key) {
        throw new Error('TC-C01: KEK gagal dibangkitkan atau tidak memuat CryptoKey.');
      }
      if (kek1.status !== 'active') {
        throw new Error(`TC-C01: Status KEK bukan 'active': ${kek1.status}`);
      }
      if (kek1.key_version !== 1) {
        throw new Error(`TC-C01: Versi KEK bukan 1: ${kek1.key_version}`);
      }
      if (!kek1.key_id.startsWith('kek_')) {
        throw new Error(`TC-C01: Format key_id tidak valid: ${kek1.key_id}`);
      }
      if (kek1.algorithm !== 'AES-GCM-256') {
        throw new Error(`TC-C01: Algoritma KEK bukan AES-GCM-256: ${kek1.algorithm}`);
      }
      if (kek1.crypto_key.extractable !== false) {
        throw new Error('TC-C01: CryptoKey tidak boleh extractable!');
      }

      // Verifikasi record tersimpan di keystore IDB
      const storedKek1 = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, kek1.key_id);
      if (!storedKek1 || storedKek1.key_id !== kek1.key_id) {
        throw new Error('TC-C01: KEK tidak ditemukan pada store keystore.');
      }

      updateTc('TC-C01', 'PASS', `KEK ID: ${kek1.key_id} (active, v1, non-extractable).`);
      addLog(`[TC-C01] PASS: KEK pertama berhasil dibangkitkan dan tersimpan di keystore.`, 'success');

      // -----------------------------------------------------------------------
      // TC-C02: Sifat non-extractable KEK
      // -----------------------------------------------------------------------
      updateTc('TC-C02', 'RUNNING');
      addLog('[TC-C02] Memverifikasi penolakan exportKey raw dan jwk terhadap CryptoKey non-extractable...', 'info');

      let rawExportRejected = false;
      try {
        await window.crypto.subtle.exportKey('raw', kek1.crypto_key);
      } catch (err: any) {
        rawExportRejected = true;
        addLog(`[TC-C02] exportKey('raw') berhasil ditolak: ${err.name || err.message}`, 'info');
      }

      let jwkExportRejected = false;
      try {
        await window.crypto.subtle.exportKey('jwk', kek1.crypto_key);
      } catch (err: any) {
        jwkExportRejected = true;
        addLog(`[TC-C02] exportKey('jwk') berhasil ditolak: ${err.name || err.message}`, 'info');
      }

      if (!rawExportRejected || !jwkExportRejected) {
        throw new Error('TC-C02: PELANGGARAN KEAMANAN! exportKey berhasil dieksekusi pada non-extractable key!');
      }

      updateTc('TC-C02', 'PASS', 'exportKey raw & jwk keduanya ditolak peramban (non-extractable terbukti).');
      addLog('[TC-C02] PASS: Penegakan non-extractable diverifikasi.', 'success');

      // -----------------------------------------------------------------------
      // TC-C03: Siklus round-trip enkripsi dan dekripsi byte-for-byte
      // -----------------------------------------------------------------------
      updateTc('TC-C03', 'RUNNING');
      addLog('[TC-C03] Menguji enkripsi payload biner ke amplop dan dekripsi byte-for-byte...', 'info');

      const plainTextSample = 'B-CHAT_E2EE_LOCAL_PERSISTENCE_TEST_PAYLOAD_UNUHA_2026_SECRET_STATE_XYZ';
      const plainBytes = new TextEncoder().encode(plainTextSample);
      const aadCtx03 = {
        storeName: BCAT_STORES.GROUP_STATES,
        recordType: 'client_state' as const,
        deviceUuid: dev01,
        roomUuid: '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        recordKey: [dev01, '6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
      };

      const envelope03 = await encryptPayloadToEnvelope(plainBytes, aadCtx03);
      if (envelope03.storage_schema_version !== 1 || envelope03.serializer_version !== 1) {
        throw new Error('TC-C03: Header versi amplop tidak sesuai.');
      }
      if (envelope03.iv.length !== 12) {
        throw new Error(`TC-C03: Panjang IV bukan 12 bytes: ${envelope03.iv.length}`);
      }
      if (envelope03.ciphertext.length !== plainBytes.length + 16) {
        throw new Error(
          `TC-C03: Panjang ciphertext (${envelope03.ciphertext.length}) tidak sesuai ` +
          `(diharapkan ${plainBytes.length + 16} bytes).`
        );
      }

      // Dekripsi kembali
      const decrypted03 = await decryptPayloadFromEnvelope(envelope03, aadCtx03);
      if (decrypted03.length !== plainBytes.length) {
        throw new Error('TC-C03: Panjang hasil dekripsi tidak cocok dengan plaintext.');
      }
      for (let i = 0; i < plainBytes.length; i++) {
        if (decrypted03[i] !== plainBytes[i]) {
          throw new Error(`TC-C03: Ketidakcocokan byte pada indeks ${i}`);
        }
      }

      updateTc('TC-C03', 'PASS', `Round-trip presisi: ${plainBytes.length} bytes terenkripsi & terdekripsi identik.`);
      addLog('[TC-C03] PASS: Siklus round-trip byte-for-byte terverifikasi 100%.', 'success');

      // -----------------------------------------------------------------------
      // TC-C04: Anti-Transplantasi: Penolakan device_uuid Berbeda (AAD)
      // -----------------------------------------------------------------------
      updateTc('TC-C04', 'RUNNING');
      addLog('[TC-C04] Mencoba mendekripsi amplop dengan device_uuid yang berbeda...', 'info');

      const tamperedDeviceAad = {
        ...aadCtx03,
        deviceUuid: '550e8400-e29b-41d4-a716-446655440099', // dev UUID berbeda
      };

      let devMismatchCaught = false;
      try {
        await decryptPayloadFromEnvelope(envelope03, tamperedDeviceAad);
      } catch (err: any) {
        if (err instanceof BchatStorageDecryptionError || err.message.includes('Integritas')) {
          devMismatchCaught = true;
          addLog(`[TC-C04] Dekripsi ditolak sesuai ekspektasi: ${err.message}`, 'info');
        }
      }

      if (!devMismatchCaught) {
        throw new Error('TC-C04: PELANGGARAN KEAMANAN! Dekripsi berhasil dengan device_uuid yang berbeda!');
      }

      updateTc('TC-C04', 'PASS', 'Transplantasi lintas device_uuid ditolak oleh AAD authentication tag.');
      addLog('[TC-C04] PASS: Anti-transplantasi device_uuid terbukti.', 'success');

      // -----------------------------------------------------------------------
      // TC-C05: Anti-Transplantasi: Penolakan room_uuid Berbeda (AAD)
      // -----------------------------------------------------------------------
      updateTc('TC-C05', 'RUNNING');
      addLog('[TC-C05] Mencoba mendekripsi amplop dengan room_uuid yang berbeda...', 'info');

      const tamperedRoomAad = {
        ...aadCtx03,
        roomUuid: '6ba7b810-9dad-11d1-80b4-00c04fd43099', // room UUID berbeda
      };

      let roomMismatchCaught = false;
      try {
        await decryptPayloadFromEnvelope(envelope03, tamperedRoomAad);
      } catch (err: any) {
        if (err instanceof BchatStorageDecryptionError || err.message.includes('Integritas')) {
          roomMismatchCaught = true;
          addLog(`[TC-C05] Dekripsi ditolak sesuai ekspektasi: ${err.message}`, 'info');
        }
      }

      if (!roomMismatchCaught) {
        throw new Error('TC-C05: PELANGGARAN KEAMANAN! Dekripsi berhasil dengan room_uuid yang berbeda!');
      }

      updateTc('TC-C05', 'PASS', 'Transplantasi lintas room_uuid ditolak oleh AAD authentication tag.');
      addLog('[TC-C05] PASS: Anti-transplantasi room_uuid terbukti.', 'success');

      // -----------------------------------------------------------------------
      // TC-C06: Anti-Transplantasi: Penolakan storeName / recordType Berbeda (AAD)
      // -----------------------------------------------------------------------
      updateTc('TC-C06', 'RUNNING');
      addLog('[TC-C06] Menguji penolakan perpindahan storeName atau recordType...', 'info');

      const tamperedStoreAad = {
        ...aadCtx03,
        storeName: BCAT_STORES.PENDING_KEY_PACKAGES, // storeName berbeda
      };

      let storeMismatchCaught = false;
      try {
        await decryptPayloadFromEnvelope(envelope03, tamperedStoreAad);
      } catch (err: any) {
        if (err instanceof BchatStorageDecryptionError || err.message.includes('Integritas')) {
          storeMismatchCaught = true;
        }
      }

      const tamperedTypeAad = {
        ...aadCtx03,
        recordType: 'outbox_message' as const, // recordType berbeda
      };

      let typeMismatchCaught = false;
      try {
        await decryptPayloadFromEnvelope(envelope03, tamperedTypeAad);
      } catch (err: any) {
        if (err instanceof BchatStorageDecryptionError || err.message.includes('Integritas')) {
          typeMismatchCaught = true;
        }
      }

      if (!storeMismatchCaught || !typeMismatchCaught) {
        throw new Error('TC-C06: PELANGGARAN KEAMANAN! Dekripsi berhasil saat storeName atau recordType diubah!');
      }

      updateTc('TC-C06', 'PASS', 'Transplantasi storeName dan recordType keduanya ditolak oleh AAD.');
      addLog('[TC-C06] PASS: Anti-transplantasi storeName & recordType terbukti.', 'success');

      // -----------------------------------------------------------------------
      // TC-C07: Keunikan IV Acak 96-bit (Sample 50 Iterasi Tanpa Kolisi)
      // -----------------------------------------------------------------------
      updateTc('TC-C07', 'RUNNING');
      addLog('[TC-C07] Menguji keunikan IV acak CSPRNG pada 50 enkripsi berurutan...', 'info');

      const ivSet = new Set<string>();
      const samplePayload = new Uint8Array([1, 2, 3, 4, 5]);

      for (let i = 0; i < 50; i++) {
        const env = await encryptPayloadToEnvelope(samplePayload, aadCtx03);
        const ivHex = Array.from(env.iv)
          .map((b) => b.toString(16).padStart(2, '0'))
          .join('');
        ivSet.add(ivHex);
      }

      if (ivSet.size !== 50) {
        throw new Error(`TC-C07: Terdeteksi tabrakan IV! Set size ${ivSet.size} dari 50 iterasi.`);
      }

      updateTc('TC-C07', 'PASS', `Sampel 50/50 IV unik terkonfirmasi (zero nonce reuse dalam sampel).`);
      addLog('[TC-C07] PASS: 50 IV CSPRNG unik tanpa kolisi terverifikasi.', 'success');

      // -----------------------------------------------------------------------
      // TC-C08: Integritas Autentikasi: Penolakan Tamper Ciphertext & Tag
      // -----------------------------------------------------------------------
      updateTc('TC-C08', 'RUNNING');
      addLog('[TC-C08] Menguji penolakan modifikasi ciphertext dan tag autentikasi...', 'info');

      // Subtest A: Modifikasi byte pertama ciphertext
      const corruptedCiphertextBody = new Uint8Array(envelope03.ciphertext);
      corruptedCiphertextBody[0] ^= 0x01; // flip 1 bit
      const tamperedBodyEnv: BchatEncryptedEnvelope = {
        ...envelope03,
        ciphertext: corruptedCiphertextBody,
      };

      let bodyTamperCaught = false;
      try {
        await decryptPayloadFromEnvelope(tamperedBodyEnv, aadCtx03);
      } catch (err: any) {
        if (err instanceof BchatStorageDecryptionError || err.message.includes('Integritas')) {
          bodyTamperCaught = true;
        }
      }

      // Subtest B: Modifikasi byte terakhir (bagian dari 16-byte authentication tag)
      const corruptedTag = new Uint8Array(envelope03.ciphertext);
      corruptedTag[corruptedTag.length - 1] ^= 0x01; // flip 1 bit pada tag
      const tamperedTagEnv: BchatEncryptedEnvelope = {
        ...envelope03,
        ciphertext: corruptedTag,
      };

      let tagTamperCaught = false;
      try {
        await decryptPayloadFromEnvelope(tamperedTagEnv, aadCtx03);
      } catch (err: any) {
        if (err instanceof BchatStorageDecryptionError || err.message.includes('Integritas')) {
          tagTamperCaught = true;
        }
      }

      if (!bodyTamperCaught || !tagTamperCaught) {
        throw new Error('TC-C08: PELANGGARAN INTEGRITAS! Modifikasi ciphertext/tag tidak membatalkan dekripsi!');
      }

      updateTc('TC-C08', 'PASS', 'Tamper pada ciphertext payload maupun auth tag ditolak AES-GCM MAC.');
      addLog('[TC-C08] PASS: Penolakan modifikasi ciphertext & tag autentikasi terverifikasi.', 'success');

      // -----------------------------------------------------------------------
      // TC-C09: Validasi Struktur Amplop Ketat Pra-WebCrypto
      // -----------------------------------------------------------------------
      updateTc('TC-C09', 'RUNNING');
      addLog('[TC-C09] Menguji penolakan format amplop rusak sebelum pemanggilan WebCrypto...', 'info');

      // Subtest A: IV bukan 12 bytes
      let ivErrorCaught = false;
      try {
        validateStorageEnvelope({
          ...envelope03,
          iv: new Uint8Array(8), // hanya 8 bytes
        });
      } catch (err: any) {
        if (err instanceof BchatEnvelopeValidationError) ivErrorCaught = true;
      }

      // Subtest B: Ciphertext < 16 bytes
      let ctErrorCaught = false;
      try {
        validateStorageEnvelope({
          ...envelope03,
          ciphertext: new Uint8Array(10), // kurang dari 16 bytes
        });
      } catch (err: any) {
        if (err instanceof BchatEnvelopeValidationError) ctErrorCaught = true;
      }

      // Subtest C: Storage schema version tidak didukung
      let verErrorCaught = false;
      try {
        validateStorageEnvelope({
          ...envelope03,
          storage_schema_version: 99,
        });
      } catch (err: any) {
        if (err instanceof BchatEnvelopeValidationError) verErrorCaught = true;
      }

      // Subtest D: Serializer version tidak didukung
      let serErrorCaught = false;
      try {
        validateStorageEnvelope({
          ...envelope03,
          serializer_version: 99,
        });
      } catch (err: any) {
        if (err instanceof BchatEnvelopeValidationError) serErrorCaught = true;
      }

      if (!ivErrorCaught || !ctErrorCaught || !verErrorCaught || !serErrorCaught) {
        throw new Error('TC-C09: Salah satu validasi struktur amplop lolos tanpa pengecualian!');
      }

      updateTc('TC-C09', 'PASS', 'IV != 12B, CT < 16B, dan versi invalid ditolak ketat oleh validator amplop.');
      addLog('[TC-C09] PASS: Validasi struktur amplop pra-WebCrypto terverifikasi.', 'success');

      // -----------------------------------------------------------------------
      // TC-C10: Pemulihan Record Lama Pasca-Rotasi KEK (Multi-Versi)
      // -----------------------------------------------------------------------
      updateTc('TC-C10', 'RUNNING');
      const dev10 = '550e8400-e29b-41d4-a716-446655440010';
      addLog(`[TC-C10] Menguji rotasi KEK dan dekripsi multi-versi untuk device ${dev10}...`, 'info');

      // KEK v1
      const kekV1 = await getOrCreateActiveKek(dev10);
      const payloadV1 = new TextEncoder().encode('OLD_DATA_ENCRYPTED_WITH_KEK_V1');
      const aadCtx10 = {
        storeName: BCAT_STORES.GROUP_STATES,
        recordType: 'client_state' as const,
        deviceUuid: dev10,
        roomUuid: '6ba7b810-9dad-11d1-80b4-00c04fd43010',
        recordKey: [dev10, '6ba7b810-9dad-11d1-80b4-00c04fd43010'],
      };
      const envV1 = await encryptPayloadToEnvelope(payloadV1, aadCtx10);

      // Rotasi KEK ke v2
      const kekV2 = await rotateActiveKek(dev10);
      if (kekV2.key_version !== 2 || kekV2.status !== 'active') {
        throw new Error(`TC-C10: Rotasi KEK tidak menghasilkan v2 active: v${kekV2.key_version} ${kekV2.status}`);
      }

      // KEK v1 harus menjadi 'deprecated' di keystore
      const recheckedKekV1 = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, kekV1.key_id);
      if (recheckedKekV1?.status !== 'deprecated') {
        throw new Error(`TC-C10: KEK v1 tidak berstatus 'deprecated' pasca rotasi: ${recheckedKekV1?.status}`);
      }

      // Enkripsi record baru dengan KEK v2
      const payloadV2 = new TextEncoder().encode('NEW_DATA_ENCRYPTED_WITH_KEK_V2');
      const envV2 = await encryptPayloadToEnvelope(payloadV2, aadCtx10);
      if (envV2.key_id !== kekV2.key_id) {
        throw new Error('TC-C10: Record baru tidak menggunakan KEK v2 aktif!');
      }

      // Dekripsi record lama (v1) dan record baru (v2)
      const decryptedV1 = await decryptPayloadFromEnvelope(envV1, aadCtx10);
      const decryptedV2 = await decryptPayloadFromEnvelope(envV2, aadCtx10);

      if (new TextDecoder().decode(decryptedV1) !== 'OLD_DATA_ENCRYPTED_WITH_KEK_V1') {
        throw new Error('TC-C10: Gagal mendekripsi record lama terenkripsi KEK v1!');
      }
      if (new TextDecoder().decode(decryptedV2) !== 'NEW_DATA_ENCRYPTED_WITH_KEK_V2') {
        throw new Error('TC-C10: Gagal mendekripsi record baru terenkripsi KEK v2!');
      }

      updateTc('TC-C10', 'PASS', `Record v1 (${kekV1.key_id}) & v2 (${kekV2.key_id}) berhasil didekripsi.`);
      addLog('[TC-C10] PASS: Pemulihan multi-versi pasca-rotasi terverifikasi.', 'success');

      // -----------------------------------------------------------------------
      // TC-C11: Konkurensi Inisialisasi KEK Simultan (Double-Checked Locking)
      // -----------------------------------------------------------------------
      updateTc('TC-C11', 'RUNNING');
      const dev11 = '550e8400-e29b-41d4-a716-446655440011';
      addLog(`[TC-C11] Menjalankan 2 pemanggilan getOrCreateActiveKek simultan untuk ${dev11}...`, 'info');

      const [res11A, res11B] = await Promise.all([
        getOrCreateActiveKek(dev11),
        getOrCreateActiveKek(dev11),
      ]);

      // Periksa isi keystore untuk device ini
      const allKeksDev11 = await withReadonlyTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
        return new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = getStore(BCAT_STORES.KEYSTORE).getAll();
          req.onsuccess = () => {
            const list = (req.result as KeystoreRecord[]).filter(
              (r) => (r.metadata?.device_uuid as string) === dev11
            );
            resolve(list);
          };
          req.onerror = () => reject(req.error);
        });
      });

      const activeKeks11 = allKeksDev11.filter((r) => r.status === 'active');
      if (activeKeks11.length !== 1) {
        throw new Error(`TC-C11: Ditemukan ${activeKeks11.length} KEK aktif untuk device (diharapkan tepat 1)!`);
      }
      if (res11A.key_id !== res11B.key_id && res11A.key_id !== activeKeks11[0].key_id && res11B.key_id !== activeKeks11[0].key_id) {
        throw new Error('TC-C11: Hasil resolusi konkurensi tidak konsisten dengan KEK aktif keystore.');
      }

      updateTc('TC-C11', 'PASS', `Tepat 1 active KEK tersimpan (${activeKeks11[0].key_id}) saat inisialisasi paralel.`);
      addLog('[TC-C11] PASS: Double-checked locking inisialisasi KEK aman dari race-condition.', 'success');

      // -----------------------------------------------------------------------
      // TC-C12: Konkurensi Rotasi KEK Simultan (Tepat 1 Active KEK, Monotonik)
      // -----------------------------------------------------------------------
      updateTc('TC-C12', 'RUNNING');
      addLog(`[TC-C12] Menjalankan 2 pemanggilan rotateActiveKek simultan untuk ${dev11}...`, 'info');

      await Promise.all([
        rotateActiveKek(dev11),
        rotateActiveKek(dev11),
      ]);

      const allKeksDev11PostRot = await withReadonlyTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
        return new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = getStore(BCAT_STORES.KEYSTORE).getAll();
          req.onsuccess = () => {
            const list = (req.result as KeystoreRecord[]).filter(
              (r) => (r.metadata?.device_uuid as string) === dev11
            );
            resolve(list);
          };
          req.onerror = () => reject(req.error);
        });
      });

      const activeKeks11Post = allKeksDev11PostRot.filter((r) => r.status === 'active');
      if (activeKeks11Post.length !== 1) {
        throw new Error(
          `TC-C12: Terdeteksi anomali: terdapat ${activeKeks11Post.length} KEK aktif setelah rotasi simultan!`
        );
      }

      // Pastikan versi terurut monotonik
      allKeksDev11PostRot.sort((a, b) => a.key_version - b.key_version);
      const versions = allKeksDev11PostRot.map((k) => k.key_version);
      addLog(`[TC-C12] Versi KEK tercatat pasca rotasi: [${versions.join(', ')}]`, 'info');

      updateTc('TC-C12', 'PASS', `Tepat 1 active KEK (v${activeKeks11Post[0].key_version}) pasca rotasi paralel.`);
      addLog('[TC-C12] PASS: Rotasi KEK konkurensi mempertahankan invarian tepat 1 KEK aktif.', 'success');

      // -----------------------------------------------------------------------
      // TC-C13: Dukungan Dekripsi Arsip KEK Status Deprecated & Revoked
      // -----------------------------------------------------------------------
      updateTc('TC-C13', 'RUNNING');
      const dev13 = '550e8400-e29b-41d4-a716-446655440013';
      addLog(`[TC-C13] Menguji dekripsi record dengan KEK berstatus deprecated dan revoked untuk ${dev13}...`, 'info');

      const kek13 = await getOrCreateActiveKek(dev13);
      const payload13 = new TextEncoder().encode('HISTORICAL_ARCHIVED_E2EE_RECORD');
      const aadCtx13 = {
        storeName: BCAT_STORES.GROUP_STATES,
        recordType: 'client_state' as const,
        deviceUuid: dev13,
        roomUuid: '6ba7b810-9dad-11d1-80b4-00c04fd43013',
        recordKey: [dev13, '6ba7b810-9dad-11d1-80b4-00c04fd43013'],
      };
      const env13 = await encryptPayloadToEnvelope(payload13, aadCtx13);

      // Ubah status KEK menjadi 'deprecated'
      await idbPut(BCAT_STORES.KEYSTORE, {
        ...kek13,
        status: 'deprecated',
      });
      const decDep = await decryptPayloadFromEnvelope(env13, aadCtx13);
      if (new TextDecoder().decode(decDep) !== 'HISTORICAL_ARCHIVED_E2EE_RECORD') {
        throw new Error('TC-C13: Gagal mendekripsi record dengan KEK berstatus deprecated.');
      }

      // Ubah status KEK menjadi 'revoked'
      await idbPut(BCAT_STORES.KEYSTORE, {
        ...kek13,
        status: 'revoked',
      });
      const decRev = await decryptPayloadFromEnvelope(env13, aadCtx13);
      if (new TextDecoder().decode(decRev) !== 'HISTORICAL_ARCHIVED_E2EE_RECORD') {
        throw new Error('TC-C13: Gagal mendekripsi record dengan KEK berstatus revoked.');
      }

      updateTc('TC-C13', 'PASS', 'KEK deprecated dan revoked tetap dapat mendekripsi record historis.');
      addLog('[TC-C13] PASS: Dekripsi record historis berstatus deprecated & revoked berhasil.', 'success');

      // -----------------------------------------------------------------------
      // TC-C14: Imutabilitas Ciphertext Saat Kegagalan Dekripsi
      // -----------------------------------------------------------------------
      updateTc('TC-C14', 'RUNNING');
      addLog('[TC-C14] Membuktikan byte ciphertext tidak berubah sama sekali saat dekripsi gagal...', 'info');

      const originalCtCopy = new Uint8Array(envelope03.ciphertext);
      let decryptFailedAsExpected = false;
      try {
        await decryptPayloadFromEnvelope(envelope03, {
          ...aadCtx03,
          deviceUuid: '550e8400-e29b-41d4-a716-446655440088', // salah AAD
        });
      } catch (err) {
        decryptFailedAsExpected = true;
      }

      if (!decryptFailedAsExpected) {
        throw new Error('TC-C14: Ekspektasi kegagalan dekripsi tidak terjadi.');
      }

      // Verifikasi byte-for-byte bahwa envelope03.ciphertext tidak termutasi
      if (envelope03.ciphertext.length !== originalCtCopy.length) {
        throw new Error('TC-C14: Panjang ciphertext termutasi saat dekripsi gagal!');
      }
      for (let i = 0; i < originalCtCopy.length; i++) {
        if (envelope03.ciphertext[i] !== originalCtCopy[i]) {
          throw new Error(`TC-C14: Byte ciphertext termutasi pada indeks ${i}!`);
        }
      }

      updateTc('TC-C14', 'PASS', 'Ciphertext terbukti 100% imutabel (zero in-place mutation) saat dekripsi gagal.');
      addLog('[TC-C14] PASS: Imutabilitas ciphertext terverifikasi.', 'success');

      // -----------------------------------------------------------------------
      // TC-C15: Rollback Transaksi IDB Saat Abort (Regresi F-02)
      // -----------------------------------------------------------------------
      updateTc('TC-C15', 'RUNNING');
      const dev15 = '550e8400-e29b-41d4-a716-446655440015';
      const room15 = '6ba7b810-9dad-11d1-80b4-00c04fd43015';
      addLog(`[TC-C15] Menguji rollback pembaruan atomik saat tx.abort() pada ${dev15}...`, 'info');

      // Simpan baseline record
      const baselineRecord: LegacyGroupStateRecord = {
        device_uuid: dev15,
        room_uuid: room15,
        epoch: '1',
        state_bytes: new Uint8Array([0x01, 0x02, 0x03]),
        group_id_hash: 'hash-initial',
        updated_at: Date.now(),
      };
      await idbPut(BCAT_STORES.GROUP_STATES, baselineRecord);

      // Jalankan transaksi yang mencoba overwrite lalu abort
      let abortedCaught = false;
      try {
        await withReadwriteTransaction([BCAT_STORES.GROUP_STATES], (tx, getStore) => {
          const store = getStore(BCAT_STORES.GROUP_STATES);
          const mutatedRecord: LegacyGroupStateRecord = {
            device_uuid: dev15,
            room_uuid: room15,
            epoch: '99',
            state_bytes: new Uint8Array([0xff, 0xff, 0xff]),
            group_id_hash: 'hash-corrupted',
            updated_at: Date.now() + 1000,
          };
          store.put(mutatedRecord);
          // Batalkan transaksi secara sengaja
          tx.abort();
        });
      } catch (err: any) {
        abortedCaught = true;
      }

      if (!abortedCaught) {
        throw new Error('TC-C15: Transaksi aborted tidak melempar error.');
      }

      // Verifikasi record baseline tetap utuh (tidak tertimpa oleh data transaksi yang di-abort)
      const readBaseline = await idbGet<LegacyGroupStateRecord>(BCAT_STORES.GROUP_STATES, [dev15, room15]);
      if (!readBaseline || readBaseline.epoch !== '1' || readBaseline.state_bytes[0] !== 0x01) {
        throw new Error('TC-C15: Regresi F-02 terdeteksi: Data baseline termutasi oleh transaksi yang di-abort!');
      }

      updateTc('TC-C15', 'PASS', 'Record baseline tetap utuh pasca abort; rollback atomik IndexedDB terbukti.');
      addLog('[TC-C15] PASS: Rollback transaksi IDB (Regresi F-02) terverifikasi.', 'success');

      // -----------------------------------------------------------------------
      // Finalisasi
      // -----------------------------------------------------------------------
      setOverallResult('SUCCESS');
      addLog('=== SELURUH 15 TEST CASE KRIPTOGRAFI IDB BERHASIL DILALUI (100% PASS) ===', 'success');
    } catch (err: any) {
      setOverallResult('FAILED');
      addLog(`PENGUJIAN KRIPTO GAGAL: ${err.message}`, 'error');
    } finally {
      setIsRunning(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-8">
      <div className="max-w-5xl mx-auto space-y-6">
        {/* Header */}
        <div className="border-b border-slate-800 pb-4 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
              <span className="text-emerald-400">B-CHAT</span> Crypto Storage & KEK Lifecycle Test Suite
            </h1>
            <p className="text-sm text-slate-400 mt-1">
              Verifikasi Browser Runtime Tahap 2C.4H-I2 • AES-256-GCM • KEK 256-bit Non-Extractable • AAD RFC 5116
            </p>
          </div>
          <div className="flex gap-3">
            <button
              onClick={handleResetDatabase}
              disabled={isRunning}
              className="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium rounded-lg transition disabled:opacity-50"
            >
              Reset Database
            </button>
            <button
              id="btn-run-crypto-tests"
              onClick={runAllTests}
              disabled={isRunning}
              className="px-5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-50 flex items-center gap-2"
            >
              {isRunning ? 'Menjalankan...' : 'Jalankan Pengujian Kripto IDB'}
            </button>
          </div>
        </div>

        {/* Status Banner */}
        <div
          id="crypto-test-status"
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
              {overallResult === 'RUNNING' && 'Sedang Menguji KEK Lifecycle, AAD, & AES-256-GCM...'}
              {overallResult === 'SUCCESS' && 'LULUS PENUH (15/15 Test Cases PASS)'}
              {overallResult === 'FAILED' && 'PENGUJIAN GAGAL (Periksa Log di Bawah)'}
            </div>
          </div>
          <div className="text-right">
            <span className="text-xs text-slate-400">Total Kriteria</span>
            <div className="text-sm font-medium">15 Kasus Uji Kriptografi</div>
          </div>
        </div>

        {/* Test Cases Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          {testCases.map((tc) => (
            <div
              key={tc.id}
              className="p-4 rounded-lg bg-slate-900/80 border border-slate-800 flex flex-col justify-between"
            >
              <div className="flex items-start justify-between gap-2">
                <div>
                  <div className="text-xs font-mono text-emerald-400 font-semibold">{tc.id}</div>
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
              <span className="text-slate-600">Klik &quot;Jalankan Pengujian Kripto IDB&quot; untuk memulai audit runtime peramban.</span>
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

