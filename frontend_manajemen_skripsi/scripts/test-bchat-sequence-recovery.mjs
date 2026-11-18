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
  sentPayloads: [],
  ignoreSessionFilterInMock: false,
  fetchDelayMs: 0,
  historyStore: new Map(), // key: `${device}:${room}:${seq}` -> item
  persistentCursor: 0,
  cursorUpdates: [],
  echoChannelListeners: new Map(), // event -> fn[]
  echoSubscribedCallbacks: [],
  echoErrorCallbacks: [],
  pusherConnectionListeners: new Map(), // event -> Set<fn>
  reset() {
    this.serverMessages = [];
    this.fetchCalls = [];
    this.sentPayloads = [];
    this.ignoreSessionFilterInMock = false;
    this.fetchDelayMs = 0;
    this.historyStore.clear();
    this.persistentCursor = 0;
    this.cursorUpdates = [];
    this.echoChannelListeners.clear();
    this.echoSubscribedCallbacks = [];
    this.echoErrorCallbacks = [];
    this.pusherConnectionListeners.clear();
  },
  emitWsEvent(eventName, payload) {
    const fns = this.echoChannelListeners.get(eventName) || [];
    for (const fn of fns) fn(payload);
  },
  emitSubscribed() {
    for (const fn of this.echoSubscribedCallbacks) fn();
  },
  emitChannelError(err) {
    for (const fn of this.echoErrorCallbacks) fn(err);
  },
  emitConnectionStateChange(previous, current) {
    const set = this.pusherConnectionListeners.get('state_change');
    if (!set) return;
    for (const fn of Array.from(set)) fn({ previous, current });
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
      const sessionUuid = query.session_uuid;
      h.fetchCalls.push({ roomUuid, after_sequence: after, limit, session_uuid: sessionUuid });
      if (h.fetchDelayMs > 0) {
        await new Promise((r) => setTimeout(r, h.fetchDelayMs));
      }
      const filtered = h.serverMessages
        .filter((m) => m.server_sequence > after)
        .filter((m) => {
          if (h.ignoreSessionFilterInMock || !sessionUuid) return true;
          return !m.session_uuid || m.session_uuid.toLowerCase() === sessionUuid.toLowerCase();
        })
        .sort((a, b) => a.server_sequence - b.server_sequence)
        .slice(0, limit);
      return { status: 'success', data: filtered };
    }
    export async function sendMessage(roomUuid, payload) {
      const h = globalThis.__BCHAT_SEQ_RECOVERY_HARNESS__;
      h.sentPayloads.push({ roomUuid, payload });
      const maxSeq = h.serverMessages.reduce((m, item) => Math.max(m, item.server_sequence), 0);
      const nextSeq = Math.max(maxSeq + 1, h.persistentCursor + 1);
      const created = {
        server_sequence: nextSeq,
        message_uuid: payload.message_uuid,
        sender_id: payload._senderId ?? 4,
        epoch: payload.epoch,
        generation: payload.generation,
        content_type: payload.content_type,
        ciphertext_payload: payload.ciphertext_payload,
        payload_hash_sha256: payload.payload_hash_sha256,
        session_uuid: payload._sessionUuid ?? null,
        client_sent_at: payload.client_sent_at,
        created_at: new Date().toISOString(),
      };
      h.serverMessages.push(created);
      return { status: 'success', message: 'Pesan E2EE berhasil dikirim dan disimpan.', data: created };
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
        connector: {
          pusher: {
            connection: {
              bind(evt, cb) {
                const set = h.pusherConnectionListeners.get(evt) || new Set();
                set.add(cb);
                h.pusherConnectionListeners.set(evt, set);
              },
              unbind(evt, cb) {
                const set = h.pusherConnectionListeners.get(evt);
                if (set) set.delete(cb);
              },
            },
          },
        },
        private() {
          const ch = {
            subscription: { subscribed: false },
            subscribed(cb) {
              h.echoSubscribedCallbacks.push(() => {
                ch.subscription.subscribed = true;
                cb();
              });
              return ch;
            },
            error(cb) {
              h.echoErrorCallbacks.push(cb);
              return ch;
            },
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
  computeSessionFloor,
  resolveActiveSessionCursor,
  classifyInboundError,
  sendApplicationMessageFlow,
  isHistoryTombstonePlaintext,
} = coordinator;
const {
  BchatRealtimeSubscriber,
  mapSubscriptionStateToConnectionStatus,
  shouldRunMessageSyncFallback,
  MESSAGE_SYNC_FALLBACK_INTERVAL_MS,
} = listener;

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

// ---------------------------------------------------------------------------
// 4. Pengujian Regresi Perbaikan Sinkronisasi Pesan B-CHAT (Gen 3 Multi-Generasi)
// ---------------------------------------------------------------------------

const GEN1_SESSION_UUID = '11111111-1111-4111-8111-111111111111';
const GEN2_SESSION_UUID = '22222222-2222-4222-8222-222222222222';
const GEN3_SESSION_UUID = '573b150e-bdb3-48dd-ab32-e01dda5d0332';

test('9. Gen 3 (start_sequence = 18): sessionFloor = 17, riwayat lokal Seq #18+ tidak reset cursor ke 0, Gen 1 & Gen 2 bukan gap', async () => {
  harness.reset();

  // 9A. sessionFloor untuk Gen 3 (start_sequence = 18) wajib 17
  assert.equal(computeSessionFloor(18), 17);
  assert.equal(computeSessionFloor(1), 0);
  assert.equal(computeSessionFloor(null), 0);

  // 9B. Mount pertama Gen 3 (riwayat lokal kosong, persistentCursor = 0) -> startingCursor = 17
  const firstMount = resolveActiveSessionCursor({
    activeSessionStartSequence: 18,
    persistentCursor: 0,
    validHistorySequences: [],
    unresolvedSequences: [],
  });
  assert.equal(firstMount.sessionFloor, 17);
  assert.equal(firstMount.hasGapBelowPersistent, false);
  assert.equal(firstMount.startingCursor, 17);

  // 9C. Pasca reload: riwayat lokal hanya berisi Seq #18 & #19 (tanpa Gen 1 #1..#10 & Gen 2 #11..#17),
  // persistentCursor = 19 -> cursor TIDAK BOLEH jatuh ke 0 dan Gen 1/2 tidak dianggap gap!
  const afterReloadGen3 = resolveActiveSessionCursor({
    activeSessionStartSequence: 18,
    persistentCursor: 19,
    validHistorySequences: [18, 19],
    unresolvedSequences: [1, 4, 11], // tombstone lama di sesi ARCHIVED tidak boleh memblokir Gen 3
  });
  assert.equal(afterReloadGen3.sessionFloor, 17);
  assert.equal(afterReloadGen3.contiguousHistorySeq, 19);
  assert.equal(afterReloadGen3.hasGapBelowPersistent, false);
  assert.equal(afterReloadGen3.startingCursor, 19);
  assert.deepEqual(afterReloadGen3.activeSessionUnresolvedSequences, []);

  // 9D. Jika benar-benar ada gap di dalam rentang Gen 3 (mis. Seq #18 & #20, tanpa #19, persistentCursor = 20):
  // cursor mundur hanya sampai #18 (batas sebelum gap #19), TIDAK pernah mundur ke 0!
  const realGen3Gap = resolveActiveSessionCursor({
    activeSessionStartSequence: 18,
    persistentCursor: 20,
    validHistorySequences: [18, 20],
    unresolvedSequences: [],
  });
  assert.equal(realGen3Gap.sessionFloor, 17);
  assert.equal(realGen3Gap.contiguousHistorySeq, 18);
  assert.equal(realGen3Gap.hasGapBelowPersistent, true);
  assert.equal(realGen3Gap.startingCursor, 18);

  // 9E. BchatRealtimeSubscriber mempertahankan contiguousSequence >= 19 saat initializeFromPersistence()
  harness.persistentCursor = 19;
  const worker = makeMockWorker();
  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: MHS_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: afterReloadGen3.startingCursor,
    sessionFloor: afterReloadGen3.sessionFloor,
    sessionUuid: GEN3_SESSION_UUID,
    knownProcessedSequences: [18, 19],
    knownUnresolvedSequences: [
      {
        server_sequence: 4,
        errorCategory: 'MLS_CRYPTO_ERROR',
        error: 'Tombstone Gen 1 lama',
        requiresRecovery: true,
      },
    ],
  });

  const initCursor = await sub.initializeFromPersistence();
  assert.equal(initCursor, 19);
  assert.equal(sub.getContiguousSequence(), 19);
  assert.deepEqual(sub.getSequenceGaps(), []);
  assert.equal(sub.getIsRecoveryRequired(), false);
});

test('10. Request REST membawa session_uuid sesi aktif & pesan sesi ARCHIVED tidak masuk dekripsi GroupState Gen 3', async () => {
  harness.reset();

  // Simulasikan database memiliki Gen 1 (#1, #4), Gen 2 (#11, #17), dan Gen 3 (#18, #19, #20)
  const msg1Gen1 = { ...makeServerMessage(1, MHS_ID, 'Gen 1 #1'), session_uuid: GEN1_SESSION_UUID };
  const msg4Gen1 = { ...makeServerMessage(4, DOSEN_ID, 'Gen 1 #4'), session_uuid: GEN1_SESSION_UUID };
  const msg17Gen2 = { ...makeServerMessage(17, MHS_ID, 'Gen 2 #17'), session_uuid: GEN2_SESSION_UUID };
  const msg18Gen3 = { ...makeServerMessage(18, DOSEN_ID, 'Gen 3 #18'), session_uuid: GEN3_SESSION_UUID };
  const msg19Gen3 = { ...makeServerMessage(19, DOSEN_ID, 'Gen 3 #19'), session_uuid: GEN3_SESSION_UUID };
  const msg20Gen3 = { ...makeServerMessage(20, MHS_ID, 'Gen 3 #20'), session_uuid: GEN3_SESSION_UUID };

  harness.serverMessages = [msg1Gen1, msg4Gen1, msg17Gen2, msg18Gen3, msg19Gen3, msg20Gen3];
  // Riwayat lokal sudah punya #18 (Gen 3) dan riwayat lama #1 tetap utuh di IndexedDB lokal
  harness.historyStore.set(`${D3_UUID}:${ROOM_UUID}:1`, {
    server_sequence: 1,
    message_uuid: msg1Gen1.message_uuid,
    sender_id: MHS_ID,
    epoch: 1,
    generation: 0,
    content_type: 1,
    is_self: true,
    plaintext: 'Riwayat lama Gen 1 tetap utuh',
  });
  harness.historyStore.set(`${D3_UUID}:${ROOM_UUID}:18`, {
    server_sequence: 18,
    message_uuid: msg18Gen3.message_uuid,
    sender_id: DOSEN_ID,
    epoch: 1,
    generation: 0,
    content_type: 1,
    is_self: false,
    plaintext: 'Gen 3 #18',
  });
  harness.persistentCursor = 18;

  // 10A. Mode normal: server memfilter berdasarkan session_uuid aktif
  const workerA = makeMockWorker({ failSequences: new Set([1, 4, 17]) });
  const receivedA = [];
  const subA = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: MHS_ID,
    deviceUuid: D3_UUID,
    workerRpc: workerA,
    fencing: FENCING,
    initialSequence: 18,
    sessionFloor: 17,
    sessionUuid: GEN3_SESSION_UUID,
    knownProcessedSequences: [18],
    onMessageDecrypted: (m) => receivedA.push(m),
  });

  await subA.initializeFromPersistence();
  subA.subscribe();
  await subA.reconcileFromRest();

  assert.equal(harness.fetchCalls.length, 1);
  assert.equal(harness.fetchCalls[0].after_sequence, 18);
  assert.equal(harness.fetchCalls[0].session_uuid, GEN3_SESSION_UUID);
  assert.deepEqual(workerA.decryptCalls, [19]); // Hanya peer Gen 3 (#19); #20 self, #1/#4/#17 tidak tersentuh
  assert.deepEqual(
    receivedA.map((m) => m.server_sequence),
    [19, 20]
  );
  assert.equal(subA.getContiguousSequence(), 20);
  // Riwayat lama di IndexedDB tidak dihapus
  assert.ok(harness.historyStore.has(`${D3_UUID}:${ROOM_UUID}:1`));

  // 10B. Defense-in-depth: bahkan bila respons mock/server menyelipkan pesan ARCHIVED (#1, #4, #17, atau session_uuid beda),
  // reconcileInboundMessages WAJIB tidak pernah mengirimnya ke DECRYPT_MESSAGE!
  harness.ignoreSessionFilterInMock = true;
  const leakedArchivedMsg = {
    ...makeServerMessage(21, DOSEN_ID, 'Archived leaked #21'),
    session_uuid: GEN2_SESSION_UUID,
  };
  const validGen3Msg22 = {
    ...makeServerMessage(22, DOSEN_ID, 'Gen 3 valid #22'),
    session_uuid: GEN3_SESSION_UUID,
  };
  harness.serverMessages.push(leakedArchivedMsg, validGen3Msg22);

  const workerB = makeMockWorker();
  const resDefense = await reconcileInboundMessages({
    roomUuid: ROOM_UUID,
    deviceUuid: D3_UUID,
    currentUserId: MHS_ID,
    workerRpc: workerB,
    fencing: FENCING,
    afterSequence: 0, // sengaja 0 untuk menguji sessionFloor & sessionUuid guard
    sessionFloor: 17,
    sessionUuid: GEN3_SESSION_UUID,
    processedSequences: new Set([18, 19, 20]),
  });

  assert.equal(resDefense.hasErrors, false);
  assert.deepEqual(workerB.decryptCalls, [22]); // #1, #4, #17, dan #21 (GEN2) 100% diblokir dari DECRYPT_MESSAGE!
  assert.equal(resDefense.processedItems.find((m) => m.server_sequence === 22)?.plaintext, 'Gen 3 valid #22');
});

test('11. classifyInboundError: OperationError/AEAD/MLS dari DECRYPT_MESSAGE -> MLS_CRYPTO_ERROR; Timeout/AbortError/IDB lock/I/O -> TRANSIENT_ERROR', () => {
  // WebCrypto AEAD OperationError (raw DOMException dan format RPC Worker) -> MLS_CRYPTO_ERROR
  const domOpErr = new DOMException('The operation failed for an operation-specific reason', 'OperationError');
  assert.equal(classifyInboundError(domOpErr, { source: 'DECRYPT_MESSAGE' }), 'MLS_CRYPTO_ERROR');
  assert.equal(
    classifyInboundError(
      new Error('[OperationError] The operation failed for an operation-specific reason'),
      { source: 'DECRYPT_MESSAGE' }
    ),
    'MLS_CRYPTO_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('[OperationError] Terjadi kesalahan internal pada Worker.'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'MLS_CRYPTO_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('[CodecError] Could not decode senderdata'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'MLS_CRYPTO_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('AEAD authentication tag mismatch on ciphertext'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'MLS_CRYPTO_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('[CryptoVerificationError] Signature invalid'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'MLS_CRYPTO_ERROR'
  );

  // OperationError dengan context GENERAL atau tanpa context WAJIB tetap TRANSIENT_ERROR
  assert.equal(classifyInboundError(domOpErr, { source: 'GENERAL' }), 'TRANSIENT_ERROR');
  assert.equal(
    classifyInboundError(
      new Error('[OperationError] The operation failed for an operation-specific reason'),
      { source: 'GENERAL' }
    ),
    'TRANSIENT_ERROR'
  );
  assert.equal(classifyInboundError(domOpErr), 'TRANSIENT_ERROR');
  assert.equal(
    classifyInboundError(
      new Error('[OperationError] The operation failed for an operation-specific reason')
    ),
    'TRANSIENT_ERROR'
  );

  // Timeout, AbortError, Network, IndexedDB busy/lock, I/O -> WAJIB tetap TRANSIENT_ERROR
  // termasuk bila pesan error kebetulan memuat kata "decrypt"!
  assert.equal(
    classifyInboundError(
      new Error("RPC TIMEOUT: Permintaan 'DECRYPT_MESSAGE' [requestId: 123] melebihi batas waktu 15000ms."),
      { source: 'DECRYPT_MESSAGE' }
    ),
    'TRANSIENT_ERROR'
  );
  assert.equal(
    classifyInboundError(new DOMException('The operation was aborted.', 'AbortError'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'TRANSIENT_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('IndexedDB transaction locked while decrypting message'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'TRANSIENT_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('Database busy during decrypt persistence'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'TRANSIENT_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('Network error: failed to fetch during decrypt'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'TRANSIENT_ERROR'
  );
  assert.equal(
    classifyInboundError(new Error('Temporary I/O error during decrypt'), {
      source: 'DECRYPT_MESSAGE',
    }),
    'TRANSIENT_ERROR'
  );
});

test('12. Real ts-mls RFC 9420: dekripsi ciphertext Gen 1 dengan GroupState Gen 3 melempar OperationError (MLS_CRYPTO_ERROR) & TIDAK mengadopsi candidate GroupState', async () => {
  harness.reset();

  const { getCiphersuiteImpl } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/crypto/getCiphersuiteImpl.js')).href
  );
  const { createGroup, joinGroup } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/clientState.js')).href
  );
  const { generateKeyPackage } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/keyPackage.js')).href
  );
  const { createCommit } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/createCommit.js')).href
  );
  const { createApplicationMessage } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/createMessage.js')).href
  );
  const { processMessage } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/processMessages.js')).href
  );
  const { mlsMessageEncoder, mlsMessageDecoder } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/message.js')).href
  );
  const { encode } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/codec/tlsEncoder.js')).href
  );
  const { decode } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/codec/tlsDecoder.js')).href
  );
  const { defaultCredentialTypes } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/defaultCredentialType.js')).href
  );
  const { defaultProposalTypes } = await import(
    pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/defaultProposalType.js')).href
  );

  const cs = await getCiphersuiteImpl('MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519');
  const context = { cipherSuite: cs, authService: { validateCredential: async () => true } };
  const groupId = new TextEncoder().encode(ROOM_UUID);

  async function buildPairwiseSession(label) {
    const mhsKp = await generateKeyPackage({
      credential: {
        credentialType: defaultCredentialTypes.basic,
        identity: new TextEncoder().encode(`mhs_${label}`),
      },
      cipherSuite: cs,
    });
    const dosenKp = await generateKeyPackage({
      credential: {
        credentialType: defaultCredentialTypes.basic,
        identity: new TextEncoder().encode(`dosen_${label}`),
      },
      cipherSuite: cs,
    });

    const g0 = await createGroup({
      context,
      groupId,
      keyPackage: mhsKp.publicPackage,
      privateKeyPackage: mhsKp.privatePackage,
    });
    const commitRes = await createCommit({
      context,
      state: g0,
      extraProposals: [{ proposalType: defaultProposalTypes.add, add: { keyPackage: dosenKp.publicPackage } }],
      ratchetTreeExtension: true,
    });
    const dosenState = await joinGroup({
      context,
      welcome: commitRes.welcome.welcome,
      keyPackage: dosenKp.publicPackage,
      privateKeys: dosenKp.privatePackage,
    });
    return { mhsState: commitRes.newState, dosenState };
  }

  const gen1 = await buildPairwiseSession('gen1');
  const gen3 = await buildPairwiseSession('gen3');

  // Enkripsi pesan pada Gen 1 dan Gen 3 (keduanya groupId = ROOM_UUID, epoch = 1)
  const gen1Send = await createApplicationMessage({
    context,
    state: gen1.mhsState,
    message: new TextEncoder().encode('Pesan Gen 1 lama'),
  });
  const gen1Wire = encode(mlsMessageEncoder, gen1Send.message);

  const gen3Send = await createApplicationMessage({
    context,
    state: gen3.mhsState,
    message: new TextEncoder().encode('Pesan Gen 3 aktif valid'),
  });
  const gen3Wire = encode(mlsMessageEncoder, gen3Send.message);

  // Simulasikan Worker nyata dengan state Gen 3 milik Dosen
  let adoptedDosenState = gen3.dosenState;
  const initialDosenStateRef = adoptedDosenState;
  let adoptCount = 0;

  const realMlsWorker = {
    async sendRequest(action, payload) {
      if (action === 'DECRYPT_MESSAGE') {
        const wireBytes = Uint8Array.from(Buffer.from(payload.ciphertextWireBase64, 'base64'));
        const decodedMsg = decode(mlsMessageDecoder, wireBytes);
        try {
          const recv = await processMessage({
            context,
            state: adoptedDosenState,
            message: decodedMsg,
          });
          // Adopsi candidate state HANYA bila processMessage sukses
          adoptedDosenState = recv.newState;
          adoptCount += 1;
          return {
            plaintext: new TextDecoder().decode(recv.message),
            epoch: adoptedDosenState.groupContext.epoch.toString(),
            senderLeafIndex: recv.senderLeafIndex,
            stateRevision: String(adoptCount),
          };
        } catch (err) {
          // Format error identik dengan BchatWorkerClient (`[code] message`)
          throw new Error(`[${err.name || 'WORKER_INTERNAL_ERROR'}] ${err.message || 'Terjadi kesalahan internal pada Worker.'}`);
        }
      }
      return { status: 'FOUND' };
    },
  };

  // Uji langsung: bila pesan Gen 1 dan Gen 3 berada pada satu batch tanpa session filter,
  // dekripsi Gen 1 melempar OperationError -> MLS_CRYPTO_ERROR (bukan TRANSIENT_ERROR),
  // candidate state TIDAK diadopsi, dan Gen 3 tetap berhasil didekripsi!
  const msgGen1 = makeServerMessage(18, MHS_ID, '', { rawWire: Buffer.from(gen1Wire) });
  const msgGen3 = makeServerMessage(19, MHS_ID, '', { rawWire: Buffer.from(gen3Wire) });
  harness.serverMessages = [msgGen1, msgGen3];

  const res = await reconcileInboundMessages({
    roomUuid: ROOM_UUID,
    deviceUuid: D3_UUID,
    currentUserId: DOSEN_ID,
    workerRpc: realMlsWorker,
    fencing: FENCING,
    afterSequence: 17,
    sessionFloor: 17,
  });

  // #18 gagal dengan MLS_CRYPTO_ERROR (OperationError), bukan TRANSIENT_ERROR
  assert.equal(res.errorCategory, 'MLS_CRYPTO_ERROR');
  assert.equal(res.failedSequence, 18);
  // Candidate GroupState tidak diadopsi saat #18 gagal, lalu hanya diadopsi 1x saat #19 sukses!
  assert.equal(adoptCount, 1);
  assert.notEqual(adoptedDosenState, initialDosenStateRef);
  // Pesan #19 (Gen 3 valid) TETAP berhasil didekripsi dengan sempurna!
  assert.equal(
    res.processedItems.find((i) => i.server_sequence === 19)?.plaintext,
    'Pesan Gen 3 aktif valid'
  );
});

test('13. Fallback REST tidak membuat request tumpang tindih, berhenti saat tab hidden/unmount, dan pulih saat visible/online', async () => {
  harness.reset();

  // 13A. Uji pure guard shouldRunMessageSyncFallback untuk seluruh kondisi
  const baseGuard = {
    isRoomActive: true,
    onboardingGate: 'PASSED',
    hasLocalGroupState: true,
    isUnrecoverableActiveRoom: false,
    hasWaitingState: false,
    isDocumentVisible: true,
    isOnline: true,
    hasActiveSubscriber: true,
    isSubscriberUnsubscribed: false,
    isReconciling: false,
    isFallbackInFlight: false,
  };
  assert.ok(MESSAGE_SYNC_FALLBACK_INTERVAL_MS >= 10000 && MESSAGE_SYNC_FALLBACK_INTERVAL_MS <= 15000);
  assert.equal(shouldRunMessageSyncFallback(baseGuard), true);
  assert.equal(shouldRunMessageSyncFallback({ ...baseGuard, isDocumentVisible: false }), false);
  assert.equal(shouldRunMessageSyncFallback({ ...baseGuard, isOnline: false }), false);
  assert.equal(shouldRunMessageSyncFallback({ ...baseGuard, isRoomActive: false }), false);
  assert.equal(shouldRunMessageSyncFallback({ ...baseGuard, isReconciling: true }), false);
  assert.equal(shouldRunMessageSyncFallback({ ...baseGuard, isFallbackInFlight: true }), false);
  assert.equal(shouldRunMessageSyncFallback({ ...baseGuard, isSubscriberUnsubscribed: true }), false);
  assert.equal(shouldRunMessageSyncFallback({ ...baseGuard, hasWaitingState: true }), false);

  // 13B. Uji non-overlapping concurrency saat reconcile sedang berjalan + stop pasca unmount (unsubscribe)
  harness.fetchDelayMs = 40;
  const msg18 = { ...makeServerMessage(18, DOSEN_ID, 'Pesan Gen 3 #18'), session_uuid: GEN3_SESSION_UUID };
  harness.serverMessages = [msg18];
  const worker = makeMockWorker();
  const uiReceived = [];

  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: MHS_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: 17,
    sessionFloor: 17,
    sessionUuid: GEN3_SESSION_UUID,
    onMessageDecrypted: (m) => uiReceived.push(m),
  });
  sub.subscribe();

  // Jalankan reconcile pertama (in-flight selama 40ms)
  const firstRun = sub.reconcileFromRest();
  assert.equal(sub.getIsReconciling(), true);

  // Saat sedang reconciling, fallback tick WAJIB diblokir agar tidak tumpang tindih!
  assert.equal(
    shouldRunMessageSyncFallback({
      ...baseGuard,
      isReconciling: sub.getIsReconciling(),
    }),
    false
  );

  await firstRun;
  assert.equal(sub.getIsReconciling(), false);
  assert.equal(harness.fetchCalls.length, 1);
  assert.equal(uiReceived.length, 1);

  // Simulasikan pesan baru #19 masuk saat WS terlewat -> tab kembali visible / online memicu fallback
  const msg19 = { ...makeServerMessage(19, DOSEN_ID, 'Pesan Gen 3 #19 via fallback'), session_uuid: GEN3_SESSION_UUID };
  harness.serverMessages.push(msg19);
  harness.fetchDelayMs = 0;

  if (shouldRunMessageSyncFallback({ ...baseGuard, isReconciling: sub.getIsReconciling() })) {
    await sub.reconcileFromRest();
  }
  assert.equal(harness.fetchCalls.length, 2);
  assert.equal(harness.fetchCalls[1].after_sequence, 18);
  assert.equal(harness.fetchCalls[1].session_uuid, GEN3_SESSION_UUID);
  assert.equal(uiReceived.filter((m) => m.server_sequence === 19).length, 1);

  // Simulasikan unmount / keluar room -> unsubscribe() -> tidak ada lagi fetch yang dijalankan
  sub.unsubscribe();
  assert.equal(
    shouldRunMessageSyncFallback({
      ...baseGuard,
      isSubscriberUnsubscribed: sub.getSubscriptionState() === 'UNSUBSCRIBED',
    }),
    false
  );
  const countAfterUnmount = await sub.reconcileFromRest();
  assert.equal(countAfterUnmount, 0);
  assert.equal(harness.fetchCalls.length, 2);
});

test('14. Status koneksi Reverb: CONNECTED baru muncul setelah channel.subscribed() terkonfirmasi, bukan saat subscribe() selesai', () => {
  harness.reset();

  const statusHistory = [];
  const worker = makeMockWorker();
  const sub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: MHS_ID,
    deviceUuid: D3_UUID,
    workerRpc: worker,
    fencing: FENCING,
    initialSequence: 17,
    sessionFloor: 17,
    sessionUuid: GEN3_SESSION_UUID,
    onSubscriptionStatusChange: (status) => {
      statusHistory.push({
        subState: status,
        uiStatus: mapSubscriptionStateToConnectionStatus(status),
      });
    },
  });

  // 14A. Setelah subscribe() selesai dipanggil, status WAJIB CONNECTING -> UI RECONNECTING (BUKAN CONNECTED!)
  sub.subscribe();
  assert.equal(sub.getSubscriptionState(), 'CONNECTING');
  assert.equal(mapSubscriptionStateToConnectionStatus(sub.getSubscriptionState()), 'RECONNECTING');

  // 14B. Transport Pusher 'connected' sebelum channel.subscribed() juga BELUM boleh menjadi CONNECTED!
  harness.emitConnectionStateChange('connecting', 'connected');
  assert.notEqual(sub.getSubscriptionState(), 'SUBSCRIBED');
  assert.notEqual(mapSubscriptionStateToConnectionStatus(sub.getSubscriptionState()), 'CONNECTED');

  // 14C. Baru setelah library memanggil callback channel.subscribed() -> SUBSCRIBED -> UI CONNECTED!
  harness.emitSubscribed();
  assert.equal(sub.getSubscriptionState(), 'SUBSCRIBED');
  assert.equal(mapSubscriptionStateToConnectionStatus(sub.getSubscriptionState()), 'CONNECTED');

  // 14D. Putus koneksi -> DISCONNECTED (OFFLINE), lalu menyambung kembali -> RECONNECTING
  harness.emitConnectionStateChange('connected', 'unavailable');
  assert.equal(sub.getSubscriptionState(), 'DISCONNECTED');
  assert.equal(mapSubscriptionStateToConnectionStatus(sub.getSubscriptionState()), 'OFFLINE');

  harness.emitConnectionStateChange('unavailable', 'connecting');
  assert.equal(sub.getSubscriptionState(), 'RECONNECTING');
  assert.equal(mapSubscriptionStateToConnectionStatus(sub.getSubscriptionState()), 'RECONNECTING');

  // 14E. Unsubscribe membersihkan listener state_change (zero leak)
  sub.unsubscribe();
  assert.equal(harness.pusherConnectionListeners.get('state_change')?.size ?? 0, 0);

  // 14F. Verifikasi kontrak sumber BchatProposalChat.tsx: tidak lagi memaksa setConnectionStatus('CONNECTED') pasca reconcileFromRest()
  const chatSource = fs.readFileSync(
    path.join(SRC_ROOT, 'components', 'bchat', 'BchatProposalChat.tsx'),
    'utf8'
  );
  const afterReconcileBlock = chatSource.slice(
    chatSource.indexOf('await subscriber.reconcileFromRest();'),
    chatSource.indexOf('await subscriber.reconcileFromRest();') + 500
  );
  assert.ok(
    afterReconcileBlock.includes('mapSubscriptionStateToConnectionStatus(currentSubState)'),
    'BchatProposalChat.tsx wajib memetakan connectionStatus dari subscriber.getSubscriptionState()'
  );
  assert.ok(
    !afterReconcileBlock.includes("setConnectionStatus('CONNECTED')"),
    'BchatProposalChat.tsx dilarang memaksa setConnectionStatus(CONNECTED) sebelum SUBSCRIBED'
  );
});

test('15. Pesan teks (content_type = 1) dan lampiran (content_type = 2) tetap dapat dikirim dan diterima pada Gen 3', async () => {
  harness.reset();
  harness.persistentCursor = 17;

  const senderWorker = {
    async sendRequest(action, payload) {
      if (action === 'ENCRYPT_MESSAGE') {
        const wireBytes = Buffer.from(`MLS_WIRE:${payload.plaintext}`, 'utf8');
        return {
          ciphertextWireBase64: wireBytes.toString('base64'),
          epoch: '1',
          clientMessageUuid: payload.clientMessageUuid,
          stateRevision: '1',
        };
      }
      if (action === 'UPDATE_OUTBOX_STATUS') {
        return { updated: true };
      }
      throw new Error(`Unexpected sender worker action: ${action}`);
    },
  };

  // 15A. Kirim pesan teks (content_type = 1)
  const textSendRes = await sendApplicationMessageFlow({
    roomUuid: ROOM_UUID,
    plaintext: 'Halo Pak, ini revisi Bab 1 Gen 3',
    deviceUuid: D3_UUID,
    workerRpc: senderWorker,
    fencing: FENCING,
    contentType: 1,
  });
  assert.equal(textSendRes.messageData.server_sequence, 18);
  assert.equal(textSendRes.messageData.content_type, 1);

  // 15B. Kirim pesan lampiran (content_type = 2) dengan attachment_uuids
  const attUuid = crypto.randomUUID();
  const manifestJson = JSON.stringify({
    version: '1.0.0',
    type: 'ATTACHMENT_MANIFEST',
    text: 'Berikut dokumen revisi proposal',
    attachments: [
      {
        attachmentUuid: attUuid,
        uploadSessionId: crypto.randomUUID(),
        fileName: 'revisi_bab1.pdf',
        mimeType: 'application/pdf',
        fileSizeBytes: 2048,
        totalChunks: 1,
        fileHashSha256: 'a'.repeat(64),
      },
    ],
  });
  const attSendRes = await sendApplicationMessageFlow({
    roomUuid: ROOM_UUID,
    plaintext: manifestJson,
    deviceUuid: D3_UUID,
    workerRpc: senderWorker,
    fencing: FENCING,
    contentType: 2,
    attachmentUuids: [attUuid],
  });
  assert.equal(attSendRes.messageData.server_sequence, 19);
  assert.equal(attSendRes.messageData.content_type, 2);
  assert.deepEqual(harness.sentPayloads[1].payload.attachment_uuids, [attUuid]);

  // Tandai kedua pesan milik sesi Gen 3 di mock server lalu terima di sisi Dosen (DOSEN_ID = 5)
  for (const m of harness.serverMessages) {
    m.session_uuid = GEN3_SESSION_UUID;
  }

  const receiverWorker = {
    async sendRequest(action, payload) {
      if (action === 'DECRYPT_MESSAGE') {
        const decoded = Buffer.from(payload.ciphertextWireBase64, 'base64').toString('utf8');
        const plaintext = decoded.replace(/^MLS_WIRE:/, '');
        return { plaintext, epoch: '1', senderLeafIndex: 0, stateRevision: '1' };
      }
      return { status: 'FOUND' };
    },
  };

  const dosenReceived = [];
  const dosenSub = new BchatRealtimeSubscriber({
    roomUuid: ROOM_UUID,
    currentUserId: DOSEN_ID,
    deviceUuid: '33333333-3333-4333-8333-333333333333',
    workerRpc: receiverWorker,
    fencing: FENCING,
    initialSequence: 17,
    sessionFloor: 17,
    sessionUuid: GEN3_SESSION_UUID,
    onMessageDecrypted: (m) => dosenReceived.push(m),
  });

  dosenSub.subscribe();
  await dosenSub.reconcileFromRest();

  assert.equal(dosenReceived.length, 2);
  assert.equal(dosenReceived[0].content_type, 1);
  assert.equal(dosenReceived[0].plaintext, 'Halo Pak, ini revisi Bab 1 Gen 3');
  assert.equal(dosenReceived[1].content_type, 2);
  const parsedManifest = JSON.parse(dosenReceived[1].plaintext);
  assert.equal(parsedManifest.type, 'ATTACHMENT_MANIFEST');
  assert.equal(parsedManifest.attachments[0].attachmentUuid, attUuid);
  assert.equal(dosenSub.getContiguousSequence(), 19);
});

