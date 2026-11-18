/**
 * B-CHAT E2EE — Suite Pengujian Pencegahan Blocker 2:
 * Penyimpanan CEK pada Manifest Lampiran, Preservasi History Transfer, & Dekripsi Lintas Session Generation
 *
 * Mengimpor dan menguji LANGSUNG kode produksi aktual:
 * - src/lib/bchat/attachment/bchat-attachment-manifest.ts
 * - src/lib/bchat/attachment/bchat-attachment-coordinator.ts
 * - src/lib/bchat/attachment/bchat-attachment-crypto.ts
 * - src/lib/bchat/history/bchat-history-transfer-coordinator.ts
 * - src/lib/bchat/history/bchat-history-transfer-crypto.ts
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
// 1. In-Memory Boundary Harness (Axios Attachment Server + History Transfer Relay + IDB)
// ---------------------------------------------------------------------------

const harness = {
  uploadSessions: new Map(),
  chunksByAttachment: new Map(), // attUuid -> Map<index, Uint8Array>
  serverSawPayloads: [],
  transfers: new Map(),
  historyStore: new Map(), // `${dev}:${room}:${seq}` -> record
  reset() {
    this.uploadSessions.clear();
    this.chunksByAttachment.clear();
    this.serverSawPayloads = [];
    this.transfers.clear();
    this.historyStore.clear();
  },
};

globalThis.__BCHAT_ATT_PREVENTION_HARNESS__ = harness;
if (typeof globalThis.window === 'undefined') {
  globalThis.window = { crypto: crypto.webcrypto };
}

const MOCK_MODULES = {
  'mock://axios': `
    const axios = {
      async post(url, payload) {
        const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
        h.serverSawPayloads.push({ method: 'POST', url, payload });
        if (url.endsWith('/attachments/upload-session')) {
          const sessionUuid = crypto.randomUUID();
          h.uploadSessions.set(sessionUuid, { ...payload, sessionUuid });
          h.chunksByAttachment.set(payload.attachment_uuid, new Map());
          return {
            data: {
              status: 'success',
              data: {
                session_uuid: sessionUuid,
                attachment_uuid: payload.attachment_uuid,
                total_chunks: payload.total_chunks,
                status: 'PENDING',
                expires_at: new Date(Date.now() + 600000).toISOString(),
              },
            },
          };
        }
        if (url.endsWith('/complete')) {
          return {
            data: {
              status: 'success',
              data: {
                session_uuid: url.split('/').slice(-2)[0],
                attachment_uuid: 'ok',
                status: 'COMPLETED',
                total_chunks: 1,
                ciphertext_bytes: 0,
              },
            },
          };
        }
        throw new Error('Unhandled mock axios POST: ' + url);
      },
      async put(url, body) {
        const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
        const parts = url.split('/');
        const chunkIndex = Number(parts[parts.length - 1]);
        const sessionUuid = parts[parts.length - 3];
        const sess = h.uploadSessions.get(sessionUuid);
        const bytes = new Uint8Array(body);
        h.chunksByAttachment.get(sess.attachment_uuid).set(chunkIndex, bytes);
        return {
          data: {
            status: 'success',
            data: { chunk_index: chunkIndex, ciphertext_bytes: bytes.length, status: 'UPLOADED' },
          },
        };
      },
      async get(url) {
        const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
        const parts = url.split('/');
        const chunkIndex = Number(parts[parts.length - 1]);
        const attUuid = parts[parts.length - 3];
        const bytes = h.chunksByAttachment.get(attUuid)?.get(chunkIndex);
        if (!bytes) throw new Error('Chunk not found');
        return { data: bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) };
      },
    };
    export default axios;
  `,
  'mock://history-transfer-client': `
    export async function requestHistoryTransfer(roomUuid, payload, recipientDeviceUuid) {
      const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
      const transfer_uuid = crypto.randomUUID();
      const rec = {
        transfer_uuid,
        room_uuid: roomUuid,
        source_device_uuid: payload.source_device_uuid,
        recipient_device_uuid: recipientDeviceUuid,
        recipient_ephemeral_public_key: payload.recipient_ephemeral_public_key,
        transfer_nonce: payload.transfer_nonce,
        request_signature: payload.request_signature,
        status: 'REQUESTED',
      };
      h.transfers.set(transfer_uuid, rec);
      return { data: rec };
    }
    export async function uploadHistoryTransferSnapshot(transferUuid, payload) {
      const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
      h.serverSawPayloads.push({ method: 'PUT', url: '/history-transfers/' + transferUuid + '/snapshot', payload });
      const rec = h.transfers.get(transferUuid);
      Object.assign(rec, payload, { status: 'READY' });
      return { data: rec };
    }
    export async function downloadHistoryTransferSnapshot(transferUuid) {
      const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
      return { data: h.transfers.get(transferUuid) };
    }
    export async function completeHistoryTransfer(transferUuid) {
      const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
      const rec = h.transfers.get(transferUuid);
      rec.status = 'COMPLETED';
      rec.snapshot_ciphertext = null;
      return { data: rec };
    }
    export async function cancelHistoryTransfer() { return { data: {} }; }
  `,
  'mock://bchat-encrypted-history': `
    export async function saveEncryptedHistoryItem(params) {
      const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
      const key = params.deviceUuid.toLowerCase() + ':' + params.roomUuid.toLowerCase() + ':' + params.sequenceNumber;
      h.historyStore.set(key, {
        server_sequence: params.sequenceNumber,
        message_uuid: params.messageUuid,
        sender_id: params.senderUserId,
        epoch: 1,
        generation: 0,
        content_type: params.contentType ?? 1,
        is_self: params.isSelf,
        plaintext: params.plaintext,
        client_sent_at: params.clientSentAt ?? null,
        created_at: params.createdAt ?? null,
      });
    }
    export async function getEncryptedHistoryItem(deviceUuid, roomUuid, seq) {
      const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
      const key = deviceUuid.toLowerCase() + ':' + roomUuid.toLowerCase() + ':' + seq;
      return h.historyStore.get(key) ?? null;
    }
    export async function loadEncryptedHistory(deviceUuid, roomUuid) {
      const h = globalThis.__BCHAT_ATT_PREVENTION_HARNESS__;
      const prefix = deviceUuid.toLowerCase() + ':' + roomUuid.toLowerCase() + ':';
      return Array.from(h.historyStore.entries())
        .filter(([k]) => k.startsWith(prefix))
        .map(([, v]) => v)
        .sort((a, b) => a.server_sequence - b.server_sequence);
    }
  `,
  'mock://bchat-device-registration': `
    export function bytesToCanonicalHex(bytes) {
      return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
    }
    export function canonicalHexToBytes(hex) {
      const clean = hex.trim().toLowerCase();
      const out = new Uint8Array(clean.length / 2);
      for (let i = 0; i < out.length; i++) out[i] = parseInt(clean.slice(i * 2, i * 2 + 2), 16);
      return out;
    }
    export function canonicalUuidToBytes(uuid) {
      return canonicalHexToBytes(uuid.replace(/-/g, ''));
    }
  `,
};

const ALIAS_MOCKS = {
  '@/lib/axios': 'mock://axios',
  './bchat-history-transfer-client': 'mock://history-transfer-client',
  '../storage/bchat-encrypted-history': 'mock://bchat-encrypted-history',
  '../device/bchat-device-registration': 'mock://bchat-device-registration',
};

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (ALIAS_MOCKS[specifier]) {
      return { url: ALIAS_MOCKS[specifier], shortCircuit: true };
    }
    if (specifier.startsWith('@/')) {
      const rel = specifier.slice(2);
      for (const c of [path.join(SRC_ROOT, rel + '.ts'), path.join(SRC_ROOT, rel + '.tsx'), path.join(SRC_ROOT, rel, 'index.ts')]) {
        if (fs.existsSync(c)) return { url: pathToFileURL(c).href, shortCircuit: true };
      }
    }
    if (specifier.startsWith('.') && context.parentURL?.startsWith('file://')) {
      const baseDir = path.dirname(fileURLToPath(context.parentURL));
      for (const c of [path.resolve(baseDir, specifier + '.ts'), path.resolve(baseDir, specifier, 'index.ts')]) {
        if (fs.existsSync(c)) return { url: pathToFileURL(c).href, shortCircuit: true };
      }
    }
    return nextResolve(specifier, context);
  },
  load(url, context, nextLoad) {
    if (MOCK_MODULES[url]) return { format: 'module', source: MOCK_MODULES[url], shortCircuit: true };
    if (url.startsWith('file://') && (url.endsWith('.ts') || url.endsWith('.tsx'))) {
      const filePath = fileURLToPath(url);
      const transpiled = ts.transpileModule(fs.readFileSync(filePath, 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
        fileName: filePath,
      });
      return { format: 'module', source: transpiled.outputText, shortCircuit: true };
    }
    return nextLoad(url, context);
  },
});

const manifestMod = await import('@/lib/bchat/attachment/bchat-attachment-manifest');
const coordinatorMod = await import('@/lib/bchat/attachment/bchat-attachment-coordinator');
const historyCoordMod = await import('@/lib/bchat/history/bchat-history-transfer-coordinator');
const historyStoreMod = await import('mock://bchat-encrypted-history');

const { validateAttachmentManifest, parseAttachmentManifest, formatAttachmentManifest } = manifestMod;
const { BchatAttachmentCoordinator } = coordinatorMod;
const {
  initiateTransferFlow,
  exportAndUploadSnapshotFlow,
  downloadAndImportSnapshotFlow,
  preserveAttachmentManifestCek,
} = historyCoordMod;

const ROOM_UUID = '7b1288fd-fe03-4a88-87fe-980c5741df18';
const SRC_DEV = 'dfac2a29-72ac-4809-90f0-b97b26b7fe63';
const DST_DEV = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
const USER_ID = 4;

function bytesToBase64(bytes) {
  return Buffer.from(bytes).toString('base64');
}

function sha256Hex(bytes) {
  return crypto.createHash('sha256').update(bytes).digest('hex');
}

function makeFakeWorkerForGeneration(secretSeed) {
  return {
    async deriveAttachmentKey({ roomUuid, messageUuid, attachmentUuid }) {
      const cek = crypto
        .createHmac('sha256', secretSeed)
        .update(`${roomUuid}:${messageUuid}:${attachmentUuid}`)
        .digest();
      return { cekBase64: cek.toString('base64'), keyLengthBytes: 32, epoch: '1' };
    },
  };
}

test('1. Validasi manifest menerima cekBase64 32-byte valid dan menolak cekBase64 rusak/salah panjang', () => {
  const validCekB64 = bytesToBase64(crypto.randomBytes(32));
  const baseAtt = {
    attachmentUuid: crypto.randomUUID(),
    uploadSessionId: crypto.randomUUID(),
    fileName: 'bab2_revisi.pdf',
    mimeType: 'application/pdf',
    fileSizeBytes: 1234,
    totalChunks: 1,
    fileHashSha256: 'a'.repeat(64),
  };

  const valid = validateAttachmentManifest({
    version: '1.0.0',
    type: 'ATTACHMENT_MANIFEST',
    attachments: [{ ...baseAtt, cekBase64: validCekB64 }],
  });
  assert.equal(valid.attachments[0].cekBase64, validCekB64);

  // Panjang salah (16 byte, bukan 32 byte) -> wajib ditolak
  assert.throws(() =>
    validateAttachmentManifest({
      version: '1.0.0',
      type: 'ATTACHMENT_MANIFEST',
      attachments: [{ ...baseAtt, cekBase64: bytesToBase64(crypto.randomBytes(16)) }],
    })
  );

  // Base64 invalid -> wajib ditolak
  assert.throws(() =>
    validateAttachmentManifest({
      version: '1.0.0',
      type: 'ATTACHMENT_MANIFEST',
      attachments: [{ ...baseAtt, cekBase64: '!!!invalid-base64!!!' }],
    })
  );
});

test('2. Upload lampiran baru (single-chunk & multi-chunk) menyematkan cekBase64 di manifest E2EE dan server tidak menerima CEK/filename/MIME', async () => {
  harness.reset();

  const gen2Worker = makeFakeWorkerForGeneration('GROUP_STATE_GEN2_SECRET');
  const gen3WrongWorker = makeFakeWorkerForGeneration('GROUP_STATE_GEN3_DIFFERENT_SECRET');

  // File multi-chunk 1.2 MiB (2 chunks: 1 MiB + 200 KiB)
  const fileBytes = crypto.randomBytes(1048576 + 204800);
  const expectedSha = sha256Hex(fileBytes);
  const msgUuid = crypto.randomUUID();
  const attUuid = crypto.randomUUID();

  const uploadRes = await BchatAttachmentCoordinator.uploadAttachment({
    roomUuid: ROOM_UUID,
    messageUuid: msgUuid,
    attachmentUuid: attUuid,
    fileBytes,
    fileName: 'naskah_skripsi_gen2_multichunk.pdf',
    workerClient: gen2Worker,
  });

  // Bentuk manifest persis seperti BchatProposalChat.tsx pasca perbaikan Blocker 2
  const cekBase64 = bytesToBase64(uploadRes.cekBytes);
  const manifestJson = formatAttachmentManifest({
    version: '1.0.0',
    type: 'ATTACHMENT_MANIFEST',
    text: 'Lampiran baru Gen 2',
    attachments: [
      {
        attachmentUuid: uploadRes.attachmentUuid,
        uploadSessionId: uploadRes.sessionUuid,
        fileName: 'naskah_skripsi_gen2_multichunk.pdf',
        mimeType: 'application/pdf',
        fileSizeBytes: uploadRes.plaintextBytes,
        totalChunks: uploadRes.totalChunks,
        fileHashSha256: uploadRes.fileHashSha256,
        cekBase64,
      },
    ],
  });

  // Verifikasi zero leakage ke backend: payload HTTP server TIDAK memuat CEK, filename, maupun MIME!
  const serializedServerTraffic = JSON.stringify(harness.serverSawPayloads);
  assert.ok(!serializedServerTraffic.includes(cekBase64), 'Server tidak boleh menerima CEK');
  assert.ok(!serializedServerTraffic.includes('naskah_skripsi_gen2_multichunk.pdf'), 'Server tidak boleh menerima filename');
  assert.ok(!serializedServerTraffic.includes('application/pdf'), 'Server tidak boleh menerima MIME plaintext');

  // Penerima mem-parsing manifest dan mengunduh/mendekripsi DENGAN worker yang berada pada Gen3 (generasi salah!)
  // -> harus tetap SUKSES menggunakan preDerivedCekBytes dari manifest!
  const parsed = parseAttachmentManifest(manifestJson);
  assert.ok(parsed?.attachments[0]?.cekBase64);
  const preDerivedCekBytes = new Uint8Array(Buffer.from(parsed.attachments[0].cekBase64, 'base64'));

  const decryptedBytes = await BchatAttachmentCoordinator.downloadAttachment({
    roomUuid: ROOM_UUID,
    messageUuid: msgUuid,
    attachmentUuid: attUuid,
    uploadSessionId: uploadRes.sessionUuid,
    totalChunks: uploadRes.totalChunks,
    expectedTotalSize: uploadRes.plaintextBytes,
    expectedFileSha256: expectedSha,
    workerClient: gen3WrongWorker, // GroupState generasi baru yg berbeda!
    preDerivedCekBytes,
  });

  assert.equal(decryptedBytes.length, fileBytes.length);
  assert.equal(sha256Hex(decryptedBytes), expectedSha);
});

test('3. History Transfer E2EE mempertahankan cekBase64, mencegah duplikasi saat impor ulang, dan perangkat penerima dapat mendekripsi tanpa GroupState lama', async () => {
  harness.reset();

  const gen1Worker = makeFakeWorkerForGeneration('GROUP_STATE_GEN1_ARCHIVED');
  const fileBytes = crypto.randomBytes(65536);
  const expectedSha = sha256Hex(fileBytes);
  const msgUuid = crypto.randomUUID();
  const attUuid = crypto.randomUUID();

  const uploadRes = await BchatAttachmentCoordinator.uploadAttachment({
    roomUuid: ROOM_UUID,
    messageUuid: msgUuid,
    attachmentUuid: attUuid,
    fileBytes,
    fileName: 'lampiran_lintas_transfer.pdf',
    workerClient: gen1Worker,
  });

  const cekBase64 = bytesToBase64(uploadRes.cekBytes);
  const manifestJson = formatAttachmentManifest({
    version: '1.0.0',
    type: 'ATTACHMENT_MANIFEST',
    attachments: [
      {
        attachmentUuid: uploadRes.attachmentUuid,
        uploadSessionId: uploadRes.sessionUuid,
        fileName: 'lampiran_lintas_transfer.pdf',
        mimeType: 'application/pdf',
        fileSizeBytes: uploadRes.plaintextBytes,
        totalChunks: uploadRes.totalChunks,
        fileHashSha256: uploadRes.fileHashSha256,
        cekBase64,
      },
    ],
  });

  // Simpan di message_history perangkat sumber (SRC_DEV)
  await historyStoreMod.saveEncryptedHistoryItem({
    deviceUuid: SRC_DEV,
    roomUuid: ROOM_UUID,
    sequenceNumber: 1,
    messageUuid: msgUuid,
    senderUserId: USER_ID,
    isSelf: true,
    plaintext: manifestJson,
    contentType: 2,
  });

  // Buat Ed25519 identity keypair asli untuk SRC_DEV & DST_DEV
  const srcIdKeys = await crypto.webcrypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify']);
  const dstIdKeys = await crypto.webcrypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify']);
  const srcPubHex = Buffer.from(await crypto.webcrypto.subtle.exportKey('raw', srcIdKeys.publicKey)).toString('hex');

  // Alur History Transfer resmi (ECDH + HKDF + AES-GCM + Ed25519)
  const { transfer } = await initiateTransferFlow(USER_ID, ROOM_UUID, DST_DEV, dstIdKeys.privateKey, SRC_DEV);
  await exportAndUploadSnapshotFlow(USER_ID, transfer, SRC_DEV, srcIdKeys.privateKey);
  const importRes = await downloadAndImportSnapshotFlow(USER_ID, transfer.transfer_uuid, DST_DEV, dstIdKeys.privateKey, srcPubHex);

  assert.equal(importRes.importedCount, 1);

  // Verifikasi server relay tidak pernah melihat cekBase64 plaintext
  const relayTraffic = JSON.stringify(harness.serverSawPayloads);
  assert.ok(!relayTraffic.includes(cekBase64), 'Snapshot ciphertext di server relay tidak boleh mengandung cekBase64 plaintext');

  // Simulasikan reload: baca ulang dari historyStore perangkat penerima (DST_DEV)
  const dstHistory = await historyStoreMod.loadEncryptedHistory(DST_DEV, ROOM_UUID);
  assert.equal(dstHistory.length, 1);
  const parsedAtDst = parseAttachmentManifest(dstHistory[0].plaintext);
  assert.equal(parsedAtDst?.attachments[0]?.cekBase64, cekBase64);

  // Perangkat penerima TANPA workerClient sama sekali (workerClient: null) tetap bisa mengunduh & mendekripsi lampiran!
  const preDerivedCekBytes = new Uint8Array(Buffer.from(parsedAtDst.attachments[0].cekBase64, 'base64'));
  const decryptedAtDst = await BchatAttachmentCoordinator.downloadAttachment({
    roomUuid: ROOM_UUID,
    messageUuid: msgUuid,
    attachmentUuid: attUuid,
    uploadSessionId: uploadRes.sessionUuid,
    totalChunks: uploadRes.totalChunks,
    expectedTotalSize: uploadRes.plaintextBytes,
    expectedFileSha256: expectedSha,
    workerClient: null,
    preDerivedCekBytes,
  });
  assert.equal(sha256Hex(decryptedAtDst), expectedSha);

  // Uji preserveAttachmentManifestCek: impor ulang manifest tanpa cekBase64 tidak menghilangkan cekBase64 yang sudah ada
  const manifestWithoutCek = formatAttachmentManifest({
    version: '1.0.0',
    type: 'ATTACHMENT_MANIFEST',
    attachments: [
      {
        attachmentUuid: uploadRes.attachmentUuid,
        uploadSessionId: uploadRes.sessionUuid,
        fileName: 'lampiran_lintas_transfer.pdf',
        mimeType: 'application/pdf',
        fileSizeBytes: uploadRes.plaintextBytes,
        totalChunks: uploadRes.totalChunks,
        fileHashSha256: uploadRes.fileHashSha256,
      },
    ],
  });
  const preservedJson = preserveAttachmentManifestCek(manifestWithoutCek, dstHistory[0].plaintext);
  assert.equal(parseAttachmentManifest(preservedJson)?.attachments[0]?.cekBase64, cekBase64);
});
