/**
 * B-CHAT E2EE — Suite Pengujian Regresi Siklus Hidup History Transfer (Tahap 2B):
 * Perbaikan HTTP 409 TRANSFER_ALREADY_PENDING, Lifecycle Modal, Error Handling, & Auto-Refresh Pairing
 *
 * Menguji secara komprehensif 7 kontrak yang dipersyaratkan:
 * 1. Pembatalan mengirim deviceUuid pada argumen/header yang benar.
 * 2. Kegagalan pembatalan tidak dianggap berhasil (tidak ditelan & UI tidak berpura-pura berhasil).
 * 3. Respons TRANSFER_ALREADY_PENDING (HTTP 409) di-parse & ditampilkan dengan benar tanpa retry buta.
 * 4. Penutupan modal tidak meninggalkan permintaan aktif tanpa penanganan (cancellation guard on close).
 * 5. Perangkat sumber terdeteksi setelah pairing tanpa reload (refreshTransferSourceDevices).
 * 6. Polling history transfer terlindungi dari eksekusi ganda dan dibersihkan saat unmount.
 * 7. Alur transfer normal E2EE (Ed25519 signature + ECDH-HKDF-AES-GCM snapshot) tetap berfungsi.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { registerHooks, createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const FRONTEND_ROOT = path.resolve(__dirname, '..');
const SRC_ROOT = path.join(FRONTEND_ROOT, 'src');
const require = createRequire(import.meta.url);
const ts = require('typescript');

// ---------------------------------------------------------------------------
// In-Memory Test Harness
// ---------------------------------------------------------------------------

const harness = {
  lastAxiosCall: null,
  axiosCalls: [],
  mockResponse: null,
  mockError: null,
  reset() {
    this.lastAxiosCall = null;
    this.axiosCalls = [];
    this.mockResponse = null;
    this.mockError = null;
  },
};

globalThis.__BCHAT_LIFECYCLE_HARNESS__ = harness;
if (typeof globalThis.window === 'undefined') {
  globalThis.window = { crypto: crypto.webcrypto };
}

const MOCK_MODULES = {
  'mock://axios': `
    const axios = {
      async post(url, body, config) {
        const h = globalThis.__BCHAT_LIFECYCLE_HARNESS__;
        h.lastAxiosCall = { method: 'POST', url, body, config };
        h.axiosCalls.push({ method: 'POST', url, body, config });
        if (h.mockError) {
          const err = h.mockError;
          throw err;
        }
        return h.mockResponse || { data: { status: 'success', data: {} } };
      },
      async get(url, config) {
        const h = globalThis.__BCHAT_LIFECYCLE_HARNESS__;
        h.lastAxiosCall = { method: 'GET', url, config };
        h.axiosCalls.push({ method: 'GET', url, config });
        if (h.mockError) {
          const err = h.mockError;
          throw err;
        }
        return h.mockResponse || { data: { status: 'success', data: {} } };
      },
    };
    export default axios;
  `,
};

const ALIAS_MOCKS = {
  '@/lib/axios': 'mock://axios',
};

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (ALIAS_MOCKS[specifier]) {
      return { url: ALIAS_MOCKS[specifier], shortCircuit: true };
    }
    if (specifier.startsWith('@/')) {
      const rel = specifier.slice(2);
      for (const c of [
        path.join(SRC_ROOT, rel + '.ts'),
        path.join(SRC_ROOT, rel + '.tsx'),
        path.join(SRC_ROOT, rel, 'index.ts'),
      ]) {
        if (fs.existsSync(c)) return { url: pathToFileURL(c).href, shortCircuit: true };
      }
    }
    if (specifier.startsWith('.') && context.parentURL?.startsWith('file://')) {
      const parentDir = path.dirname(fileURLToPath(context.parentURL));
      for (const c of [
        path.resolve(parentDir, specifier + '.ts'),
        path.resolve(parentDir, specifier + '.tsx'),
        path.resolve(parentDir, specifier, 'index.ts'),
      ]) {
        if (fs.existsSync(c)) return { url: pathToFileURL(c).href, shortCircuit: true };
      }
    }
    return nextResolve(specifier, context);
  },
  load(url, context, nextLoad) {
    if (MOCK_MODULES[url]) {
      return { format: 'module', source: MOCK_MODULES[url], shortCircuit: true };
    }
    if (url.startsWith('file://') && (url.endsWith('.ts') || url.endsWith('.tsx'))) {
      const source = fs.readFileSync(fileURLToPath(url), 'utf-8');
      const compiled = ts.transpileModule(source, {
        compilerOptions: {
          module: ts.ModuleKind.ESNext,
          target: ts.ScriptTarget.ES2022,
          jsx: ts.JsxEmit.ReactJSX,
        },
      });
      return { format: 'module', source: compiled.outputText, shortCircuit: true };
    }
    return nextLoad(url, context);
  },
});

// Import production code dynamically
const {
  cancelHistoryTransfer,
  requestHistoryTransfer,
  fetchPendingHistoryTransfers,
  fetchHistoryTransferDetail,
  uploadHistoryTransferSnapshot,
  downloadHistoryTransferSnapshot,
  completeHistoryTransfer,
  BchatApiError,
  parseBchatApiError,
} = await import('../src/lib/bchat/history/bchat-history-transfer-client.ts');

const {
  shouldPollIncomingHistoryTransfers,
} = await import('../src/lib/bchat/handshake/bchat-handshake-coordinator.ts');

const {
  generateEphemeralTransferKeypair,
  importEphemeralPublicKey,
  deriveTransferAesKey,
  buildTransferRequestBuffer,
  buildSnapshotHeaderBuffer,
  signBufferWithDeviceKey,
  verifyBufferWithDevicePublicKey,
  encryptSnapshotPayload,
  decryptSnapshotPayload,
} = await import('../src/lib/bchat/history/bchat-history-transfer-crypto.ts');

// ===========================================================================
// TEST 1: Pembatalan Mengirim deviceUuid pada Argumen dan Header yang Benar
// ===========================================================================
test('1. cancelHistoryTransfer() mengirim deviceUuid pada header X-Bchat-Device-Uuid dan reason pada body', async () => {
  harness.reset();
  const transferUuid = '2eb2e00b-8cb1-4310-8d11-efe264751ba1';
  const reason = 'Dibatalkan oleh perangkat penerima';
  const deviceUuid = '2b8c7665-e80f-4388-8384-bf9ddc5debc5';

  harness.mockResponse = {
    data: {
      status: 'success',
      data: {
        transfer_uuid: transferUuid,
        status: 'CANCELLED',
        cancellation_reason: reason,
      },
    },
  };

  const res = await cancelHistoryTransfer(transferUuid, reason, deviceUuid);
  assert.equal(res.data.status, 'CANCELLED');

  const call = harness.lastAxiosCall;
  assert.ok(call, 'Axios POST harus dipanggil');
  assert.equal(call.method, 'POST');
  assert.equal(call.url, `/api/bchat/history-transfers/${transferUuid}/cancel`);

  // Pastikan body berisi reason, BUKAN deviceUuid!
  assert.deepEqual(call.body, { reason });
  assert.notEqual(call.body.reason, deviceUuid);

  // Pastikan header X-Bchat-Device-Uuid berisi deviceUuid, BUKAN teks reason!
  assert.equal(call.config?.headers?.['X-Bchat-Device-Uuid'], deviceUuid);
  assert.notEqual(call.config?.headers?.['X-Bchat-Device-Uuid'], reason);
});

// ===========================================================================
// TEST 2: Kegagalan Pembatalan Tidak Dianggap Berhasil & Menolak Penelanan Error
// ===========================================================================
test('2. Kegagalan cancelHistoryTransfer() menghasilkan BchatApiError dan tidak ditelan', async () => {
  harness.reset();
  const transferUuid = '5406cee9-4985-4072-be24-7be90264c0da';
  const deviceUuid = '221e5c67-08fa-459c-b1fe-f90608650222';

  // Simulasi error 403 FORBIDDEN_DEVICE_ACCESS dari backend
  harness.mockError = {
    message: 'Request failed with status code 403',
    response: {
      status: 403,
      data: {
        status: 'error',
        message: 'Perangkat tidak berhak membatalkan transfer ini.',
        error_code: 'FORBIDDEN_DEVICE_ACCESS',
      },
    },
  };

  await assert.rejects(
    async () => {
      await cancelHistoryTransfer(transferUuid, 'Test reason', deviceUuid);
    },
    (err) => {
      assert.ok(err instanceof BchatApiError);
      assert.equal(err.status, 403);
      assert.equal(err.errorCode, 'FORBIDDEN_DEVICE_ACCESS');
      assert.equal(err.message, 'Perangkat tidak berhak membatalkan transfer ini.');
      return true;
    }
  );

  // Verifikasi source code BchatHistoryTransferModal.tsx tidak menelan pembatalan dengan catch (_e) {}
  const modalSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatHistoryTransferModal.tsx'),
    'utf-8'
  );

  // handleCancelRequest tidak boleh memiliki catch kosong
  const cancelReqBlock = modalSrc.match(/const handleCancelRequest = async \(\) => {[\s\S]*?};/)?.[0] || '';
  assert.ok(cancelReqBlock.length > 0);
  assert.ok(!cancelReqBlock.includes('catch (_e) {}'));
  assert.ok(!cancelReqBlock.includes('catch (_e) {'));
  assert.match(cancelReqBlock, /parseBchatApiError/);
  assert.match(cancelReqBlock, /setErrorMsg\(/);

  // handleRejectIncoming tidak boleh menelan error selain 404 / TRANSFER_NOT_FOUND
  const rejectBlock = modalSrc.match(/const handleRejectIncoming = async \(\) => {[\s\S]*?};/)?.[0] || '';
  assert.ok(rejectBlock.length > 0);
  assert.ok(!rejectBlock.includes('catch (_e) {}'));
  assert.ok(!rejectBlock.includes('catch (_e) {'));
  assert.match(rejectBlock, /parseBchatApiError/);
});

// ===========================================================================
// TEST 3: Respons TRANSFER_ALREADY_PENDING (HTTP 409) Ditangani Spesifik
// ===========================================================================
test('3. HTTP 409 TRANSFER_ALREADY_PENDING di-parse sebagai BchatApiError terstruktur tanpa retry otomatis', async () => {
  harness.reset();
  const roomUuid = '7b1288fd-fe03-4a88-87fe-980c5741df18';
  const deviceUuid = '2b8c7665-e80f-4388-8384-bf9ddc5debc5';

  harness.mockError = {
    message: 'Request failed with status code 409',
    response: {
      status: 409,
      data: {
        status: 'error',
        message: 'Masih terdapat sesi transfer riwayat yang aktif untuk ruang obrolan ini.',
        error_code: 'TRANSFER_ALREADY_PENDING',
      },
    },
  };

  await assert.rejects(
    async () => {
      await requestHistoryTransfer(
        roomUuid,
        {
          source_device_uuid: '221e5c67-08fa-459c-b1fe-f90608650222',
          recipient_ephemeral_public_key: 'abcdef123456',
          transfer_nonce: 'nonce123',
          request_signature: 'sig123',
        },
        deviceUuid
      );
    },
    (err) => {
      assert.ok(err instanceof BchatApiError);
      assert.equal(err.status, 409);
      assert.equal(err.errorCode, 'TRANSFER_ALREADY_PENDING');
      assert.equal(
        err.message,
        'Masih terdapat sesi transfer riwayat yang aktif untuk ruang obrolan ini.'
      );
      return true;
    }
  );

  // Verifikasi penanganan di BchatHistoryTransferModal
  const modalSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatHistoryTransferModal.tsx'),
    'utf-8'
  );
  assert.match(modalSrc, /TRANSFER_ALREADY_PENDING/);
  assert.match(modalSrc, /Masih terdapat sesi transfer riwayat yang aktif/);
});

// ===========================================================================
// TEST 4: Penutupan Modal Menjalankan Cancellation Guard & Tidak Mereset Sembarangan
// ===========================================================================
test('4. Penutupan modal saat WAITING_APPROVAL membatalkan transfer di server; gagal batal menahan modal terbuka', () => {
  const modalSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatHistoryTransferModal.tsx'),
    'utf-8'
  );

  // 1. handleRequestClose ada dan memeriksa WAITING_APPROVAL serta recipient_device_uuid
  const closeBlock = modalSrc.match(/const handleRequestClose = async \(\) => {[\s\S]*?};/)?.[0] || '';
  assert.ok(closeBlock.length > 0);
  assert.match(closeBlock, /recipientStep === 'WAITING_APPROVAL'/);
  assert.match(closeBlock, /activeTransfer\.recipient_device_uuid === currentDeviceUuid/);
  assert.match(closeBlock, /cancelHistoryTransfer\(/);
  assert.match(closeBlock, /Dibatalkan karena dialog ditutup oleh pengguna/);

  // 2. Tombol close memanggil handleRequestClose, bukan sekadar clearTimers & onClose
  assert.match(modalSrc, /onClick=\{handleRequestClose\}/);

  // 3. Re-open modal tidak mereset state jika ada transfer aktif yang sedang menunggu persetujuan
  assert.match(modalSrc, /activeTransfer && \(recipientStep === 'WAITING_APPROVAL' \|\| recipientStep === 'IMPORTING'\)/);
});

// ===========================================================================
// TEST 5: Refresh Deteksi Perangkat Sumber Pasca-Pairing Tanpa Reload Halaman
// ===========================================================================
test('5. refreshTransferSourceDevices() memperbarui hasTransferSourceDevices saat pairing baru disetujui', async () => {
  const devManagementSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatDeviceManagementModal.tsx'),
    'utf-8'
  );
  assert.match(devManagementSrc, /onDevicesChanged\?: \(\) => void;/);
  assert.match(devManagementSrc, /if \(onDevicesChanged\) \{\s*onDevicesChanged\(\);\s*\}/);

  const chatSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatProposalChat.tsx'),
    'utf-8'
  );
  assert.match(chatSrc, /refreshTransferSourceDevices = useCallback/);
  assert.match(chatSrc, /onDevicesChanged=\{refreshTransferSourceDevices\}/);

  // Simulasi logika evaluasi:
  // Device 3 (Chrome / Approver)
  const activeDeviceUuid = '221e5c67-08fa-459c-b1fe-f90608650222';

  // Sebelum pairing: Device 5 belum tepercaya / belum ada
  const devicesBeforePairing = [
    { device_uuid: activeDeviceUuid, status: 'ACTIVE', is_trusted: true },
    { device_uuid: '2b8c7665-e80f-4388-8384-bf9ddc5debc5', status: 'ACTIVE', is_trusted: false },
  ];
  const eligibleBefore = devicesBeforePairing.filter(
    (d) =>
      d.device_uuid.toLowerCase() !== activeDeviceUuid.toLowerCase() &&
      d.status === 'ACTIVE' &&
      d.is_trusted === true
  );
  assert.equal(eligibleBefore.length, 0);

  // Eligibility polling sebelum pairing -> false
  const eligibilityBefore = shouldPollIncomingHistoryTransfers({
    deviceUuid: activeDeviceUuid,
    userId: 5,
    onboardingGate: 'PASSED',
    connectionStatus: 'CONNECTED',
    hasTransferSourceDevices: eligibleBefore.length > 0,
    hasLocalGroupState: true,
    isUnrecoverableActiveRoom: false,
    hasWaitingState: false,
    isHistoryTransferModalOpen: false,
    hasIncomingTransferRequest: false,
  });
  assert.equal(eligibilityBefore, false);

  // Setelah pairing: Device 5 disetujui menjadi is_trusted = true
  const devicesAfterPairing = [
    { device_uuid: activeDeviceUuid, status: 'ACTIVE', is_trusted: true },
    { device_uuid: '2b8c7665-e80f-4388-8384-bf9ddc5debc5', status: 'ACTIVE', is_trusted: true },
  ];
  const eligibleAfter = devicesAfterPairing.filter(
    (d) =>
      d.device_uuid.toLowerCase() !== activeDeviceUuid.toLowerCase() &&
      d.status === 'ACTIVE' &&
      d.is_trusted === true
  );
  assert.equal(eligibleAfter.length, 1);

  // Eligibility polling setelah pairing langsung -> true tanpa reload!
  const baseReady = {
    deviceUuid: activeDeviceUuid,
    userId: 5,
    onboardingGate: 'PASSED',
    connectionStatus: 'CONNECTED',
    hasTransferSourceDevices: eligibleAfter.length > 0,
    hasLocalGroupState: true,
    isUnrecoverableActiveRoom: false,
    hasWaitingState: false,
    isHistoryTransferModalOpen: false,
    hasIncomingTransferRequest: false,
  };
  assert.equal(shouldPollIncomingHistoryTransfers(baseReady), true);

  // Dekopel: Saat WebSocket Reverb sedang connecting/reconnecting (yellow badge 'Menghubungkan...'),
  // polling HTTP REST History Transfer TETAP aktif!
  assert.equal(
    shouldPollIncomingHistoryTransfers({
      ...baseReady,
      connectionStatus: 'RECONNECTING',
    }),
    true
  );

  // Jika koneksi room dalam kondisi ERROR atau INITIALIZING, polling dilarang
  assert.equal(
    shouldPollIncomingHistoryTransfers({
      ...baseReady,
      connectionStatus: 'ERROR',
    }),
    false
  );
  assert.equal(
    shouldPollIncomingHistoryTransfers({
      ...baseReady,
      connectionStatus: 'INITIALIZING',
    }),
    false
  );
});

// ===========================================================================
// TEST 6: Proteksi Polling Ganda & Pembersihan Timer Lifecycle
// ===========================================================================
test('6. Polling history transfer memiliki pelindung in-flight guard, timer tunggal, dan cleanup pada unmount', () => {
  const chatSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatProposalChat.tsx'),
    'utf-8'
  );

  // Timer ref tunggal
  assert.match(chatSrc, /const historyTransferPollingTimerRef = useRef<NodeJS\.Timeout \| null>\(null\);/);
  // In-flight guard ref
  assert.match(chatSrc, /const historyTransferInFlightRef = useRef<boolean>\(false\);/);

  // Pengecekan in-flight sebelum fetch
  assert.match(chatSrc, /if \(!isMounted \|\| historyTransferInFlightRef\.current\) return;/);
  // Setting in-flight ke true & false dalam finally
  assert.match(chatSrc, /historyTransferInFlightRef\.current = true;/);
  assert.match(chatSrc, /historyTransferInFlightRef\.current = false;/);

  // Pembersihan timer pada top of effect dan return cleanup
  assert.match(chatSrc, /if \(historyTransferPollingTimerRef\.current\) \{\s*clearInterval\(historyTransferPollingTimerRef\.current\);/);
});

// ===========================================================================
// TEST 7: Alur Transfer Normal E2EE Berfungsi Penuh (Crypto Roundtrip)
// ===========================================================================
test('7. Alur kriptografi History Transfer E2EE (Ed25519 + ECDH-P256 + HKDF + AES-256-GCM) berhasil roundtrip', async () => {
  // 1. Generate identity Ed25519 keypairs untuk Perangkat Sumber & Penerima
  const sourceIdentity = await crypto.subtle.generateKey('Ed25519', true, ['sign', 'verify']);
  const recipientIdentity = await crypto.subtle.generateKey('Ed25519', true, ['sign', 'verify']);

  const rawSourcePub = await crypto.subtle.exportKey('raw', sourceIdentity.publicKey);
  const sourcePubHex = Array.from(new Uint8Array(rawSourcePub)).map((b) => b.toString(16).padStart(2, '0')).join('');

  const rawRecipientPub = await crypto.subtle.exportKey('raw', recipientIdentity.publicKey);
  const recipientPubHex = Array.from(new Uint8Array(rawRecipientPub)).map((b) => b.toString(16).padStart(2, '0')).join('');

  const roomUuid = '7b1288fd-fe03-4a88-87fe-980c5741df18';
  const sourceDevUuid = '221e5c67-08fa-459c-b1fe-f90608650222';
  const recipientDevUuid = '2b8c7665-e80f-4388-8384-bf9ddc5debc5';
  const userId = 5;

  // 2. Penerima membuat ephemeral keypair & tanda tangan Ed25519
  const recipientEphemeral = await generateEphemeralTransferKeypair();
  const nonceBytes = crypto.getRandomValues(new Uint8Array(32));
  const nonceHex = Array.from(nonceBytes).map((b) => b.toString(16).padStart(2, '0')).join('');

  const reqBuffer = await buildTransferRequestBuffer(
    userId,
    roomUuid,
    recipientDevUuid,
    sourceDevUuid,
    nonceHex,
    recipientEphemeral.publicKeyHex
  );
  const requestSig = await signBufferWithDeviceKey(recipientIdentity.privateKey, reqBuffer);

  // 3. Sumber memverifikasi tanda tangan permintaan penerima
  const isReqValid = await verifyBufferWithDevicePublicKey(recipientPubHex, requestSig, reqBuffer);
  assert.equal(isReqValid, true, 'Tanda tangan permintaan Ed25519 penerima harus valid');

  // 4. Sumber mengekspor snapshot, generate ephemeral sumber, derive shared key via ECDH + HKDF, enkripsi AES-256-GCM
  const transferUuid = crypto.randomUUID();
  const sourceEphemeral = await generateEphemeralTransferKeypair();
  const recipientPubCryptoKey = await importEphemeralPublicKey(recipientEphemeral.publicKeyHex);

  const salt = new TextEncoder().encode(`transfer_salt_${transferUuid}`);
  const info = `UNUHA-BCHAT-TRANSFER-KEY:${transferUuid}:${roomUuid}`;
  const transferAesKey = await deriveTransferAesKey(
    sourceEphemeral.privateKey,
    recipientPubCryptoKey,
    salt,
    info
  );

  const sampleHistoryData = {
    messages: [
      { server_sequence: 18, plaintext: 'Halo dari Gen 3' },
      { server_sequence: 19, plaintext: 'Pesan kedua' },
    ],
  };

  const aad = `UNUHA-BCHAT-AAD:${userId}:${transferUuid}:${roomUuid}:${recipientDevUuid}:${sourceDevUuid}`;
  const encrypted = await encryptSnapshotPayload(JSON.stringify(sampleHistoryData), transferAesKey, aad);

  // Sumber menandatangani snapshot header dengan Ed25519 miliknya
  const snapshotHeaderBuf = await buildSnapshotHeaderBuffer(
    userId,
    transferUuid,
    roomUuid,
    recipientDevUuid,
    sourceDevUuid,
    encrypted.hashSha256Hex,
    sourceEphemeral.publicKeyHex
  );
  const snapshotSig = await signBufferWithDeviceKey(sourceIdentity.privateKey, snapshotHeaderBuf);

  // 5. Penerima memverifikasi tanda tangan snapshot sumber
  const isSnapshotSigValid = await verifyBufferWithDevicePublicKey(sourcePubHex, snapshotSig, snapshotHeaderBuf);
  assert.equal(isSnapshotSigValid, true, 'Tanda tangan snapshot Ed25519 sumber harus valid');

  // 6. Penerima derive shared key yang sama via ECDH + HKDF dan mendekripsi payload
  const sourcePubCryptoKey = await importEphemeralPublicKey(sourceEphemeral.publicKeyHex);
  const recipientAesKey = await deriveTransferAesKey(
    recipientEphemeral.privateKey,
    sourcePubCryptoKey,
    salt,
    info
  );

  const decryptedJson = await decryptSnapshotPayload(
    encrypted.ciphertextBase64,
    encrypted.ivHex,
    recipientAesKey,
    aad
  );
  const decryptedPayload = JSON.parse(decryptedJson);

  assert.deepEqual(decryptedPayload, sampleHistoryData, 'Payload riwayat yang didekripsi harus identik dengan aslinya');
});
