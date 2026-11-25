/**
 * B-CHAT E2EE — Dedicated Browser Web Worker PoC
 *
 * Worker ini mengisolasi seluruh operasi Messaging Layer Security (MLS) RFC 9420:
 * - Menampung pasangan kunci privat (PrivateKeyPackage) & ClientState di dalam closure memori terisolasi.
 * - Berkomunikasi dengan Main Thread murni melalui antarmuka RPC typed message.
 * - Tidak pernah mengekspos ClientState atau private keys ke Main Thread.
 * - Mematuhi kebijakan MUST NOT ZERO terhadap buffer consumed.
 * - Menggunakan ciphersuite MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519 berbasis native WebCrypto & @hpke/core
 *   tanpa memuat peer dependency opsional yang tidak terpasang.
 */

// Polyfill global window di lingkungan Worker agar modul IDB core / storage yang diimpor
// dapat mengakses self.indexedDB dan self.crypto secara transparan.
if (typeof self !== 'undefined' && typeof (self as any).window === 'undefined') {
  (self as any).window = self;
}

import { createGroup, joinGroup, type ClientState } from '../../node_modules/ts-mls/dist/src/clientState.js';
import {
  generateKeyPackage,
  keyPackageEncoder,
  keyPackageDecoder,
  type KeyPackage,
  type PrivateKeyPackage,
} from '../../node_modules/ts-mls/dist/src/keyPackage.js';
import { createCommit } from '../../node_modules/ts-mls/dist/src/createCommit.js';
import { createApplicationMessage } from '../../node_modules/ts-mls/dist/src/createMessage.js';
import { processMessage } from '../../node_modules/ts-mls/dist/src/processMessages.js';
import { mlsMessageEncoder, mlsMessageDecoder } from '../../node_modules/ts-mls/dist/src/message.js';
import { mlsExporter } from '../../node_modules/ts-mls/dist/src/keySchedule.js';
import { acceptAll } from '../../node_modules/ts-mls/dist/src/incomingMessageAction.js';
import { encode } from '../../node_modules/ts-mls/dist/src/codec/tlsEncoder.js';
import { decode } from '../../node_modules/ts-mls/dist/src/codec/tlsDecoder.js';
import { defaultCredentialTypes } from '../../node_modules/ts-mls/dist/src/defaultCredentialType.js';
import { defaultProposalTypes } from '../../node_modules/ts-mls/dist/src/defaultProposalType.js';
import { wireformats } from '../../node_modules/ts-mls/dist/src/wireformat.js';
import { bytesToBase64, base64ToBytes } from '../../node_modules/ts-mls/dist/src/util/byteArray.js';
import { unsafeTestingAuthenticationService } from '../../node_modules/ts-mls/dist/src/authenticationService.js';
import { defaultClientConfig } from '../../node_modules/ts-mls/dist/src/clientConfig.js';
import type { MlsContext } from '../../node_modules/ts-mls/dist/src/mlsContext.js';
import type { CiphersuiteImpl } from '../../node_modules/ts-mls/dist/src/crypto/ciphersuite.js';
import { makeGenericHpke } from '../../node_modules/ts-mls/dist/src/crypto/implementation/hpke.js';
import { makeKdf, makeKdfImpl } from '../../node_modules/ts-mls/dist/src/crypto/implementation/default/makeKdfImpl.js';
import { makeHashImpl } from '../../node_modules/ts-mls/dist/src/crypto/implementation/default/makeHashImpl.js';
import { defaultRng } from '../../node_modules/ts-mls/dist/src/crypto/implementation/default/rng.js';
import { CipherSuite, DhkemX25519HkdfSha256, HkdfSha256, Aes128Gcm } from '@hpke/core';

import {
  restoreGroupState,
  persistGroupState,
  persistGroupStateAndOutbox,
  persistGroupStateAndProtocolOutbox,
  savePendingKeyPackage,
  getPendingPrivateKeyPackage,
  listPendingKeyPackages,
  joinGroupAndConsumePackage,
  getOutboxMessage,
  updateOutboxMessageStatus,
  claimOutboxMessage,
  getOutboxMessagesByRoom,
  generateCryptoRandomUuid,
  type FencingCredentials,
} from '@/lib/bchat/storage/bchat-mls-persistence';
import {
  BCAT_STORES,
  type TabLeaseRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import { idbGet, idbPut } from '@/lib/bchat/storage/bchat-idb-core';
import { normalizeCanonicalUuid } from '@/lib/bchat/storage/bchat-storage-aad';

import type {
  WorkerRpcRequest,
  WorkerRpcResponse,
  WorkerPeerRole,
  InitPeerPayload,
  InitPeerResult,
  GenerateKeyPackagePayload,
  GenerateKeyPackageResult,
  CreateGroupPayload,
  CreateGroupResult,
  CommitAddMemberPayload,
  CommitAddMemberResult,
  JoinWelcomePayload,
  JoinWelcomeResult,
  ProcessCommitPayload,
  ProcessCommitResult,
  EncryptMessagePayload,
  EncryptMessageResult,
  DecryptMessagePayload,
  DecryptMessageResult,
  RestoreGroupStatePayload,
  RestoreGroupStateResult,
  RetryOutboxMessagePayload,
  RetryOutboxMessageResult,
  UpdateOutboxStatusPayload,
  UpdateOutboxStatusResult,
  ClaimOutboxMessagePayload,
  ClaimOutboxMessageResult,
  GetRoomOutboxPayload,
  GetRoomOutboxResult,
  DeriveAttachmentKeyPayload,
  DeriveAttachmentKeyResult,
  OutboxItemSummary,
} from '@/lib/bchat/bchat-poc-types';

// State internal Worker (tersimpan murni di memori worker, tidak pernah keluar)
let currentRole: WorkerPeerRole | null = null;
let currentIdentity: string | null = null;
let mlsContext: MlsContext | null = null;
let localKeyPackage: {
  publicPackage: KeyPackage;
  privatePackage: PrivateKeyPackage;
} | null = null;
let clientState: ClientState | null = null;
let currentDeviceUuid: string | null = null;
let currentRoomUuid: string | null = null;
let currentEpoch: string | null = null;
let currentStateRevision: string | null = null;
let currentGroupIdHash: string | null = null;
let currentFencing: FencingCredentials | null = null;

// FAIL-CLOSED SESSION LOCK STATE:
// Mengunci seluruh operasi MLS di memori Worker jika terjadi kegagalan persistensi.
let isSessionLocked = false;
let sessionLockReason: string | null = null;

function lockSession(reason: string): void {
  isSessionLocked = true;
  sessionLockReason = reason;
  clearWorkerGroupSession();
}

function unlockSession(): void {
  isSessionLocked = false;
  sessionLockReason = null;
}

/**
 * Membersihkan seluruh state sesi grup dari memori Worker (Zero State Retention).
 * Wajib dipanggil saat restore gagal, dibatalkan, atau terdeteksi stale lease.
 */
function clearWorkerGroupSession(): void {
  clientState = null;
  currentDeviceUuid = null;
  currentRoomUuid = null;
  currentEpoch = null;
  currentStateRevision = null;
  currentGroupIdHash = null;
  currentFencing = null;
}

function areUint8ArraysEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.byteLength !== b.byteLength) return false;
  for (let i = 0; i < a.byteLength; i++) {
    if (a[i] !== b[i]) return false;
  }
  return true;
}

const multiDeviceClientConfig = {
  ...defaultClientConfig,
  keyPackageEqualityConfig: {
    compareKeyPackages(a: any, b: any) {
      return areUint8ArraysEqual(a.leafNode.signaturePublicKey, b.leafNode.signaturePublicKey);
    },
    compareKeyPackageToLeafNode(a: any, b: any) {
      return areUint8ArraysEqual(a.leafNode.signaturePublicKey, b.signaturePublicKey);
    },
  },
};

/**
 * Konversi string UUID kanonis (36 karakter bertanda hubung) ke 16 byte biner mentah (Big-Endian).
 */
function uuidStringTo16Bytes(uuid: string): Uint8Array {
  const clean = uuid.replace(/-/g, '').toLowerCase();
  if (clean.length !== 32 || !/^[0-9a-f]{32}$/.test(clean)) {
    throw new Error(`Format string UUID tidak valid: '${uuid}' (wajib 32 hex chars / RFC 4122)`);
  }
  const bytes = new Uint8Array(16);
  for (let i = 0; i < 16; i++) {
    bytes[i] = parseInt(clean.substring(i * 2, i * 2 + 2), 16);
  }
  return bytes;
}

/**
 * Menyalin byte dari Uint8Array ke ArrayBuffer biasa untuk parameter W3C WebCrypto API
 * yang mensyaratkan BufferSource dengan ArrayBuffer murni (non-Shared).
 *
 * Menjamin kompatibilitas tipe BufferSource tanpa type assertion (as BufferSource),
 * serta memastikan panjang byte dan isi data tidak berubah sama sekali.
 */
function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  const ab = new ArrayBuffer(bytes.byteLength);
  new Uint8Array(ab).set(bytes);
  return ab;
}

/**
 * Konversi kunci privat mentah Ed25519 (32 byte) ke format PKCS#8 ASN.1 DER baku (RFC 8410)
 * agar dapat diimpor langsung oleh W3C WebCrypto API (subtle.importKey('pkcs8', ...)).
 */
function rawEd25519ToPKCS8(rawKey: Uint8Array): Uint8Array {
  const prefix = new Uint8Array([
    0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x04, 0x22, 0x04, 0x20,
  ]);
  const pkcs8 = new Uint8Array(prefix.length + rawKey.length);
  pkcs8.set(prefix, 0);
  pkcs8.set(rawKey, prefix.length);
  return pkcs8;
}

/**
 * Pembangun primitif Signature Ed25519 berbasis native W3C WebCrypto API (crypto.subtle).
 */
function createWebCryptoEd25519Signature(subtle: SubtleCrypto) {
  return {
    async sign(signKey: Uint8Array, message: Uint8Array): Promise<Uint8Array> {
      const keyData = signKey.length === 32 ? rawEd25519ToPKCS8(signKey) : signKey;
      const key = await subtle.importKey('pkcs8', toArrayBuffer(keyData), 'Ed25519', false, ['sign']);
      const sig = await subtle.sign('Ed25519', key, toArrayBuffer(message));
      return new Uint8Array(sig);
    },
    async verify(publicKey: Uint8Array, message: Uint8Array, signature: Uint8Array): Promise<boolean> {
      const key = await subtle.importKey('raw', toArrayBuffer(publicKey), 'Ed25519', false, ['verify']);
      return subtle.verify('Ed25519', key, toArrayBuffer(signature), toArrayBuffer(message));
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

/**
 * Enkripsi AEAD AES-128-GCM menggunakan native W3C WebCrypto API (crypto.subtle).
 */
async function encryptAes128Gcm(
  key: Uint8Array,
  nonce: Uint8Array,
  aad: Uint8Array,
  plaintext: Uint8Array
): Promise<Uint8Array> {
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    toArrayBuffer(key),
    { name: 'AES-GCM' },
    false,
    ['encrypt']
  );
  const params: AesGcmParams = {
    name: 'AES-GCM',
    iv: toArrayBuffer(nonce),
  };
  if (aad && aad.length > 0) {
    params.additionalData = toArrayBuffer(aad);
  }
  const result = await crypto.subtle.encrypt(params, cryptoKey, toArrayBuffer(plaintext));
  return new Uint8Array(result);
}

/**
 * Dekripsi AEAD AES-128-GCM menggunakan native W3C WebCrypto API (crypto.subtle).
 */
async function decryptAes128Gcm(
  key: Uint8Array,
  nonce: Uint8Array,
  aad: Uint8Array,
  ciphertext: Uint8Array
): Promise<Uint8Array> {
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    toArrayBuffer(key),
    { name: 'AES-GCM' },
    false,
    ['decrypt']
  );
  const params: AesGcmParams = {
    name: 'AES-GCM',
    iv: toArrayBuffer(nonce),
  };
  if (aad && aad.length > 0) {
    params.additionalData = toArrayBuffer(aad);
  }
  const result = await crypto.subtle.decrypt(params, cryptoKey, toArrayBuffer(ciphertext));
  return new Uint8Array(result);
}

/**
 * Pembangun Ciphersuite MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519 native
 * bebas dari dependensi statis modul opsional @noble/curves atau @hpke non-default.
 */
async function buildNativeMls128Ciphersuite(): Promise<CiphersuiteImpl> {
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
 * Utilitas penghitungan SHA-256 menggunakan W3C WebCrypto Subtle di dalam Worker.
 */
async function computeSha256Hex(data: Uint8Array): Promise<string> {
  const digest = await globalThis.crypto.subtle.digest('SHA-256', toArrayBuffer(data));
  const hashArray = Array.from(new Uint8Array(digest));
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Handler utama pesan RPC masuk dari Main Thread.
 */
self.onmessage = async (event: MessageEvent<WorkerRpcRequest>) => {
  const req = event.data;

  // Validasi amplop dasar request
  if (!req || typeof req !== 'object' || !req.requestId || !req.action) {
    const errorResponse: WorkerRpcResponse = {
      requestId: req?.requestId || 'unknown',
      action: req?.action || ('INVALID' as any),
      success: false,
      error: {
        code: 'INVALID_RPC_ENVELOPE',
        message: 'Format amplop WorkerRpcRequest tidak valid.',
      },
    };
    self.postMessage(errorResponse);
    return;
  }

  const { requestId, action, payload } = req;

  // FAIL-CLOSED GATEKEEPER:
  // Jika sesi Worker dikunci akibat kegagalan persistensi sebelumnya, seluruh aksi MLS
  // ditolak sampai sesi dipulihkan kembali melalui RESTORE_GROUP_STATE yang berhasil.
  if (
    isSessionLocked &&
    action !== 'RESTORE_GROUP_STATE' &&
    action !== 'INIT_PEER' &&
    action !== 'UPDATE_OUTBOX_STATUS'
  ) {
    const errorResponse: WorkerRpcResponse = {
      requestId,
      action,
      success: false,
      error: {
        code: 'SESSION_LOCKED_PERSISTENCE_FAILED',
        message: `Worker session is locked due to previous persistence failure: ${sessionLockReason}. Sesi wajib di-restore ulang melalui RESTORE_GROUP_STATE sebelum dapat memproses operasi MLS.`,
      },
    };
    self.postMessage(errorResponse);
    return;
  }

  try {
    switch (action) {
      // ---------------------------------------------------------------------
      // 1. INIT_PEER: Inisialisasi peran dan primitif WebCrypto
      // ---------------------------------------------------------------------
      case 'INIT_PEER': {
        const initPayload = payload as InitPeerPayload;
        if (!initPayload?.role || !initPayload?.identity) {
          throw new Error('INIT_PEER membutuhkan field role dan identity.');
        }

        // Verifikasi WebCrypto
        if (!globalThis.crypto || !globalThis.crypto.subtle) {
          throw new Error('Primitif globalThis.crypto.subtle tidak tersedia di Worker ini.');
        }

        // Inisialisasi Ciphersuite MLS
        const cs = await buildNativeMls128Ciphersuite();
        mlsContext = {
          cipherSuite: cs,
          authService: unsafeTestingAuthenticationService,
          clientConfig: multiDeviceClientConfig as any,
        };

        currentRole = initPayload.role;
        currentIdentity = initPayload.identity;
        clientState = null;
        localKeyPackage = null;
        unlockSession();

        // Sesi identity & lease binding
        if (initPayload.deviceUuid) {
          currentDeviceUuid = normalizeCanonicalUuid(initPayload.deviceUuid, 'deviceUuid');
          if (initPayload.fencing) {
            currentFencing = {
              ownerToken: initPayload.fencing.ownerToken,
              generation: initPayload.fencing.generation,
            };
          } else {
            currentFencing = null;
          }
        } else {
          // Binding identitas device sesi tervalidasi dan provision lease aktif di tab_leases
          // sehingga seluruh mutasi MLS tetap tersimpan transaksional ke IndexedDB tanpa fallback in-memory!
          const identityBytes = new TextEncoder().encode(`${currentRole}:${currentIdentity}`);
          const idHash = await computeSha256Hex(identityBytes);
          const deterministicUuid = `${idHash.substring(0, 8)}-${idHash.substring(8, 12)}-4${idHash.substring(13, 16)}-8${idHash.substring(17, 20)}-${idHash.substring(20, 32)}`;
          currentDeviceUuid = deterministicUuid;
          const ownerToken = `session_${idHash.substring(0, 16)}`;
          currentFencing = {
            ownerToken,
            generation: '1',
          };

          try {
            const leaseRecord: TabLeaseRecord = {
              device_uuid: currentDeviceUuid,
              owner_token: currentFencing.ownerToken,
              generation: currentFencing.generation,
              acquired_at: Date.now(),
              expires_at: Date.now() + 86400000,
            };
            await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);
          } catch {
            // Ignore if IDB not accessible
          }
        }

        const result: InitPeerResult = {
          initialized: true,
          role: currentRole,
          ciphersuite: 'MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519',
        };

        const response: WorkerRpcResponse<InitPeerResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 2. GENERATE_KEY_PACKAGE: Pembangkitan KeyPackage independen
      // ---------------------------------------------------------------------
      case 'GENERATE_KEY_PACKAGE': {
        if (!mlsContext || !currentIdentity) {
          throw new Error('Worker belum diinisialisasi. Panggil INIT_PEER terlebih dahulu.');
        }

        const genPayload = payload as GenerateKeyPackagePayload | undefined;
        let deviceUuid: string | null = null;
        if (genPayload && 'deviceUuid' in genPayload) {
          if (!genPayload.deviceUuid || typeof genPayload.deviceUuid !== 'string' || genPayload.deviceUuid.trim() === '') {
            throw new Error('GENERATE_KEY_PACKAGE: deviceUuid yang dikirim tidak valid.');
          }
          deviceUuid = genPayload.deviceUuid;
        } else {
          deviceUuid = currentDeviceUuid;
        }

        if (!deviceUuid) {
          throw new Error('GENERATE_KEY_PACKAGE: identitas device tidak tersedia pada payload atau sesi Worker.');
        }

        let fencing: FencingCredentials | null = null;
        if (genPayload && 'fencing' in genPayload) {
          if (!genPayload.fencing || !genPayload.fencing.ownerToken || !genPayload.fencing.generation) {
            throw new Error('GENERATE_KEY_PACKAGE: fencing yang dikirim tidak valid.');
          }
          fencing = genPayload.fencing;
        }

        const cred = {
          credentialType: defaultCredentialTypes.basic,
          identity: new TextEncoder().encode(currentIdentity),
        };

        const kp = await generateKeyPackage({
          credential: cred,
          cipherSuite: mlsContext.cipherSuite,
        });

        // Enkode publicPackage ke wireformat biner
        const publicBytes = encode(keyPackageEncoder, kp.publicPackage);
        const hashHex = await computeSha256Hex(publicBytes);
        const base64Wire = bytesToBase64(publicBytes);

        // MANDATORY DURABLE STORAGE: Simpan PrivateKeyPackage terenkripsi ke pending_key_packages
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
        try {
          await savePendingKeyPackage({
            deviceUuid: canonicalDeviceUuid,
            keyPackageHash: hashHex,
            privateKeyPackage: kp.privatePackage,
            publicKeyPackageBytes: publicBytes,
            fencing: fencing ?? undefined,
          });
        } catch (persistErr: any) {
          // FAIL-CLOSED: Jangan simpan di memori jika persistensi gagal!
          localKeyPackage = null;
          throw persistErr;
        }

        // HANYA setelah persistensi durable berhasil, simpan di memori worker
        localKeyPackage = kp;
        if (!currentDeviceUuid) {
          currentDeviceUuid = canonicalDeviceUuid;
        }
        if (fencing && !currentFencing) {
          currentFencing = fencing;
        }

        const result: GenerateKeyPackageResult = {
          keyPackageWireBase64: base64Wire,
          keyPackageHash: hashHex,
        };

        const response: WorkerRpcResponse<GenerateKeyPackageResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 3. CREATE_GROUP: Pembuatan MLS Group awal (Khusus Mahasiswa)
      // ---------------------------------------------------------------------
      case 'CREATE_GROUP': {
        if (!mlsContext || !localKeyPackage) {
          throw new Error('MLS context atau KeyPackage lokal belum siap.');
        }
        if (currentRole !== 'mahasiswa') {
          throw new Error('Hanya peer mahasiswa yang diizinkan memanggil CREATE_GROUP.');
        }

        const groupPayload = payload as CreateGroupPayload;
        if (!groupPayload?.roomUuid) {
          throw new Error('CREATE_GROUP membutuhkan roomUuid.');
        }

        const canonicalRoomUuid = normalizeCanonicalUuid(groupPayload.roomUuid, 'roomUuid');

        // Resolusi deviceUuid
        let deviceUuid: string | null = null;
        if (groupPayload && 'deviceUuid' in groupPayload) {
          if (!groupPayload.deviceUuid || typeof groupPayload.deviceUuid !== 'string' || groupPayload.deviceUuid.trim() === '') {
            throw new Error('CREATE_GROUP: deviceUuid yang dikirim tidak valid.');
          }
          deviceUuid = groupPayload.deviceUuid;
        } else {
          deviceUuid = currentDeviceUuid;
        }
        if (!deviceUuid) {
          throw new Error('CREATE_GROUP: identitas device tidak tersedia pada payload atau sesi Worker.');
        }
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

        // Resolusi fencing
        let fencing: FencingCredentials | null = null;
        if (groupPayload && 'fencing' in groupPayload) {
          if (!groupPayload.fencing || !groupPayload.fencing.ownerToken || !groupPayload.fencing.generation) {
            throw new Error('CREATE_GROUP: fencing credentials yang dikirim tidak valid.');
          }
          fencing = groupPayload.fencing;
        } else {
          fencing = currentFencing;
        }
        if (!fencing) {
          throw new Error('CREATE_GROUP: fencing credentials tidak tersedia pada payload atau sesi Worker.');
        }

        const groupIdBytes = new TextEncoder().encode(canonicalRoomUuid);
        const groupIdHash = await computeSha256Hex(groupIdBytes);

        // Buat MLS Group awal (epoch 0n)
        const candidateState = await createGroup({
          context: mlsContext,
          groupId: groupIdBytes,
          keyPackage: localKeyPackage.publicPackage,
          privateKeyPackage: localKeyPackage.privatePackage,
        });

        // Wajib simpan GroupState secara transaksional di IndexedDB sebelum adopsi
        let newRevision: string;
        try {
          const persistRes = await persistGroupState({
            deviceUuid: canonicalDeviceUuid,
            roomUuid: canonicalRoomUuid,
            state: candidateState,
            expectedPreviousRevision: null,
            fencing: {
              ownerToken: fencing.ownerToken,
              generation: fencing.generation,
            },
          });
          newRevision = persistRes.stateRevision;
          currentDeviceUuid = canonicalDeviceUuid;
          currentRoomUuid = canonicalRoomUuid;
          currentFencing = fencing;
          currentStateRevision = newRevision;
        } catch (persistErr: any) {
          // FAIL-CLOSED: Jangan adopsi state baru dan kunci sesi
          lockSession(`CREATE_GROUP persistensi gagal: ${persistErr?.message || 'Unknown error'}`);
          throw persistErr;
        }

        // ADOPSI state baru ke memori Worker HANYA setelah persistensi berhasil
        clientState = candidateState;
        currentEpoch = clientState.groupContext.epoch.toString();
        currentGroupIdHash = groupIdHash;

        const result: CreateGroupResult = {
          epoch: currentEpoch,
          groupIdHash,
          stateRevision: newRevision,
        };

        const response: WorkerRpcResponse<CreateGroupResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 4. COMMIT_ADD_MEMBER: Commit Add Proposal & Buat Welcome (Mahasiswa / Dosen)
      // ---------------------------------------------------------------------
      case 'COMMIT_ADD_MEMBER': {
        if (!mlsContext || !clientState) {
          throw new Error('Grup MLS belum dibuat pada peer aktif.');
        }
        if (currentRole !== 'mahasiswa' && currentRole !== 'dosen') {
          throw new Error('Hanya peer aktif yang diizinkan memanggil COMMIT_ADD_MEMBER.');
        }

        const commitPayload = payload as CommitAddMemberPayload;
        if (!commitPayload?.partnerKeyPackageBase64) {
          throw new Error('COMMIT_ADD_MEMBER membutuhkan partnerKeyPackageBase64.');
        }

        // Resolusi roomUuid
        const roomUuid = commitPayload.roomUuid ?? currentRoomUuid;
        if (!roomUuid) {
          throw new Error('COMMIT_ADD_MEMBER membutuhkan roomUuid.');
        }
        const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');

        // Resolusi deviceUuid
        let deviceUuid: string | null = null;
        if (commitPayload && 'deviceUuid' in commitPayload) {
          if (!commitPayload.deviceUuid || typeof commitPayload.deviceUuid !== 'string' || commitPayload.deviceUuid.trim() === '') {
            throw new Error('COMMIT_ADD_MEMBER: deviceUuid yang dikirim tidak valid.');
          }
          deviceUuid = commitPayload.deviceUuid;
        } else {
          deviceUuid = currentDeviceUuid;
        }
        if (!deviceUuid) {
          throw new Error('COMMIT_ADD_MEMBER: identitas device tidak tersedia pada payload atau sesi Worker.');
        }
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

        // Resolusi fencing
        let fencing: FencingCredentials | null = null;
        if (commitPayload && 'fencing' in commitPayload) {
          if (!commitPayload.fencing || !commitPayload.fencing.ownerToken || !commitPayload.fencing.generation) {
            throw new Error('COMMIT_ADD_MEMBER: fencing credentials yang dikirim tidak valid.');
          }
          fencing = commitPayload.fencing;
        } else {
          fencing = currentFencing;
        }
        if (!fencing) {
          throw new Error('COMMIT_ADD_MEMBER: fencing credentials tidak tersedia pada payload atau sesi Worker.');
        }

        // Dekode public KeyPackage dosen dari wireformat Base64
        const partnerBytes = base64ToBytes(commitPayload.partnerKeyPackageBase64);
        const partnerKeyPackage = decode(keyPackageDecoder, partnerBytes);
        if (!partnerKeyPackage) {
          throw new Error('Gagal mendekode KeyPackage publik milik partner.');
        }

        const addProposal = {
          proposalType: defaultProposalTypes.add,
          add: {
            keyPackage: partnerKeyPackage,
          },
        };

        // Jalankan createCommit dengan ratchetTreeExtension: true
        const commitResult = await createCommit({
          context: mlsContext,
          state: clientState,
          extraProposals: [addProposal],
          ratchetTreeExtension: true,
        });

        if (!commitResult.welcome) {
          throw new Error('Pesan Welcome tidak dihasilkan oleh createCommit.');
        }

        // Enkode pesan Commit dan Welcome ke wireformat biner TLS RFC 9420
        const commitBytes = encode(mlsMessageEncoder, commitResult.commit);
        const welcomeBytes = encode(mlsMessageEncoder, commitResult.welcome);

        const commitMsgUuid = commitPayload.clientMessageUuidCommit ?? generateCryptoRandomUuid();
        const welcomeMsgUuid = commitPayload.clientMessageUuidWelcome ?? generateCryptoRandomUuid();
        const operationId = commitPayload.operationId ?? generateCryptoRandomUuid();

        // Wajib simpan GroupState baru + Commit & Welcome outbox dalam SATU transaksi atomik
        let newRevision: string;
        try {
          const persistRes = await persistGroupStateAndProtocolOutbox({
            deviceUuid: canonicalDeviceUuid,
            roomUuid: canonicalRoomUuid,
            state: commitResult.newState,
            expectedPreviousRevision: currentStateRevision,
            fencing: {
              ownerToken: fencing.ownerToken,
              generation: fencing.generation,
            },
            operationId,
            commitOutbox: {
              clientMessageUuid: commitMsgUuid,
              messageType: 'commit',
              ciphertextBytes: commitBytes,
              epoch: commitResult.newState.groupContext.epoch.toString(),
              seq: 1,
            },
            welcomeOutbox: {
              clientMessageUuid: welcomeMsgUuid,
              messageType: 'welcome',
              ciphertextBytes: welcomeBytes,
              epoch: commitResult.newState.groupContext.epoch.toString(),
              seq: 2,
            },
          });
          newRevision = persistRes.stateRevision;
          currentDeviceUuid = canonicalDeviceUuid;
          currentRoomUuid = canonicalRoomUuid;
          currentFencing = fencing;
          currentStateRevision = newRevision;
        } catch (persistErr: any) {
          // FAIL-CLOSED: Penguncian sesi mutlak jika persistensi outbox/group state gagal
          lockSession(`COMMIT_ADD_MEMBER persistensi gagal: ${persistErr?.message || 'Unknown error'}`);
          throw persistErr;
        }

        // ADOPSI newState HANYA setelah persistensi berhasil
        clientState = commitResult.newState;
        currentEpoch = clientState.groupContext.epoch.toString();

        const welcomeBase64 = bytesToBase64(welcomeBytes);
        const commitBase64 = bytesToBase64(commitBytes);

        const result: CommitAddMemberResult = {
          welcomeWireBase64: welcomeBase64,
          commitWireBase64: commitBase64,
          clientMessageUuidCommit: commitMsgUuid,
          clientMessageUuidWelcome: welcomeMsgUuid,
          operationId,
          epoch: currentEpoch,
          stateRevision: newRevision,
        };

        const response: WorkerRpcResponse<CommitAddMemberResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 5. JOIN_WELCOME: Peer bergabung ke grup dari Welcome (Dosen / Mahasiswa)
      // ---------------------------------------------------------------------
      case 'JOIN_WELCOME': {
        if (!mlsContext) {
          throw new Error('MLS context belum diinisialisasi pada peer.');
        }
        if (currentRole !== 'dosen' && currentRole !== 'mahasiswa') {
          throw new Error('Hanya peer terdaftar yang diizinkan memanggil JOIN_WELCOME.');
        }

        const joinPayload = payload as JoinWelcomePayload;
        if (!joinPayload?.welcomeWireBase64) {
          throw new Error('JOIN_WELCOME membutuhkan welcomeWireBase64.');
        }

        // Resolusi deviceUuid
        let deviceUuid: string | null = null;
        if (joinPayload && 'deviceUuid' in joinPayload) {
          if (!joinPayload.deviceUuid || typeof joinPayload.deviceUuid !== 'string' || joinPayload.deviceUuid.trim() === '') {
            throw new Error('JOIN_WELCOME: deviceUuid yang dikirim tidak valid.');
          }
          deviceUuid = joinPayload.deviceUuid;
        } else {
          deviceUuid = currentDeviceUuid;
        }
        if (!deviceUuid) {
          throw new Error('JOIN_WELCOME: identitas device tidak tersedia pada payload atau sesi Worker.');
        }
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

        // Resolusi fencing
        let fencing: FencingCredentials | null = null;
        if (joinPayload && 'fencing' in joinPayload) {
          if (!joinPayload.fencing || !joinPayload.fencing.ownerToken || !joinPayload.fencing.generation) {
            throw new Error('JOIN_WELCOME: fencing credentials yang dikirim tidak valid.');
          }
          fencing = joinPayload.fencing;
        } else {
          fencing = currentFencing;
        }
        if (!fencing) {
          throw new Error('JOIN_WELCOME: fencing credentials tidak tersedia pada payload atau sesi Worker.');
        }

        let keyPkg = localKeyPackage;
        let keyPkgHash = joinPayload.keyPackageHash;

        // Ambil KeyPackage & PrivateKeyPackage dari store DURABLE pending_key_packages
        if (!keyPkgHash && keyPkg) {
          const publicBytes = encode(keyPackageEncoder, keyPkg.publicPackage);
          keyPkgHash = await computeSha256Hex(publicBytes);
        }

        // Fail-closed: keyPackageHash wajib disediakan secara tepat tanpa fallback pemilihan acak/pertama
        if (!keyPkgHash) {
          throw new Error('JOIN_WELCOME: keyPackageHash wajib disertakan pada payload dan tidak ditemukan pada sesi lokal.');
        }

        // Verifikasi PrivateKeyPackage DURABLE di IndexedDB (wajib tersimpan durable)
        const loaded = await getPendingPrivateKeyPackage(canonicalDeviceUuid, keyPkgHash);
        if (!loaded) {
          throw new Error(`JOIN_WELCOME: Pending KeyPackage [${keyPkgHash}] tidak ditemukan pada pending_key_packages durable storage.`);
        }
        if (loaded.status === 'consumed' && !joinPayload.isRecovery) {
          throw new Error(`JOIN_WELCOME: Pending KeyPackage [${keyPkgHash}] sudah dikonsumsi sebelumnya (anti-reuse).`);
        }
        if (!loaded.publicPackage && !keyPkg?.publicPackage) {
          throw new Error(`JOIN_WELCOME: Pending KeyPackage [${keyPkgHash}] tidak memiliki public key package valid.`);
        }

        keyPkg = {
          privatePackage: loaded.privatePackage,
          publicPackage: loaded.publicPackage ?? keyPkg!.publicPackage,
        };

        const welcomeBytes = base64ToBytes(joinPayload.welcomeWireBase64);
        const decodedMsg = decode(mlsMessageDecoder, welcomeBytes);
        if (!decodedMsg || decodedMsg.wireformat !== wireformats.mls_welcome) {
          throw new Error('Pesan wireformat bukan bertipe mls_welcome yang valid.');
        }

        // Dosen/Peer bergabung menggunakan privateKeyPackage miliknya sendiri
        const candidateState = await joinGroup({
          context: mlsContext,
          welcome: (decodedMsg as any).welcome,
          keyPackage: keyPkg.publicPackage,
          privateKeys: keyPkg.privatePackage,
        });

        const groupIdHash = await computeSha256Hex(candidateState.groupContext.groupId);
        const roomUuidFromGroup = new TextDecoder().decode(candidateState.groupContext.groupId);
        const roomUuid = joinPayload.roomUuid ?? currentRoomUuid ?? roomUuidFromGroup;
        const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');

        // Validasi identitas room: GroupId di Welcome WAJIB sama dengan roomUuid target
        if (roomUuidFromGroup.toLowerCase() !== canonicalRoomUuid.toLowerCase()) {
          throw new Error(
            `JOIN_WELCOME: Room UUID di dalam Welcome GroupContext (${roomUuidFromGroup}) tidak cocok dengan target room (${canonicalRoomUuid}).`
          );
        }

        // SATU transaksi atomik multi-store [tab_leases, group_states, pending_key_packages]
        let newRevision: string;
        try {
          const persistRes = await joinGroupAndConsumePackage({
            deviceUuid: canonicalDeviceUuid,
            roomUuid: canonicalRoomUuid,
            keyPackageHash: keyPkgHash,
            state: candidateState,
            expectedPreviousRevision: currentStateRevision ?? null,
            fencing: {
              ownerToken: fencing.ownerToken,
              generation: fencing.generation,
            },
            allowRecoveryReuse: !!joinPayload.isRecovery,
          });
          newRevision = persistRes.stateRevision;
          currentDeviceUuid = canonicalDeviceUuid;
          currentRoomUuid = canonicalRoomUuid;
          currentFencing = fencing;
          currentStateRevision = newRevision;
        } catch (joinErr: any) {
          // FAIL-CLOSED: Kunci sesi jika penyimpanan / konsumsi atomik gagal
          lockSession(`JOIN_WELCOME persistensi gagal: ${joinErr?.message || 'Unknown error'}`);
          throw joinErr;
        }

        // ADOPSI state baru HANYA setelah persistensi berhasil
        clientState = candidateState;
        currentEpoch = clientState.groupContext.epoch.toString();
        currentGroupIdHash = groupIdHash;
        localKeyPackage = null; // Clear from memory once consumed!

        const result: JoinWelcomeResult = {
          epoch: currentEpoch,
          groupIdHash,
          stateRevision: newRevision,
        };

        const response: WorkerRpcResponse<JoinWelcomeResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 5b. PROCESS_COMMIT: Anggota eksisting memproses Commit masuk (RFC 9420)
      // ---------------------------------------------------------------------
      case 'PROCESS_COMMIT': {
        if (!mlsContext || !clientState) {
          throw new Error('Grup MLS belum diinisialisasi pada perangkat ini.');
        }

        const procPayload = payload as ProcessCommitPayload;
        if (!procPayload?.commitWireBase64) {
          throw new Error('PROCESS_COMMIT membutuhkan commitWireBase64.');
        }

        // Resolusi roomUuid
        const roomUuid = procPayload.roomUuid ?? currentRoomUuid;
        if (!roomUuid) {
          throw new Error('PROCESS_COMMIT membutuhkan roomUuid.');
        }
        const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');

        // Resolusi deviceUuid
        let deviceUuid: string | null = null;
        if (procPayload && 'deviceUuid' in procPayload && procPayload.deviceUuid) {
          deviceUuid = procPayload.deviceUuid;
        } else {
          deviceUuid = currentDeviceUuid;
        }
        if (!deviceUuid) {
          throw new Error('PROCESS_COMMIT: identitas device tidak tersedia.');
        }
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

        // Resolusi fencing
        let fencing: FencingCredentials | null = null;
        if (procPayload && 'fencing' in procPayload && procPayload.fencing?.ownerToken && procPayload.fencing?.generation) {
          fencing = procPayload.fencing;
        } else {
          fencing = currentFencing;
        }
        if (!fencing) {
          throw new Error('PROCESS_COMMIT: fencing credentials tidak tersedia.');
        }

        const curEpochBig = clientState.groupContext.epoch;
        const curEpochStr = curEpochBig.toString();

        // Idempotency check: jika expectedToEpoch diberikan dan currentEpoch sudah >= expectedToEpoch
        if (procPayload.expectedToEpoch && BigInt(curEpochStr) >= BigInt(procPayload.expectedToEpoch)) {
          const idempotentRes: ProcessCommitResult = {
            roomUuid: canonicalRoomUuid,
            deviceUuid: canonicalDeviceUuid,
            epoch: curEpochStr,
            stateRevision: currentStateRevision ?? '',
            actionTaken: 'accept_idempotent',
          };
          const response: WorkerRpcResponse<ProcessCommitResult> = {
            requestId,
            action,
            success: true,
            data: idempotentRes,
          };
          self.postMessage(response);
          break;
        }

        // Validasi expectedFromEpoch jika dikirim
        if (procPayload.expectedFromEpoch && curEpochStr !== procPayload.expectedFromEpoch) {
          throw new Error(`PROCESS_COMMIT: Epoch saat ini [${curEpochStr}] tidak sesuai dengan expectedFromEpoch [${procPayload.expectedFromEpoch}].`);
        }

        // Dekode commit message dari base64
        const commitBytes = base64ToBytes(procPayload.commitWireBase64);
        const decodedCommit = decode(mlsMessageDecoder, commitBytes);
        if (!decodedCommit) {
          throw new Error('PROCESS_COMMIT: Gagal mendekode commitWireBase64.');
        }

        // Proses commit via ts-mls
        let recvResult;
        try {
          recvResult = await processMessage({
            context: mlsContext,
            state: clientState,
            message: decodedCommit as any,
            callback: acceptAll,
          });
        } catch (procErr: any) {
          throw new Error(`PROCESS_COMMIT gagal memproses pesan Commit: ${procErr?.message || procErr}`);
        }

        if (recvResult.kind !== 'newState') {
          throw new Error(`PROCESS_COMMIT: Hasil pemrosesan bukan newState (kind: ${(recvResult as any).kind}).`);
        }

        const candidateState = recvResult.newState;
        const newEpochStr = candidateState.groupContext.epoch.toString();

        // Verifikasi bahwa epoch benar-benar bertambah maju
        if (BigInt(newEpochStr) <= BigInt(curEpochStr)) {
          throw new Error(`PROCESS_COMMIT: Epoch hasil transisi [${newEpochStr}] tidak lebih besar dari epoch sebelumnya [${curEpochStr}].`);
        }

        // Simpan GroupState baru secara ATOMIK
        let newRevision: string;
        try {
          const persistRes = await persistGroupState({
            deviceUuid: canonicalDeviceUuid,
            roomUuid: canonicalRoomUuid,
            state: candidateState,
            expectedPreviousRevision: currentStateRevision,
            fencing: {
              ownerToken: fencing.ownerToken,
              generation: fencing.generation,
            },
          });
          newRevision = persistRes.stateRevision;
          currentDeviceUuid = canonicalDeviceUuid;
          currentRoomUuid = canonicalRoomUuid;
          currentFencing = fencing;
          currentStateRevision = newRevision;
        } catch (persistErr: any) {
          // FAIL-CLOSED: Penguncian sesi mutlak jika persistensi group state gagal
          lockSession(`PROCESS_COMMIT persistensi gagal: ${persistErr?.message || 'Unknown error'}`);
          throw persistErr;
        }

        // Adopsi state hanya setelah persistensi berhasil
        clientState = candidateState;
        currentEpoch = newEpochStr;

        const result: ProcessCommitResult = {
          roomUuid: canonicalRoomUuid,
          deviceUuid: canonicalDeviceUuid,
          epoch: newEpochStr,
          stateRevision: newRevision,
          actionTaken: recvResult.actionTaken,
        };

        const response: WorkerRpcResponse<ProcessCommitResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 6. ENCRYPT_MESSAGE: Enkripsi pesan aplikasi & Transaksi Atomik Outbox
      // ---------------------------------------------------------------------
      case 'ENCRYPT_MESSAGE': {
        if (!mlsContext || !clientState) {
          throw new Error('State grup belum aktif untuk melakukan enkripsi pesan.');
        }

        const encPayload = payload as EncryptMessagePayload;
        if (typeof encPayload?.plaintext !== 'string') {
          throw new Error('ENCRYPT_MESSAGE membutuhkan string plaintext.');
        }

        // Resolusi roomUuid
        const roomUuidFromGroup = new TextDecoder().decode(clientState.groupContext.groupId);
        const roomUuid = encPayload.roomUuid ?? currentRoomUuid ?? roomUuidFromGroup;
        const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');

        // Resolusi deviceUuid
        let deviceUuid: string | null = null;
        if (encPayload && 'deviceUuid' in encPayload) {
          if (!encPayload.deviceUuid || typeof encPayload.deviceUuid !== 'string' || encPayload.deviceUuid.trim() === '') {
            throw new Error('ENCRYPT_MESSAGE: deviceUuid yang dikirim tidak valid.');
          }
          deviceUuid = encPayload.deviceUuid;
        } else {
          deviceUuid = currentDeviceUuid;
        }
        if (!deviceUuid) {
          throw new Error('ENCRYPT_MESSAGE: identitas device tidak tersedia pada payload atau sesi Worker.');
        }
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

        // Resolusi fencing
        let fencing: FencingCredentials | null = null;
        if (encPayload && 'fencing' in encPayload) {
          if (!encPayload.fencing || !encPayload.fencing.ownerToken || !encPayload.fencing.generation) {
            throw new Error('ENCRYPT_MESSAGE: fencing credentials yang dikirim tidak valid.');
          }
          fencing = encPayload.fencing;
        } else {
          fencing = currentFencing;
        }
        if (!fencing) {
          throw new Error('ENCRYPT_MESSAGE: fencing credentials tidak tersedia pada payload atau sesi Worker.');
        }

        // UUID pesan ditentukan satu kali (disediakan atau dibangkitkan baru secara CSPRNG fail-closed)
        const clientMessageUuid = encPayload.clientMessageUuid || generateCryptoRandomUuid();

        // Enkripsi pesan aplikasi via MLS (menghasilkan candidate newState)
        const plainBytes = new TextEncoder().encode(encPayload.plaintext);
        const sendResult = await createApplicationMessage({
          context: mlsContext,
          state: clientState,
          message: plainBytes,
        });

        const candidateState = sendResult.newState;

        // Enkode pesan terenkripsi ke wireformat biner MLS
        const wireBytes = encode(mlsMessageEncoder, sendResult.message);
        const ciphertextBase64 = bytesToBase64(wireBytes);

        let newRevision: string;

        try {
          const persistResult = await persistGroupStateAndOutbox({
            deviceUuid: canonicalDeviceUuid,
            roomUuid: canonicalRoomUuid,
            state: candidateState,
            expectedPreviousRevision: currentStateRevision,
            fencing: {
              ownerToken: fencing.ownerToken,
              generation: fencing.generation,
            },
            outbox: {
              clientMessageUuid,
              ciphertextBytes: wireBytes,
            },
          });

          newRevision = persistResult.stateRevision;
          currentDeviceUuid = canonicalDeviceUuid;
          currentRoomUuid = canonicalRoomUuid;
          currentFencing = fencing;
          currentStateRevision = newRevision;
        } catch (persistErr: any) {
          lockSession(`ENCRYPT_MESSAGE persistensi gagal: ${persistErr?.message || 'Unknown error'}`);
          throw persistErr;
        }

        // ADOPSI candidateState ke memori Worker HANYA setelah transaksi durable berhasil!
        clientState = candidateState;
        currentEpoch = clientState.groupContext.epoch.toString();

        const result: EncryptMessageResult = {
          ciphertextWireBase64: ciphertextBase64,
          epoch: currentEpoch,
          clientMessageUuid,
          stateRevision: newRevision,
        };

        const response: WorkerRpcResponse<EncryptMessageResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 7. DECRYPT_MESSAGE: Dekripsi pesan aplikasi (Inbound Persist-Before-Adopt)
      // ---------------------------------------------------------------------
      case 'DECRYPT_MESSAGE': {
        if (!mlsContext || !clientState) {
          throw new Error('State grup belum aktif untuk memproses pesan masuk.');
        }

        const decPayload = payload as DecryptMessagePayload;
        if (!decPayload?.ciphertextWireBase64) {
          throw new Error('DECRYPT_MESSAGE membutuhkan ciphertextWireBase64.');
        }

        // Resolusi roomUuid
        const roomUuidFromGroup = new TextDecoder().decode(clientState.groupContext.groupId);
        const roomUuid = decPayload.roomUuid ?? currentRoomUuid ?? roomUuidFromGroup;
        const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');

        // Resolusi deviceUuid
        let deviceUuid: string | null = null;
        if (decPayload && 'deviceUuid' in decPayload) {
          if (!decPayload.deviceUuid || typeof decPayload.deviceUuid !== 'string' || decPayload.deviceUuid.trim() === '') {
            throw new Error('DECRYPT_MESSAGE: deviceUuid yang dikirim tidak valid.');
          }
          deviceUuid = decPayload.deviceUuid;
        } else {
          deviceUuid = currentDeviceUuid;
        }
        if (!deviceUuid) {
          throw new Error('DECRYPT_MESSAGE: identitas device tidak tersedia pada payload atau sesi Worker.');
        }
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

        // Resolusi fencing
        let fencing: FencingCredentials | null = null;
        if (decPayload && 'fencing' in decPayload) {
          if (!decPayload.fencing || !decPayload.fencing.ownerToken || !decPayload.fencing.generation) {
            throw new Error('DECRYPT_MESSAGE: fencing credentials yang dikirim tidak valid.');
          }
          fencing = decPayload.fencing;
        } else {
          fencing = currentFencing;
        }
        if (!fencing) {
          throw new Error('DECRYPT_MESSAGE: fencing credentials tidak tersedia pada payload atau sesi Worker.');
        }

        const wireBytes = base64ToBytes(decPayload.ciphertextWireBase64);
        const decodedMsg = decode(mlsMessageDecoder, wireBytes);
        if (!decodedMsg) {
          throw new Error('Gagal mendekode ciphertext wireformat MLS.');
        }

        const recvResult = await processMessage({
          context: mlsContext,
          state: clientState,
          message: decodedMsg as any,
        });

        if (recvResult.kind !== 'applicationMessage') {
          if ((recvResult as any).kind === 'newState') {
            throw new Error('Pesan yang diterima adalah Commit/Proposal MLS. Gunakan aksi PROCESS_COMMIT untuk memproses transisi epoch.');
          }
          throw new Error(`Pesan yang diterima bukan applicationMessage (kind: ${(recvResult as any).kind}).`);
        }

        const candidateState = recvResult.newState;
        let newRevision: string;

        try {
          const persistRes = await persistGroupState({
            deviceUuid: canonicalDeviceUuid,
            roomUuid: canonicalRoomUuid,
            state: candidateState,
            expectedPreviousRevision: currentStateRevision,
            fencing: {
              ownerToken: fencing.ownerToken,
              generation: fencing.generation,
            },
            lastProcessedSequence: decPayload.serverSequence,
          });

          newRevision = persistRes.stateRevision;
          currentDeviceUuid = canonicalDeviceUuid;
          currentRoomUuid = canonicalRoomUuid;
          currentFencing = fencing;
          currentStateRevision = newRevision;
        } catch (persistErr: any) {
          lockSession(`DECRYPT_MESSAGE persistensi gagal: ${persistErr?.message || 'Unknown error'}`);
          throw persistErr;
        }

        // ADOPSI state baru HANYA setelah persistensi berhasil
        clientState = candidateState;
        currentEpoch = clientState.groupContext.epoch.toString();

        // BARU dekode plaintext untuk dikembalikan setelah adopsi sukses
        const plaintext = new TextDecoder().decode(recvResult.message);

        const result: DecryptMessageResult = {
          plaintext,
          epoch: currentEpoch,
          senderLeafIndex: recvResult.senderLeafIndex,
          stateRevision: newRevision,
        };

        const response: WorkerRpcResponse<DecryptMessageResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 8. RETRY_OUTBOX_MESSAGE: Ambil ciphertext tersimpan tanpa enkripsi ulang
      // ---------------------------------------------------------------------
      case 'RETRY_OUTBOX_MESSAGE': {
        const retryPayload = payload as RetryOutboxMessagePayload;
        if (!retryPayload?.clientMessageUuid) {
          throw new Error('RETRY_OUTBOX_MESSAGE membutuhkan clientMessageUuid.');
        }

        let deviceUuid = retryPayload.deviceUuid ?? currentDeviceUuid;
        let fencing = retryPayload.fencing ?? currentFencing;

        // Validasi fencing jika disertakan
        if (fencing && deviceUuid) {
          const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
          const lease = await idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, canonicalDeviceUuid);
          if (!lease || lease.owner_token !== fencing.ownerToken || BigInt(lease.generation) !== BigInt(fencing.generation)) {
            throw new Error('RETRY_OUTBOX_MESSAGE: lease fencing tidak valid.');
          }
        }

        const outboxRecord = await getOutboxMessage(retryPayload.clientMessageUuid);
        if (!outboxRecord) {
          throw new Error(
            `Pesan outbox dengan clientMessageUuid [${retryPayload.clientMessageUuid}] tidak ditemukan.`
          );
        }

        // Terminal ACK Protection: Jangan retry pesan yang sudah acked
        if (outboxRecord.status === 'acked') {
          throw new Error(
            `Pesan outbox [${retryPayload.clientMessageUuid}] sudah berstatus 'acked' (terminal). Retry ditolak.`
          );
        }

        const ciphertextWireBase64 = bytesToBase64(outboxRecord.ciphertext_bytes);

        const result: RetryOutboxMessageResult = {
          clientMessageUuid: outboxRecord.client_message_uuid,
          roomUuid: outboxRecord.room_uuid,
          ciphertextWireBase64,
          status: outboxRecord.status,
        };

        const response: WorkerRpcResponse<RetryOutboxMessageResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 8. RESTORE_GROUP_STATE: Memulihkan dan Mengadopsi GroupState dari IDB
      // ---------------------------------------------------------------------
      case 'RESTORE_GROUP_STATE': {
        const restorePayload = payload as RestoreGroupStatePayload;

        // A. Validasi amplop payload dasar
        if (!restorePayload || typeof restorePayload !== 'object') {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'RESTORE_ERROR',
            message: 'RESTORE_GROUP_STATE membutuhkan payload valid.',
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        const { deviceUuid, roomUuid, fencing } = restorePayload;

        let canonicalDeviceUuid: string;
        let canonicalRoomUuid: string;
        try {
          canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');
          canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');
        } catch (normErr: any) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'RESTORE_ERROR',
            message: `Normalisasi UUID gagal: ${normErr?.message || 'Invalid UUID'}`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        // B. Validasi kredensial fencing (R3-01 / H-I3B.1)
        const DECIMAL_STRING_REGEX = /^(0|[1-9][0-9]*)$/;
        if (
          !fencing ||
          typeof fencing.ownerToken !== 'string' ||
          fencing.ownerToken.trim() === '' ||
          typeof fencing.generation !== 'string' ||
          !DECIMAL_STRING_REGEX.test(fencing.generation)
        ) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'FENCING_STALE',
            message: 'Kredensial fencing tidak valid atau format generation bukan decimal string kanonikal.',
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        // C. Verifikasi lease aktif awal pada IndexedDB (Deteksi Takeover / Stale Leader / Expired Lease)
        let activeLease: TabLeaseRecord | undefined;
        try {
          activeLease = await idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, canonicalDeviceUuid);
        } catch (leaseErr: any) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'RESTORE_ERROR',
            message: `Gagal membaca tab_leases: ${leaseErr?.message || 'IDB error'}`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        if (!activeLease) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'FENCING_STALE',
            message: `Tidak ditemukan tab lease aktif untuk perangkat [${canonicalDeviceUuid}]. Hak akses ditolak.`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        if (activeLease.owner_token !== fencing.ownerToken) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'FENCING_STALE',
            message: `Fencing owner token mismatch: lease aktif milik '${activeLease.owner_token}', pemanggil '${fencing.ownerToken}'. Takeover terdeteksi.`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        if (
          typeof activeLease.generation !== 'string' ||
          !DECIMAL_STRING_REGEX.test(activeLease.generation)
        ) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'FENCING_STALE',
            message: `Generasi lease aktif '${activeLease.generation}' tidak valid.`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        // Perbandingan BigInt tanpa konversi Number
        const callerGen = BigInt(fencing.generation);
        const activeGen = BigInt(activeLease.generation);
        if (callerGen !== activeGen) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'FENCING_STALE',
            message: `Fencing generation mismatch: lease aktif generasi '${activeLease.generation}', pemanggil '${fencing.generation}'. Leader usang.`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        const now = Date.now();
        if (typeof activeLease.expires_at === 'number' && now > activeLease.expires_at) {
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'FENCING_STALE',
            message: `Tab lease untuk perangkat [${canonicalDeviceUuid}] telah kedaluwarsa (expired at ${activeLease.expires_at}, now ${now}).`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        // D. Pastikan MlsContext siap untuk operasi kriptografi
        if (!mlsContext) {
          const cs = await buildNativeMls128Ciphersuite();
          mlsContext = {
            cipherSuite: cs,
            authService: unsafeTestingAuthenticationService,
            clientConfig: multiDeviceClientConfig as any,
          };
        }

        // E. Eksekusi restoreGroupState() dengan proteksi fencing
        const restoreRes = await restoreGroupState(canonicalDeviceUuid, canonicalRoomUuid, fencing);

        if (restoreRes.status === 'NOT_FOUND') {
          // Aturan: Jangan membuat GroupState baru dan jangan membuat identitas MLS baru
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'NOT_FOUND',
            message: `Tidak ditemukan record group_states untuk room [${canonicalRoomUuid}].`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        if (restoreRes.status === 'QUARANTINED') {
          // Aturan: Jangan mengabaikan status QUARANTINED, jangan adopsi state
          clearWorkerGroupSession();
          const result: RestoreGroupStateResult = {
            status: 'QUARANTINED',
            quarantineReason: restoreRes.quarantineReason || 'Record is quarantined',
            message: `Group state untuk room [${canonicalRoomUuid}] berada dalam karantina. Tidak boleh digunakan.`,
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        if (restoreRes.status === 'FOUND') {
          const restoredState = restoreRes.state;
          if (!restoredState || !restoredState.groupContext || !restoredState.groupContext.groupId) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'RESTORE_ERROR',
              message: 'State hasil pemulihan tidak memiliki GroupContext yang valid.',
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          // Validasi Group ID 36 byte sesuai UTF-8 canonical room UUID
          const expectedGroupIdBytes = new TextEncoder().encode(canonicalRoomUuid);
          if (
            restoredState.groupContext.groupId.byteLength !== 36 ||
            !areUint8ArraysEqual(restoredState.groupContext.groupId, expectedGroupIdBytes)
          ) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'RESTORE_ERROR',
              message: `Group ID (${restoredState.groupContext.groupId.byteLength} byte) tidak cocok dengan canonical room UUID 36 byte.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          // Validasi SHA-256 Group ID hash
          const computedHash = await computeSha256Hex(restoredState.groupContext.groupId);
          if (restoreRes.groupIdHash && computedHash !== restoreRes.groupIdHash) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'RESTORE_ERROR',
              message: `Hash Group ID tidak cocok: computed '${computedHash}' vs stored '${restoreRes.groupIdHash}'.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          // Validasi Epoch
          const epochStr = restoredState.groupContext.epoch.toString();
          if (restoreRes.epoch && epochStr !== restoreRes.epoch) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'RESTORE_ERROR',
              message: `Epoch ClientState '${epochStr}' tidak cocok dengan epoch metadata '${restoreRes.epoch}'.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          // F. Verifikasi Ulang Fencing Pra-Adopsi (Anti-Race / Anti-Takeover Defense)
          // Menjamin Worker tidak mengadopsi ClientState jika kepemilikan lease telah berpindah
          // atau kedaluwarsa selama proses pembacaan / dekripsi WebCrypto berlangsung.
          let leaseAfter: TabLeaseRecord | undefined;
          try {
            leaseAfter = await idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, canonicalDeviceUuid);
          } catch (postCheckErr: any) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'RESTORE_ERROR',
              message: `Gagal membaca tab_leases saat konfirmasi pra-adopsi: ${postCheckErr?.message || 'IDB error'}`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          if (!leaseAfter) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'FENCING_STALE',
              message: `Tab lease tidak ditemukan saat konfirmasi pra-adopsi untuk perangkat [${canonicalDeviceUuid}]. Adopsi dibatalkan.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          if (leaseAfter.owner_token !== fencing.ownerToken) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'FENCING_STALE',
              message: `Takeover terdeteksi saat proses restore berlangsung: lease aktif milik '${leaseAfter.owner_token}', pemanggil '${fencing.ownerToken}'. Adopsi dibatalkan.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          if (
            typeof leaseAfter.generation !== 'string' ||
            !DECIMAL_STRING_REGEX.test(leaseAfter.generation)
          ) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'FENCING_STALE',
              message: `Generasi lease aktif '${leaseAfter.generation}' tidak valid saat konfirmasi pra-adopsi. Adopsi dibatalkan.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          const postActiveGen = BigInt(leaseAfter.generation);
          if (callerGen !== postActiveGen) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'FENCING_STALE',
              message: `Generasi lease telah maju saat proses restore berlangsung (aktif '${leaseAfter.generation}', pemanggil '${fencing.generation}'). Adopsi dibatalkan.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          const postNow = Date.now();
          if (typeof leaseAfter.expires_at === 'number' && postNow > leaseAfter.expires_at) {
            clearWorkerGroupSession();
            const result: RestoreGroupStateResult = {
              status: 'FENCING_STALE',
              message: `Tab lease telah kedaluwarsa saat proses restore berlangsung (expired at ${leaseAfter.expires_at}, now ${postNow}). Adopsi dibatalkan.`,
            };
            self.postMessage({ requestId, action, success: true, data: result });
            break;
          }

          // G. ADOPSI ClientState HANYA SETELAH SELURUH VALIDASI DAN LEASE CHECK PRA-ADOPSI LOLOS
          clientState = restoredState;
          currentDeviceUuid = canonicalDeviceUuid;
          currentRoomUuid = canonicalRoomUuid;
          currentEpoch = epochStr;
          currentStateRevision = restoreRes.stateRevision || '1';
          currentGroupIdHash = computedHash;
          currentFencing = { ...fencing };
          unlockSession();

          const result: RestoreGroupStateResult = {
            status: 'FOUND',
            epoch: epochStr,
            groupIdHash: computedHash,
            stateRevision: restoreRes.stateRevision,
            lastProcessedSequence: restoreRes.lastProcessedSequence,
            message: 'GroupState berhasil dipulihkan, divalidasi, dan diadopsi ke memori Worker.',
          };
          self.postMessage({ requestId, action, success: true, data: result });
          break;
        }

        clearWorkerGroupSession();
        const result: RestoreGroupStateResult = {
          status: 'RESTORE_ERROR',
          message: `Status pemulihan tidak dikenal: ${(restoreRes as any)?.status}`,
        };
        self.postMessage({ requestId, action, success: true, data: result });
        break;
      }

      // ---------------------------------------------------------------------
      // 9. UPDATE_OUTBOX_STATUS: Update status pesan outbox (dengan proteksi Late ACK)
      // ---------------------------------------------------------------------
      case 'UPDATE_OUTBOX_STATUS': {
        const uPayload = payload as UpdateOutboxStatusPayload;
        if (!uPayload?.clientMessageUuid || !uPayload?.status) {
          throw new Error('UPDATE_OUTBOX_STATUS membutuhkan clientMessageUuid dan status.');
        }

        let deviceUuid = uPayload.deviceUuid ?? currentDeviceUuid;
        let fencing = uPayload.fencing ?? currentFencing;

        await updateOutboxMessageStatus({
          clientMessageUuid: uPayload.clientMessageUuid,
          status: uPayload.status,
          sentAt: uPayload.sentAt,
          errorMessage: uPayload.errorMessage,
          deviceUuid: deviceUuid ?? undefined,
          fencing: fencing ? {
            ownerToken: fencing.ownerToken,
            generation: fencing.generation,
          } : undefined,
          claimToken: uPayload.claimToken,
        });

        const result: UpdateOutboxStatusResult = {
          success: true,
          clientMessageUuid: uPayload.clientMessageUuid,
          status: uPayload.status,
        };

        const response: WorkerRpcResponse<UpdateOutboxStatusResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 10. CLAIM_OUTBOX_MESSAGE: Klaim atomik outbox (status in_transit)
      // ---------------------------------------------------------------------
      case 'CLAIM_OUTBOX_MESSAGE': {
        const claimPayload = payload as ClaimOutboxMessagePayload;
        if (!claimPayload?.clientMessageUuid) {
          throw new Error('CLAIM_OUTBOX_MESSAGE membutuhkan clientMessageUuid.');
        }

        let deviceUuid = claimPayload.deviceUuid ?? currentDeviceUuid;
        if (!deviceUuid) {
          throw new Error('CLAIM_OUTBOX_MESSAGE: identitas device tidak tersedia.');
        }
        const canonicalDeviceUuid = normalizeCanonicalUuid(deviceUuid, 'deviceUuid');

        let fencing = claimPayload.fencing ?? currentFencing;
        if (!fencing) {
          throw new Error('CLAIM_OUTBOX_MESSAGE: fencing credentials tidak tersedia.');
        }

        const claimRes = await claimOutboxMessage({
          deviceUuid: canonicalDeviceUuid,
          clientMessageUuid: claimPayload.clientMessageUuid,
          fencing: {
            ownerToken: fencing.ownerToken,
            generation: fencing.generation,
          },
          roomUuid: claimPayload.roomUuid ?? currentRoomUuid ?? undefined,
          claimTimeoutMs: claimPayload.claimTimeoutMs,
        });

        const result: ClaimOutboxMessageResult = {
          success: true,
          clientMessageUuid: claimRes.outboxRecord.client_message_uuid,
          roomUuid: claimRes.outboxRecord.room_uuid,
          status: 'in_transit',
          ciphertextWireBase64: bytesToBase64(claimRes.outboxRecord.ciphertext_bytes),
          claimToken: claimRes.claimToken,
          retryCount: claimRes.outboxRecord.retry_count ?? 1,
          sentAt: claimRes.outboxRecord.sent_at ?? Date.now(),
          metadata: claimRes.outboxRecord.metadata,
        };

        const response: WorkerRpcResponse<ClaimOutboxMessageResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 11. GET_ROOM_OUTBOX: Mengambil rekaman pesan outbox suatu room
      // ---------------------------------------------------------------------
      case 'GET_ROOM_OUTBOX': {
        const gPayload = payload as GetRoomOutboxPayload;
        if (!gPayload?.roomUuid) {
          throw new Error('GET_ROOM_OUTBOX membutuhkan roomUuid.');
        }

        const records = await getOutboxMessagesByRoom(gPayload.roomUuid, gPayload.status);
        const summaries: OutboxItemSummary[] = records.map((rec) => ({
          clientMessageUuid: rec.client_message_uuid,
          roomUuid: rec.room_uuid,
          status: rec.status,
          ciphertextWireBase64: bytesToBase64(rec.ciphertext_bytes),
          createdAt: rec.created_at,
          sentAt: rec.sent_at,
          retryCount: rec.retry_count,
          messageType: (rec.metadata?.message_type as string) ?? undefined,
        }));

        const result: GetRoomOutboxResult = {
          messages: summaries,
        };

        const response: WorkerRpcResponse<GetRoomOutboxResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      // ---------------------------------------------------------------------
      // 12. DERIVE_ATTACHMENT_KEY: Derivasi Kunci Lampiran via MLS Exporter (RFC 9420 §8.5)
      // ---------------------------------------------------------------------
      case 'DERIVE_ATTACHMENT_KEY': {
        if (isSessionLocked) {
          throw new Error(`Sesi Worker terkunci (fail-closed): ${sessionLockReason ?? 'Unknown reason'}`);
        }
        if (!mlsContext || !clientState) {
          throw new Error('State grup belum aktif untuk melakukan derivasi kunci lampiran.');
        }

        const derivePayload = payload as DeriveAttachmentKeyPayload;
        if (!derivePayload || typeof derivePayload !== 'object') {
          throw new Error('DERIVE_ATTACHMENT_KEY membutuhkan payload yang valid.');
        }

        const { roomUuid, messageUuid, attachmentUuid } = derivePayload;
        if (!roomUuid || !messageUuid || !attachmentUuid) {
          throw new Error('DERIVE_ATTACHMENT_KEY membutuhkan roomUuid, messageUuid, dan attachmentUuid.');
        }

        const canonicalRoomUuid = normalizeCanonicalUuid(roomUuid, 'roomUuid');
        const canonicalMessageUuid = normalizeCanonicalUuid(messageUuid, 'messageUuid');
        const canonicalAttachmentUuid = normalizeCanonicalUuid(attachmentUuid, 'attachmentUuid');

        // Pastikan room yang diminta cocok dengan groupContext aktif saat ini (Room Isolation)
        const roomUuidFromGroup = new TextDecoder().decode(clientState.groupContext.groupId);
        if (normalizeCanonicalUuid(roomUuidFromGroup, 'groupContext.groupId') !== canonicalRoomUuid) {
          throw new Error(
            `Ketidakcocokan room: context grup aktif '${roomUuidFromGroup}' tidak cocok dengan permintaan '${canonicalRoomUuid}'.`
          );
        }

        // Resolusi fencing jika disediakan
        if (derivePayload.fencing) {
          if (!currentFencing || currentFencing.ownerToken !== derivePayload.fencing.ownerToken) {
            throw new Error('Fencing credentials tidak valid atau stale.');
          }
        }

        // Susun 48-byte context: RoomUUID (16B) || MessageUUID (16B) || AttachmentUUID (16B)
        const roomBytes = uuidStringTo16Bytes(canonicalRoomUuid);
        const msgBytes = uuidStringTo16Bytes(canonicalMessageUuid);
        const attBytes = uuidStringTo16Bytes(canonicalAttachmentUuid);

        const contextBytes = new Uint8Array(48);
        contextBytes.set(roomBytes, 0);
        contextBytes.set(msgBytes, 16);
        contextBytes.set(attBytes, 32);

        if (contextBytes.length !== 48) {
          throw new Error(`Panjang context exporter tidak valid (${contextBytes.length} byte, wajib tepat 48 byte).`);
        }

        // Eksekusi MLS Exporter (RFC 9420 §8.5)
        const derivedKeyBytes = await mlsExporter(
          clientState.keySchedule.exporterSecret,
          'bchat_attachment_cek',
          contextBytes,
          32,
          mlsContext.cipherSuite
        );

        if (derivedKeyBytes.length !== 32) {
          throw new Error(`Hasil derivasi exporter tidak valid (${derivedKeyBytes.length} byte, wajib 32 byte).`);
        }

        const result: DeriveAttachmentKeyResult = {
          cekBase64: bytesToBase64(derivedKeyBytes),
          keyLengthBytes: derivedKeyBytes.length,
          epoch: clientState.groupContext.epoch.toString(),
        };

        const response: WorkerRpcResponse<DeriveAttachmentKeyResult> = {
          requestId,
          action,
          success: true,
          data: result,
        };
        self.postMessage(response);
        break;
      }

      default: {
        throw new Error(`Aksi RPC tidak dikenal: ${action}`);
      }
    }
  } catch (err: any) {
    const errorResponse: WorkerRpcResponse = {
      requestId,
      action,
      success: false,
      error: {
        code: err.name || 'WORKER_INTERNAL_ERROR',
        message: err.message || 'Terjadi kesalahan internal pada Worker.',
      },
    };
    self.postMessage(errorResponse);
  }
};
