/**
 * B-CHAT E2EE — Suite Pengujian Terarah Blocker 1: Sequence Recovery, Cursor Contiguity & Realtime Consistency
 *
 * Mengimpor dan menguji LANGSUNG kode produksi aktual:
 * - src/lib/bchat/message/bchat-message-coordinator.ts
 * - src/lib/bchat/message/bchat-realtime-listener.ts
 *
 * Menggunakan synchronous module hook bawaan Node.js + TypeScript compiler untuk
 * me-mock hanya boundary I/O (REST API, IndexedDB history, GroupState cursor, Laravel Echo).
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { registerHooks } from 'node:module';
import { createRequire } from 'node:module';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const FRONTEND_ROOT = path.resolve(__dirname, '..');
const SRC_ROOT = path.join(FRONTEND_ROOT, 'src');

const require = createRequire(import.meta.url);
const ts = require('typescript');

// ---------------------------------------------------------------------------
// 1. In-Memory Harness State untuk Boundary I/O (API, IDB History, Cursor, Echo)
// ---------------------------------------------------------------------------

const harness = {
  serverMessages: [],
  fetchCalls: [],
  historyStore: new Map(), // key: `${device}:${room}:${seq}` -> item
  persistentCursor: 0,
  cursorUpdates: [],
  echoChannelListeners: new Map(), // event -> fn[]
  echoSubscribedCallbacks: [],
  reset() {
    this.serverMessages = [];
    this.fetchCalls = [];
    this.historyStore.clear();
    this.persistentCursor = 0;
    this.cursorUpdates = [];
    this.echoChannelListeners.clear();
    this.echoSubscribedCallbacks = [];
  },
  emitWsEvent(eventName, payload) {
    const fns = this.echoChannelListeners.get(eventName) || [];
    for (const fn of fns) fn(payload);
  },
};

globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__ = harness;

const MOCK_MODULES = {
  'mock://bchat-api-client': `
    export class BchatApiError extends Error {
      constructor(message, status, errorCode) {
        super(message);
        this.status = status;
        this.errorCode = errorCode;
      }
    }
    export async function fetchMessages(roomUuid, query = {}) {
      const h = globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__;
      const after = query.after_sequence ?? 0;
      const limit = query.limit ?? 50;
      h.fetchCalls.push({ roomUuid, after_sequence: after, limit });
      const filtered = h.serverMessages
        .filter((m) => m.server_sequence > after)
        .sort((a, b) => a.server_sequence - b.server_sequence)
        .slice(0, limit);
      return { status: 'success', data: filtered };
    }
    export async function sendMessage() {
      throw new Error('sendMessage not used directly in inbound recovery harness');
    }
  `,
  'mock://bchat-mls-persistence': `
    export async function getGroupStateCursor() {
      return globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__.persistentCursor;
    }
    export async function updateGroupStateCursor(params) {
      const h = globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__;
      h.cursorUpdates.push(params.sequence);
      if (params.sequence > h.persistentCursor) {
        h.persistentCursor = params.sequence;
      }
    }
  `,
  'mock://bchat-encrypted-history': `
    export async function saveEncryptedHistoryItem(params) {
      const h = globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__;
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
      const h = globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__;
      const key = deviceUuid.toLowerCase() + ':' + roomUuid.toLowerCase() + ':' + seq;
      return h.historyStore.get(key) ?? null;
    }
    export async function loadEncryptedHistory(deviceUuid, roomUuid) {
      const h = globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__;
      const prefix = deviceUuid.toLowerCase() + ':' + roomUuid.toLowerCase() + ':';
      return Array.from(h.historyStore.entries())
        .filter(([k]) => k.startsWith(prefix))
        .map(([, v]) => v)
        .sort((a, b) => a.server_sequence - b.server_sequence);
    }
  `,
  'mock://echo': `
    export function getEcho() {
      const h = globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__;
      return {
        private() {
          const ch = {
            subscribed(cb) {
              h.echoSubscribedCallbacks.push(cb);
              return ch;
            },
            error() { return ch; },
            listen(evt, cb) {
              const list = h.echoChannelListeners.get(evt) || [];
              list.push(cb);
              h.echoChannelListeners.set(evt, list);
              return ch;
            },
            stopListening(evt) {
              h.echoChannelListeners.delete(evt);
              return ch;
            },
          };
          return ch;
        },
        leave() {},
      };
    }
  `,
};

const ALIAS_MOCKS = {
  '@/lib/bchat/api/bchat-api-client': 'mock://bchat-api-client',
  '@/lib/bchat/storage/bchat-mls-persistence': 'mock://bchat-mls-persistence',
  '@/lib/bchat/storage/bchat-encrypted-history': 'mock://bchat-encrypted-history',
  '@/lib/echo': 'mock://echo',
};

registerHooks({
  resolve(specifier, context, nextResolve) {
    if (ALIAS_MOCKS[specifier]) {
      return { url: ALIAS_MOCKS[specifier], shortCircuit: true };
    }
    if (specifier.startsWith('@/')) {
      const rel = specifier.slice(2);
      const candidates = [
        path.join(SRC_ROOT, rel + '.ts'),
        path.join(SRC_ROOT, rel + '.tsx'),
        path.join(SRC_ROOT, rel, 'index.ts'),
      ];
      for (const c of candidates) {
        if (fs.existsSync(c)) {
          return { url: pathToFileURL(c).href, shortCircuit: true };
        }
      }
    }
    return nextResolve(specifier, context);
  },
  load(url, context, nextLoad) {
    if (MOCK_MODULES[url]) {
      return { format: 'module', source: MOCK_MODULES[url], shortCircuit: true };
    }
    if (url.startsWith('file://') && (url.endsWith('.ts') || url.endsWith('.tsx'))) {
      const filePath = fileURLToPath(url);
      const raw = fs.readFileSync(filePath, 'utf8');
      const transpiled = ts.transpileModule(raw, {
        compilerOptions: {
          module: ts.ModuleKind.ESNext,
          target: ts.ScriptTarget.ES2022,
          jsx: ts.JsxEmit.ReactJSX,
        },
        fileName: filePath,
      });
      return { format: 'module', source: transpiled.outputText, shortCircuit: true };
    }
    return nextLoad(url, context);
  },
});

// Impor LANGSUNG modul produksi aktual
const coordinator = await import('@/lib/bchat/message/bchat-message-coordinator');
const listener = await import('@/lib/bchat/message/bchat-realtime-listener');

const {
  reconcileInboundMessages,
  computeContiguousResolvedSequence,
  isHistoryTombstonePlaintext,
} = coordinator;
const { BchatRealtimeSubscriber } = listener;

// ---------------------------------------------------------------------------
// 2. Helper Fixture Pembuat Pesan & Mock Worker MLS Deterministik
// ---------------------------------------------------------------------------

const ROOM_UUID = '7b1288fd-fe03-4a88-87fe-980c5741df18';
const D3_UUID = '221e5c67-08fa-459c-b1fe-f90608650222';
const DOSEN_ID = 5;
const MHS_ID = 4;
const FENCING = { ownerToken: 'lease_test', generation: '1' };

function makeServerMessage(seq, senderId, plaintextPayload, opts = {}) {
  const wireBytes = Buffer.from(opts.rawWire ?? `MLS_WIRE:${seq}:${plaintextPayload}`, 'utf8');
  const hashHex = opts.tamperHash ?? crypto.createHash('sha256').update(wireBytes).digest('hex');
  return {
    id: seq,
    message_uuid: opts.messageUuid ?? crypto.randomUUID(),
    room_uuid: ROOM_UUID,
    sender_id: senderId,
    epoch: 1,
    generation: opts.generation ?? 0,
    content_type: 1,
    server_sequence: seq,
    ciphertext_payload: opts.corruptBase64 ?? wireBytes.toString('base64'),
    payload_hash_sha256: hashHex,
    client_sent_at: '2026-11-18T03:34:00+00:00',
    created_at: '2026-11-18T03:34:00+00:00',
  };
}

function makeMockWorker({ failSequences = new Set(), transientFailOnce = new Set() } = {}) {
  const decryptCalls = [];
  const serverSequenceArgs = [];
  const transientTriggered = new Set();

  return {
    decryptCalls,
    serverSequenceArgs,
    async sendRequest(action, payload) {
      if (action === 'DECRYPT_MESSAGE') {
        const decoded = Buffer.from(payload.ciphertextWireBase64, 'base64').toString('utf8');
        const match = decoded.match(/^MLS_WIRE:(\d+):(.*)$/);
        const seq = match ? Number(match[1]) : -1;
        const text = match ? match[2] : decoded;

        decryptCalls.push(seq);
        serverSequenceArgs.push({ seq, serverSequenceParam: payload.serverSequence });

        if (transientFailOnce.has(seq) && !transientTriggered.has(seq)) {
          transientTriggered.add(seq);
          throw new Error('IndexedDB transaction busy timeout');
        }

        if (failSequences.has(seq)) {
          throw new Error('OpenMLS error: Desired gen in the past (Forward Secrecy RFC 9420)');
        }

        // Simulasikan perilaku Worker nyata: hanya update persistentCursor jika serverSequence dikirim!
        if (payload.serverSequence !== undefined) {
          if (payload.serverSequence > harness.persistentCursor) {
            harness.persistentCursor = payload.serverSequence;
          }
          harness.cursorUpdates.push(payload.serverSequence);
        }

        return { plaintext: text, epoch: '1', senderLeafIndex: 0, stateRevision: String(decryptCalls.length) };
      }
      if (action === 'RESTORE_GROUP_STATE') {
        return { status: 'FOUND' };
      }
      throw new Error(`Unexpected worker action: ${action}`);
    },
  };
}

function seedBaselineHistory(seqs = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]) {
  for (const s of seqs) {
    const key = `${D3_UUID}:${ROOM_UUID}:${s}`;
    harness.historyStore.set(key, {
      server_sequence: s,
      message_uuid: crypto.randomUUID(),
      sender_id: s % 2 === 0 ? DOSEN_ID : MHS_ID,
      epoch: 1,
      generation: 0,
      content_type: 1,
      is_self: s % 2 === 0,
      plaintext: `Riwayat Gen 1 pesan #${s}`,
      client_sent_at: '2026-11-14T10:00:00+00:00',
      created_at: '2026-11-14T10:00:00+00:00',
    });
  }
  harness.persistentCursor = 10;
}

// ---------------------------------------------------------------------------
// 3. Pengujian 8 Skenario Wajib Blocker 1
// ---------------------------------------------------------------------------

test('1 & 2. Seq #11 gagal decrypt (MLS_CRYPTO_ERROR) tidak menghentikan Seq #12 (self) dan Seq #13 (peer), cursor kontigu tetap 10', async () => {
  harness.reset();
  seedBaselineHistory();

  const msg11 = makeServerMessage(11, MHS_ID, 'Pesan 11 gen 0 sudah terpakai');
  const msg12 = makeServerMessage(12, DOSEN_ID, 'Pesan 12 dari Dosen sendiri');
  const msg13 = makeServerMessage(13, MHS_ID, 'Pesan 13 real-time dari Mahasiswa');
  harness.serverMessages = [msg11, msg12, msg13];

  const worker = makeMockWorker({ failSequences: new Set([11]) });
  const receivedByUi = [];
  const recoveryEvents = [];

  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: DOSEN_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: 10,
    onMessageDecrypted: (m) => receivedByUi.push(m),
    onRecoveryStateChange: (s) => recoveryEvents.push(s),
  });

  await sub.initializeFromPersistence();
  sub.subscribe();
  await sub.reconcileFromRest();

  // Seq #11 gagal, tercatat eksplisit di unresolvedSequences & isRecoveryRequired = true
  assert.equal(sub.getIsRecoveryRequired(), true);
  assert.equal(sub.getLastFailedSequence(), 11);
  assert.deepEqual(
    sub.getUnresolvedSequences().map((u) => u.server_sequence),
    [11]
  );

  // Seq #12 (self) dan Seq #13 (peer) TETAP diproses & tampil di UI, bukan hilang diam-diam!
  const uiSeqs = receivedByUi.map((m) => m.server_sequence);
  assert.ok(uiSeqs.includes(12), 'Seq #12 (self) harus diproses');
  assert.ok(uiSeqs.includes(13), 'Seq #13 (peer) harus diproses');
  assert.equal(
    receivedByUi.find((m) => m.server_sequence === 13)?.plaintext,
    'Pesan 13 real-time dari Mahasiswa'
  );

  // Cursor kontigu & persistentCursor TETAP di 10 — TIDAK melompati #11!
  assert.equal(sub.getContiguousSequence(), 10);
  assert.equal(sub.getLatestSequence(), 10);
  assert.equal(harness.persistentCursor, 10);

  // Worker dipanggil untuk #13 dengan serverSequence = undefined agar GroupState cursor tidak lompat!
  const arg13 = worker.serverSequenceArgs.find((a) => a.seq === 13);
  assert.equal(arg13?.serverSequenceParam, undefined);

  // Sequence gap terdeteksi secara eksplisit: [11]
  assert.deepEqual(sub.getSequenceGaps(), [11]);
  assert.ok(recoveryEvents.some((e) => e.isRecoveryRequired && e.failedSequence === 11));
});

test('3. WebSocket menerima pesan baru (Seq #13 & #15) saat subscriber sedang recovery -> pesan diproses real-time tanpa hilang', async () => {
  harness.reset();
  seedBaselineHistory();

  // Awal: hanya #11 (gagal) dan #12 (self) di server
  const msg11 = makeServerMessage(11, MHS_ID, 'Pesan 11 gagal');
  const msg12 = makeServerMessage(12, DOSEN_ID, 'Pesan 12 self');
  harness.serverMessages = [msg11, msg12];

  const worker = makeMockWorker({ failSequences: new Set([11]) });
  const uiMessages = new Map();

  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: DOSEN_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: 10,
    onMessageDecrypted: (m) => uiMessages.set(m.server_sequence, m),
  });

  sub.subscribe();
  await sub.reconcileFromRest();

  assert.equal(sub.getIsRecoveryRequired(), true);
  assert.equal(sub.getContiguousSequence(), 10);

  // Sekarang Mahasiswa mengirim Seq #13 dan Reverb mengirim WS signal saat subscriber sedang recovery!
  const msg13 = makeServerMessage(13, MHS_ID, 'Halo Pak Dosen via WS saat recovery');
  harness.serverMessages.push(msg13);

  harness.emitWsEvent('.message.sent', {
    room_uuid: ROOM_UUID,
    server_sequence: 13,
    message_uuid: msg13.message_uuid,
    sender_id: MHS_ID,
    created_at: msg13.created_at,
  });

  // Tunggu async reconcileFromRest selesai
  await new Promise((r) => setTimeout(r, 50));

  assert.equal(uiMessages.get(13)?.plaintext, 'Halo Pak Dosen via WS saat recovery');
  // Cursor kontigu tetap tertahan di 10 karena #11 masih unresolved!
  assert.equal(sub.getContiguousSequence(), 10);
  assert.equal(harness.persistentCursor, 10);
  // Worker TIDAK memanggil dekripsi ulang pada #11 ketika #13 tiba!
  assert.deepEqual(worker.decryptCalls, [11, 13]);
});

test('4. Outbound message (Seq #14) TIDAK melompati inbound sequence yang belum terselesaikan (#11, #12, #13)', async () => {
  harness.reset();
  seedBaselineHistory();

  const msg11 = makeServerMessage(11, MHS_ID, 'Pesan 11 gagal');
  harness.serverMessages = [msg11];

  const worker = makeMockWorker({ failSequences: new Set([11]) });
  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: DOSEN_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: 10,
  });

  sub.subscribe();
  await sub.reconcileFromRest();

  assert.equal(sub.getContiguousSequence(), 10);
  assert.equal(harness.persistentCursor, 10);

  // Dosen mengirim pesan outbound Seq #14 saat #11 gagal dan #12, #13 belum diterima
  sub.markSequenceProcessed(14);

  // Echo WS dari pesan sendiri Seq #14 juga masuk
  harness.emitWsEvent('.message.sent', {
    room_uuid: ROOM_UUID,
    server_sequence: 14,
    message_uuid: crypto.randomUUID(),
    sender_id: DOSEN_ID,
    created_at: '2026-11-18T03:35:00+00:00',
  });
  await new Promise((r) => setTimeout(r, 50));

  // Cursor kontigu & persistent cursor WAJIB tetap 10 — TIDAK BOLEH meloncat ke 14!
  assert.equal(sub.getContiguousSequence(), 10);
  assert.equal(sub.getLatestSequence(), 10);
  assert.equal(harness.persistentCursor, 10);
  // Gap terdeteksi eksplisit: [11, 12, 13]
  assert.deepEqual(sub.getSequenceGaps(), [11, 12, 13]);
  // Namun #14 tetap tercatat di processedSequences agar tidak didekripsi/diproses ulang
  assert.ok(sub.getProcessedSequences().includes(14));
});

test('5. Reconciliation berulang tidak menghasilkan pesan duplikat dan tidak mengulang dekripsi pesan yang sudah diproses', async () => {
  harness.reset();
  seedBaselineHistory();

  const msg11 = makeServerMessage(11, MHS_ID, 'Pesan 11 gagal');
  const msg12 = makeServerMessage(12, DOSEN_ID, 'Pesan 12 self');
  const msg13 = makeServerMessage(13, MHS_ID, 'Pesan 13 peer valid');
  harness.serverMessages = [msg11, msg12, msg13];

  const worker = makeMockWorker({ failSequences: new Set([11]) });
  const uiList = [];
  const upsertUi = (msg) => {
    const idx = uiList.findIndex((m) => m.server_sequence === msg.server_sequence);
    if (idx === -1) uiList.push(msg);
  };

  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: DOSEN_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: 10,
    onMessageDecrypted: upsertUi,
  });

  sub.subscribe();
  const c1 = await sub.reconcileFromRest();
  const c2 = await sub.reconcileFromRest();
  const c3 = await sub.reconcileFromRest();

  assert.equal(c1, 2); // #12 dan #13
  assert.equal(c2, 0); // tidak ada re-process
  assert.equal(c3, 0); // tidak ada re-process
  assert.deepEqual(worker.decryptCalls, [11, 13]); // masing-masing tepat 1x!
  assert.equal(uiList.filter((m) => m.server_sequence === 13).length, 1);
  assert.equal(uiList.filter((m) => m.server_sequence === 12).length, 1);
});

test('6. Cursor tetap konsisten setelah reload (termasuk memulihkan celah meski persistentCursor lama sempat melompat ke 14)', async () => {
  harness.reset();
  seedBaselineHistory();

  // Simulasikan kondisi tepat seperti Device 3 pasca bug lama sebelum perbaikan:
  // IDB punya: 1..10 (valid), 11 ([Dekripsi Gagal] tombstone), 14 (outbound valid),
  // sementara #12 & #13 HILANG dan persistentCursor lama sempat tertulis 14!
  harness.historyStore.set(`${D3_UUID}:${ROOM_UUID}:11`, {
    server_sequence: 11,
    message_uuid: crypto.randomUUID(),
    sender_id: MHS_ID,
    epoch: 1,
    generation: 0,
    content_type: 1,
    is_self: false,
    plaintext: '[Dekripsi Gagal]',
    client_sent_at: null,
    created_at: null,
  });
  harness.historyStore.set(`${D3_UUID}:${ROOM_UUID}:14`, {
    server_sequence: 14,
    message_uuid: crypto.randomUUID(),
    sender_id: DOSEN_ID,
    epoch: 1,
    generation: 0,
    content_type: 1,
    is_self: true,
    plaintext: 'Pesan 14 outbound Dosen',
    client_sent_at: null,
    created_at: null,
  });
  harness.persistentCursor = 14; // kondisi tercemar dari bug lama

  const msg11 = makeServerMessage(11, MHS_ID, 'Pesan 11 gagal');
  const msg12 = makeServerMessage(12, DOSEN_ID, 'Pesan 12 self yang sempat hilang');
  const msg13 = makeServerMessage(13, MHS_ID, 'Pesan 13 peer yang sempat hilang');
  const msg14 = makeServerMessage(14, DOSEN_ID, 'Pesan 14 outbound Dosen');
  harness.serverMessages = [msg11, msg12, msg13, msg14];

  // Rekonstruksi reload persis seperti BchatProposalChat.tsx
  const loadedHistory = Array.from(harness.historyStore.values()).sort(
    (a, b) => a.server_sequence - b.server_sequence
  );
  const validSeqs = loadedHistory
    .filter((i) => !isHistoryTombstonePlaintext(i.plaintext))
    .map((i) => i.server_sequence);
  const tombstones = loadedHistory
    .filter((i) => isHistoryTombstonePlaintext(i.plaintext))
    .map((i) => ({
      server_sequence: i.server_sequence,
      message_uuid: i.message_uuid,
      errorCategory: 'MLS_CRYPTO_ERROR',
      error: 'Tombstone dari sesi sebelumnya',
      requiresRecovery: true,
    }));
  const startingCursor = computeContiguousResolvedSequence(validSeqs, 0);
  assert.equal(startingCursor, 10, 'Contiguous cursor dari riwayat lokal wajib 10 (bukan 14)');

  const worker = makeMockWorker({ failSequences: new Set([11]) });
  const recoveredInUi = [];

  const subAfterReload = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: DOSEN_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: startingCursor,
    knownProcessedSequences: validSeqs,
    knownUnresolvedSequences: tombstones,
    onMessageDecrypted: (m) => recoveredInUi.push(m),
  });

  // initializeFromPersistence() TIDAK boleh memajukan cursor ke 14 karena ada tombstone #11 & celah #12-#13!
  const initCursor = await subAfterReload.initializeFromPersistence();
  assert.equal(initCursor, 10);

  subAfterReload.subscribe();
  await subAfterReload.reconcileFromRest();

  // #12 dan #13 yang sempat hilang langsung direkonsiliasi dari REST (after_sequence=10)!
  assert.equal(harness.fetchCalls[0].after_sequence, 10);
  assert.ok(recoveredInUi.some((m) => m.server_sequence === 12));
  assert.ok(
    recoveredInUi.some(
      (m) => m.server_sequence === 13 && m.plaintext === 'Pesan 13 peer yang sempat hilang'
    )
  );
  // #14 tidak diduplikasi / tidak diproses ulang
  assert.ok(!recoveredInUi.some((m) => m.server_sequence === 14));
  // #11 tetap tidak diklaim sukses (tombstone tidak pernah dihitung valid!)
  assert.equal(subAfterReload.getContiguousSequence(), 10);
  assert.equal(subAfterReload.getIsRecoveryRequired(), true);
});

test('7. Kondisi recovery pulih penuh saat mekanisme pemulihan valid tersedia (History Transfer / retryRecovery) dan cursor maju atomik ke 14', async () => {
  harness.reset();
  seedBaselineHistory();

  const msg11 = makeServerMessage(11, MHS_ID, 'Pesan 11 asli yang dipulihkan');
  const msg12 = makeServerMessage(12, DOSEN_ID, 'Pesan 12 self');
  const msg13 = makeServerMessage(13, MHS_ID, 'Pesan 13 peer');
  const msg14 = makeServerMessage(14, DOSEN_ID, 'Pesan 14 outbound');
  harness.serverMessages = [msg11, msg12, msg13, msg14];

  const worker = makeMockWorker({ failSequences: new Set([11]) });
  const uiMap = new Map();

  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: DOSEN_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: 10,
    onMessageDecrypted: (m) => uiMap.set(m.server_sequence, m),
  });

  sub.subscribe();
  await sub.reconcileFromRest();

  assert.equal(sub.getIsRecoveryRequired(), true);
  assert.equal(sub.getContiguousSequence(), 10);
  assert.equal(harness.persistentCursor, 10);

  // Simulasikan pemulihan sah melalui History Transfer: plaintext valid Seq #11 ditulis ke message_history lokal
  harness.historyStore.set(`${D3_UUID}:${ROOM_UUID}:11`, {
    server_sequence: 11,
    message_uuid: msg11.message_uuid,
    sender_id: MHS_ID,
    epoch: 1,
    generation: 0,
    content_type: 1,
    is_self: false,
    plaintext: 'Pesan 11 asli yang dipulihkan',
    client_sent_at: msg11.client_sent_at,
    created_at: msg11.created_at,
  });

  // Panggil retryRecovery() (tanpa memaksakan decrypt ratchet ilegal!)
  await sub.retryRecovery();

  assert.equal(sub.getIsRecoveryRequired(), false);
  assert.deepEqual(sub.getUnresolvedSequences(), []);
  assert.deepEqual(sub.getSequenceGaps(), []);
  assert.equal(sub.getContiguousSequence(), 14);
  assert.equal(harness.persistentCursor, 14);
  assert.equal(uiMap.get(11)?.plaintext, 'Pesan 11 asli yang dipulihkan');
});

test('8. Regression: Alur normal, integrity error (SHA-256 mismatch), dan transient error fail-closed tetap bekerja benar', async () => {
  // 8A. Alur normal berurutan tanpa error
  harness.reset();
  seedBaselineHistory();
  const m11 = makeServerMessage(11, MHS_ID, 'Normal 11');
  const m12 = makeServerMessage(12, MHS_ID, 'Normal 12');
  harness.serverMessages = [m11, m12];
  const wNormal = makeMockWorker();

  const resNormal = await reconcileInboundMessages({
    roomUuid: ROOM_UUID,
    deviceUuid: D3_UUID,
    currentUserId: DOSEN_ID,
    workerRpc: wNormal,
    fencing: FENCING,
    afterSequence: 10,
  });
  assert.equal(resNormal.hasErrors, false);
  assert.equal(resNormal.contiguousSequence, 12);
  assert.equal(harness.persistentCursor, 12);

  // 8B. Transient error berhenti fail-closed tanpa melompati urutan
  harness.reset();
  seedBaselineHistory();
  const t11 = makeServerMessage(11, MHS_ID, 'Transient 11');
  const t12 = makeServerMessage(12, MHS_ID, 'After transient 12');
  harness.serverMessages = [t11, t12];
  // Gagal 3x (lebih dari maxRetries) -> stop batch fail-closed
  const alwaysTransientWorker = {
    async sendRequest(action) {
      if (action === 'DECRYPT_MESSAGE') throw new Error('IndexedDB transaction locked temporarily');
      return { status: 'FOUND' };
    },
  };
  const resTrans = await reconcileInboundMessages({
    roomUuid: ROOM_UUID,
    deviceUuid: D3_UUID,
    currentUserId: DOSEN_ID,
    workerRpc: alwaysTransientWorker,
    fencing: FENCING,
    afterSequence: 10,
  });
  assert.equal(resTrans.hasErrors, true);
  assert.equal(resTrans.errorCategory, 'TRANSIENT_ERROR');
  assert.equal(resTrans.failedSequence, 11);
  assert.equal(resTrans.contiguousSequence, 10);
  assert.equal(harness.persistentCursor, 10);
  assert.equal(resTrans.processedItems.length, 1); // berhenti di #11, tidak melompat saat transient!

  // 8C. Integrity mismatch pada #11 tetap ditolak (tidak diklaim sukses) & tidak memajukan cursor,
  // sementara #12 yang sah tetap diproses
  harness.reset();
  seedBaselineHistory();
  const bad11 = makeServerMessage(11, MHS_ID, 'Tampered', { tamperHash: 'f'.repeat(64) });
  const ok12 = makeServerMessage(12, MHS_ID, 'Valid 12');
  harness.serverMessages = [bad11, ok12];
  const wInt = makeMockWorker();
  const resInt = await reconcileInboundMessages({
    roomUuid: ROOM_UUID,
    deviceUuid: D3_UUID,
    currentUserId: DOSEN_ID,
    workerRpc: wInt,
    fencing: FENCING,
    afterSequence: 10,
  });
  assert.equal(resInt.hasErrors, true);
  assert.equal(resInt.errorCategory, 'PAYLOAD_INTEGRITY_ERROR');
  assert.equal(resInt.contiguousSequence, 10);
  assert.equal(harness.persistentCursor, 10);
  assert.equal(resInt.processedItems.find((i) => i.server_sequence === 11)?.plaintext, '[Integritas Gagal]');
  assert.equal(resInt.processedItems.find((i) => i.server_sequence === 12)?.plaintext, 'Valid 12');
});

