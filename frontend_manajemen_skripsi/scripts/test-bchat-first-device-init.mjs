/**
 * B-CHAT E2EE — Suite Pengujian First Device Initialization & Multi-Generation Session
 *
 * Mengimpor dan menguji LANGSUNG kode produksi aktual:
 * - src/lib/bchat/device/bchat-device-registration.ts (selectActiveLocalDeviceForUser, metadata user_id, backward compatibility legacy Device3/Device4)
 * - src/lib/bchat/api/bchat-api-client.ts (X-Bchat-Device-Uuid header & endpoints /sessions/*)
 * - src/lib/bchat/handshake/bchat-handshake-coordinator.ts (initiateNextSessionHandshakeFlow, completePendingSessionHandshakeFlow)
 * - src/lib/bchat/handshake/bchat-welcome-ack-builder.ts (158-byte canonical ACK + Ed25519 detached signature)
 * - ts-mls RFC 9420 asli (createGroup, createCommit, joinGroup, createApplicationMessage, processMessage dua arah)
 * - Verifikasi kontrak UI BchatDeviceOnboarding.tsx & BchatProposalChat.tsx (anti double-init, trust-gated KeyPackage, 3 cabang GroupState)
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

// Polyfill browser WebCrypto globals for Node.js test runtime
if (typeof globalThis.window === 'undefined') {
  globalThis.window = {
    crypto: crypto.webcrypto,
    atob: (b64) => Buffer.from(b64, 'base64').toString('binary'),
    btoa: (bin) => Buffer.from(bin, 'binary').toString('base64'),
  };
}

// ---------------------------------------------------------------------------
// 1. In-Memory Boundary Harness (Simulates Laravel B-CHAT Backend + IDB)
// ---------------------------------------------------------------------------

const harness = {
  requests: [], // { method, url, payload, headers }
  rooms: new Map(), // roomUuid -> state
  pendingKeyPackages: new Map(), // `${devUuid}:${hash}` -> PendingKeyPackageRecord
  deviceIdentityKeys: new Map(), // devUuid -> KeystoreRecord (with real Ed25519 CryptoKey)
  reset() {
    this.requests = [];
    this.rooms.clear();
    this.pendingKeyPackages.clear();
    this.deviceIdentityKeys.clear();
  },
};

globalThis.__BCHAT_FIRST_DEV_HARNESS__ = harness;

const MOCK_MODULES = {
  'mock://axios': `
    import crypto from 'node:crypto';

    function makeAxiosError(status, message, errorCode, headers) {
      const err = new Error(message);
      err.response = {
        status,
        headers: headers || {},
        data: { status: 'error', message, error_code: errorCode },
      };
      return err;
    }

    const axios = {
      async get(url, config) {
        const h = globalThis.__BCHAT_FIRST_DEV_HARNESS__;
        const headers = config?.headers || {};
        h.requests.push({ method: 'GET', url, payload: null, headers });

        // GET /api/bchat/rooms/{roomUuid}
        const roomDetailMatch = url.match(/^\\/api\\/bchat\\/rooms\\/([^/]+)$/);
        if (roomDetailMatch) {
          const roomUuid = decodeURIComponent(roomDetailMatch[1]);
          const room = h.rooms.get(roomUuid);
          if (!room) throw makeAxiosError(404, 'Room not found', 'ROOM_NOT_FOUND');
          return { data: { status: 'success', data: room.buildDetail() } };
        }

        // GET /api/bchat/rooms/{roomUuid}/partner-key-package
        const partnerKpMatch = url.match(/^\\/api\\/bchat\\/rooms\\/([^/]+)\\/partner-key-package$/);
        if (partnerKpMatch) {
          const roomUuid = decodeURIComponent(partnerKpMatch[1]);
          const room = h.rooms.get(roomUuid);
          if (!room || !room.partnerKeyPackage) {
            throw makeAxiosError(404, 'KeyPackage unavailable', 'KEY_PACKAGE_UNAVAILABLE');
          }
          return { data: { status: 'success', data: room.partnerKeyPackage } };
        }

        // GET /api/bchat/rooms/{roomUuid}/sessions/pending
        const pendingMatch = url.match(/^\\/api\\/bchat\\/rooms\\/([^/]+)\\/sessions\\/pending$/);
        if (pendingMatch) {
          const roomUuid = decodeURIComponent(pendingMatch[1]);
          const room = h.rooms.get(roomUuid);
          if (!room || !room.pendingSession) {
            throw makeAxiosError(404, 'No pending session', 'NO_PENDING_SESSION');
          }
          return { data: { status: 'success', data: room.pendingSession } };
        }

        // GET /api/bchat/rooms/{roomUuid}/sessions/{sessionUuid}/welcome-challenge
        const challengeMatch = url.match(/^\\/api\\/bchat\\/rooms\\/([^/]+)\\/sessions\\/([^/]+)\\/welcome-challenge$/);
        if (challengeMatch) {
          const roomUuid = decodeURIComponent(challengeMatch[1]);
          const sessionUuid = decodeURIComponent(challengeMatch[2]);
          const room = h.rooms.get(roomUuid);
          if (!room || !room.pendingSession || room.pendingSession.session_uuid !== sessionUuid) {
            throw makeAxiosError(404, 'Session not found', 'SESSION_NOT_FOUND');
          }
          if (!room.activeChallenge) {
            throw makeAxiosError(404, 'No active challenge', 'NO_ACTIVE_WELCOME_OPERATION');
          }
          const reqDev = (headers['X-Bchat-Device-Uuid'] || '').toLowerCase();
          if (reqDev && room.activeChallenge.recipient_device_uuid.toLowerCase() !== reqDev) {
            throw makeAxiosError(403, 'Device mismatch', 'RECIPIENT_DEVICE_MISMATCH');
          }
          // Rotate in-place if expired
          if (room.activeChallenge._expired) {
            room.activeChallenge.challenge_generation += 1;
            room.activeChallenge.challenge_uuid = crypto.randomUUID();
            room.activeChallenge.challenge_nonce = crypto.randomBytes(32).toString('hex');
            room.activeChallenge._expired = false;
            room.activeChallenge.ttl_remaining_seconds = 300;
          }
          return { data: { status: 'success', data: { ...room.activeChallenge } } };
        }

        throw makeAxiosError(404, 'Unhandled GET: ' + url, 'NOT_FOUND');
      },

      async post(url, payload, config) {
        const h = globalThis.__BCHAT_FIRST_DEV_HARNESS__;
        const headers = config?.headers || {};
        h.requests.push({ method: 'POST', url, payload, headers });

        // POST /api/bchat/rooms/{roomUuid}/sessions/next
        const nextMatch = url.match(/^\\/api\\/bchat\\/rooms\\/([^/]+)\\/sessions\\/next$/);
        if (nextMatch) {
          const roomUuid = decodeURIComponent(nextMatch[1]);
          const room = h.rooms.get(roomUuid);
          if (!room) throw makeAxiosError(404, 'Room not found', 'ROOM_NOT_FOUND');
          if (room.pendingSession) {
            throw makeAxiosError(409, 'Session already pending', 'SESSION_ALREADY_PENDING');
          }
          room.createNextCalls += 1;
          const nextGen = (room.activeSession?.generation_number ?? 0) + 1;
          room.pendingSession = {
            session_uuid: crypto.randomUUID(),
            room_uuid: roomUuid,
            generation_number: nextGen,
            status: 'PENDING',
            group_id_hash: payload?.group_id_hash ?? room.groupIdHash,
            start_epoch: 1,
            end_epoch: null,
            start_sequence: room.currentSequence + 1,
            end_sequence: null,
            current_sequence: room.currentSequence,
            created_by_user_id: room.initiatorUserId,
            activated_at: null,
            archived_at: null,
            failed_at: null,
            failure_reason: null,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
          };
          return { data: { status: 'success', data: room.pendingSession } };
        }

        // POST /api/bchat/rooms/{roomUuid}/sessions/{sessionUuid}/welcome
        const welcomeMatch = url.match(/^\\/api\\/bchat\\/rooms\\/([^/]+)\\/sessions\\/([^/]+)\\/welcome$/);
        if (welcomeMatch) {
          const roomUuid = decodeURIComponent(welcomeMatch[1]);
          const sessionUuid = decodeURIComponent(welcomeMatch[2]);
          const room = h.rooms.get(roomUuid);
          if (!room || !room.pendingSession || room.pendingSession.session_uuid !== sessionUuid) {
            throw makeAxiosError(404, 'Session not found', 'SESSION_NOT_FOUND');
          }
          if (room.activeChallenge) {
            throw makeAxiosError(409, 'Active welcome exists', 'ACTIVE_WELCOME_OPERATION_EXISTS');
          }
          if (room.failNextWelcomeWith429 && room.failNextWelcomeWith429 > 0) {
            room.failNextWelcomeWith429 -= 1;
            throw makeAxiosError(429, 'Too Many Attempts.', undefined, { 'retry-after': '45' });
          }
          room.welcomePublishCalls += 1;
          room.activeChallenge = {
            welcome_operation_uuid: crypto.randomUUID(),
            session_uuid: sessionUuid,
            room_uuid: roomUuid,
            recipient_device_uuid: payload.recipient_device_uuid,
            welcome_message: payload.welcome_message,
            group_id_hash: payload.group_id_hash,
            epoch: '1',
            challenge_uuid: crypto.randomUUID(),
            challenge_nonce: crypto.randomBytes(32).toString('hex'),
            challenge_generation: 1,
            expires_at: new Date(Date.now() + 300000).toISOString(),
            ttl_remaining_seconds: 300,
            _expired: false,
          };
          return {
            data: {
              status: 'success',
              data: {
                session_uuid: sessionUuid,
                welcome_operation_uuid: room.activeChallenge.welcome_operation_uuid,
                challenge_uuid: room.activeChallenge.challenge_uuid,
                expires_at: room.activeChallenge.expires_at,
              },
            },
          };
        }

        // POST /api/bchat/rooms/{roomUuid}/sessions/{sessionUuid}/ack
        const ackMatch = url.match(/^\\/api\\/bchat\\/rooms\\/([^/]+)\\/sessions\\/([^/]+)\\/ack$/);
        if (ackMatch) {
          const roomUuid = decodeURIComponent(ackMatch[1]);
          const sessionUuid = decodeURIComponent(ackMatch[2]);
          const room = h.rooms.get(roomUuid);
          if (!room || !room.pendingSession || room.pendingSession.session_uuid !== sessionUuid) {
            throw makeAxiosError(404, 'Session not found', 'SESSION_NOT_FOUND');
          }
          if (!room.activeChallenge) {
            throw makeAxiosError(409, 'No active challenge', 'NO_ACTIVE_WELCOME_OPERATION');
          }
          if (room.failFirstAckWithExpired && !room._didFailFirstAck) {
            room._didFailFirstAck = true;
            room.activeChallenge._expired = true;
            throw makeAxiosError(409, 'Tantangan Welcome telah kedaluwarsa.', 'CHALLENGE_EXPIRED');
          }
          if (payload.challenge_uuid !== room.activeChallenge.challenge_uuid) {
            throw makeAxiosError(409, 'Challenge UUID mismatch', 'CHALLENGE_UUID_MISMATCH');
          }
          // Verify Ed25519 signature cryptographically against recipient's registered public key!
          if (room.verifyAckSignature) {
            const ok = await room.verifyAckSignature(room.activeChallenge, payload.signature);
            if (!ok) {
              throw makeAxiosError(422, 'Invalid Ed25519 ACK signature', 'SIGNATURE_VERIFICATION_FAILED');
            }
          }
          // Atomically archive old activeSession & activate pendingSession
          if (room.activeSession) {
            room.archivedSessions.push({ ...room.activeSession, status: 'ARCHIVED' });
          }
          room.activeSession = {
            ...room.pendingSession,
            status: 'ACTIVE',
            activated_at: new Date().toISOString(),
          };
          room.pendingSession = null;
          room.activeChallenge = null;
          return { data: { status: 'success', data: room.activeSession } };
        }

        throw makeAxiosError(404, 'Unhandled POST: ' + url, 'NOT_FOUND');
      },
    };
    export default axios;
  `,
  'mock://idb-core': `
    export class BchatStorageError extends Error {
      constructor(msg, code) { super(msg); this.code = code; }
    }
    export async function idbGet(storeName, key) {
      const h = globalThis.__BCHAT_FIRST_DEV_HARNESS__;
      if (storeName === 'keystore') {
        const uuid = String(key).replace(/^identity_key_/, '').toLowerCase();
        return h.deviceIdentityKeys.get(uuid);
      }
      return undefined;
    }
    export async function idbPut(storeName, record) {
      const h = globalThis.__BCHAT_FIRST_DEV_HARNESS__;
      if (storeName === 'keystore' && record?.metadata?.device_uuid) {
        h.deviceIdentityKeys.set(String(record.metadata.device_uuid).toLowerCase(), record);
      }
    }
    export async function withReadonlyTransaction(storeNames, cb) {
      const h = globalThis.__BCHAT_FIRST_DEV_HARNESS__;
      const fakeTx = {};
      const getStore = (name) => ({
        getAll() {
          const req = {};
          queueMicrotask(() => {
            if (name === 'pending_key_packages') {
              req.result = Array.from(h.pendingKeyPackages.values());
            } else if (name === 'keystore') {
              req.result = Array.from(h.deviceIdentityKeys.values());
            } else {
              req.result = [];
            }
            req.onsuccess?.();
          });
          return req;
        },
        get(key) {
          const req = {};
          queueMicrotask(() => {
            if (name === 'keystore') {
              const uuid = String(key).replace(/^identity_key_/, '').toLowerCase();
              req.result = h.deviceIdentityKeys.get(uuid);
            } else {
              req.result = undefined;
            }
            req.onsuccess?.();
          });
          return req;
        },
      });
      return cb(fakeTx, getStore);
    }
    export async function withReadwriteTransaction(storeNames, cb) {
      return withReadonlyTransaction(storeNames, cb);
    }
  `,
};

// Register Node module hooks to transpile TS production files on the fly
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '@/lib/axios') {
      return { shortCircuit: true, url: 'mock://axios' };
    }
    if (specifier === '@/lib/bchat/storage/bchat-idb-core') {
      return { shortCircuit: true, url: 'mock://idb-core' };
    }
    if (specifier.startsWith('@/')) {
      const rel = specifier.slice(2);
      const candidates = [
        path.join(SRC_ROOT, `${rel}.ts`),
        path.join(SRC_ROOT, `${rel}.tsx`),
        path.join(SRC_ROOT, rel, 'index.ts'),
      ];
      for (const c of candidates) {
        if (fs.existsSync(c)) {
          return { shortCircuit: true, url: pathToFileURL(c).href };
        }
      }
    }
    if ((specifier.startsWith('./') || specifier.startsWith('../')) && context.parentURL?.startsWith('file:')) {
      const parentDir = path.dirname(fileURLToPath(context.parentURL));
      const base = path.resolve(parentDir, specifier);
      const candidates = [`${base}.ts`, `${base}.tsx`, path.join(base, 'index.ts'), base];
      for (const c of candidates) {
        if (fs.existsSync(c) && fs.statSync(c).isFile()) {
          return { shortCircuit: true, url: pathToFileURL(c).href };
        }
      }
    }
    return nextResolve(specifier, context);
  },
  load(url, context, nextLoad) {
    if (url in MOCK_MODULES) {
      return { shortCircuit: true, format: 'module', source: MOCK_MODULES[url] };
    }
    if (url.startsWith('file:') && (url.endsWith('.ts') || url.endsWith('.tsx'))) {
      const filePath = fileURLToPath(url);
      const source = fs.readFileSync(filePath, 'utf8');
      const transpiled = ts.transpileModule(source, {
        compilerOptions: {
          module: ts.ModuleKind.ESNext,
          target: ts.ScriptTarget.ES2022,
          jsx: ts.JsxEmit.ReactJSX,
          esModuleInterop: true,
        },
        fileName: filePath,
      });
      return { shortCircuit: true, format: 'module', source: transpiled.outputText };
    }
    return nextLoad(url, context);
  },
});

// Import REAL production modules + REAL ts-mls RFC 9420 ciphersuite!
const { selectActiveLocalDeviceForUser } = await import('@/lib/bchat/device/bchat-device-registration');
const {
  initiateNextSessionHandshakeFlow,
  completePendingSessionHandshakeFlow,
  evaluateActiveRoomFoundState,
  shouldPollIncomingHistoryTransfers,
  findRecoverablePendingWelcomeOutbox,
  isRateLimitPublishError,
} = await import('@/lib/bchat/handshake/bchat-handshake-coordinator');
const { buildWelcomeAckPayload } = await import('@/lib/bchat/handshake/bchat-welcome-ack-builder');

// Real ts-mls imports (identical to bchat-mls-poc.worker.ts)
const { createGroup, joinGroup } = await import(
  pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/clientState.js')).href
);
const { generateKeyPackage, keyPackageEncoder, keyPackageDecoder } = await import(
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
const { wireformats } = await import(
  pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/wireformat.js')).href
);
const { unsafeTestingAuthenticationService } = await import(
  pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/authenticationService.js')).href
);
const { makeGenericHpke } = await import(
  pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/crypto/implementation/hpke.js')).href
);
const { makeKdf, makeKdfImpl } = await import(
  pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/crypto/implementation/default/makeKdfImpl.js')).href
);
const { makeHashImpl } = await import(
  pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/crypto/implementation/default/makeHashImpl.js')).href
);
const { defaultRng } = await import(
  pathToFileURL(path.join(FRONTEND_ROOT, 'node_modules/ts-mls/dist/src/crypto/implementation/default/rng.js')).href
);
const { CipherSuite, DhkemX25519HkdfSha256, HkdfSha256, Aes128Gcm } = await import('@hpke/core');

// Build native MLS 128 ciphersuite identical to bchat-mls-poc.worker.ts
async function buildMlsContext() {
  const subtle = crypto.webcrypto.subtle;
  const rawEd25519ToPKCS8 = (rawKey) => {
    const prefix = new Uint8Array([
      0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x04, 0x22, 0x04, 0x20,
    ]);
    const out = new Uint8Array(prefix.length + rawKey.length);
    out.set(prefix, 0);
    out.set(rawKey, prefix.length);
    return out;
  };
  const aead = {
    async encrypt(k, n, a, p) {
      const key = await subtle.importKey('raw', k, { name: 'AES-GCM' }, false, ['encrypt']);
      const params = { name: 'AES-GCM', iv: n };
      if (a && a.length > 0) params.additionalData = a;
      return new Uint8Array(await subtle.encrypt(params, key, p));
    },
    async decrypt(k, n, a, c) {
      const key = await subtle.importKey('raw', k, { name: 'AES-GCM' }, false, ['decrypt']);
      const params = { name: 'AES-GCM', iv: n };
      if (a && a.length > 0) params.additionalData = a;
      return new Uint8Array(await subtle.decrypt(params, key, c));
    },
  };
  const hpke = await makeGenericHpke(
    { kem: 'DHKEM-X25519-HKDF-SHA256', kdf: 'HKDF-SHA256', aead: 'AES128GCM' },
    aead,
    new CipherSuite({
      kem: new DhkemX25519HkdfSha256(),
      kdf: new HkdfSha256(),
      aead: new Aes128Gcm(),
    })
  );
  const signature = {
    async sign(signKey, msg) {
      const keyData = signKey.length === 32 ? rawEd25519ToPKCS8(signKey) : signKey;
      const key = await subtle.importKey('pkcs8', keyData, 'Ed25519', false, ['sign']);
      return new Uint8Array(await subtle.sign('Ed25519', key, msg));
    },
    async verify(pubKey, msg, sig) {
      const key = await subtle.importKey('raw', pubKey, 'Ed25519', false, ['verify']);
      return subtle.verify('Ed25519', key, sig, msg);
    },
    async keygen() {
      const kp = await subtle.generateKey('Ed25519', true, ['sign', 'verify']);
      return {
        signKey: new Uint8Array(await subtle.exportKey('pkcs8', kp.privateKey)),
        publicKey: new Uint8Array(await subtle.exportKey('raw', kp.publicKey)),
      };
    },
  };
  return {
    cipherSuite: {
      id: 1,
      kdf: makeKdfImpl(makeKdf('HKDF-SHA256')),
      hash: makeHashImpl(subtle, 'SHA-256'),
      signature,
      hpke,
      rng: defaultRng,
    },
    authService: unsafeTestingAuthenticationService,
  };
}

/**
 * Real RFC 9420 MLS Worker peer simulator using actual ts-mls state machine.
 */
function createRealMlsWorkerPeer({ role, identity, deviceUuid, mlsContext }) {
  let localKeyPackage = null;
  let clientState = null;
  let persistedState = null; // simulates IDB group_states
  let lastProcessedSequence = 0;
  const outbox = [];
  const pendingPrivatePackages = new Map();
  const stats = {
    generateKpCalls: 0,
    createGroupCalls: 0,
    commitCalls: 0,
  };

  return {
    getClientState: () => clientState,
    getPersistedState: () => persistedState,
    getOutbox: () => outbox,
    clearOutbox: () => {
      outbox.length = 0;
    },
    setLastProcessedSequence: (seq) => {
      lastProcessedSequence = seq;
    },
    getStats: () => ({ ...stats }),
    resetStats: () => {
      stats.generateKpCalls = 0;
      stats.createGroupCalls = 0;
      stats.commitCalls = 0;
    },
    async generateAndRegisterKeyPackage() {
      const kp = await generateKeyPackage({
        credential: {
          credentialType: defaultCredentialTypes.basic,
          identity: new TextEncoder().encode(identity),
        },
        cipherSuite: mlsContext.cipherSuite,
      });
      const publicBytes = encode(keyPackageEncoder, kp.publicPackage);
      const hashHex = crypto.createHash('sha256').update(publicBytes).digest('hex');
      pendingPrivatePackages.set(hashHex, { kp, consumed: false });
      harness.pendingKeyPackages.set(`${deviceUuid}:${hashHex}`, {
        device_uuid: deviceUuid,
        key_package_hash: hashHex,
        private_key_package_bytes: new Uint8Array([1, 2, 3]),
        public_key_package_bytes: publicBytes,
        created_at: Date.now(),
        metadata: { status: 'pending', publication_status: 'published' },
      });
      return {
        key_package_hash: hashHex,
        key_package_data: Buffer.from(publicBytes).toString('base64'),
      };
    },
    async sendRequest(action, payload) {
      if (action === 'GET_ROOM_OUTBOX') {
        return { messages: outbox.filter((m) => m.roomUuid === payload.roomUuid) };
      }
      if (action === 'UPDATE_OUTBOX_STATUS') {
        const item = outbox.find((m) => m.clientMessageUuid === payload.clientMessageUuid);
        if (item) item.status = payload.status;
        return { updated: true };
      }
      if (action === 'RESTORE_GROUP_STATE') {
        if (!persistedState || persistedState.roomUuid !== payload.roomUuid) {
          return { status: 'NOT_FOUND' };
        }
        clientState = persistedState.state;
        return {
          status: 'FOUND',
          epoch: persistedState.state.groupContext.epoch.toString(),
          groupIdHash: persistedState.groupIdHash,
          stateRevision: persistedState.revision,
          lastProcessedSequence,
        };
      }
      if (action === 'GENERATE_KEY_PACKAGE') {
        stats.generateKpCalls += 1;
        const kp = await generateKeyPackage({
          credential: {
            credentialType: defaultCredentialTypes.basic,
            identity: new TextEncoder().encode(identity),
          },
          cipherSuite: mlsContext.cipherSuite,
        });
        localKeyPackage = kp;
        const pub = encode(keyPackageEncoder, kp.publicPackage);
        return {
          keyPackageHash: crypto.createHash('sha256').update(pub).digest('hex'),
          keyPackageWireBase64: Buffer.from(pub).toString('base64'),
        };
      }
      if (action === 'CREATE_GROUP') {
        stats.createGroupCalls += 1;
        const groupIdBytes = new TextEncoder().encode(payload.roomUuid);
        const groupIdHash = crypto.createHash('sha256').update(groupIdBytes).digest('hex');
        clientState = await createGroup({
          context: mlsContext,
          groupId: groupIdBytes,
          keyPackage: localKeyPackage.publicPackage,
          privateKeyPackage: localKeyPackage.privatePackage,
        });
        persistedState = { roomUuid: payload.roomUuid, state: clientState, groupIdHash, revision: '1' };
        return { epoch: '0', groupIdHash, stateRevision: '1' };
      }
      if (action === 'COMMIT_ADD_MEMBER') {
        stats.commitCalls += 1;
        const partnerBytes = new Uint8Array(Buffer.from(payload.partnerKeyPackageBase64, 'base64'));
        const partnerKp = decode(keyPackageDecoder, partnerBytes);
        const commitResult = await createCommit({
          context: mlsContext,
          state: clientState,
          extraProposals: [{ proposalType: defaultProposalTypes.add, add: { keyPackage: partnerKp } }],
          ratchetTreeExtension: true,
        });
        const welcomeBytes = encode(mlsMessageEncoder, commitResult.welcome);
        const commitBytes = encode(mlsMessageEncoder, commitResult.commit);
        clientState = commitResult.newState;
        const groupIdHash = crypto.createHash('sha256').update(clientState.groupContext.groupId).digest('hex');
        persistedState = { roomUuid: payload.roomUuid, state: clientState, groupIdHash, revision: '2' };
        const commitUuid = crypto.randomUUID();
        const welcomeUuid = crypto.randomUUID();
        const welcomeBase64 = Buffer.from(welcomeBytes).toString('base64');
        outbox.push({
          roomUuid: payload.roomUuid,
          clientMessageUuid: commitUuid,
          messageType: 'commit',
          status: 'pending',
          ciphertextWireBase64: Buffer.from(commitBytes).toString('base64'),
        });
        outbox.push({
          roomUuid: payload.roomUuid,
          clientMessageUuid: welcomeUuid,
          messageType: 'welcome',
          status: 'pending',
          ciphertextWireBase64: welcomeBase64,
        });
        return {
          welcomeWireBase64: welcomeBase64,
          commitWireBase64: Buffer.from(commitBytes).toString('base64'),
          clientMessageUuidCommit: commitUuid,
          clientMessageUuidWelcome: welcomeUuid,
          epoch: clientState.groupContext.epoch.toString(),
          stateRevision: '2',
        };
      }
      if (action === 'JOIN_WELCOME') {
        const entry = pendingPrivatePackages.get(payload.keyPackageHash);
        assert.ok(entry && !entry.consumed, 'PrivateKeyPackage must exist and not be reused');
        const welcomeBytes = new Uint8Array(Buffer.from(payload.welcomeWireBase64, 'base64'));
        const decodedMsg = decode(mlsMessageDecoder, welcomeBytes);
        assert.equal(decodedMsg.wireformat, wireformats.mls_welcome);
        clientState = await joinGroup({
          context: mlsContext,
          welcome: decodedMsg.welcome,
          keyPackage: entry.kp.publicPackage,
          privateKeys: entry.kp.privatePackage,
        });
        entry.consumed = true;
        const rec = harness.pendingKeyPackages.get(`${deviceUuid}:${payload.keyPackageHash}`);
        if (rec) rec.metadata.status = 'consumed';
        const groupIdHash = crypto.createHash('sha256').update(clientState.groupContext.groupId).digest('hex');
        persistedState = { roomUuid: payload.roomUuid, state: clientState, groupIdHash, revision: '1' };
        return { epoch: clientState.groupContext.epoch.toString(), groupIdHash, stateRevision: '1' };
      }
      if (action === 'ENCRYPT_MESSAGE') {
        const res = await createApplicationMessage({
          context: mlsContext,
          state: clientState,
          message: new TextEncoder().encode(payload.plaintext),
        });
        clientState = res.newState;
        persistedState.state = clientState;
        const wireBytes = encode(mlsMessageEncoder, res.message);
        return {
          ciphertextWireBase64: Buffer.from(wireBytes).toString('base64'),
          epoch: clientState.groupContext.epoch.toString(),
        };
      }
      if (action === 'DECRYPT_MESSAGE') {
        const wireBytes = new Uint8Array(Buffer.from(payload.ciphertextWireBase64, 'base64'));
        const decoded = decode(mlsMessageDecoder, wireBytes);
        const res = await processMessage({
          context: mlsContext,
          state: clientState,
          message: decoded,
        });
        assert.equal(res.kind, 'applicationMessage');
        clientState = res.newState;
        persistedState.state = clientState;
        return {
          plaintext: new TextDecoder().decode(res.message),
          epoch: clientState.groupContext.epoch.toString(),
        };
      }
      throw new Error(`Unhandled worker action: ${action}`);
    },
  };
}

// ===========================================================================
// TEST 1: Per-User Device Resolution + Legacy Device3/Device4 Backward Compat
// ===========================================================================
test('1. Resolusi identitas perangkat per user + backward compatibility legacy Device3/Device4', () => {
  const devMhsUuid = '11111111-1111-4111-8111-111111111111';
  const devDosenUuid = '22222222-2222-4222-8222-222222222222';
  const legacyDevice3Uuid = '33333333-3333-4333-8333-333333333333';

  const localDevices = [
    {
      deviceUuid: devMhsUuid,
      record: {
        key_id: `identity_key_${devMhsUuid}`,
        key_version: 1,
        status: 'active',
        algorithm: 'Ed25519',
        created_at: 1000,
        metadata: { key_type: 'DEVICE_IDENTITY', device_uuid: devMhsUuid, user_id: '101' },
      },
    },
    {
      deviceUuid: devDosenUuid,
      record: {
        key_id: `identity_key_${devDosenUuid}`,
        key_version: 1,
        status: 'active',
        algorithm: 'Ed25519',
        created_at: 2000,
        metadata: { key_type: 'DEVICE_IDENTITY', device_uuid: devDosenUuid, user_id: '202' },
      },
    },
  ];

  // Mahasiswa (user 101) pada browser bersama hanya memilih devMhsUuid
  const selectedMhs = selectActiveLocalDeviceForUser(localDevices, {
    userId: 101,
    backendActiveDeviceUuids: [devMhsUuid],
  });
  assert.equal(selectedMhs?.deviceUuid, devMhsUuid);

  // Dosen (user 202) pada browser bersama hanya memilih devDosenUuid (tidak pernah ambil devMhsUuid!)
  const selectedDosen = selectActiveLocalDeviceForUser(localDevices, {
    userId: 202,
    backendActiveDeviceUuids: [devDosenUuid],
  });
  assert.equal(selectedDosen?.deviceUuid, devDosenUuid);

  // User baru (user 303) tanpa perangkat ACTIVE di backend -> MUST return null (tidak boleh fallback ke device user lain!)
  const selectedNewUser = selectActiveLocalDeviceForUser(localDevices, {
    userId: 303,
    backendActiveDeviceUuids: [],
  });
  assert.equal(selectedNewUser, null);

  // Backward compatibility: Record legacy Device3/Device4 tanpa metadata.user_id tetap sah bila terdaftar ACTIVE di backend user tersebut
  const legacyList = [
    {
      deviceUuid: legacyDevice3Uuid,
      record: {
        key_id: `identity_key_${legacyDevice3Uuid}`,
        key_version: 1,
        status: 'active',
        algorithm: 'Ed25519',
        created_at: 500,
        metadata: { key_type: 'DEVICE_IDENTITY', device_uuid: legacyDevice3Uuid }, // NO user_id!
      },
    },
  ];
  const selectedLegacy = selectActiveLocalDeviceForUser(legacyList, {
    userId: 2,
    backendActiveDeviceUuids: [legacyDevice3Uuid],
  });
  assert.equal(selectedLegacy?.deviceUuid, legacyDevice3Uuid);

  // Record legacy milik akun lain tidak boleh dipakai jika tidak ada di backendActiveDeviceUuids akun login
  const rejectedCrossUserLegacy = selectActiveLocalDeviceForUser(legacyList, {
    userId: 999,
    backendActiveDeviceUuids: ['99999999-9999-4999-8999-999999999999'],
  });
  assert.equal(rejectedCrossUserLegacy, null);
});

// ===========================================================================
// TEST 2: Full E2E Multi-Generation Session Handshake + Two-Way MLS Chat + Headers + Rotation
// ===========================================================================
test('2. Room ACTIVE tanpa GroupState: Inisiasi sesi baru, Welcome, JOIN_WELCOME, ACK (dengan rotasi expired), & chat dua arah MLS RFC 9420', async () => {
  harness.reset();
  const mlsContext = await buildMlsContext();

  const roomUuid = crypto.randomUUID();
  const mhsDeviceUuid = crypto.randomUUID();
  const dosenDeviceUuid = crypto.randomUUID();
  const mhsUserId = 401;
  const dosenUserId = 402;
  const fencing = { ownerToken: 'tab_test_owner', generation: '1' };

  // Generate real Ed25519 device identity keypair for Dosen (for real Welcome ACK signature verification!)
  const dosenEd25519 = await crypto.webcrypto.subtle.generateKey('Ed25519', false, ['sign']);
  const dosenPubRaw = new Uint8Array(await crypto.webcrypto.subtle.exportKey('raw', dosenEd25519.publicKey));
  harness.deviceIdentityKeys.set(dosenDeviceUuid, {
    key_id: `identity_key_${dosenDeviceUuid}`,
    key_version: 1,
    status: 'active',
    algorithm: 'Ed25519',
    crypto_key: dosenEd25519.privateKey,
    created_at: Date.now(),
    metadata: { key_type: 'DEVICE_IDENTITY', device_uuid: dosenDeviceUuid, user_id: String(dosenUserId) },
  });

  const mhsWorker = createRealMlsWorkerPeer({
    role: 'mahasiswa',
    identity: 'mhs.first@unuha.test',
    deviceUuid: mhsDeviceUuid,
    mlsContext,
  });
  const dosenWorker = createRealMlsWorkerPeer({
    role: 'dosen',
    identity: 'dosen.first@unuha.test',
    deviceUuid: dosenDeviceUuid,
    mlsContext,
  });

  // Dosen uploads KeyPackage
  const dosenKp = await dosenWorker.generateAndRegisterKeyPackage();

  // Room starts ACTIVE (Gen 1 active on server, neither fresh browser has GroupState)
  const roomState = {
    initiatorUserId: mhsUserId,
    groupIdHash: crypto.createHash('sha256').update(roomUuid).digest('hex'),
    currentSequence: 0,
    activeSession: {
      session_uuid: crypto.randomUUID(),
      generation_number: 1,
      status: 'ACTIVE',
      start_sequence: 1,
      current_sequence: 0,
    },
    pendingSession: null,
    archivedSessions: [],
    activeChallenge: null,
    partnerKeyPackage: {
      recipient_user_id: String(dosenUserId),
      recipient_device_uuid: dosenDeviceUuid,
      key_package_hash: dosenKp.key_package_hash,
      key_package_data: dosenKp.key_package_data,
    },
    createNextCalls: 0,
    welcomePublishCalls: 0,
    failFirstAckWithExpired: true, // Force CHALLENGE_EXPIRED on first ACK attempt to prove in-place rotation!
    _didFailFirstAck: false,
    currentViewerRole: 'mahasiswa',
    async verifyAckSignature(challenge, sigBase64) {
      const built = buildWelcomeAckPayload({
        roomUuid,
        recipientUserId: dosenUserId,
        welcomeOperationUuid: challenge.welcome_operation_uuid,
        challengeUuid: challenge.challenge_uuid,
        challengeGeneration: challenge.challenge_generation,
        groupIdHash: challenge.group_id_hash,
        epoch: BigInt(1),
        challengeNonce: challenge.challenge_nonce,
      });
      const sigBytes = new Uint8Array(Buffer.from(sigBase64, 'base64'));
      const pubKey = await crypto.webcrypto.subtle.importKey('raw', dosenPubRaw, 'Ed25519', false, ['verify']);
      return crypto.webcrypto.subtle.verify('Ed25519', pubKey, sigBytes, built.payloadBytes);
    },
    buildDetail() {
      let pendingInfo = null;
      if (this.pendingSession) {
        let actionType = 'NONE';
        let actionRequired = false;
        if (!this.activeChallenge) {
          actionType = this.currentViewerRole === 'mahasiswa' ? 'PUBLISH_SESSION_WELCOME' : 'AWAIT_PARTNER_SESSION_WELCOME';
          actionRequired = this.currentViewerRole === 'mahasiswa';
        } else {
          actionType = this.currentViewerRole === 'dosen_pembimbing' ? 'FETCH_SESSION_CHALLENGE_AND_ACK' : 'AWAIT_PARTNER_SESSION_ACK';
          actionRequired = this.currentViewerRole === 'dosen_pembimbing';
        }
        pendingInfo = {
          session_uuid: this.pendingSession.session_uuid,
          generation_number: this.pendingSession.generation_number,
          status: 'PENDING',
          start_sequence: this.pendingSession.start_sequence,
          current_sequence: this.pendingSession.current_sequence,
          created_by_user_id: mhsUserId,
          has_active_challenge: Boolean(this.activeChallenge),
          welcome_operation_uuid: this.activeChallenge?.welcome_operation_uuid ?? null,
          recipient_device_uuid: this.activeChallenge?.recipient_device_uuid ?? null,
          expires_at: this.activeChallenge?.expires_at ?? null,
          action_required: actionRequired,
          action_type: actionType,
        };
      }
      return {
        room_uuid: roomUuid,
        status: 'ACTIVE',
        tahap: 'proposal',
        pembimbing_ke: 1,
        current_epoch: '1',
        group_id_hash: this.groupIdHash,
        my_membership: {
          role: this.currentViewerRole,
          is_initiator: this.currentViewerRole === 'mahasiswa',
          is_active: true,
          joined_at_epoch: 1,
        },
        partner: {
          name: 'Mitra Test',
          role: this.currentViewerRole === 'mahasiswa' ? 'dosen_pembimbing' : 'mahasiswa',
          has_active_device: true,
          has_key_package: true,
          is_active: true,
        },
        assignment: { status_penugasan: 'AKTIF', judul_skripsi: 'Skripsi Uji' },
        action_hint: { action_required: false, action_type: 'NONE' },
        handshake_context: {
          has_active_challenge: false,
          welcome_operation_uuid: null,
          epoch: null,
          action_required: false,
          action_type: 'NONE',
        },
        capabilities: { can_send_messages: true, can_read_messages: true, can_handshake: false },
        active_session: this.activeSession,
        pending_session: pendingInfo,
      };
    },
  };
  harness.rooms.set(roomUuid, roomState);

  // Step 1: Mahasiswa initiates new session generation explicitly
  roomState.currentViewerRole = 'mahasiswa';
  const initRes = await initiateNextSessionHandshakeFlow(mhsWorker, roomUuid, mhsDeviceUuid, { fencing });
  assert.equal(initRes.status, 'AWAITING_ACK');
  assert.equal(initRes.generationNumber, 2);
  assert.equal(roomState.createNextCalls, 1);
  assert.equal(roomState.welcomePublishCalls, 1);

  // Step 2: Retry initiateNextSessionHandshakeFlow MUST NOT create duplicate session or duplicate Welcome!
  const retryInitRes = await initiateNextSessionHandshakeFlow(mhsWorker, roomUuid, mhsDeviceUuid, { fencing });
  assert.equal(retryInitRes.status, 'AWAITING_ACK');
  assert.equal(retryInitRes.sessionUuid, initRes.sessionUuid);
  assert.equal(roomState.createNextCalls, 1, 'Retry tidak boleh membuat sesi baru berulang kali');
  assert.equal(roomState.welcomePublishCalls, 1, 'Retry tidak boleh mempublikasikan Welcome ganda');

  // Verify X-Bchat-Device-Uuid header was sent on Mahasiswa handshake requests
  const mhsSessionNextReq = harness.requests.find((r) => r.url.endsWith('/sessions/next'));
  const mhsPartnerKpReq = harness.requests.find((r) => r.url.endsWith('/partner-key-package'));
  const mhsWelcomeReq = harness.requests.find((r) => r.url.endsWith('/welcome'));
  assert.equal(mhsSessionNextReq?.headers['X-Bchat-Device-Uuid'], mhsDeviceUuid);
  assert.equal(mhsPartnerKpReq?.headers['X-Bchat-Device-Uuid'], mhsDeviceUuid);
  assert.equal(mhsWelcomeReq?.headers['X-Bchat-Device-Uuid'], mhsDeviceUuid);

  // Step 3: Dosen completes pending session handshake (first ACK hits CHALLENGE_EXPIRED -> auto rotates & succeeds without reusing PrivateKeyPackage!)
  roomState.currentViewerRole = 'dosen_pembimbing';
  const ackRes = await completePendingSessionHandshakeFlow(dosenWorker, roomUuid, dosenDeviceUuid, {
    fencing,
    userId: dosenUserId,
    expectedSessionUuid: initRes.sessionUuid,
  });
  assert.equal(ackRes.sessionStatus, 'ACTIVE');
  assert.equal(ackRes.generationNumber, 2);
  assert.equal(roomState.activeSession.generation_number, 2);
  assert.equal(roomState.archivedSessions.length, 1);
  assert.equal(roomState.archivedSessions[0].generation_number, 1);
  assert.equal(roomState.pendingSession, null);

  // Verify X-Bchat-Device-Uuid header was sent on Dosen challenge & ACK requests
  const dosenChallengeReqs = harness.requests.filter((r) => r.url.endsWith('/welcome-challenge'));
  const dosenAckReqs = harness.requests.filter((r) => r.url.endsWith('/ack'));
  assert.equal(dosenChallengeReqs.length, 2, 'Harus memanggil welcome-challenge 2x (awal + rotasi setelah CHALLENGE_EXPIRED)');
  assert.equal(dosenAckReqs.length, 2, 'Harus mengirim ACK 2x (pertama expired, kedua sukses dengan challenge hasil rotasi)');
  for (const r of [...dosenChallengeReqs, ...dosenAckReqs]) {
    assert.equal(r.headers['X-Bchat-Device-Uuid'], dosenDeviceUuid);
  }

  // Step 4: Both peers restore their persisted GroupState & exchange real two-way E2EE MLS messages!
  const mhsRestore = await mhsWorker.sendRequest('RESTORE_GROUP_STATE', { roomUuid, deviceUuid: mhsDeviceUuid, fencing });
  const dosenRestore = await dosenWorker.sendRequest('RESTORE_GROUP_STATE', { roomUuid, deviceUuid: dosenDeviceUuid, fencing });
  assert.equal(mhsRestore.status, 'FOUND');
  assert.equal(dosenRestore.status, 'FOUND');

  // Mahasiswa -> Dosen
  const msg1Plain = 'Halo Pak Dosen, ini pesan perdana sesi aman baru!';
  const enc1 = await mhsWorker.sendRequest('ENCRYPT_MESSAGE', { plaintext: msg1Plain });
  const dec1 = await dosenWorker.sendRequest('DECRYPT_MESSAGE', { ciphertextWireBase64: enc1.ciphertextWireBase64 });
  assert.equal(dec1.plaintext, msg1Plain);

  // Dosen -> Mahasiswa
  const msg2Plain = 'Waalaikumsalam, pesan perdana diterima dengan aman melalui sesi Gen 2.';
  const enc2 = await dosenWorker.sendRequest('ENCRYPT_MESSAGE', { plaintext: msg2Plain });
  const dec2 = await mhsWorker.sendRequest('DECRYPT_MESSAGE', { ciphertextWireBase64: enc2.ciphertextWireBase64 });
  assert.equal(dec2.plaintext, msg2Plain);
});

// ===========================================================================
// TEST 3: Fail-Closed Protection — Device with existing active GroupState FOUND cannot overwrite via initiateNextSessionHandshakeFlow
// ===========================================================================
test('3. Pengaman Operasional: Perangkat dengan GroupState FOUND ditolak masuk jalur inisiasi sesi baru (fail-closed)', async () => {
  harness.reset();
  const mlsContext = await buildMlsContext();
  const roomUuid = crypto.randomUUID();
  const devUuid = crypto.randomUUID();
  const fencing = { ownerToken: 'tab_op_guard', generation: '1' };

  const worker = createRealMlsWorkerPeer({
    role: 'mahasiswa',
    identity: 'mhs.existing@unuha.test',
    deviceUuid: devUuid,
    mlsContext,
  });
  const partnerWorker = createRealMlsWorkerPeer({
    role: 'dosen',
    identity: 'dosen.existing@unuha.test',
    deviceUuid: crypto.randomUUID(),
    mlsContext,
  });
  const partnerKp = await partnerWorker.generateAndRegisterKeyPackage();

  // Simulate Device4 on Room1: ALREADY has an active epoch 1 GroupState (no pending outbox)
  await worker.sendRequest('GENERATE_KEY_PACKAGE', { deviceUuid: devUuid, fencing });
  await worker.sendRequest('CREATE_GROUP', { roomUuid, deviceUuid: devUuid, fencing });
  const commitRes = await worker.sendRequest('COMMIT_ADD_MEMBER', {
    roomUuid,
    deviceUuid: devUuid,
    partnerKeyPackageBase64: partnerKp.key_package_data,
    fencing,
  });
  await worker.sendRequest('UPDATE_OUTBOX_STATUS', { clientMessageUuid: commitRes.clientMessageUuidCommit, status: 'acked' });
  await worker.sendRequest('UPDATE_OUTBOX_STATUS', { clientMessageUuid: commitRes.clientMessageUuidWelcome, status: 'acked' });

  harness.rooms.set(roomUuid, {
    initiatorUserId: 1,
    groupIdHash: crypto.createHash('sha256').update(roomUuid).digest('hex'),
    currentSequence: 16,
    activeSession: { session_uuid: crypto.randomUUID(), generation_number: 2, status: 'ACTIVE', start_sequence: 11, current_sequence: 16 },
    pendingSession: null,
    archivedSessions: [],
    activeChallenge: null,
    partnerKeyPackage: {
      recipient_user_id: '2',
      recipient_device_uuid: crypto.randomUUID(),
      key_package_hash: partnerKp.key_package_hash,
      key_package_data: partnerKp.key_package_data,
    },
    createNextCalls: 0,
    welcomePublishCalls: 0,
    buildDetail() {
      return {
        room_uuid: roomUuid,
        status: 'ACTIVE',
        my_membership: { role: 'mahasiswa', is_initiator: true, is_active: true, joined_at_epoch: 1 },
        partner: { name: 'Dosen', role: 'dosen_pembimbing', has_active_device: true, has_key_package: true, is_active: true },
        action_hint: { action_required: false, action_type: 'NONE' },
        handshake_context: { has_active_challenge: false, welcome_operation_uuid: null, epoch: null, action_required: false, action_type: 'NONE' },
        capabilities: { can_send_messages: true, can_read_messages: true, can_handshake: false },
        active_session: this.activeSession,
        pending_session: this.pendingSession,
      };
    },
  });

  await assert.rejects(
    () => initiateNextSessionHandshakeFlow(worker, roomUuid, devUuid, { fencing }),
    (err) => err?.errorCode === 'ACTIVE_GROUP_STATE_ALREADY_EXISTS'
  );
  // Pastikan tidak pernah menciptakan sesi PENDING baru saat fail-closed!
  assert.equal(harness.rooms.get(roomUuid).createNextCalls, 0);
});

// ===========================================================================
// TEST 4: Static Contract Audit on BchatDeviceOnboarding.tsx & BchatProposalChat.tsx
// ===========================================================================
test('4. Kontrak Onboarding & UI Flow: anti double-init, trust-gated KeyPackage, 3 cabang GroupState, & tanpa fallback UUID', () => {
  const onboardingSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatDeviceOnboarding.tsx'),
    'utf8'
  );
  const chatSrc = fs.readFileSync(
    path.join(SRC_ROOT, 'components/bchat/BchatProposalChat.tsx'),
    'utf8'
  );

  // 1. Onboarding uses single-notify guard & removes duplicate DEVICE_READY useEffect
  assert.match(onboardingSrc, /notifiedReadyDeviceRef/);
  assert.doesNotMatch(onboardingSrc, /if \(phase === 'DEVICE_READY' && registeredDeviceUuid\)/);

  // 2. KeyPackage publication obeys is_trusted check
  assert.match(onboardingSrc, /if \(!result\.isTrusted\)/);
  assert.match(onboardingSrc, /if \(matchingActiveDevice\.is_trusted === false\)/);

  // 3. No unvalidated fallback `|| devStatusRes.data.active_device` in BchatProposalChat
  assert.doesNotMatch(chatSrc, /\|\|\s*devStatusRes\.data\.active_device/);

  // 4. BchatProposalChat does not double-invoke initializeChatSession inside onDeviceReady
  const onDeviceReadyBlock = chatSrc.match(/onDeviceReady=\{\(devUuid\) => \{[\s\S]*?\}\}/)?.[0] || '';
  assert.ok(onDeviceReadyBlock.includes("setOnboardingGate('PASSED')"));
  assert.ok(!onDeviceReadyBlock.includes('initializeChatSession()'), 'onDeviceReady tidak boleh memanggil initializeChatSession() ganda');

  // 5. BchatProposalChat distinguishes FOUND, HAS_TRANSFER_SOURCE, and FIRST_DEVICE_INIT_MAHASISWA + Dosen waiting state
  assert.match(chatSrc, /FIRST_DEVICE_INIT_MAHASISWA/);
  assert.match(chatSrc, /HAS_TRANSFER_SOURCE/);
  assert.match(chatSrc, /Inisiasi Sesi Aman Baru/);
  assert.match(chatSrc, /Menunggu Inisiasi Sesi Aman Mahasiswa/);

  // 6. Polling never calls createNextRoomSession or initiateNextSessionHandshakeFlow
  const pollingBlock = chatSrc.match(/const startWaitingPolling = [\s\S]*?5000\);\s*\};/)?.[0] || '';
  assert.ok(pollingBlock.length > 0);
  assert.ok(!pollingBlock.includes('createNextRoomSession'));
  assert.ok(!pollingBlock.includes('initiateNextSessionHandshakeFlow'));

  // 7. History transfer polling uses gated eligibility helper, single ref timer, in-flight guard, and cleanup
  assert.match(chatSrc, /shouldPollIncomingHistoryTransfers\(/);
  assert.match(chatSrc, /historyTransferPollingTimerRef/);
  assert.match(chatSrc, /historyTransferInFlightRef/);
  assert.match(chatSrc, /PENDING_WELCOME_RECOVERY/);
  assert.match(chatSrc, /evaluateActiveRoomFoundState\(/);
});

// ===========================================================================
// TEST 5: Polling History Transfer Eligibility & Anti-Duplicate Lifecycle Guard
// ===========================================================================
test('5. Polling history-transfers/pending tidak berjalan pada First Device Init & hanya aktif pada perangkat sumber eligible', () => {
  const baseEligible = {
    deviceUuid: 'dfac2a29-72ac-4809-90f0-b97b26b7fe63',
    userId: 4,
    onboardingGate: 'PASSED',
    connectionStatus: 'CONNECTED',
    hasTransferSourceDevices: true,
    hasLocalGroupState: true,
    isUnrecoverableActiveRoom: false,
    hasWaitingState: false,
    isHistoryTransferModalOpen: false,
    hasIncomingTransferRequest: false,
  };

  // Eligible source device (sudah terhubung, punya GroupState lokal, dan punya peer tepercaya lain) -> BOLEH polling
  assert.equal(shouldPollIncomingHistoryTransfers(baseEligible), true);

  // First Device Initialization (mahasiswa tanpa perangkat sumber / tanpa GroupState) -> DILARANG polling
  assert.equal(
    shouldPollIncomingHistoryTransfers({
      ...baseEligible,
      hasTransferSourceDevices: false,
      hasLocalGroupState: false,
      isUnrecoverableActiveRoom: true,
    }),
    false
  );

  // Perangkat tunggal tanpa peer lain (tidak ada target/sumber History Transfer) -> DILARANG polling
  assert.equal(
    shouldPollIncomingHistoryTransfers({ ...baseEligible, hasTransferSourceDevices: false }),
    false
  );

  // Partial state recovery (PENDING_WELCOME_RECOVERY) atau layar tunggu (AWAIT_SESSION_WELCOME) -> DILARANG polling
  assert.equal(
    shouldPollIncomingHistoryTransfers({ ...baseEligible, isUnrecoverableActiveRoom: true, hasLocalGroupState: false }),
    false
  );
  assert.equal(
    shouldPollIncomingHistoryTransfers({ ...baseEligible, hasWaitingState: true, hasLocalGroupState: false }),
    false
  );

  // Onboarding / Pairing / Initializing / Error -> DILARANG polling
  assert.equal(shouldPollIncomingHistoryTransfers({ ...baseEligible, onboardingGate: 'ONBOARDING' }), false);
  assert.equal(shouldPollIncomingHistoryTransfers({ ...baseEligible, onboardingGate: 'PAIRING' }), false);
  assert.equal(shouldPollIncomingHistoryTransfers({ ...baseEligible, connectionStatus: 'INITIALIZING' }), false);
  assert.equal(shouldPollIncomingHistoryTransfers({ ...baseEligible, connectionStatus: 'ERROR' }), false);

  // Saat modal transfer sudah terbuka atau permintaan masuk sudah ditampilkan -> jeda polling
  assert.equal(shouldPollIncomingHistoryTransfers({ ...baseEligible, isHistoryTransferModalOpen: true }), false);
  assert.equal(shouldPollIncomingHistoryTransfers({ ...baseEligible, hasIncomingTransferRequest: true }), false);
});

// ===========================================================================
// TEST 6: Gen 3 PENDING + Welcome Outbox Failed (HTTP 429) Recovery & Idempotency
// ===========================================================================
test('6. Pemulihan Gen 3 PENDING + outbox Welcome failed akibat 429 memakai UUID & Welcome asli tanpa Gen 4 / regenerasi', async () => {
  harness.reset();
  const mlsContext = await buildMlsContext();

  const roomUuid = crypto.randomUUID();
  const gen2SessionUuid = crypto.randomUUID();
  const gen3SessionUuid = crypto.randomUUID();
  const mhsDeviceUuid = crypto.randomUUID();
  const dosenDeviceUuid = crypto.randomUUID();
  const mhsUserId = 401;
  const dosenUserId = 402;
  const fencing = { ownerToken: 'tab_gen3_recovery', generation: '1' };

  const dosenEd25519 = await crypto.webcrypto.subtle.generateKey('Ed25519', false, ['sign']);
  const dosenPubRaw = new Uint8Array(await crypto.webcrypto.subtle.exportKey('raw', dosenEd25519.publicKey));
  harness.deviceIdentityKeys.set(dosenDeviceUuid, {
    key_id: `identity_key_${dosenDeviceUuid}`,
    key_version: 1,
    status: 'active',
    algorithm: 'Ed25519',
    crypto_key: dosenEd25519.privateKey,
    created_at: Date.now(),
    metadata: { key_type: 'DEVICE_IDENTITY', device_uuid: dosenDeviceUuid, user_id: String(dosenUserId) },
  });

  const mhsWorker = createRealMlsWorkerPeer({
    role: 'mahasiswa',
    identity: 'mhs.gen3@unuha.test',
    deviceUuid: mhsDeviceUuid,
    mlsContext,
  });
  const dosenWorker = createRealMlsWorkerPeer({
    role: 'dosen',
    identity: 'dosen.gen3@unuha.test',
    deviceUuid: dosenDeviceUuid,
    mlsContext,
  });
  const dosenKp = await dosenWorker.generateAndRegisterKeyPackage();

  // Simulasikan kondisi partial Gen 3 yang persis terjadi di browser:
  // GroupState lokal epoch 1 sudah terbentuk + Commit acked + Welcome outbox berstatus 'failed' akibat HTTP 429 sebelumnya.
  await mhsWorker.sendRequest('GENERATE_KEY_PACKAGE', { deviceUuid: mhsDeviceUuid, fencing });
  await mhsWorker.sendRequest('CREATE_GROUP', { roomUuid, deviceUuid: mhsDeviceUuid, fencing });
  const initialCommit = await mhsWorker.sendRequest('COMMIT_ADD_MEMBER', {
    roomUuid,
    deviceUuid: mhsDeviceUuid,
    partnerKeyPackageBase64: dosenKp.key_package_data,
    fencing,
  });
  await mhsWorker.sendRequest('UPDATE_OUTBOX_STATUS', {
    clientMessageUuid: initialCommit.clientMessageUuidCommit,
    status: 'acked',
  });
  await mhsWorker.sendRequest('UPDATE_OUTBOX_STATUS', {
    clientMessageUuid: initialCommit.clientMessageUuidWelcome,
    status: 'failed', // status akibat 429 sebelumnya
  });
  const originalWelcomeWire = initialCommit.welcomeWireBase64;
  const originalGroupIdHash = mhsWorker.getPersistedState().groupIdHash;
  mhsWorker.resetStats();

  const roomState = {
    initiatorUserId: mhsUserId,
    groupIdHash: originalGroupIdHash,
    currentSequence: 16,
    activeSession: {
      session_uuid: gen2SessionUuid,
      generation_number: 2,
      status: 'ACTIVE',
      start_sequence: 11,
      current_sequence: 16,
    },
    pendingSession: {
      session_uuid: gen3SessionUuid,
      generation_number: 3,
      status: 'PENDING',
      start_sequence: 17,
      current_sequence: 16,
      created_by_user_id: mhsUserId,
    },
    archivedSessions: [],
    activeChallenge: null,
    partnerKeyPackage: {
      recipient_user_id: String(dosenUserId),
      recipient_device_uuid: dosenDeviceUuid,
      key_package_hash: dosenKp.key_package_hash,
      key_package_data: dosenKp.key_package_data,
    },
    createNextCalls: 0,
    welcomePublishCalls: 0,
    failNextWelcomeWith429: 1, // Percobaan retry pertama terkena transient HTTP 429
    currentViewerRole: 'mahasiswa',
    async verifyAckSignature(challenge, sigBase64) {
      const built = buildWelcomeAckPayload({
        roomUuid,
        recipientUserId: dosenUserId,
        welcomeOperationUuid: challenge.welcome_operation_uuid,
        challengeUuid: challenge.challenge_uuid,
        challengeGeneration: challenge.challenge_generation,
        groupIdHash: challenge.group_id_hash,
        epoch: BigInt(1),
        challengeNonce: challenge.challenge_nonce,
      });
      const sigBytes = new Uint8Array(Buffer.from(sigBase64, 'base64'));
      const pubKey = await crypto.webcrypto.subtle.importKey('raw', dosenPubRaw, 'Ed25519', false, ['verify']);
      return crypto.webcrypto.subtle.verify('Ed25519', pubKey, sigBytes, built.payloadBytes);
    },
    buildDetail() {
      const hasChal = Boolean(this.activeChallenge);
      let pendingActionType = 'NONE';
      let pendingActionReq = false;
      if (this.pendingSession) {
        if (!hasChal) {
          pendingActionType = this.currentViewerRole === 'mahasiswa' ? 'PUBLISH_SESSION_WELCOME' : 'AWAIT_PARTNER_SESSION_WELCOME';
          pendingActionReq = this.currentViewerRole === 'mahasiswa';
        } else {
          pendingActionType = this.currentViewerRole === 'dosen' ? 'FETCH_SESSION_CHALLENGE_AND_ACK' : 'AWAIT_PARTNER_SESSION_ACK';
          pendingActionReq = this.currentViewerRole === 'dosen';
        }
      }
      return {
        room_uuid: roomUuid,
        status: 'ACTIVE',
        group_id_hash: this.groupIdHash,
        my_membership: { role: this.currentViewerRole, is_initiator: this.currentViewerRole === 'mahasiswa', is_active: true, joined_at_epoch: 1 },
        partner: { name: 'Mitra', role: 'dosen_pembimbing', has_active_device: true, has_key_package: true, is_active: true },
        action_hint: { action_required: false, action_type: 'NONE' },
        handshake_context: { has_active_challenge: false, welcome_operation_uuid: null, epoch: null, action_required: false, action_type: 'NONE' },
        capabilities: { can_send_messages: true, can_read_messages: true, can_handshake: false },
        active_session: this.activeSession,
        pending_session: this.pendingSession
          ? {
              ...this.pendingSession,
              has_active_challenge: hasChal,
              welcome_operation_uuid: this.activeChallenge?.welcome_operation_uuid ?? null,
              recipient_device_uuid: this.activeChallenge?.recipient_device_uuid ?? null,
              expires_at: this.activeChallenge?.expires_at ?? null,
              action_required: pendingActionReq,
              action_type: pendingActionType,
            }
          : null,
      };
    },
  };
  harness.rooms.set(roomUuid, roomState);

  // 1. Percobaan pertama kena transient HTTP 429 -> dibedakan dari kegagalan permanen!
  let rateLimitErr = null;
  try {
    await initiateNextSessionHandshakeFlow(mhsWorker, roomUuid, mhsDeviceUuid, { fencing });
  } catch (err) {
    rateLimitErr = err;
  }
  assert.ok(rateLimitErr, 'Harus melempar error saat HTTP 429');
  assert.equal(rateLimitErr.errorCode, 'SESSION_WELCOME_RATE_LIMITED', 'Error 429 harus dibedakan dari permanent SESSION_WELCOME_PUBLISH_FAILED');
  assert.equal(isRateLimitPublishError(rateLimitErr.originalError), true);
  assert.equal(rateLimitErr.context?.retryAfterSeconds, 45);
  assert.equal(rateLimitErr.context?.sessionUuid, gen3SessionUuid);
  // Outbox Welcome tetap tersimpan dengan status 'pending' (transient, bukan failed permanen)
  const welcomeAfter429 = mhsWorker.getOutbox().find((m) => m.messageType === 'welcome');
  assert.equal(welcomeAfter429?.status, 'pending');
  assert.equal(welcomeAfter429?.ciphertextWireBase64, originalWelcomeWire);
  // ZERO regenerasi GroupState/Commit/Welcome dan ZERO pembuatan Gen 4!
  assert.equal(roomState.createNextCalls, 0);
  assert.deepEqual(mhsWorker.getStats(), { generateKpCalls: 0, createGroupCalls: 0, commitCalls: 0 });

  // 2. Retry kedua lolos -> WAJIB memakai Gen 3 UUID & Welcome ciphertext asli
  const recovered = await initiateNextSessionHandshakeFlow(mhsWorker, roomUuid, mhsDeviceUuid, { fencing });
  assert.equal(recovered.status, 'AWAITING_ACK');
  assert.equal(recovered.sessionUuid, gen3SessionUuid, 'Recovery wajib memakai session UUID Gen 3 yang sama');
  assert.equal(recovered.generationNumber, 3);
  assert.equal(roomState.createNextCalls, 0, 'Retry tidak boleh menciptakan Gen 4');
  assert.equal(roomState.welcomePublishCalls, 1, 'Welcome hanya dipublikasikan 1 kali');
  assert.equal(roomState.activeChallenge.welcome_message, originalWelcomeWire, 'Payload Welcome harus identik dengan outbox asli');
  assert.deepEqual(mhsWorker.getStats(), { generateKpCalls: 0, createGroupCalls: 0, commitCalls: 0 });
  assert.equal(mhsWorker.getOutbox().find((m) => m.messageType === 'welcome')?.status, 'acked');

  // 3. Retry ketiga (idempoten) saat challenge sudah aktif -> ZERO duplikasi Welcome & ZERO Gen 4
  const idempotentRetry = await initiateNextSessionHandshakeFlow(mhsWorker, roomUuid, mhsDeviceUuid, { fencing });
  assert.equal(idempotentRetry.status, 'AWAITING_ACK');
  assert.equal(idempotentRetry.sessionUuid, gen3SessionUuid);
  assert.equal(roomState.createNextCalls, 0);
  assert.equal(roomState.welcomePublishCalls, 1, 'Retry idempoten tidak boleh mengirim Welcome duplikat');

  // 4. Buktikan Welcome hasil recovery valid secara kriptografis (Dosen JOIN + ACK + chat dua arah)
  roomState.currentViewerRole = 'dosen';
  const ackRes = await completePendingSessionHandshakeFlow(dosenWorker, roomUuid, dosenDeviceUuid, {
    fencing,
    userId: dosenUserId,
    expectedSessionUuid: gen3SessionUuid,
  });
  assert.equal(ackRes.sessionStatus, 'ACTIVE');
  assert.equal(ackRes.generationNumber, 3);

  const enc = await mhsWorker.sendRequest('ENCRYPT_MESSAGE', { plaintext: 'Pesan Gen 3 pasca recovery 429!' });
  const dec = await dosenWorker.sendRequest('DECRYPT_MESSAGE', { ciphertextWireBase64: enc.ciphertextWireBase64 });
  assert.equal(dec.plaintext, 'Pesan Gen 3 pasca recovery 429!');
});

// ===========================================================================
// TEST 7: Reload Guard (evaluateActiveRoomFoundState) & Fail-Closed Missing/Corrupt Outbox
// ===========================================================================
test('7. Reload pada partial state Gen 3 memblokir chat normal; outbox Welcome hilang/korup fail-closed tanpa sesi pengganti', async () => {
  harness.reset();
  const mlsContext = await buildMlsContext();
  const roomUuid = crypto.randomUUID();
  const gen3SessionUuid = crypto.randomUUID();
  const devUuid = crypto.randomUUID();
  const fencing = { ownerToken: 'tab_reload_guard', generation: '1' };
  const validGroupIdHash = crypto.createHash('sha256').update(roomUuid).digest('hex');
  const validWire = Buffer.alloc(64, 0xab).toString('base64');

  const pendingGen3 = {
    session_uuid: gen3SessionUuid,
    generation_number: 3,
    status: 'PENDING',
    start_sequence: 17,
    current_sequence: 16,
    created_by_user_id: 4,
    has_active_challenge: false,
    welcome_operation_uuid: null,
    recipient_device_uuid: null,
    expires_at: null,
    action_required: true,
    action_type: 'PUBLISH_SESSION_WELCOME',
  };

  // 7a. Reload dengan outbox Welcome status 'failed' (akibat 429) -> RECOVER_PENDING_SESSION_WELCOME (BUKAN normal chat!)
  const decisionRecover = evaluateActiveRoomFoundState({
    roomUuid,
    myRole: 'mahasiswa',
    pendingSession: pendingGen3,
    restoreRes: { status: 'FOUND', epoch: '1', groupIdHash: validGroupIdHash, lastProcessedSequence: 0 },
    localHistoryCount: 0,
    outboxMessages: [
      {
        clientMessageUuid: crypto.randomUUID(),
        roomUuid,
        status: 'failed',
        ciphertextWireBase64: validWire,
        createdAt: Date.now(),
        messageType: 'welcome',
      },
    ],
  });
  assert.equal(decisionRecover.action, 'RECOVER_PENDING_SESSION_WELCOME');
  assert.equal(decisionRecover.sessionUuid, gen3SessionUuid);
  assert.equal(decisionRecover.generationNumber, 3);

  // 7b. Reload dengan outbox Welcome HILANG -> FAIL_CLOSED_MISSING_PENDING_WELCOME
  const decisionMissing = evaluateActiveRoomFoundState({
    roomUuid,
    myRole: 'mahasiswa',
    pendingSession: pendingGen3,
    restoreRes: { status: 'FOUND', epoch: '1', groupIdHash: validGroupIdHash, lastProcessedSequence: 0 },
    localHistoryCount: 0,
    outboxMessages: [],
  });
  assert.equal(decisionMissing.action, 'FAIL_CLOSED_MISSING_PENDING_WELCOME');

  // 7c. Reload dengan outbox Welcome KORUP (bukan base64 valid / terlalu pendek) -> FAIL_CLOSED
  assert.equal(
    findRecoverablePendingWelcomeOutbox(
      [
        {
          clientMessageUuid: crypto.randomUUID(),
          roomUuid,
          status: 'failed',
          ciphertextWireBase64: '!!!corrupt!!!',
          createdAt: Date.now(),
          messageType: 'welcome',
        },
      ],
      roomUuid
    ),
    null
  );

  // 7d. Pemanggilan langsung coordinator saat outbox hilang + Gen 3 PENDING + GroupState FOUND (epoch 1, seq 0) -> fail-closed PENDING_WELCOME_OUTBOX_MISSING tanpa buat sesi baru!
  const worker = createRealMlsWorkerPeer({
    role: 'mahasiswa',
    identity: 'mhs.corrupt@unuha.test',
    deviceUuid: devUuid,
    mlsContext,
  });
  const partnerWorker = createRealMlsWorkerPeer({
    role: 'dosen',
    identity: 'dosen.corrupt@unuha.test',
    deviceUuid: crypto.randomUUID(),
    mlsContext,
  });
  const partnerKp = await partnerWorker.generateAndRegisterKeyPackage();
  await worker.sendRequest('GENERATE_KEY_PACKAGE', { deviceUuid: devUuid, fencing });
  await worker.sendRequest('CREATE_GROUP', { roomUuid, deviceUuid: devUuid, fencing });
  await worker.sendRequest('COMMIT_ADD_MEMBER', {
    roomUuid,
    deviceUuid: devUuid,
    partnerKeyPackageBase64: partnerKp.key_package_data,
    fencing,
  });
  worker.clearOutbox(); // Simulasikan outbox Welcome hilang/tidak dapat diverifikasi
  worker.resetStats();

  const roomState = {
    initiatorUserId: 4,
    groupIdHash: validGroupIdHash,
    currentSequence: 16,
    activeSession: { session_uuid: crypto.randomUUID(), generation_number: 2, status: 'ACTIVE', start_sequence: 11, current_sequence: 16 },
    pendingSession: pendingGen3,
    activeChallenge: null,
    partnerKeyPackage: {
      recipient_user_id: '5',
      recipient_device_uuid: crypto.randomUUID(),
      key_package_hash: partnerKp.key_package_hash,
      key_package_data: partnerKp.key_package_data,
    },
    createNextCalls: 0,
    welcomePublishCalls: 0,
    buildDetail() {
      return {
        room_uuid: roomUuid,
        status: 'ACTIVE',
        group_id_hash: validGroupIdHash,
        my_membership: { role: 'mahasiswa', is_initiator: true, is_active: true, joined_at_epoch: 1 },
        partner: { name: 'Dosen', role: 'dosen_pembimbing', has_active_device: true, has_key_package: true, is_active: true },
        action_hint: { action_required: false, action_type: 'NONE' },
        handshake_context: { has_active_challenge: false, welcome_operation_uuid: null, epoch: null, action_required: false, action_type: 'NONE' },
        capabilities: { can_send_messages: true, can_read_messages: true, can_handshake: false },
        active_session: this.activeSession,
        pending_session: this.pendingSession,
      };
    },
  };
  harness.rooms.set(roomUuid, roomState);

  await assert.rejects(
    () => initiateNextSessionHandshakeFlow(worker, roomUuid, devUuid, { fencing }),
    (err) => err?.errorCode === 'PENDING_WELCOME_OUTBOX_MISSING'
  );
  assert.equal(roomState.createNextCalls, 0, 'Fail-closed tidak boleh membuat sesi pengganti otomatis');
  assert.equal(roomState.welcomePublishCalls, 0);
  assert.deepEqual(worker.getStats(), { generateKpCalls: 0, createGroupCalls: 0, commitCalls: 0 });
});

// ===========================================================================
// TEST 8: Normal Active Session (GroupState FOUND dengan riwayat/cursor > 0) Tidak Memicu Inisiasi Baru
// ===========================================================================
test('8. GroupState FOUND pada sesi normal operasional (mis. Device 4 Gen 2) selalu lanjut chat normal tanpa memicu recovery/sesi baru', () => {
  const roomUuid = '7b1288fd-fe03-4a88-87fe-980c5741df18';
  const validGroupIdHash = 'e8b3c602f68b8d99be2e5a35d6e290c8b497f9bcc80c7dd773e39fed5015a0aa';

  // Kasus A: Room ACTIVE tanpa pending_session
  const decisionNormal = evaluateActiveRoomFoundState({
    roomUuid,
    myRole: 'mahasiswa',
    pendingSession: null,
    restoreRes: { status: 'FOUND', epoch: '1', groupIdHash: validGroupIdHash, lastProcessedSequence: 16 },
    localHistoryCount: 16,
    outboxMessages: [],
  });
  assert.equal(decisionNormal.action, 'PROCEED_NORMAL_CHAT');

  // Kasus B: Device 4 operasional (seq 16, 16 pesan) tetap masuk chat normal Gen 2 meski ada Gen 3 PENDING di server
  const decisionOperationalWithPending = evaluateActiveRoomFoundState({
    roomUuid,
    myRole: 'mahasiswa',
    pendingSession: {
      session_uuid: '573b150e-bdb3-48dd-ab32-e01dda5d0332',
      generation_number: 3,
      status: 'PENDING',
      start_sequence: 17,
      current_sequence: 16,
      created_by_user_id: 4,
      has_active_challenge: false,
      welcome_operation_uuid: null,
      recipient_device_uuid: null,
      expires_at: null,
      action_required: true,
      action_type: 'PUBLISH_SESSION_WELCOME',
    },
    restoreRes: { status: 'FOUND', epoch: '1', groupIdHash: validGroupIdHash, lastProcessedSequence: 16 },
    localHistoryCount: 16,
    outboxMessages: [],
  });
  assert.equal(decisionOperationalWithPending.action, 'PROCEED_NORMAL_CHAT');
});

