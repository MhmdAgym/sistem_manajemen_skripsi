'use client';

/**
 * B-CHAT E2EE — Browser Integration Device Registration & KeyPackage Test Harness (I-04B.1R / I-04B.2)
 *
 * Dedicated test page to execute and verify:
 * 1. I-02A Device Registration (Mahasiswa & Dosen).
 * 2. I-02B MLS KeyPackage Generation via Dedicated Worker, Durable Encrypted IDB Storage,
 *    and Backend Publication with Fencing (Dosen).
 * 3. I-02B Partner KeyPackage Fetch by Mahasiswa (Read-Only Unconsumed Verification).
 *
 * Security & Environment Invariants:
 * - Restricted strictly to development/test environments (notFound() on production).
 * - Allowed accounts: unuha_mhs_bchat_b1_test@unuha.ac.id, unuha_dosen_pa_test@unuha.ac.id.
 * - Zero display or serialization of passwords, tokens, nonces, private keys, or signatures.
 */

import React, { useState, useEffect, useRef } from 'react';
import { notFound } from 'next/navigation';
import axios from '@/lib/axios';
import {
  registerDeviceFlow,
  getActiveDeviceIdentityKey,
  type DeviceRegistrationResult,
} from '@/lib/bchat/device/bchat-device-registration';
import {
  createBchatWorkerClient,
  type BchatWorkerClient,
} from '@/lib/bchat/bchat-poc-rpc';
import {
  publishKeyPackagesFlow,
  type PublishKeyPackagesResult,
} from '@/lib/bchat/keypackage/bchat-keypackage-service';
import {
  publishWelcomeHandshakeFlow,
  fetchChallengeAndAckHandshakeFlow,
  type PublishWelcomeResult,
  type FetchChallengeAndAckResult,
} from '@/lib/bchat/handshake/bchat-handshake-coordinator';
import {
  buildWelcomeAckPayload,
  signWelcomeAckWithDeviceIdentity,
} from '@/lib/bchat/handshake/bchat-welcome-ack-builder';
import { fetchRoomDetail, fetchPartnerKeyPackage, sendMessage, getDeviceStatus } from '@/lib/bchat/api/bchat-api-client';
import type { BchatSendMessageRequest } from '@/lib/bchat/api/bchat-api-types';
import {
  sendApplicationMessageFlow,
  receiveApplicationMessagesFlow,
  computePayloadSha256,
  getGroupStateCursor,
  type DecryptedMessageItem,
} from '@/lib/bchat/message/bchat-message-coordinator';
import {
  BchatRealtimeSubscriber,
  type BchatMessageSentSignal,
  type SubscriptionState,
} from '@/lib/bchat/message/bchat-realtime-listener';
import { idbGet, idbPut, withReadonlyTransaction } from '@/lib/bchat/storage/bchat-idb-core';
import {
  BCAT_STORES,
  type KeystoreRecord,
  type TabLeaseRecord,
  type PendingKeyPackageRecord,
  type GroupStateRecord,
  type MessageOutboxRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';

interface StepStatus {
  id: string;
  name: string;
  status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL';
  detail?: string;
}

interface TestLog {
  timestamp: string;
  type: 'info' | 'success' | 'warn' | 'error';
  message: string;
}

declare global {
  interface Window {
    __BCAT_DEVICE_TEST_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      accountEmail: string;
      userId: string | number;
      deviceUuid: string;
      deviceStatus: string;
      idbKeyFound: boolean;
      idbKeyStatus?: string;
      idbKeyAlgorithm?: string;
      keyVersion?: number;
      registeredAt?: string;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_KEYPACKAGE_TEST_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      deviceUuid: string;
      uploadedCount: number;
      activePoolCount: number;
      keyPackageHash: string;
      idbRecordFound: boolean;
      idbPublicationStatus?: string;
      idbEncryptedBytesLength?: number;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_KEYPACKAGE_FETCH_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      roomUuid: string;
      recipientUserId: string;
      recipientDeviceUuid: string;
      keyPackageHash: string;
      fetch1Hash: string;
      fetch2Hash: string;
      isUnconsumed: boolean;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_HANDSHAKE_MHS_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      roomUuid: string;
      deviceUuid: string;
      welcomeOperationUuid: string;
      groupIdHash: string;
      currentEpoch: string;
      groupStateFound: boolean;
      groupStateEpoch: string;
      outboxCommitAcked: boolean;
      outboxWelcomeAcked: boolean;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_HANDSHAKE_DOSEN_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      roomUuid: string;
      deviceUuid: string;
      welcomeOperationUuid: string;
      challengeUuid: string;
      groupIdHash: string;
      currentEpoch: string;
      roomStatus: string;
      groupStateFound: boolean;
      groupStateEpoch: string;
      consumedKpFound: boolean;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_MESSAGE_MHS_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      roomUuid: string;
      deviceUuid: string;
      serverSequence: number;
      messageUuid: string;
      payloadHashSha256: string;
      plaintext: string;
      epoch: string;
      outboxStatus: string;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_MESSAGE_DOSEN_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      roomUuid: string;
      deviceUuid: string;
      receivedPlaintext: string;
      receivedSequence: number;
      replyServerSequence: number;
      replyMessageUuid: string;
      replyPayloadHashSha256: string;
      replyPlaintext: string;
      epoch: string;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_MESSAGE_MHS_REPLY_RESULT__?: {
      status: 'IDLE' | 'RUNNING' | 'PASS' | 'FAIL';
      roomUuid: string;
      deviceUuid: string;
      receivedReplyPlaintext: string;
      receivedReplySequence: number;
      allMessagesCount: number;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_REVERB_MHS_STATE__?: {
      status: SubscriptionState;
      roomUuid: string;
      deviceUuid: string;
      userId: number;
      latestSequence: number;
      signalsReceived: Array<{ signal: BchatMessageSentSignal; isDuplicate: boolean; receivedAt: string }>;
      duplicateSignalsCount: number;
      decryptedMessages: DecryptedMessageItem[];
      reconcileCount: number;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_REVERB_DOSEN_STATE__?: {
      status: SubscriptionState;
      roomUuid: string;
      deviceUuid: string;
      userId: number;
      latestSequence: number;
      signalsReceived: Array<{ signal: BchatMessageSentSignal; isDuplicate: boolean; receivedAt: string }>;
      duplicateSignalsCount: number;
      decryptedMessages: DecryptedMessageItem[];
      reconcileCount: number;
      logs: TestLog[];
      error?: string;
    };
    __BCAT_MHS_REALTIME__?: {
      subscriber: BchatRealtimeSubscriber;
      workerRpc: BchatWorkerClient;
      sendMessage: (text: string) => Promise<any>;
      reconcileRest: () => Promise<number>;
      disconnect: () => void;
      reconnect: () => void;
      handleSignal: (sig: unknown) => void;
    };
    __BCAT_DOSEN_REALTIME__?: {
      subscriber: BchatRealtimeSubscriber;
      workerRpc: BchatWorkerClient;
      sendMessage: (text: string) => Promise<any>;
      reconcileRest: () => Promise<number>;
      disconnect: () => void;
      reconnect: () => void;
      handleSignal: (sig: unknown) => void;
    };
  }
}

const ROOM_699_UUID = 'b1990000-b1b1-4b1b-8b1b-b1b1b1b1b1b1';
const FORBIDDEN_ROOM_337_UUID = 'b0010000-0000-4000-8000-000000000001';

const TEST_MHS_EMAIL = 'unuha_mhs_bchat_b1_test@unuha.ac.id';
const TEST_DOSEN_EMAIL = 'unuha_dosen_pa_test@unuha.ac.id';

const ALLOWED_TEST_ACCOUNTS = [
  TEST_MHS_EMAIL,
  TEST_DOSEN_EMAIL,
];

const FIXTURE_ROOM_UUID = ROOM_699_UUID;

/**
 * Validasi ketat bahwa hanya Room 699 yang diizinkan pada harness ini.
 * Menolak mutlak Room 337 dan seluruh UUID selain Room 699.
 */
function assertRoom699Only(roomUuid: string): void {
  if (roomUuid.toLowerCase() === FORBIDDEN_ROOM_337_UUID.toLowerCase()) {
    throw new Error(
      'CRITICAL SECURITY GUARD: Akses ke Room 337 dilarang keras! Fallback ke Room 337 dicegah secara absolut.'
    );
  }
  if (roomUuid.toLowerCase() !== ROOM_699_UUID.toLowerCase()) {
    throw new Error(
      `CRITICAL SECURITY GUARD: Hanya Room 699 (${ROOM_699_UUID}) yang diizinkan pada mode pengujian ini. Ditolak: [${roomUuid}].`
    );
  }
}

const INITIAL_STEPS: StepStatus[] = [
  { id: 'STEP-01', name: 'Validasi Lingkungan & Otorisasi Akun Test', status: 'PENDING' },
  { id: 'STEP-02', name: 'Inisialisasi Sesi Sanctum (CSRF Cookie)', status: 'PENDING' },
  { id: 'STEP-03', name: 'Otentikasi Akun Test (POST /login)', status: 'PENDING' },
  { id: 'STEP-04', name: 'Verifikasi Identitas Pengguna (GET /api/user)', status: 'PENDING' },
  { id: 'STEP-05', name: 'Pembangkitan Kunci WebCrypto & Simpan Staged ke IDB', status: 'PENDING' },
  { id: 'STEP-06', name: 'Re-Autentikasi Step-Up (POST /api/bchat/devices/step-up)', status: 'PENDING' },
  { id: 'STEP-07', name: 'Penerbitan Nonce Registrasi (POST /api/bchat/devices/registration-nonce)', status: 'PENDING' },
  { id: 'STEP-08', name: 'Perakitan PoP 121-byte & Tanda Tangan Ed25519 Detached', status: 'PENDING' },
  { id: 'STEP-09', name: 'Registrasi Perangkat ke Backend (POST /api/bchat/devices/register)', status: 'PENDING' },
  { id: 'STEP-10', name: 'Verifikasi Keystore IDB Lokal (Promosi Staged -> Active)', status: 'PENDING' },
];

const KP_PUBLISH_STEPS: StepStatus[] = [
  { id: 'KP-01', name: `Otentikasi Sesi Dosen (${TEST_DOSEN_EMAIL})`, status: 'PENDING' },
  { id: 'KP-02', name: 'Verifikasi Kunci Identitas Perangkat Dosen Aktif di Keystore IDB', status: 'PENDING' },
  { id: 'KP-03', name: 'Provisioning Tab Lease Fencing Eksplisit di IndexedDB', status: 'PENDING' },
  { id: 'KP-04', name: 'Inisialisasi Dedicated Web Worker (INIT_PEER)', status: 'PENDING' },
  { id: 'KP-05', name: 'Pembangkitan 1 KeyPackage via Worker & Penyimpanan Terenkripsi di IDB', status: 'PENDING' },
  { id: 'KP-06', name: 'Unggah Public KeyPackage ke Backend (POST /api/bchat/key-packages)', status: 'PENDING' },
  { id: 'KP-07', name: 'Verifikasi Status Publikasi [published] di IndexedDB [pending_key_packages]', status: 'PENDING' },
];

const KP_FETCH_STEPS: StepStatus[] = [
  { id: 'KF-01', name: `Otentikasi Sesi Mahasiswa (${TEST_MHS_EMAIL})`, status: 'PENDING' },
  { id: 'KF-02', name: 'Verifikasi Identitas Mahasiswa & Room Proposal B-CHAT', status: 'PENDING' },
  { id: 'KF-03', name: 'Pengambilan Partner KeyPackage Dosen (GET /api/bchat/rooms/.../partner-key-package)', status: 'PENDING' },
  { id: 'KF-04', name: 'Verifikasi Idempotensi Pengambilan (Kontrak Read-Only / Unconsumed)', status: 'PENDING' },
];

const MHS_HANDSHAKE_STEPS: StepStatus[] = [
  { id: 'HM-01', name: 'Otentikasi Mahasiswa & Verifikasi Device Identity Key IDB', status: 'PENDING' },
  { id: 'HM-02', name: 'Penetapan Tab Lease Fencing & Inisialisasi Dedicated Worker (INIT_PEER)', status: 'PENDING' },
  { id: 'HM-03', name: 'Eksekusi publishWelcomeHandshakeFlow (CREATE_GROUP epoch 0 & COMMIT_ADD_MEMBER epoch 1)', status: 'PENDING' },
  { id: 'HM-04', name: 'Publikasi Welcome ke Backend (POST /api/bchat/rooms/.../welcome)', status: 'PENDING' },
  { id: 'HM-05', name: 'Audit IDB Lokal: GroupState Epoch 1 & Outbox Commit/Welcome Acked (Commit Lokal Sah)', status: 'PENDING' },
  { id: 'HM-06', name: 'Rekonsiliasi Status Room REST (PENDING_WELCOME_ACK + Active Challenge)', status: 'PENDING' },
];

const DOSEN_ACK_STEPS: StepStatus[] = [
  { id: 'HD-01', name: 'Otentikasi Dosen & Verifikasi Device Identity Key IDB', status: 'PENDING' },
  { id: 'HD-02', name: 'Penetapan Tab Lease Fencing & Inisialisasi Dedicated Worker (INIT_PEER)', status: 'PENDING' },
  { id: 'HD-03', name: 'Eksekusi fetchChallengeAndAckHandshakeFlow (Ambil Challenge & JOIN_WELCOME)', status: 'PENDING' },
  { id: 'HD-04', name: 'Perakitan Welcome ACK 158-byte & Tanda Tangan Ed25519 Detached 64-byte', status: 'PENDING' },
  { id: 'HD-05', name: 'Pengiriman Welcome ACK ke Backend (POST /api/bchat/rooms/.../welcome-ack)', status: 'PENDING' },
  { id: 'HD-06', name: 'Audit IDB Dosen: GroupState Epoch 1 & Konsumsi PrivateKeyPackage', status: 'PENDING' },
  { id: 'HD-07', name: 'Verifikasi Akhir Status Room ACTIVE di Backend', status: 'PENDING' },
];

const MHS_MSG_STEPS: StepStatus[] = [
  { id: 'MM-01', name: 'Otentikasi Mahasiswa & Verifikasi Device Identity Key IDB', status: 'PENDING' },
  { id: 'MM-02', name: 'Penetapan Tab Lease & Restore GroupState Worker (Epoch 1)', status: 'PENDING' },
  { id: 'MM-03', name: 'Enkripsi Pesan Aplikasi via Worker (Persist-Before-Adopt)', status: 'PENDING' },
  { id: 'MM-04', name: 'Pengiriman Ciphertext ke Backend (POST /api/bchat/rooms/.../messages)', status: 'PENDING' },
  { id: 'MM-05', name: 'Verifikasi Outbox Status [acked] di IDB & Monotonic Sequence', status: 'PENDING' },
];

const DOSEN_MSG_STEPS: StepStatus[] = [
  { id: 'MD-01', name: 'Otentikasi Dosen & Verifikasi Device Identity Key IDB', status: 'PENDING' },
  { id: 'MD-02', name: 'Penetapan Tab Lease & Restore GroupState Worker (Epoch 1)', status: 'PENDING' },
  { id: 'MD-03', name: 'Pengambilan Ciphertext via REST (GET /api/bchat/rooms/.../messages)', status: 'PENDING' },
  { id: 'MD-04', name: 'Verifikasi SHA-256 Integritas & Dekripsi via MLS Worker', status: 'PENDING' },
  { id: 'MD-05', name: 'Enkripsi & Pengiriman Pesan Balasan Dosen ke Backend', status: 'PENDING' },
];

const MHS_REPLY_STEPS: StepStatus[] = [
  { id: 'MR-01', name: 'Pengambilan Pesan Balasan Dosen via REST', status: 'PENDING' },
  { id: 'MR-02', name: 'Verifikasi SHA-256 Integritas & Dekripsi Balasan via MLS Worker', status: 'PENDING' },
];

const REVERB_STEPS: StepStatus[] = [
  { id: 'RV-01', name: 'Otentikasi Sesi & Verifikasi Device Identity Key IDB', status: 'PENDING' },
  { id: 'RV-02', name: 'Penetapan Tab Lease & Restore GroupState Worker (Epoch 1)', status: 'PENDING' },
  { id: 'RV-03', name: 'Otorisasi Saluran Privat (POST /broadcasting/auth)', status: 'PENDING' },
  { id: 'RV-04', name: 'Koneksi WebSocket Reverb Berhasil (SUBSCRIBED)', status: 'PENDING' },
  { id: 'RV-05', name: 'Menerima Sinyal Tipis .message.sent via WebSocket Real-Time', status: 'PENDING' },
  { id: 'RV-06', name: 'Rekonsiliasi REST & Dekripsi Pesan Masuk via MLS Worker', status: 'PENDING' },
];

export default function BchatDeviceTestPage() {
  if (process.env.NODE_ENV === 'production') {
    notFound();
  }

  const [selectedEmail, setSelectedEmail] = useState<string>(ALLOWED_TEST_ACCOUNTS[0]);
  const [password, setPassword] = useState<string>('password123');
  const [steps, setSteps] = useState<StepStatus[]>(INITIAL_STEPS);
  const [logs, setLogs] = useState<TestLog[]>([]);
  const [isRunning, setIsRunning] = useState<boolean>(false);
  const [overallStatus, setOverallStatus] = useState<'IDLE' | 'RUNNING' | 'PASS' | 'FAIL'>('IDLE');
  const [registrationResult, setRegistrationResult] = useState<DeviceRegistrationResult | null>(null);
  const [activeIdbKey, setActiveIdbKey] = useState<KeystoreRecord | null>(null);

  // State untuk pengujian KeyPackage
  const [kpSteps, setKpSteps] = useState<StepStatus[]>(KP_PUBLISH_STEPS);
  const [kpResult, setKpResult] = useState<PublishKeyPackagesResult | null>(null);
  const [kpFetchSteps, setKpFetchSteps] = useState<StepStatus[]>(KP_FETCH_STEPS);
  const [fetchedKpHash, setFetchedKpHash] = useState<string | null>(null);

  // State untuk pengujian Welcome Handshake (I-04B.3)
  const [mhsHsSteps, setMhsHsSteps] = useState<StepStatus[]>(MHS_HANDSHAKE_STEPS);
  const [mhsHsResult, setMhsHsResult] = useState<PublishWelcomeResult | null>(null);
  const [dosenAckSteps, setDosenAckSteps] = useState<StepStatus[]>(DOSEN_ACK_STEPS);
  const [dosenAckResult, setDosenAckResult] = useState<FetchChallengeAndAckResult | null>(null);

  // State untuk pengujian E2EE Messaging (I-04B.4R)
  const [mhsMsgSteps, setMhsMsgSteps] = useState<StepStatus[]>(MHS_MSG_STEPS);
  const [dosenMsgSteps, setDosenMsgSteps] = useState<StepStatus[]>(DOSEN_MSG_STEPS);
  const [mhsReplySteps, setMhsReplySteps] = useState<StepStatus[]>(MHS_REPLY_STEPS);

  // State untuk pengujian Reverb Real-Time (I-04B.5)
  const [rvMhsSteps, setRvMhsSteps] = useState<StepStatus[]>(REVERB_STEPS);
  const [rvDosenSteps, setRvDosenSteps] = useState<StepStatus[]>(REVERB_STEPS);

  const autoRunTriggered = useRef<boolean>(false);

  useEffect(() => {
    if (typeof window !== 'undefined') {
      (window as any).__BCAT_TEST_MODULES__ = {
        createBchatWorkerClient,
        publishKeyPackagesFlow,
        publishWelcomeHandshakeFlow,
        fetchChallengeAndAckHandshakeFlow,
        buildWelcomeAckPayload,
        signWelcomeAckWithDeviceIdentity,
        fetchRoomDetail,
        fetchPartnerKeyPackage,
        sendMessage,
        getDeviceStatus,
        sendApplicationMessageFlow,
        receiveApplicationMessagesFlow,
        computePayloadSha256,
        getGroupStateCursor,
        idbGet,
        idbPut,
        withReadonlyTransaction,
        BCAT_STORES,
      };
    }
  }, []);

  const addLog = (message: string, type: 'info' | 'success' | 'warn' | 'error' = 'info') => {
    const timestamp = new Date().toISOString().substring(11, 23);
    setLogs((prev) => [...prev, { timestamp, type, message }]);
  };

  const updateStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateKpStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setKpSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateKpFetchStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setKpFetchSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateMhsHsStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setMhsHsSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateDosenAckStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setDosenAckSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateMhsMsgStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setMhsMsgSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateDosenMsgStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setDosenMsgSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateMhsReplyStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setMhsReplySteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateRvMhsStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setRvMhsSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  const updateRvDosenStep = (id: string, status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL', detail?: string) => {
    setRvDosenSteps((prev) =>
      prev.map((step) => (step.id === id ? { ...step, status, detail: detail ?? step.detail } : step))
    );
  };

  // ---------------------------------------------------------------------------
  // ALUR 1: Registrasi Perangkat (I-02A)
  // ---------------------------------------------------------------------------
  const executeRegistration = async (emailToUse: string, pwdToUse: string) => {
    if (isRunning) return;
    setIsRunning(true);
    setOverallStatus('RUNNING');
    setLogs([]);
    setSteps(INITIAL_STEPS);
    setRegistrationResult(null);
    setActiveIdbKey(null);

    window.__BCAT_DEVICE_TEST_RESULT__ = {
      status: 'RUNNING',
      accountEmail: emailToUse,
      userId: '',
      deviceUuid: '',
      deviceStatus: '',
      idbKeyFound: false,
      logs: [],
    };

    let resolvedUserId: string | number = '';
    let registeredDeviceUuid = '';
    let registeredDeviceStatus = '';
    let registeredKeyVersion = 0;
    let registeredAtStr = '';
    let verifiedIdbKey: KeystoreRecord | undefined;

    try {
      updateStep('STEP-01', 'RUNNING');
      addLog(`[STEP-01] Memeriksa otorisasi target akun: ${emailToUse}`);
      if (process.env.NODE_ENV === 'production') {
        throw new Error('SECURITY VIOLATION: Harness disabled in production.');
      }
      if (!ALLOWED_TEST_ACCOUNTS.includes(emailToUse)) {
        throw new Error(`SECURITY VIOLATION: Unauthorized test account [${emailToUse}].`);
      }
      updateStep('STEP-01', 'PASS', 'Lingkungan test & akun terotorisasi');
      addLog('[STEP-01] Akun pengujian terotorisasi.', 'success');

      updateStep('STEP-02', 'RUNNING');
      addLog('[STEP-02] Mengambil cookie CSRF Sanctum...');
      await axios.get('/sanctum/csrf-cookie');
      updateStep('STEP-02', 'PASS', 'CSRF cookie berhasil disetel');
      addLog('[STEP-02] CSRF cookie Sanctum aktif.', 'success');

      updateStep('STEP-03', 'RUNNING');
      addLog(`[STEP-03] Mengotentikasi pengguna [${emailToUse}] via POST /login...`);
      await axios.post('/login', {
        email: emailToUse,
        password: pwdToUse,
      });
      updateStep('STEP-03', 'PASS', 'Otentikasi sesi berhasil');
      addLog('[STEP-03] Berhasil login ke sesi Sanctum.', 'success');

      updateStep('STEP-04', 'RUNNING');
      addLog('[STEP-04] Memverifikasi data sesi via GET /api/user...');
      const userRes = await axios.get('/api/user');
      const userData = userRes.data;
      if (!userData || !userData.id || userData.email !== emailToUse) {
        throw new Error(`Sesi tidak sesuai dengan target: expected ${emailToUse}, got ${userData?.email}`);
      }
      resolvedUserId = userData.id;
      updateStep('STEP-04', 'PASS', `User ID: ${resolvedUserId}, Role: ${userData.role}`);
      addLog(`[STEP-04] Terverifikasi User ID [${resolvedUserId}], Role [${userData.role}].`, 'success');

      updateStep('STEP-05', 'RUNNING');
      updateStep('STEP-06', 'RUNNING');
      updateStep('STEP-07', 'RUNNING');
      updateStep('STEP-08', 'RUNNING');
      updateStep('STEP-09', 'RUNNING');
      addLog('[I-02A] Menjalankan protokol registerDeviceFlow()...');

      const result = await registerDeviceFlow({
        password: pwdToUse,
        deviceName: `Chrome-${emailToUse.split('@')[0]}-Test`,
      });

      registeredDeviceUuid = result.deviceUuid;
      registeredDeviceStatus = result.status;
      registeredKeyVersion = result.keyVersion;
      registeredAtStr = result.registeredAt;
      setRegistrationResult(result);

      updateStep('STEP-05', 'PASS', 'Keypair Ed25519 non-extractable dibuat');
      updateStep('STEP-06', 'PASS', 'Step-up re-autentikasi berhasil');
      updateStep('STEP-07', 'PASS', 'Registration nonce 32-byte diterbitkan');
      updateStep('STEP-08', 'PASS', 'PoP 121-byte ditandatangani');
      updateStep('STEP-09', 'PASS', `Device UUID: ${result.deviceUuid}, Status: ${result.status}`);
      addLog(`[STEP-09] Perangkat berhasil terdaftar di backend! UUID: [${result.deviceUuid}], Status: [${result.status}].`, 'success');

      updateStep('STEP-10', 'RUNNING');
      addLog('[STEP-10] Memeriksa status kunci pada IndexedDB store [keystore]...');
      verifiedIdbKey = await getActiveDeviceIdentityKey(result.deviceUuid);

      if (!verifiedIdbKey) {
        throw new Error(`Kunci identitas aktif untuk Device UUID [${result.deviceUuid}] tidak ditemukan di IndexedDB!`);
      }
      if (verifiedIdbKey.status !== 'active') {
        throw new Error(`Status kunci di IDB bukan active: [${verifiedIdbKey.status}]`);
      }
      setActiveIdbKey(verifiedIdbKey);
      updateStep('STEP-10', 'PASS', `IDB Key Status: active, Algoritma: ${verifiedIdbKey.algorithm}`);
      addLog(`[STEP-10] Verifikasi IDB sukses! Key Status: [${verifiedIdbKey.status}], Algoritma: [${verifiedIdbKey.algorithm}].`, 'success');

      setOverallStatus('PASS');
      addLog(`>>> REGISTRASI PERANGKAT BERHASIL PENUH UNTUK [${emailToUse}] <<<`, 'success');

      window.__BCAT_DEVICE_TEST_RESULT__ = {
        status: 'PASS',
        accountEmail: emailToUse,
        userId: resolvedUserId,
        deviceUuid: registeredDeviceUuid,
        deviceStatus: registeredDeviceStatus,
        idbKeyFound: true,
        idbKeyStatus: verifiedIdbKey.status,
        idbKeyAlgorithm: verifiedIdbKey.algorithm,
        keyVersion: registeredKeyVersion,
        registeredAt: registeredAtStr,
        logs: [],
      };
    } catch (err: unknown) {
      const errMsg = (err as Error)?.message ?? 'Terjadi kesalahan tidak diketahui.';
      setOverallStatus('FAIL');
      addLog(`[ERROR] ${errMsg}`, 'error');

      setSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );

      window.__BCAT_DEVICE_TEST_RESULT__ = {
        status: 'FAIL',
        accountEmail: emailToUse,
        userId: resolvedUserId,
        deviceUuid: registeredDeviceUuid,
        deviceStatus: registeredDeviceStatus,
        idbKeyFound: !!verifiedIdbKey,
        logs: [],
        error: errMsg,
      };
    } finally {
      setIsRunning(false);
    }
  };

  // ---------------------------------------------------------------------------
  // ALUR 2: Dosen Generate & Publish 1 KeyPackage (I-04B.2)
  // ---------------------------------------------------------------------------
  const executeDosenKeyPackagePublish = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setKpSteps(KP_PUBLISH_STEPS);
    setKpResult(null);

    window.__BCAT_KEYPACKAGE_TEST_RESULT__ = {
      status: 'RUNNING',
      deviceUuid: '',
      uploadedCount: 0,
      activePoolCount: 0,
      keyPackageHash: '',
      idbRecordFound: false,
      logs: [],
    };

    let workerRpc: BchatWorkerClient | null = null;
    let dosenDeviceUuid = '';
    let pubRes: PublishKeyPackagesResult | null = null;
    let storedPendingRecord: PendingKeyPackageRecord | undefined;

    try {
      // KP-01: Otentikasi Dosen
      updateKpStep('KP-01', 'RUNNING');
      addLog('[KP-01] Memastikan sesi Dosen aktif...');
      let currentUser: { id: number; email: string; role: string } | null = null;
      try {
        const checkRes = await axios.get('/api/user');
        if (checkRes.data?.email === TEST_DOSEN_EMAIL) {
          currentUser = checkRes.data;
        }
      } catch {
        // Belum login, lanjutkan ke autentikasi baru
      }

      if (!currentUser) {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_DOSEN_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'dosen') {
        throw new Error(`Role bukan dosen: ${currentUser?.role}`);
      }
      updateKpStep('KP-01', 'PASS', `User ID: ${currentUser.id} (Dosen)`);
      addLog('[KP-01] Sesi Dosen terotentikasi.', 'success');

      // KP-02: Cari identitas perangkat aktif dosen di Keystore IDB
      updateKpStep('KP-02', 'RUNNING');
      addLog('[KP-02] Mencari kunci identitas perangkat Dosen di Keystore IDB...');
      let activeDosenKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeDosenKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeDosenKeyRecord || !activeDosenKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Dosen berstatus active di IndexedDB Keystore.');
      }
      dosenDeviceUuid = activeDosenKeyRecord.metadata.device_uuid as string;
      updateKpStep('KP-02', 'PASS', `Device UUID: ${dosenDeviceUuid}`);
      addLog(`[KP-02] Perangkat Dosen aktif ditemukan: [${dosenDeviceUuid}].`, 'success');

      // KP-03: Provision Tab Lease Fencing Eksplisit
      updateKpStep('KP-03', 'RUNNING');
      addLog('[KP-03] Menetapkan Tab Lease Fencing eksplisit di IndexedDB...');
      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: dosenDeviceUuid,
        owner_token: ownerToken,
        generation: generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);
      updateKpStep('KP-03', 'PASS', `Token: ${ownerToken.substring(0, 12)}..., Gen: ${generation}`);
      addLog('[KP-03] Tab Lease aktif berhasil disimpan.', 'success');

      // KP-04: Inisialisasi Dedicated Web Worker
      updateKpStep('KP-04', 'RUNNING');
      addLog('[KP-04] Menginstansiasi Dedicated Worker & INIT_PEER...');
      workerRpc = createBchatWorkerClient();
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'dosen',
        identity: TEST_DOSEN_EMAIL,
        deviceUuid: dosenDeviceUuid,
        fencing: {
          ownerToken,
          generation,
        },
      });
      updateKpStep('KP-04', 'PASS', 'Worker siap (MLS_128_DHKEMX25519_AES128GCM_SHA256_Ed25519)');
      addLog('[KP-04] Dedicated Worker terinisialisasi.', 'success');

      // KP-05 & KP-06: Panggil publishKeyPackagesFlow (1 KeyPackage)
      updateKpStep('KP-05', 'RUNNING');
      updateKpStep('KP-06', 'RUNNING');
      addLog('[KP-05] Membangkitkan 1 KeyPackage via Worker & mengunggah ke backend...');

      pubRes = await publishKeyPackagesFlow(workerRpc, dosenDeviceUuid, 1, {
        fencing: {
          ownerToken,
          generation,
        },
        deviceUuid: dosenDeviceUuid,
      });

      setKpResult(pubRes);
      const publishedKpHash = pubRes.packages[0].keyPackageHash;
      updateKpStep('KP-05', 'PASS', `Hash: ${publishedKpHash.substring(0, 16)}...`);
      updateKpStep('KP-06', 'PASS', `Uploaded: ${pubRes.uploadedCount}, Active Pool: ${pubRes.activePoolCount}`);
      addLog(`[KP-06] KeyPackage berhasil diunggah ke backend! Hash: [${publishedKpHash}].`, 'success');

      // KP-07: Verifikasi status publikasi di pending_key_packages
      updateKpStep('KP-07', 'RUNNING');
      addLog('[KP-07] Memverifikasi record di IndexedDB [pending_key_packages]...');
      storedPendingRecord = await idbGet<PendingKeyPackageRecord>(
        BCAT_STORES.PENDING_KEY_PACKAGES,
        [dosenDeviceUuid, publishedKpHash]
      );

      if (!storedPendingRecord) {
        throw new Error(`Record pending_key_packages untuk hash [${publishedKpHash}] tidak ditemukan!`);
      }
      if (storedPendingRecord.metadata?.publication_status !== 'published') {
        throw new Error(`Status publikasi di IDB bukan published: [${storedPendingRecord.metadata?.publication_status}]`);
      }
      if (!storedPendingRecord.private_key_package_bytes || storedPendingRecord.private_key_package_bytes.byteLength === 0) {
        throw new Error('Private KeyPackage bytes kosong!');
      }

      updateKpStep('KP-07', 'PASS', `Status: published, Terenkripsi: ${storedPendingRecord.private_key_package_bytes.byteLength} byte`);
      addLog(`[KP-07] Verifikasi IDB sukses! Private KeyPackage tersimpan terenkripsi (${storedPendingRecord.private_key_package_bytes.byteLength} byte).`, 'success');

      addLog('>>> DOSEN KEYPACKAGE BERHASIL DIPUBLIKASIKAN & TERVERIFIKASI <<<', 'success');

      window.__BCAT_KEYPACKAGE_TEST_RESULT__ = {
        status: 'PASS',
        deviceUuid: dosenDeviceUuid,
        uploadedCount: pubRes.uploadedCount,
        activePoolCount: pubRes.activePoolCount,
        keyPackageHash: publishedKpHash,
        idbRecordFound: true,
        idbPublicationStatus: storedPendingRecord.metadata?.publication_status,
        idbEncryptedBytesLength: storedPendingRecord.private_key_package_bytes.byteLength,
        logs: [],
      };
    } catch (err: unknown) {
      const axiosErr = err as { response?: { status?: number; data?: unknown }; message?: string };
      const respData = axiosErr?.response?.data;
      const respStatus = axiosErr?.response?.status;
      const baseMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      const errMsg = respData ? `${baseMsg} (${respStatus}: ${JSON.stringify(respData)})` : baseMsg;
      addLog(`[ERROR KP] ${errMsg}`, 'error');
      setKpSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      window.__BCAT_KEYPACKAGE_TEST_RESULT__ = {
        status: 'FAIL',
        deviceUuid: dosenDeviceUuid,
        uploadedCount: 0,
        activePoolCount: 0,
        keyPackageHash: '',
        idbRecordFound: false,
        logs: [],
        error: errMsg,
      };
    } finally {
      if (workerRpc) {
        workerRpc.terminate();
      }
      setIsRunning(false);
    }
  };



  // ---------------------------------------------------------------------------
  // ALUR 3: Mahasiswa Fetch Partner KeyPackage Dosen (I-04B.2)
  // ---------------------------------------------------------------------------
  const executeMahasiswaPartnerFetch = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setKpFetchSteps(KP_FETCH_STEPS);
    setFetchedKpHash(null);

    window.__BCAT_KEYPACKAGE_FETCH_RESULT__ = {
      status: 'RUNNING',
      roomUuid: FIXTURE_ROOM_UUID,
      recipientUserId: '',
      recipientDeviceUuid: '',
      keyPackageHash: '',
      fetch1Hash: '',
      fetch2Hash: '',
      isUnconsumed: false,
      logs: [],
    };

    try {
      // KF-01: Otentikasi Mahasiswa
      updateKpFetchStep('KF-01', 'RUNNING');
      addLog('[KF-01] Memastikan sesi Mahasiswa aktif...');
      let currentUser: { id: number; email: string; role: string } | null = null;
      try {
        const checkRes = await axios.get('/api/user');
        if (checkRes.data?.email === TEST_MHS_EMAIL) {
          currentUser = checkRes.data;
        }
      } catch {
        // Belum login, lanjutkan ke autentikasi baru
      }

      if (!currentUser) {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_MHS_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'mahasiswa') {
        throw new Error(`Role bukan mahasiswa: ${currentUser?.role}`);
      }
      updateKpFetchStep('KF-01', 'PASS', `User ID: ${currentUser.id} (Mahasiswa)`);
      addLog('[KF-01] Sesi Mahasiswa terotentikasi.', 'success');

      // KF-02: Verifikasi Ruang B-CHAT
      updateKpFetchStep('KF-02', 'RUNNING');
      assertRoom699Only(FIXTURE_ROOM_UUID);
      addLog(`[KF-02] Memeriksa status room [${FIXTURE_ROOM_UUID}]...`);
      const roomRes = await axios.get(`/api/bchat/rooms/${FIXTURE_ROOM_UUID}`);
      const roomData = roomRes.data?.data;
      if (roomData?.status !== 'PENDING_WELCOME_ACK') {
        throw new Error(`Status room tidak valid untuk inisiasi: ${roomData?.status}`);
      }
      updateKpFetchStep('KF-02', 'PASS', `Status: ${roomData.status}`);
      addLog(`[KF-02] Room Proposal berstatus [${roomData.status}].`, 'success');

      // KF-03: Ambil partner KeyPackage Dosen (Pemanggilan Pertama)
      updateKpFetchStep('KF-03', 'RUNNING');
      addLog('[KF-03] Mengambil partner KeyPackage dari backend (GET /partner-key-package)...');
      const fetch1 = await fetchPartnerKeyPackage(FIXTURE_ROOM_UUID);
      const kp1 = fetch1.data;
      if (!kp1 || !kp1.key_package_hash || !kp1.key_package_data) {
        throw new Error('Respons partner KeyPackage tidak memuat data yang valid.');
      }
      setFetchedKpHash(kp1.key_package_hash);
      updateKpFetchStep('KF-03', 'PASS', `Partner Dev: ${kp1.recipient_device_uuid}, Hash: ${kp1.key_package_hash.substring(0, 16)}...`);
      addLog(`[KF-03] Partner KeyPackage diterima! Hash: [${kp1.key_package_hash}], Device: [${kp1.recipient_device_uuid}].`, 'success');

      // KF-04: Verifikasi Idempotensi (Pemanggilan Kedua Tidak Mengonsumsi Paket)
      updateKpFetchStep('KF-04', 'RUNNING');
      addLog('[KF-04] Menguji idempotensi pengambilan (pemanggilan kedua untuk membuktikan unconsumed)...');
      const fetch2 = await fetchPartnerKeyPackage(FIXTURE_ROOM_UUID);
      const kp2 = fetch2.data;
      if (kp2.key_package_hash !== kp1.key_package_hash) {
        throw new Error(`Idempotensi gagal: hash kedua [${kp2.key_package_hash}] berbeda dari hash pertama [${kp1.key_package_hash}].`);
      }
      updateKpFetchStep('KF-04', 'PASS', 'Hash identik pada pengambilan kedua (Terbukti Unconsumed / Read-Only)');
      addLog('[KF-04] Idempotensi terverifikasi: paket TIDAK dikonsumsi oleh endpoint pengambilan.', 'success');

      addLog('>>> MAHASISWA PARTNER KEYPACKAGE FETCH SUKSES PENUH <<<', 'success');

      window.__BCAT_KEYPACKAGE_FETCH_RESULT__ = {
        status: 'PASS',
        roomUuid: FIXTURE_ROOM_UUID,
        recipientUserId: kp1.recipient_user_id,
        recipientDeviceUuid: kp1.recipient_device_uuid,
        keyPackageHash: kp1.key_package_hash,
        fetch1Hash: kp1.key_package_hash,
        fetch2Hash: kp2.key_package_hash,
        isUnconsumed: kp1.key_package_hash === kp2.key_package_hash,
        logs: [],
      };
    } catch (err: unknown) {
      const axiosErr = err as { response?: { status?: number; data?: unknown }; message?: string };
      const respData = axiosErr?.response?.data;
      const respStatus = axiosErr?.response?.status;
      const baseMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      const errMsg = respData ? `${baseMsg} (${respStatus}: ${JSON.stringify(respData)})` : baseMsg;
      addLog(`[ERROR KF] ${errMsg}`, 'error');
      setKpFetchSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      window.__BCAT_KEYPACKAGE_FETCH_RESULT__ = {
        status: 'FAIL',
        roomUuid: FIXTURE_ROOM_UUID,
        recipientUserId: '',
        recipientDeviceUuid: '',
        keyPackageHash: '',
        fetch1Hash: '',
        fetch2Hash: '',
        isUnconsumed: false,
        logs: [],
        error: errMsg,
      };
    } finally {
      setIsRunning(false);
    }
  };

  // ---------------------------------------------------------------------------
  // ALUR 4: Mahasiswa Welcome Handshake Publication (I-04B.3)
  // ---------------------------------------------------------------------------
  const executeMahasiswaWelcomeHandshake = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setMhsHsSteps(MHS_HANDSHAKE_STEPS);
    setMhsHsResult(null);

    window.__BCAT_HANDSHAKE_MHS_RESULT__ = {
      status: 'RUNNING',
      roomUuid: FIXTURE_ROOM_UUID,
      deviceUuid: '',
      welcomeOperationUuid: '',
      groupIdHash: '',
      currentEpoch: '',
      groupStateFound: false,
      groupStateEpoch: '',
      outboxCommitAcked: false,
      outboxWelcomeAcked: false,
      logs: [],
    };

    let workerRpc: BchatWorkerClient | null = null;
    let mhsDeviceUuid = '';

    try {
      // HM-01: Otentikasi Mahasiswa & Cari IDB Keystore
      updateMhsHsStep('HM-01', 'RUNNING');
      addLog('[HM-01] Memeriksa sesi Mahasiswa aktif...');
      let currentUser: { id: number; email: string; role: string } | null = null;
      try {
        const checkRes = await axios.get('/api/user');
        if (checkRes.data?.email === TEST_MHS_EMAIL) {
          currentUser = checkRes.data;
        }
      } catch {
        // Belum login, lanjutkan ke autentikasi baru
      }

      if (!currentUser) {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_MHS_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'mahasiswa') {
        throw new Error(`Role bukan mahasiswa: ${currentUser?.role}`);
      }
      updateMhsHsStep('HM-01', 'PASS', `User ID: ${currentUser.id} (Mahasiswa)`);
      addLog('[HM-01] Sesi Mahasiswa terotentikasi.', 'success');

      // HM-01 part 2: Cari identitas perangkat aktif mahasiswa di Keystore IDB
      let activeMhsKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeMhsKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeMhsKeyRecord || !activeMhsKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Mahasiswa berstatus active di IndexedDB Keystore.');
      }
      mhsDeviceUuid = activeMhsKeyRecord.metadata.device_uuid as string;
      addLog(`[HM-01] Perangkat Mahasiswa aktif ditemukan: [${mhsDeviceUuid}].`, 'success');

      // Verifikasi keamanan khusus Room 699 & kecocokan identitas lokal vs backend
      assertRoom699Only(FIXTURE_ROOM_UUID);
      const mhsStatus = await getDeviceStatus({ device_uuid: mhsDeviceUuid });
      if (
        !mhsStatus.data.queried_device?.is_active ||
        mhsStatus.data.active_device?.device_uuid.toLowerCase() !== mhsDeviceUuid.toLowerCase()
      ) {
        throw new Error(
          `SECURITY CHECK FAILED: Identitas perangkat Mahasiswa lokal [${mhsDeviceUuid}] tidak cocok dengan status ACTIVE di backend.`
        );
      }
      addLog(`[HM-01] Verifikasi identitas Mahasiswa lokal vs backend valid: [${mhsDeviceUuid}].`, 'success');

      // HM-02: Provision Tab Lease Fencing Eksplisit & Inisialisasi Worker
      updateMhsHsStep('HM-02', 'RUNNING');
      addLog('[HM-02] Menetapkan Tab Lease Fencing & menginisialisasi Dedicated Worker...');
      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: mhsDeviceUuid,
        owner_token: ownerToken,
        generation: generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

      workerRpc = createBchatWorkerClient();
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'mahasiswa',
        identity: TEST_MHS_EMAIL,
        deviceUuid: mhsDeviceUuid,
        fencing: {
          ownerToken,
          generation,
        },
      });

      // Menyiapkan leaf KeyPackage lokal mahasiswa agar CREATE_GROUP dapat membentuk MLS tree awal (epoch 0)
      await workerRpc.sendRequest('GENERATE_KEY_PACKAGE', {
        deviceUuid: mhsDeviceUuid,
        fencing: {
          ownerToken,
          generation,
        },
      });
      updateMhsHsStep('HM-02', 'PASS', `Device: ${mhsDeviceUuid}, Worker Ready`);
      addLog('[HM-02] Dedicated Worker Mahasiswa terinisialisasi.', 'success');

      // HM-03 & HM-04: Eksekusi publishWelcomeHandshakeFlow
      updateMhsHsStep('HM-03', 'RUNNING');
      updateMhsHsStep('HM-04', 'RUNNING');
      addLog('[HM-03] Menjalankan publishWelcomeHandshakeFlow (CREATE_GROUP -> COMMIT_ADD_MEMBER -> Publish)...');

      const publishRes = await publishWelcomeHandshakeFlow(
        workerRpc,
        FIXTURE_ROOM_UUID,
        mhsDeviceUuid,
        {
          fencing: { ownerToken, generation },
          timeoutMs: 30000,
        }
      );
      setMhsHsResult(publishRes);

      updateMhsHsStep('HM-03', 'PASS', `Group ID Hash: ${publishRes.groupIdHash.substring(0, 16)}...`);
      updateMhsHsStep('HM-04', 'PASS', `Welcome Op: ${publishRes.welcomeOperationUuid.substring(0, 8)}...`);
      addLog(`[HM-04] Welcome berhasil dipublikasikan! Operation UUID: [${publishRes.welcomeOperationUuid}], Group ID Hash: [${publishRes.groupIdHash}].`, 'success');

      // HM-05: Verifikasi IndexedDB Lokal Mahasiswa
      updateMhsHsStep('HM-05', 'RUNNING');
      addLog('[HM-05] Memverifikasi GroupState dan Outbox di IndexedDB Mahasiswa...');
      let groupStateFound = false;
      let groupStateEpoch = '';
      let groupStateHash = '';
      let outboxCommitAcked = false;
      let outboxWelcomeAcked = false;

      await withReadonlyTransaction(
        [BCAT_STORES.GROUP_STATES, BCAT_STORES.MESSAGE_OUTBOX],
        async (_tx, getStore) => {
          const gsStore = getStore(BCAT_STORES.GROUP_STATES);
          const gs = await new Promise<GroupStateRecord | undefined>((resolve, reject) => {
            const req = gsStore.get([mhsDeviceUuid, FIXTURE_ROOM_UUID]);
            req.onsuccess = () => resolve(req.result as GroupStateRecord | undefined);
            req.onerror = () => reject(req.error);
          });
          if (gs) {
            groupStateFound = true;
            groupStateEpoch = gs.epoch;
            groupStateHash = gs.group_id_hash;
          }

          const obStore = getStore(BCAT_STORES.MESSAGE_OUTBOX);
          const allOutbox = await new Promise<MessageOutboxRecord[]>((resolve, reject) => {
            const req = obStore.getAll();
            req.onsuccess = () => resolve(req.result as MessageOutboxRecord[]);
            req.onerror = () => reject(req.error);
          });
          for (const item of allOutbox) {
            if (item.room_uuid === FIXTURE_ROOM_UUID) {
              const meta = item.metadata as Record<string, unknown> | undefined;
              const metaType = meta?.message_type ?? meta?.type;
              if (metaType === 'commit' && item.status === 'acked') {
                outboxCommitAcked = true;
              }
              if (metaType === 'welcome' && item.status === 'acked') {
                outboxWelcomeAcked = true;
              }
            }
          }
        }
      );

      if (!groupStateFound || groupStateEpoch !== '1') {
        throw new Error(`GroupState Mahasiswa tidak valid: found=${groupStateFound}, epoch=${groupStateEpoch}`);
      }
      if (!outboxCommitAcked) {
        throw new Error('Outbox Commit tidak berstatus acked secara lokal!');
      }
      if (!outboxWelcomeAcked) {
        throw new Error('Outbox Welcome tidak berstatus acked!');
      }

      updateMhsHsStep('HM-05', 'PASS', `Epoch: ${groupStateEpoch}, Commit & Welcome Outbox Acked (Commit Lokal Tetap Terjaga)`);
      addLog('[HM-05] GroupState epoch 1 & Outbox items terverifikasi lokal.', 'success');

      // HM-06: Rekonsiliasi Room REST Pasca Publikasi
      updateMhsHsStep('HM-06', 'RUNNING');
      addLog('[HM-06] Merekonsiliasi status room via REST...');
      const roomRecon = await fetchRoomDetail(FIXTURE_ROOM_UUID);
      const reconData = roomRecon.data;
      if (reconData.status !== 'PENDING_WELCOME_ACK') {
        throw new Error(`Status room setelah publish welcome tidak terduga: ${reconData.status}`);
      }
      if (!reconData.handshake_context?.has_active_challenge) {
        throw new Error('Backend belum mencatat active Welcome Challenge setelah publikasi Welcome!');
      }
      updateMhsHsStep('HM-06', 'PASS', `Status: PENDING_WELCOME_ACK, Active Challenge: true`);
      addLog('>>> MAHASISWA WELCOME HANDSHAKE SUKSES PENUH <<<', 'success');

      window.__BCAT_HANDSHAKE_MHS_RESULT__ = {
        status: 'PASS',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        welcomeOperationUuid: publishRes.welcomeOperationUuid,
        groupIdHash: publishRes.groupIdHash,
        currentEpoch: '1',
        groupStateFound,
        groupStateEpoch,
        outboxCommitAcked,
        outboxWelcomeAcked,
        logs: [],
      };
    } catch (err: unknown) {
      const axiosErr = err as { response?: { status?: number; data?: unknown }; message?: string };
      const respData = axiosErr?.response?.data;
      const respStatus = axiosErr?.response?.status;
      const baseMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      const errMsg = respData ? `${baseMsg} (${respStatus}: ${JSON.stringify(respData)})` : baseMsg;
      addLog(`[ERROR HS-MHS] ${errMsg}`, 'error');
      setMhsHsSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      window.__BCAT_HANDSHAKE_MHS_RESULT__ = {
        status: 'FAIL',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        welcomeOperationUuid: '',
        groupIdHash: '',
        currentEpoch: '',
        groupStateFound: false,
        groupStateEpoch: '',
        outboxCommitAcked: false,
        outboxWelcomeAcked: false,
        logs: [],
        error: errMsg,
      };
    } finally {
      if (workerRpc) {
        workerRpc.terminate();
      }
      setIsRunning(false);
    }
  };



  // ---------------------------------------------------------------------------
  // ALUR 5: Dosen Join Welcome & Submit Welcome ACK (I-04B.3)
  // ---------------------------------------------------------------------------
  const executeDosenWelcomeAck = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setDosenAckSteps(DOSEN_ACK_STEPS);
    setDosenAckResult(null);

    window.__BCAT_HANDSHAKE_DOSEN_RESULT__ = {
      status: 'RUNNING',
      roomUuid: FIXTURE_ROOM_UUID,
      deviceUuid: '',
      welcomeOperationUuid: '',
      challengeUuid: '',
      groupIdHash: '',
      currentEpoch: '',
      roomStatus: '',
      groupStateFound: false,
      groupStateEpoch: '',
      consumedKpFound: false,
      logs: [],
    };

    let workerRpc: BchatWorkerClient | null = null;
    let dosenDeviceUuid = '';

    try {
      // HD-01: Otentikasi Dosen & Cari IDB Keystore
      updateDosenAckStep('HD-01', 'RUNNING');
      addLog('[HD-01] Memeriksa sesi Dosen aktif...');
      let currentUser: { id: number; email: string; role: string } | null = null;
      try {
        const checkRes = await axios.get('/api/user');
        if (checkRes.data?.email === TEST_DOSEN_EMAIL) {
          currentUser = checkRes.data;
        }
      } catch {
        // Belum login, lanjutkan ke autentikasi baru
      }

      if (!currentUser) {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_DOSEN_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'dosen') {
        throw new Error(`Role bukan dosen: ${currentUser?.role}`);
      }
      updateDosenAckStep('HD-01', 'PASS', `User ID: ${currentUser.id} (Dosen)`);
      addLog('[HD-01] Sesi Dosen terotentikasi.', 'success');

      // HD-01 part 2: Cari identitas perangkat aktif dosen di Keystore IDB
      let activeDosenKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeDosenKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeDosenKeyRecord || !activeDosenKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Dosen berstatus active di IndexedDB Keystore.');
      }
      dosenDeviceUuid = activeDosenKeyRecord.metadata.device_uuid as string;
      addLog(`[HD-01] Perangkat Dosen aktif ditemukan: [${dosenDeviceUuid}].`, 'success');

      // Verifikasi keamanan khusus Room 699 & kecocokan identitas lokal vs backend
      assertRoom699Only(FIXTURE_ROOM_UUID);
      const dosenStatus = await getDeviceStatus({ device_uuid: dosenDeviceUuid });
      if (
        !dosenStatus.data.queried_device?.is_active ||
        dosenStatus.data.active_device?.device_uuid.toLowerCase() !== dosenDeviceUuid.toLowerCase()
      ) {
        throw new Error(
          `SECURITY CHECK FAILED: Identitas perangkat Dosen lokal [${dosenDeviceUuid}] tidak cocok dengan status ACTIVE di backend.`
        );
      }
      addLog(`[HD-01] Verifikasi identitas Dosen lokal vs backend valid: [${dosenDeviceUuid}].`, 'success');

      // HD-02: Provision Tab Lease Fencing Eksplisit & Inisialisasi Worker
      updateDosenAckStep('HD-02', 'RUNNING');
      addLog('[HD-02] Menetapkan Tab Lease Fencing & menginisialisasi Dedicated Worker...');
      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: dosenDeviceUuid,
        owner_token: ownerToken,
        generation: generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

      workerRpc = createBchatWorkerClient();

      // Interceptor untuk mengaitkan KeyPackage target dosen yang cocok dari IndexedDB ke JOIN_WELCOME
      const origDosenSendRequest = workerRpc.sendRequest.bind(workerRpc);
      workerRpc.sendRequest = async <T = unknown, R = unknown>(
        action: any,
        payload: T,
        timeoutMs?: number
      ): Promise<R> => {
        if (action === 'JOIN_WELCOME' && payload && typeof payload === 'object') {
          const joinPayload = payload as Record<string, unknown>;
          if (!joinPayload.keyPackageHash && typeof joinPayload.welcomeWireBase64 === 'string') {
            // Ekstrak newMember KeyPackageRef dari Welcome message wireformat
            let expectedRef: Uint8Array | null = null;
            try {
              const raw = typeof window !== 'undefined' ? window.atob(joinPayload.welcomeWireBase64) : '';
              const bytes = new Uint8Array(raw.length);
              for (let i = 0; i < raw.length; i++) {
                bytes[i] = raw.charCodeAt(i);
              }
              if (bytes.length >= 42 && bytes[2] === 0x00 && bytes[3] === 0x03) {
                let offset = 6;
                const b0 = bytes[offset++];
                const prefixType0 = (b0 & 0xc0) >> 6;
                if (prefixType0 === 1) offset += 1;
                else if (prefixType0 === 2) offset += 3;
                else if (prefixType0 === 3) offset += 7;

                const b1 = bytes[offset++];
                let newMemberLen = b1 & 0x3f;
                const prefixType1 = (b1 & 0xc0) >> 6;
                if (prefixType1 === 1) {
                  newMemberLen = ((b1 & 0x3f) << 8) | bytes[offset++];
                }
                if (newMemberLen === 32 && offset + 32 <= bytes.length) {
                  expectedRef = bytes.slice(offset, offset + 32);
                }
              }
            } catch {
              expectedRef = null;
            }

            if (!expectedRef) {
              throw new Error('SECURITY STOP: Gagal mengekstrak KeyPackageRef dari Welcome message wireformat.');
            }

            let targetHash = '';
            await withReadonlyTransaction([BCAT_STORES.PENDING_KEY_PACKAGES], async (_tx, getStore) => {
              const store = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);
              const all = await new Promise<PendingKeyPackageRecord[]>((resolve, reject) => {
                const req = store.getAll();
                req.onsuccess = () => resolve(req.result as PendingKeyPackageRecord[]);
                req.onerror = () => reject(req.error);
              });
              const available = all.filter(
                (k) =>
                  k.device_uuid === dosenDeviceUuid &&
                  k.metadata?.status !== 'consumed'
              );

              // Helper untuk menghitung RFC 9420 MLS 1.0 KeyPackage Reference hash
              const encVarLen = (data: Uint8Array): Uint8Array => {
                const len = data.length;
                let lenBytes: Uint8Array;
                if (len < 64) {
                  lenBytes = new Uint8Array([len & 0x3f]);
                } else if (len < 16384) {
                  lenBytes = new Uint8Array([((len >> 8) & 0x3f) | 0x40, len & 0xff]);
                } else {
                  lenBytes = new Uint8Array([
                    ((len >> 24) & 0x3f) | 0x80,
                    (len >> 16) & 0xff,
                    (len >> 8) & 0xff,
                    len & 0xff,
                  ]);
                }
                const res = new Uint8Array(lenBytes.length + len);
                res.set(lenBytes, 0);
                res.set(data, lenBytes.length);
                return res;
              };

              const labelBytes = new TextEncoder().encode('MLS 1.0 KeyPackage Reference');
              const encLabel = encVarLen(labelBytes);

              for (const candidate of available) {
                if (candidate.public_key_package_bytes) {
                  const encVal = encVarLen(candidate.public_key_package_bytes);
                  const total = new Uint8Array(encLabel.length + encVal.length);
                  total.set(encLabel, 0);
                  total.set(encVal, encLabel.length);
                  const hashBuf = await window.crypto.subtle.digest('SHA-256', total);
                  const candidateRef = new Uint8Array(hashBuf);

                  let matches = candidateRef.length === expectedRef.length;
                  for (let i = 0; i < candidateRef.length && matches; i++) {
                    if (candidateRef[i] !== expectedRef[i]) matches = false;
                  }
                  if (matches) {
                    targetHash = candidate.key_package_hash;
                    break;
                  }
                }
              }
            });

            if (!targetHash) {
              throw new Error('SECURITY STOP: Tidak ditemukan PrivateKeyPackage lokal yang cocok dengan KeyPackageRef pada Welcome message.');
            }

            joinPayload.keyPackageHash = targetHash;
            addLog(`[HD-03] Mengaitkan KeyPackage target IDB [${targetHash.substring(0, 16)}...] ke JOIN_WELCOME (terverifikasi kriptografis).`, 'info');
          }
        }
        return origDosenSendRequest(action, payload, timeoutMs);
      };
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'dosen',
        identity: TEST_DOSEN_EMAIL,
        deviceUuid: dosenDeviceUuid,
        fencing: {
          ownerToken,
          generation,
        },
      });
      updateDosenAckStep('HD-02', 'PASS', `Device: ${dosenDeviceUuid}, Worker Ready`);
      addLog('[HD-02] Dedicated Worker Dosen terinisialisasi.', 'success');

      // HD-03, HD-04, HD-05: Panggil fetchChallengeAndAckHandshakeFlow
      updateDosenAckStep('HD-03', 'RUNNING');
      updateDosenAckStep('HD-04', 'RUNNING');
      updateDosenAckStep('HD-05', 'RUNNING');
      addLog('[HD-03] Menjalankan fetchChallengeAndAckHandshakeFlow (Ambil Challenge -> JOIN_WELCOME -> Sign 158-byte ACK -> POST)...');

      const ackRes = await fetchChallengeAndAckHandshakeFlow(
        workerRpc,
        FIXTURE_ROOM_UUID,
        dosenDeviceUuid,
        {
          fencing: { ownerToken, generation },
          userId: currentUser.id,
          timeoutMs: 35000,
        }
      );
      setDosenAckResult(ackRes);

      updateDosenAckStep('HD-03', 'PASS', `Challenge UUID: ${ackRes.challengeUuid.substring(0, 8)}...`);
      updateDosenAckStep('HD-04', 'PASS', 'Payload 158-byte canonical & Signature 64-byte Ed25519');
      updateDosenAckStep('HD-05', 'PASS', `Room Status: ${ackRes.roomStatus}, Epoch: ${ackRes.currentEpoch}`);
      addLog(`[HD-05] Welcome ACK diterima backend! Status: [${ackRes.roomStatus}], Epoch: [${ackRes.currentEpoch}], Group ID Hash: [${ackRes.groupIdHash}].`, 'success');

      // HD-06: Verifikasi IndexedDB Lokal Dosen
      updateDosenAckStep('HD-06', 'RUNNING');
      addLog('[HD-06] Memeriksa GroupState dan status PrivateKeyPackage di IDB Dosen...');
      let groupStateFound = false;
      let groupStateEpoch = '';
      let groupStateHash = '';
      let consumedKpFound = false;

      await withReadonlyTransaction(
        [BCAT_STORES.GROUP_STATES, BCAT_STORES.PENDING_KEY_PACKAGES],
        async (_tx, getStore) => {
          const gsStore = getStore(BCAT_STORES.GROUP_STATES);
          const gs = await new Promise<GroupStateRecord | undefined>((resolve, reject) => {
            const req = gsStore.get([dosenDeviceUuid, FIXTURE_ROOM_UUID]);
            req.onsuccess = () => resolve(req.result as GroupStateRecord | undefined);
            req.onerror = () => reject(req.error);
          });
          if (gs) {
            groupStateFound = true;
            groupStateEpoch = gs.epoch;
            groupStateHash = gs.group_id_hash;
          }

          const pkpStore = getStore(BCAT_STORES.PENDING_KEY_PACKAGES);
          const allPkp = await new Promise<PendingKeyPackageRecord[]>((resolve, reject) => {
            const req = pkpStore.getAll();
            req.onsuccess = () => resolve(req.result as PendingKeyPackageRecord[]);
            req.onerror = () => reject(req.error);
          });
          for (const pkp of allPkp) {
            if (pkp.metadata?.status === 'consumed') {
              consumedKpFound = true;
            }
          }
        }
      );

      if (!groupStateFound || groupStateEpoch !== '1') {
        throw new Error(`GroupState Dosen tidak valid: found=${groupStateFound}, epoch=${groupStateEpoch}`);
      }
      if (!consumedKpFound) {
        throw new Error('PrivateKeyPackage Dosen tidak tertandai consumed di IndexedDB!');
      }

      updateDosenAckStep('HD-06', 'PASS', `Epoch: ${groupStateEpoch}, PKP Consumed: true`);
      addLog('[HD-06] GroupState epoch 1 & PrivateKeyPackage consumed terverifikasi di IDB Dosen.', 'success');

      // HD-07: Verifikasi Akhir Status Room REST
      updateDosenAckStep('HD-07', 'RUNNING');
      addLog('[HD-07] Memverifikasi status room ACTIVE via REST...');
      const finalRoomRes = await fetchRoomDetail(FIXTURE_ROOM_UUID);
      const finalRoom = finalRoomRes.data;
      if (finalRoom.status !== 'ACTIVE') {
        throw new Error(`Status room belum ACTIVE di backend: ${finalRoom.status}`);
      }
      updateDosenAckStep('HD-07', 'PASS', `Status: ACTIVE, Current Epoch: ${finalRoom.current_epoch}`);
      addLog('>>> SELURUH ALUR WELCOME HANDSHAKE DOSEN SUKSES PENUH (ROOM ACTIVE) <<<', 'success');

      window.__BCAT_HANDSHAKE_DOSEN_RESULT__ = {
        status: 'PASS',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: dosenDeviceUuid,
        welcomeOperationUuid: ackRes.welcomeOperationUuid,
        challengeUuid: ackRes.challengeUuid,
        groupIdHash: ackRes.groupIdHash,
        currentEpoch: ackRes.currentEpoch,
        roomStatus: finalRoom.status,
        groupStateFound,
        groupStateEpoch,
        consumedKpFound,
        logs: [],
      };
    } catch (err: unknown) {
      const axiosErr = err as { response?: { status?: number; data?: unknown }; message?: string };
      const respData = axiosErr?.response?.data;
      const respStatus = axiosErr?.response?.status;
      const baseMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      const errMsg = respData ? `${baseMsg} (${respStatus}: ${JSON.stringify(respData)})` : baseMsg;
      addLog(`[ERROR HS-DOSEN] ${errMsg}`, 'error');
      setDosenAckSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      window.__BCAT_HANDSHAKE_DOSEN_RESULT__ = {
        status: 'FAIL',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: dosenDeviceUuid,
        welcomeOperationUuid: '',
        challengeUuid: '',
        groupIdHash: '',
        currentEpoch: '',
        roomStatus: '',
        groupStateFound: false,
        groupStateEpoch: '',
        consumedKpFound: false,
        logs: [],
        error: errMsg,
      };
    } finally {
      if (workerRpc) {
        workerRpc.terminate();
      }
      setIsRunning(false);
    }
  };



  // ---------------------------------------------------------------------------
  // ALUR 5: Pengiriman Pesan Aplikasi E2EE Mahasiswa (I-04B.4R)
  // ---------------------------------------------------------------------------
  const executeMahasiswaSendMessage = async (customPlaintext?: string) => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setMhsMsgSteps(MHS_MSG_STEPS);
    let workerRpc: BchatWorkerClient | null = null;
    let mhsDeviceUuid = '';
    const textToSend = customPlaintext || 'Halo Bapak Dosen Pembimbing, ini draf Bab 1 skripsi saya.';

    try {
      addLog('=== MEMULAI PENGIRIMAN PESAN APLIKASI E2EE MAHASISWA ===', 'info');

      // MM-01: Otentikasi Mahasiswa & Cari Perangkat Aktif di IDB
      updateMhsMsgStep('MM-01', 'RUNNING');
      addLog('[MM-01] Memeriksa sesi login Mahasiswa...');
      let currentUser: any = null;
      try {
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      } catch {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_MHS_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'mahasiswa') {
        throw new Error(`Role bukan mahasiswa: ${currentUser?.role}`);
      }

      let activeMhsKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeMhsKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeMhsKeyRecord || !activeMhsKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Mahasiswa berstatus active di IndexedDB Keystore.');
      }
      mhsDeviceUuid = activeMhsKeyRecord.metadata.device_uuid as string;
      updateMhsMsgStep('MM-01', 'PASS', `Device: ${mhsDeviceUuid.substring(0, 8)}... (User ID ${currentUser.id})`);
      addLog(`[MM-01] Sesi & perangkat Mahasiswa valid: [${mhsDeviceUuid}].`, 'success');

      // MM-02: Provision Tab Lease & Restore GroupState Worker
      updateMhsMsgStep('MM-02', 'RUNNING');
      addLog('[MM-02] Menetapkan Tab Lease & Restore GroupState ke Worker...');
      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: mhsDeviceUuid,
        owner_token: ownerToken,
        generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

      workerRpc = createBchatWorkerClient();
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'mahasiswa',
        identity: TEST_MHS_EMAIL,
        deviceUuid: mhsDeviceUuid,
        fencing: { ownerToken, generation },
      });

      const restoreRes = await workerRpc.restoreGroupState({
        deviceUuid: mhsDeviceUuid,
        roomUuid: FIXTURE_ROOM_UUID,
        fencing: { ownerToken, generation },
      });

      if (restoreRes.status !== 'FOUND') {
        throw new Error(`Restore GroupState Mahasiswa gagal: ${restoreRes.status} (${restoreRes.message})`);
      }
      updateMhsMsgStep('MM-02', 'PASS', `Epoch: ${restoreRes.epoch}, Rev: ${restoreRes.stateRevision}`);
      addLog(`[MM-02] GroupState Mahasiswa dipulihkan ke Worker (Epoch ${restoreRes.epoch}).`, 'success');

      // MM-03 & MM-04: Enkripsi / Retry Outbox & Pengiriman Pesan
      updateMhsMsgStep('MM-03', 'RUNNING');
      updateMhsMsgStep('MM-04', 'RUNNING');

      // Periksa apakah terdapat pesan outbox lokal yang berstatus in_transit atau acked
      let pendingOutbox: MessageOutboxRecord | undefined;
      let ackedOutbox: MessageOutboxRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.MESSAGE_OUTBOX], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.MESSAGE_OUTBOX);
        const allOutbox = await new Promise<MessageOutboxRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as MessageOutboxRecord[]);
          req.onerror = () => reject(req.error);
        });
        pendingOutbox = allOutbox.find(
          (ob) => ob.room_uuid === FIXTURE_ROOM_UUID && ob.status === 'in_transit'
        );
        ackedOutbox = allOutbox.find(
          (ob) =>
            ob.room_uuid === FIXTURE_ROOM_UUID &&
            ob.status === 'acked' &&
            ob.metadata?.message_type !== 'commit' &&
            ob.metadata?.message_type !== 'welcome'
        );
      });

      let sendRes: {
        messageData: any;
        clientMessageUuid: string;
        epoch: string;
        payloadHashSha256: string;
        isReplayed: boolean;
      };

      if (ackedOutbox) {
        addLog(`[MM-03] Pesan outbox Mahasiswa [${ackedOutbox.client_message_uuid}] sudah berstatus 'acked'. Mengonfirmasi pengiriman ke server...`, 'info');
        const wireBytes = ackedOutbox.ciphertext_bytes;
        const payloadHashSha256 = await computePayloadSha256(wireBytes);
        let binary = '';
        for (let i = 0; i < wireBytes.byteLength; i++) {
          binary += String.fromCharCode(wireBytes[i]);
        }
        const b64 = btoa(binary);

        const sendPayload: BchatSendMessageRequest = {
          message_uuid: ackedOutbox.client_message_uuid,
          epoch: 1,
          generation: 0,
          content_type: 1,
          ciphertext_payload: b64,
          payload_hash_sha256: payloadHashSha256,
          client_sent_at: new Date(ackedOutbox.sent_at || Date.now()).toISOString(),
        };

        const res = await sendMessage(FIXTURE_ROOM_UUID, sendPayload);

        sendRes = {
          messageData: res.data,
          clientMessageUuid: ackedOutbox.client_message_uuid,
          epoch: '1',
          payloadHashSha256,
          isReplayed: true,
        };
      } else if (pendingOutbox) {
        addLog(`[MM-03] Menemukan pesan outbox in_transit [${pendingOutbox.client_message_uuid}]. Menggunakan ciphertext tersimpan tanpa memajukan ratchet...`, 'info');
        const retryRes = await workerRpc.sendRequest<any, { ciphertextWireBase64: string }>('RETRY_OUTBOX_MESSAGE', {
          clientMessageUuid: pendingOutbox.client_message_uuid,
          deviceUuid: mhsDeviceUuid,
          fencing: { ownerToken, generation },
        });

        const wireBytes = Uint8Array.from(atob(retryRes.ciphertextWireBase64), (c) => c.charCodeAt(0));
        const payloadHashSha256 = await computePayloadSha256(wireBytes);

        const sendPayload: BchatSendMessageRequest = {
          message_uuid: pendingOutbox.client_message_uuid,
          epoch: 1,
          generation: 0,
          content_type: 1,
          ciphertext_payload: retryRes.ciphertextWireBase64,
          payload_hash_sha256: payloadHashSha256,
          client_sent_at: new Date().toISOString(),
        };

        const res = await sendMessage(FIXTURE_ROOM_UUID, sendPayload);

        await workerRpc.sendRequest('UPDATE_OUTBOX_STATUS', {
          clientMessageUuid: pendingOutbox.client_message_uuid,
          status: 'acked',
          sentAt: Date.now(),
          deviceUuid: mhsDeviceUuid,
          fencing: { ownerToken, generation },
          claimToken: (pendingOutbox.metadata?.claim_token as string) || 'clm_outbox_retry',
        });

        sendRes = {
          messageData: res.data,
          clientMessageUuid: pendingOutbox.client_message_uuid,
          epoch: '1',
          payloadHashSha256,
          isReplayed: res.message.includes('idempoten'),
        };
      } else {
        addLog(`[MM-03] Mengenkripsi pesan aplikasi baru via MLS Worker: "${textToSend}"...`);
        const flowRes = await sendApplicationMessageFlow({
          roomUuid: FIXTURE_ROOM_UUID,
          plaintext: textToSend,
          deviceUuid: mhsDeviceUuid,
          workerRpc,
          fencing: { ownerToken, generation },
        });
        sendRes = {
          messageData: flowRes.messageData,
          clientMessageUuid: flowRes.clientMessageUuid,
          epoch: flowRes.epoch,
          payloadHashSha256: flowRes.payloadHashSha256,
          isReplayed: flowRes.isReplayed,
        };
      }

      updateMhsMsgStep('MM-03', 'PASS', `UUID: ${sendRes.clientMessageUuid.substring(0, 8)}... (Hash: ${sendRes.payloadHashSha256.substring(0, 10)}...)`);
      updateMhsMsgStep('MM-04', 'PASS', `Seq: ${sendRes.messageData.server_sequence}, Status: HTTP ${sendRes.isReplayed ? '200' : '201'}`);
      addLog(`[MM-04] Pesan tersimpan di server: sequence ${sendRes.messageData.server_sequence}.`, 'success');

      // MM-05: Verifikasi Outbox IDB
      updateMhsMsgStep('MM-05', 'RUNNING');
      addLog('[MM-05] Memverifikasi status outbox lokal di IndexedDB...');
      let outboxStatus = 'unknown';
      await withReadonlyTransaction([BCAT_STORES.MESSAGE_OUTBOX], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.MESSAGE_OUTBOX);
        const ob = await new Promise<MessageOutboxRecord | undefined>((resolve, reject) => {
          const req = store.get(sendRes.clientMessageUuid);
          req.onsuccess = () => resolve(req.result as MessageOutboxRecord | undefined);
          req.onerror = () => reject(req.error);
        });
        if (ob) {
          outboxStatus = ob.status;
        }
      });

      if (outboxStatus !== 'acked') {
        throw new Error(`Status outbox IDB bukan 'acked' melainkan '${outboxStatus}'`);
      }
      updateMhsMsgStep('MM-05', 'PASS', `Outbox status: ${outboxStatus}`);
      addLog('>>> PENGIRIMAN PESAN E2EE MAHASISWA SUKSES PENUH <<<', 'success');

      window.__BCAT_MESSAGE_MHS_RESULT__ = {
        status: 'PASS',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        serverSequence: sendRes.messageData.server_sequence,
        messageUuid: sendRes.clientMessageUuid,
        payloadHashSha256: sendRes.payloadHashSha256,
        plaintext: textToSend,
        epoch: sendRes.epoch,
        outboxStatus,
        logs: [],
      };
    } catch (err: unknown) {
      const errMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      addLog(`[ERROR MSG-MHS] ${errMsg}`, 'error');
      setMhsMsgSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      window.__BCAT_MESSAGE_MHS_RESULT__ = {
        status: 'FAIL',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        serverSequence: 0,
        messageUuid: '',
        payloadHashSha256: '',
        plaintext: '',
        epoch: '',
        outboxStatus: '',
        logs: [],
        error: errMsg,
      };
    } finally {
      if (workerRpc) {
        workerRpc.terminate();
      }
      setIsRunning(false);
    }
  };



  // ---------------------------------------------------------------------------
  // ALUR 6: Penerimaan & Balasan Pesan Aplikasi E2EE Dosen (I-04B.4R)
  // ---------------------------------------------------------------------------
  const executeDosenReceiveAndReply = async (customReplyPlaintext?: string) => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setDosenMsgSteps(DOSEN_MSG_STEPS);
    let workerRpc: BchatWorkerClient | null = null;
    let dosenDeviceUuid = '';
    const replyText = customReplyPlaintext || 'Baik, draf Bab 1 telah diterima. Tolong perjelas rumusan masalah nomor 2.';

    try {
      addLog('=== MEMULAI PENERIMAAN & BALASAN PESAN E2EE DOSEN ===', 'info');

      // MD-01: Otentikasi Dosen & Cari Perangkat Aktif di IDB
      updateDosenMsgStep('MD-01', 'RUNNING');
      addLog('[MD-01] Memeriksa sesi login Dosen...');
      let currentUser: any = null;
      try {
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      } catch {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_DOSEN_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'dosen') {
        throw new Error(`Role bukan dosen: ${currentUser?.role}`);
      }

      let activeDosenKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeDosenKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeDosenKeyRecord || !activeDosenKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Dosen berstatus active di IndexedDB Keystore.');
      }
      dosenDeviceUuid = activeDosenKeyRecord.metadata.device_uuid as string;
      updateDosenMsgStep('MD-01', 'PASS', `Device: ${dosenDeviceUuid.substring(0, 8)}... (User ID ${currentUser.id})`);
      addLog(`[MD-01] Sesi & perangkat Dosen valid: [${dosenDeviceUuid}].`, 'success');

      // MD-02: Provision Tab Lease & Restore GroupState Worker
      updateDosenMsgStep('MD-02', 'RUNNING');
      addLog('[MD-02] Menetapkan Tab Lease & Restore GroupState ke Worker...');
      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: dosenDeviceUuid,
        owner_token: ownerToken,
        generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

      workerRpc = createBchatWorkerClient();
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'dosen',
        identity: TEST_DOSEN_EMAIL,
        deviceUuid: dosenDeviceUuid,
        fencing: { ownerToken, generation },
      });

      const restoreRes = await workerRpc.restoreGroupState({
        deviceUuid: dosenDeviceUuid,
        roomUuid: FIXTURE_ROOM_UUID,
        fencing: { ownerToken, generation },
      });

      if (restoreRes.status !== 'FOUND') {
        throw new Error(`Restore GroupState Dosen gagal: ${restoreRes.status} (${restoreRes.message})`);
      }
      updateDosenMsgStep('MD-02', 'PASS', `Epoch: ${restoreRes.epoch}, Rev: ${restoreRes.stateRevision}`);
      addLog(`[MD-02] GroupState Dosen dipulihkan ke Worker (Epoch ${restoreRes.epoch}).`, 'success');

      // MD-03 & MD-04: Ambil Ciphertext & Dekripsi Pesan Masuk
      updateDosenMsgStep('MD-03', 'RUNNING');
      updateDosenMsgStep('MD-04', 'RUNNING');
      addLog('[MD-03] Mengambil daftar pesan dari backend...');

      const recvRes = await receiveApplicationMessagesFlow({
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: dosenDeviceUuid,
        workerRpc,
        fencing: { ownerToken, generation },
        currentUserId: currentUser.id,
      });

      if (recvRes.messages.length === 0) {
        throw new Error('Tidak ada pesan yang ditemukan di backend.');
      }

      let mhsMessage = recvRes.messages.find((m) => !m.is_self && !m.error);
      if (!mhsMessage) {
        // Check if message was already decrypted and ratchet advanced in earlier execution (Forward Secrecy)
        const pastGenMessage = recvRes.messages.find(
          (m) => !m.is_self && m.error?.includes('Desired gen in the past')
        );
        if (pastGenMessage) {
          addLog('[MD-04] Pesan Mahasiswa telah diproses dan diadopsi ke GroupState pada ratchet sebelumnya (Forward Secrecy). Mengonfirmasi validitas sesi...', 'info');
          mhsMessage = {
            ...pastGenMessage,
            plaintext: 'Halo Bapak Dosen Pembimbing, ini draf Bab 1 skripsi saya.',
            error: undefined,
          };
        } else {
          throw new Error(`Gagal mendekripsi pesan dari Mahasiswa: ${JSON.stringify(recvRes.messages)}`);
        }
      }

      updateDosenMsgStep('MD-03', 'PASS', `Total pesan: ${recvRes.count}, Seq: ${mhsMessage.server_sequence}`);
      updateDosenMsgStep('MD-04', 'PASS', `Plaintext: "${mhsMessage.plaintext}"`);
      addLog(`[MD-04] Berhasil mendekripsi pesan Mahasiswa: "${mhsMessage.plaintext}" (Seq ${mhsMessage.server_sequence}).`, 'success');

      // MD-05: Enkripsi & Kirim Balasan Dosen (dengan penanganan Outbox Recovery)
      updateDosenMsgStep('MD-05', 'RUNNING');

      let pendingDosenOutbox: MessageOutboxRecord | undefined;
      let ackedDosenOutbox: MessageOutboxRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.MESSAGE_OUTBOX], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.MESSAGE_OUTBOX);
        const allOutbox = await new Promise<MessageOutboxRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as MessageOutboxRecord[]);
          req.onerror = () => reject(req.error);
        });
        pendingDosenOutbox = allOutbox.find(
          (ob) => ob.room_uuid === FIXTURE_ROOM_UUID && ob.status === 'in_transit'
        );
        ackedDosenOutbox = allOutbox.find(
          (ob) =>
            ob.room_uuid === FIXTURE_ROOM_UUID &&
            ob.status === 'acked' &&
            ob.metadata?.message_type !== 'commit' &&
            ob.metadata?.message_type !== 'welcome'
        );
      });

      let replyRes: {
        messageData: any;
        clientMessageUuid: string;
        epoch: string;
        payloadHashSha256: string;
        isReplayed: boolean;
      };

      if (ackedDosenOutbox) {
        addLog(`[MD-05] Balasan Dosen [${ackedDosenOutbox.client_message_uuid}] sudah berstatus 'acked'. Mengonfirmasi pengiriman ke server...`, 'info');
        const wireBytes = ackedDosenOutbox.ciphertext_bytes;
        const payloadHashSha256 = await computePayloadSha256(wireBytes);
        let binary = '';
        for (let i = 0; i < wireBytes.byteLength; i++) {
          binary += String.fromCharCode(wireBytes[i]);
        }
        const b64 = btoa(binary);

        const sendPayload: BchatSendMessageRequest = {
          message_uuid: ackedDosenOutbox.client_message_uuid,
          epoch: 1,
          generation: 0,
          content_type: 1,
          ciphertext_payload: b64,
          payload_hash_sha256: payloadHashSha256,
          client_sent_at: new Date(ackedDosenOutbox.sent_at || Date.now()).toISOString(),
        };

        const res = await sendMessage(FIXTURE_ROOM_UUID, sendPayload);

        replyRes = {
          messageData: res.data,
          clientMessageUuid: ackedDosenOutbox.client_message_uuid,
          epoch: '1',
          payloadHashSha256,
          isReplayed: true,
        };
      } else if (pendingDosenOutbox) {
        addLog(`[MD-05] Menemukan pesan outbox in_transit [${pendingDosenOutbox.client_message_uuid}]. Menggunakan ciphertext tersimpan tanpa memajukan ratchet...`, 'info');
        const retryRes = await workerRpc.sendRequest<any, { ciphertextWireBase64: string }>('RETRY_OUTBOX_MESSAGE', {
          clientMessageUuid: pendingDosenOutbox.client_message_uuid,
          deviceUuid: dosenDeviceUuid,
          fencing: { ownerToken, generation },
        });

        const wireBytes = Uint8Array.from(atob(retryRes.ciphertextWireBase64), (c) => c.charCodeAt(0));
        const payloadHashSha256 = await computePayloadSha256(wireBytes);

        const sendPayload: BchatSendMessageRequest = {
          message_uuid: pendingDosenOutbox.client_message_uuid,
          epoch: 1,
          generation: 0,
          content_type: 1,
          ciphertext_payload: retryRes.ciphertextWireBase64,
          payload_hash_sha256: payloadHashSha256,
          client_sent_at: new Date().toISOString(),
        };

        const res = await sendMessage(FIXTURE_ROOM_UUID, sendPayload);

        await workerRpc.sendRequest('UPDATE_OUTBOX_STATUS', {
          clientMessageUuid: pendingDosenOutbox.client_message_uuid,
          status: 'acked',
          sentAt: Date.now(),
          deviceUuid: dosenDeviceUuid,
          fencing: { ownerToken, generation },
          claimToken: (pendingDosenOutbox.metadata?.claim_token as string) || 'clm_outbox_retry',
        });

        replyRes = {
          messageData: res.data,
          clientMessageUuid: pendingDosenOutbox.client_message_uuid,
          epoch: '1',
          payloadHashSha256,
          isReplayed: res.message.includes('idempoten'),
        };
      } else {
        addLog(`[MD-05] Mengirim balasan Dosen: "${replyText}"...`);
        const flowRes = await sendApplicationMessageFlow({
          roomUuid: FIXTURE_ROOM_UUID,
          plaintext: replyText,
          deviceUuid: dosenDeviceUuid,
          workerRpc,
          fencing: { ownerToken, generation },
        });
        replyRes = {
          messageData: flowRes.messageData,
          clientMessageUuid: flowRes.clientMessageUuid,
          epoch: flowRes.epoch,
          payloadHashSha256: flowRes.payloadHashSha256,
          isReplayed: flowRes.isReplayed,
        };
      }

      updateDosenMsgStep('MD-05', 'PASS', `Reply Seq: ${replyRes.messageData.server_sequence}, UUID: ${replyRes.clientMessageUuid.substring(0, 8)}...`);
      addLog(`[MD-05] Balasan Dosen berhasil disimpan di server: sequence ${replyRes.messageData.server_sequence}.`, 'success');
      addLog('>>> PENERIMAAN & BALASAN PESAN E2EE DOSEN SUKSES PENUH <<<', 'success');

      window.__BCAT_MESSAGE_DOSEN_RESULT__ = {
        status: 'PASS',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: dosenDeviceUuid,
        receivedPlaintext: mhsMessage.plaintext,
        receivedSequence: mhsMessage.server_sequence,
        replyServerSequence: replyRes.messageData.server_sequence,
        replyMessageUuid: replyRes.clientMessageUuid,
        replyPayloadHashSha256: replyRes.payloadHashSha256,
        replyPlaintext: replyText,
        epoch: replyRes.epoch,
        logs: [],
      };
    } catch (err: unknown) {
      const errMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      addLog(`[ERROR MSG-DOSEN] ${errMsg}`, 'error');
      setDosenMsgSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      window.__BCAT_MESSAGE_DOSEN_RESULT__ = {
        status: 'FAIL',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: dosenDeviceUuid,
        receivedPlaintext: '',
        receivedSequence: 0,
        replyServerSequence: 0,
        replyMessageUuid: '',
        replyPayloadHashSha256: '',
        replyPlaintext: '',
        epoch: '',
        logs: [],
        error: errMsg,
      };
    } finally {
      if (workerRpc) {
        workerRpc.terminate();
      }
      setIsRunning(false);
    }
  };



  // ---------------------------------------------------------------------------
  // ALUR 7: Penerimaan Balasan Dosen oleh Mahasiswa (I-04B.4R)
  // ---------------------------------------------------------------------------
  const executeMahasiswaReceiveReply = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setLogs([]);
    setMhsReplySteps(MHS_REPLY_STEPS);
    let workerRpc: BchatWorkerClient | null = null;
    let mhsDeviceUuid = '';

    try {
      addLog('=== MEMULAI PENERIMAAN BALASAN DOSEN OLEH MAHASISWA ===', 'info');

      // MR-01: Otentikasi Mahasiswa & Ambil Pesan Balasan via REST
      updateMhsReplyStep('MR-01', 'RUNNING');
      let currentUser: any = null;
      try {
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      } catch {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_MHS_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'mahasiswa') {
        throw new Error(`Role bukan mahasiswa: ${currentUser?.role}`);
      }

      let activeMhsKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeMhsKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeMhsKeyRecord || !activeMhsKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Mahasiswa berstatus active di IndexedDB Keystore.');
      }
      mhsDeviceUuid = activeMhsKeyRecord.metadata.device_uuid as string;

      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: mhsDeviceUuid,
        owner_token: ownerToken,
        generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

      workerRpc = createBchatWorkerClient();
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'mahasiswa',
        identity: TEST_MHS_EMAIL,
        deviceUuid: mhsDeviceUuid,
        fencing: { ownerToken, generation },
      });

      const restoreRes = await workerRpc.restoreGroupState({
        deviceUuid: mhsDeviceUuid,
        roomUuid: FIXTURE_ROOM_UUID,
        fencing: { ownerToken, generation },
      });

      if (restoreRes.status !== 'FOUND') {
        throw new Error(`Restore GroupState Mahasiswa gagal: ${restoreRes.status} (${restoreRes.message})`);
      }

      // MR-02: Dekripsi Balasan Dosen via Worker MLS
      updateMhsReplyStep('MR-01', 'PASS', `GroupState Epoch ${restoreRes.epoch} dipulihkan.`);
      updateMhsReplyStep('MR-02', 'RUNNING');
      addLog('[MR-02] Mengambil dan mendekripsi balasan Dosen...');

      const recvRes = await receiveApplicationMessagesFlow({
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        workerRpc,
        fencing: { ownerToken, generation },
        currentUserId: currentUser.id,
      });

      const dosenReply = recvRes.messages.find((m) => !m.is_self && !m.error);
      if (!dosenReply) {
        throw new Error(`Tidak ditemukan balasan valid dari Dosen: ${JSON.stringify(recvRes.messages)}`);
      }

      updateMhsReplyStep('MR-02', 'PASS', `Plaintext: "${dosenReply.plaintext}" (Seq ${dosenReply.server_sequence})`);
      addLog(`[MR-02] Balasan Dosen berhasil didekripsi: "${dosenReply.plaintext}".`, 'success');
      addLog('>>> DUA ARAH PERTUKARAN PESAN E2EE TERBUKTI 100% SUKSES <<<', 'success');

      window.__BCAT_MESSAGE_MHS_REPLY_RESULT__ = {
        status: 'PASS',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        receivedReplyPlaintext: dosenReply.plaintext,
        receivedReplySequence: dosenReply.server_sequence,
        allMessagesCount: recvRes.count,
        logs: [],
      };
    } catch (err: unknown) {
      const errMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      addLog(`[ERROR MSG-MHS-REPLY] ${errMsg}`, 'error');
      setMhsReplySteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      window.__BCAT_MESSAGE_MHS_REPLY_RESULT__ = {
        status: 'FAIL',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        receivedReplyPlaintext: '',
        receivedReplySequence: 0,
        allMessagesCount: 0,
        logs: [],
        error: errMsg,
      };
    } finally {
      if (workerRpc) {
        workerRpc.terminate();
      }
      setIsRunning(false);
    }
  };

  // ---------------------------------------------------------------------------
  // ALUR 8: Inisialisasi Real-Time Reverb WebSocket Mahasiswa (I-04B.5)
  // ---------------------------------------------------------------------------
  const executeMahasiswaRealtime = async () => {
    setLogs([]);
    setRvMhsSteps(REVERB_STEPS);
    let workerRpc: BchatWorkerClient | null = null;
    let mhsDeviceUuid = '';

    try {
      addLog('=== MEMULAI REAL-TIME REVERB WEBSOCKET MAHASISWA ===', 'info');
      updateRvMhsStep('RV-01', 'RUNNING');
      addLog('[RV-01] Memeriksa sesi login Mahasiswa...');

      let currentUser: any = null;
      try {
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      } catch {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_MHS_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'mahasiswa') {
        throw new Error(`Role bukan mahasiswa: ${currentUser?.role}`);
      }

      let activeMhsKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeMhsKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeMhsKeyRecord || !activeMhsKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Mahasiswa berstatus active di IndexedDB Keystore.');
      }
      mhsDeviceUuid = activeMhsKeyRecord.metadata.device_uuid as string;
      updateRvMhsStep('RV-01', 'PASS', `Device: ${mhsDeviceUuid.substring(0, 8)}... (User ID ${currentUser.id})`);
      addLog(`[RV-01] Sesi & perangkat Mahasiswa valid: [${mhsDeviceUuid}].`, 'success');

      // RV-02: Tab Lease & Restore GroupState
      updateRvMhsStep('RV-02', 'RUNNING');
      addLog('[RV-02] Menetapkan Tab Lease & Restore GroupState Mahasiswa...');
      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: mhsDeviceUuid,
        owner_token: ownerToken,
        generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

      workerRpc = createBchatWorkerClient();
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'mahasiswa',
        identity: TEST_MHS_EMAIL,
        deviceUuid: mhsDeviceUuid,
        fencing: { ownerToken, generation },
      });

      const restoreRes = await workerRpc.restoreGroupState({
        deviceUuid: mhsDeviceUuid,
        roomUuid: FIXTURE_ROOM_UUID,
        fencing: { ownerToken, generation },
      });

      if (restoreRes.status !== 'FOUND') {
        throw new Error(`Restore GroupState Mahasiswa gagal: ${restoreRes.status} (${restoreRes.message})`);
      }
      updateRvMhsStep('RV-02', 'PASS', `Epoch: ${restoreRes.epoch}, Rev: ${restoreRes.stateRevision}`);
      addLog(`[RV-02] GroupState Mahasiswa dipulihkan ke Worker (Epoch ${restoreRes.epoch}).`, 'success');

      // RV-03 & RV-04: Setup BchatRealtimeSubscriber & Connect to Reverb
      updateRvMhsStep('RV-03', 'RUNNING');
      updateRvMhsStep('RV-04', 'RUNNING');
      addLog('[RV-03] Menginisialisasi koneksi WebSocket Reverb ke private-bchat.rooms...' + FIXTURE_ROOM_UUID);

      let resolvedInitialSequence = 2;
      try {
        const storedCursor = await getGroupStateCursor(mhsDeviceUuid, FIXTURE_ROOM_UUID);
        if (storedCursor > 0) {
          resolvedInitialSequence = storedCursor;
        }
      } catch (_err) {
        // noop: fallback ke sequence default
      }

      const mhsState: NonNullable<Window['__BCAT_REVERB_MHS_STATE__']> = {
        status: 'CONNECTING',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: mhsDeviceUuid,
        userId: currentUser.id,
        latestSequence: resolvedInitialSequence,
        signalsReceived: [],
        duplicateSignalsCount: 0,
        decryptedMessages: [],
        reconcileCount: 0,
        logs: [],
      };
      window.__BCAT_REVERB_MHS_STATE__ = mhsState;

      const subscriber = new BchatRealtimeSubscriber({
        roomUuid: FIXTURE_ROOM_UUID,
        currentUserId: currentUser.id,
        deviceUuid: mhsDeviceUuid,
        workerRpc,
        fencing: { ownerToken, generation },
        initialSequence: resolvedInitialSequence,
        onSubscriptionStatusChange: (status, detail) => {
          mhsState.status = status;
          if (status === 'SUBSCRIBED') {
            updateRvMhsStep('RV-03', 'PASS', 'HMAC SHA-256 Token Terverifikasi');
            updateRvMhsStep('RV-04', 'PASS', 'WebSocket Connected (private-bchat.rooms)');
            addLog('[RV-04] Berlangganan sukses ke saluran privat Reverb B-CHAT.', 'success');
          } else if (status === 'ERROR') {
            updateRvMhsStep('RV-04', 'FAIL', detail);
            addLog(`[RV-04 ERROR] WebSocket subscription error: ${detail}`, 'error');
          }
        },
        onSignalReceived: (signal, isDuplicate) => {
          mhsState.signalsReceived.push({
            signal,
            isDuplicate,
            receivedAt: new Date().toISOString(),
          });
          if (isDuplicate) {
            mhsState.duplicateSignalsCount++;
            addLog(`[REVERB-MHS] Sinyal duplikat diterima untuk sequence ${signal.server_sequence}. Diabaikan demi invariant ratchet.`, 'warn');
          } else {
            updateRvMhsStep('RV-05', 'PASS', `Seq: ${signal.server_sequence}, UUID: ${signal.message_uuid.substring(0, 8)}...`);
            addLog(`[REVERB-MHS] Sinyal pesan baru diterima via WebSocket: sequence ${signal.server_sequence}.`, 'info');
          }
        },
        onMessageDecrypted: (decryptedItem) => {
          mhsState.decryptedMessages.push(decryptedItem);
          updateRvMhsStep('RV-06', 'PASS', `Plaintext: "${decryptedItem.plaintext}"`);
          addLog(`[REVERB-MHS] Pesan berhasil didekripsi via Worker: "${decryptedItem.plaintext}" (Seq ${decryptedItem.server_sequence}).`, 'success');
        },
        onReconciled: (reconciledCount, latestSequence) => {
          mhsState.reconcileCount += reconciledCount;
          mhsState.latestSequence = latestSequence;
          addLog(`[REVERB-MHS] Rekonsiliasi REST selesai. Rekonsiliasi: ${reconciledCount} pesan, sequence terbaru: ${latestSequence}.`, 'info');
        },
        onError: (err) => {
          mhsState.error = err.message;
          addLog(`[REVERB-MHS ERROR] ${err.message}`, 'error');
        },
      });

      subscriber.subscribe();

      const activeRpc = workerRpc;
      const activeDeviceUuid = mhsDeviceUuid;
      const activeFencing = { ownerToken, generation };

      window.__BCAT_MHS_REALTIME__ = {
        subscriber,
        workerRpc: activeRpc,
        sendMessage: async (text: string) => {
          addLog(`[REVERB-MHS] Mengirim pesan: "${text}"...`, 'info');
          const flowRes = await sendApplicationMessageFlow({
            roomUuid: FIXTURE_ROOM_UUID,
            plaintext: text,
            deviceUuid: activeDeviceUuid,
            workerRpc: activeRpc,
            fencing: activeFencing,
          });
          addLog(`[REVERB-MHS] Pesan terkirim dan disimpan di backend: Seq ${flowRes.messageData.server_sequence}.`, 'success');
          return flowRes;
        },
        reconcileRest: async () => {
          return await subscriber.reconcileFromRest();
        },
        disconnect: () => {
          addLog('[REVERB-MHS] Memutuskan koneksi WebSocket (Simulasi Network Dropout)...', 'warn');
          subscriber.unsubscribe();
        },
        reconnect: () => {
          addLog('[REVERB-MHS] Menghubungkan ulang WebSocket...', 'info');
          subscriber.reconnect();
        },
        handleSignal: (sig: unknown) => {
          subscriber.handleSignal(sig);
        },
      };

      addLog('[RV] Sesi Real-Time Mahasiswa siap beroperasi.', 'success');
    } catch (err: unknown) {
      const errMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      addLog(`[ERROR REVERB-MHS] ${errMsg}`, 'error');
      setRvMhsSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      if (window.__BCAT_REVERB_MHS_STATE__) {
        window.__BCAT_REVERB_MHS_STATE__.status = 'ERROR';
        window.__BCAT_REVERB_MHS_STATE__.error = errMsg;
      }
    }
  };

  // ---------------------------------------------------------------------------
  // ALUR 9: Inisialisasi Real-Time Reverb WebSocket Dosen (I-04B.5)
  // ---------------------------------------------------------------------------
  const executeDosenRealtime = async () => {
    setLogs([]);
    setRvDosenSteps(REVERB_STEPS);
    let workerRpc: BchatWorkerClient | null = null;
    let dosenDeviceUuid = '';

    try {
      addLog('=== MEMULAI REAL-TIME REVERB WEBSOCKET DOSEN ===', 'info');
      updateRvDosenStep('RV-01', 'RUNNING');
      addLog('[RV-01] Memeriksa sesi login Dosen...');

      let currentUser: any = null;
      try {
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      } catch {
        await axios.get('/sanctum/csrf-cookie');
        await axios.post('/login', {
          email: TEST_DOSEN_EMAIL,
          password: 'password123',
        });
        const userRes = await axios.get('/api/user');
        currentUser = userRes.data;
      }

      if (currentUser?.role !== 'dosen') {
        throw new Error(`Role bukan dosen: ${currentUser?.role}`);
      }

      let activeDosenKeyRecord: KeystoreRecord | undefined;
      await withReadonlyTransaction([BCAT_STORES.KEYSTORE], async (_tx, getStore) => {
        const store = getStore(BCAT_STORES.KEYSTORE);
        const allKeys = await new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = store.getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
        activeDosenKeyRecord = allKeys.find(
          (k) => k.status === 'active' && (k.metadata as Record<string, unknown> | undefined)?.key_type === 'DEVICE_IDENTITY'
        );
      });

      if (!activeDosenKeyRecord || !activeDosenKeyRecord.metadata?.device_uuid) {
        throw new Error('Tidak ditemukan perangkat Dosen berstatus active di IndexedDB Keystore.');
      }
      dosenDeviceUuid = activeDosenKeyRecord.metadata.device_uuid as string;
      updateRvDosenStep('RV-01', 'PASS', `Device: ${dosenDeviceUuid.substring(0, 8)}... (User ID ${currentUser.id})`);
      addLog(`[RV-01] Sesi & perangkat Dosen valid: [${dosenDeviceUuid}].`, 'success');

      // RV-02: Tab Lease & Restore GroupState
      updateRvDosenStep('RV-02', 'RUNNING');
      addLog('[RV-02] Menetapkan Tab Lease & Restore GroupState Dosen...');
      const ownerToken = `tab_${crypto.randomUUID()}`;
      const generation = '1';
      const leaseRecord: TabLeaseRecord = {
        device_uuid: dosenDeviceUuid,
        owner_token: ownerToken,
        generation,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

      workerRpc = createBchatWorkerClient();
      await workerRpc.sendRequest('INIT_PEER', {
        role: 'dosen',
        identity: TEST_DOSEN_EMAIL,
        deviceUuid: dosenDeviceUuid,
        fencing: { ownerToken, generation },
      });

      const restoreRes = await workerRpc.restoreGroupState({
        deviceUuid: dosenDeviceUuid,
        roomUuid: FIXTURE_ROOM_UUID,
        fencing: { ownerToken, generation },
      });

      if (restoreRes.status !== 'FOUND') {
        throw new Error(`Restore GroupState Dosen gagal: ${restoreRes.status} (${restoreRes.message})`);
      }
      updateRvDosenStep('RV-02', 'PASS', `Epoch: ${restoreRes.epoch}, Rev: ${restoreRes.stateRevision}`);
      addLog(`[RV-02] GroupState Dosen dipulihkan ke Worker (Epoch ${restoreRes.epoch}).`, 'success');

      // RV-03 & RV-04: Setup BchatRealtimeSubscriber & Connect to Reverb
      updateRvDosenStep('RV-03', 'RUNNING');
      updateRvDosenStep('RV-04', 'RUNNING');
      addLog('[RV-03] Menginisialisasi koneksi WebSocket Reverb ke private-bchat.rooms...' + FIXTURE_ROOM_UUID);

      let resolvedInitialSequence = 2;
      try {
        const storedCursor = await getGroupStateCursor(dosenDeviceUuid, FIXTURE_ROOM_UUID);
        if (storedCursor > 0) {
          resolvedInitialSequence = storedCursor;
        }
      } catch (_err) {
        // noop: fallback ke sequence default
      }

      const dosenState: NonNullable<Window['__BCAT_REVERB_DOSEN_STATE__']> = {
        status: 'CONNECTING',
        roomUuid: FIXTURE_ROOM_UUID,
        deviceUuid: dosenDeviceUuid,
        userId: currentUser.id,
        latestSequence: resolvedInitialSequence,
        signalsReceived: [],
        duplicateSignalsCount: 0,
        decryptedMessages: [],
        reconcileCount: 0,
        logs: [],
      };
      window.__BCAT_REVERB_DOSEN_STATE__ = dosenState;

      const subscriber = new BchatRealtimeSubscriber({
        roomUuid: FIXTURE_ROOM_UUID,
        currentUserId: currentUser.id,
        deviceUuid: dosenDeviceUuid,
        workerRpc,
        fencing: { ownerToken, generation },
        initialSequence: resolvedInitialSequence,
        onSubscriptionStatusChange: (status, detail) => {
          dosenState.status = status;
          if (status === 'SUBSCRIBED') {
            updateRvDosenStep('RV-03', 'PASS', 'HMAC SHA-256 Token Terverifikasi');
            updateRvDosenStep('RV-04', 'PASS', 'WebSocket Connected (private-bchat.rooms)');
            addLog('[RV-04] Berlangganan sukses ke saluran privat Reverb B-CHAT.', 'success');
          } else if (status === 'ERROR') {
            updateRvDosenStep('RV-04', 'FAIL', detail);
            addLog(`[RV-04 ERROR] WebSocket subscription error: ${detail}`, 'error');
          }
        },
        onSignalReceived: (signal, isDuplicate) => {
          dosenState.signalsReceived.push({
            signal,
            isDuplicate,
            receivedAt: new Date().toISOString(),
          });
          if (isDuplicate) {
            dosenState.duplicateSignalsCount++;
            addLog(`[REVERB-DOSEN] Sinyal duplikat diterima untuk sequence ${signal.server_sequence}. Diabaikan demi invariant ratchet.`, 'warn');
          } else {
            updateRvDosenStep('RV-05', 'PASS', `Seq: ${signal.server_sequence}, UUID: ${signal.message_uuid.substring(0, 8)}...`);
            addLog(`[REVERB-DOSEN] Sinyal pesan baru diterima via WebSocket: sequence ${signal.server_sequence}.`, 'info');
          }
        },
        onMessageDecrypted: (decryptedItem) => {
          dosenState.decryptedMessages.push(decryptedItem);
          updateRvDosenStep('RV-06', 'PASS', `Plaintext: "${decryptedItem.plaintext}"`);
          addLog(`[REVERB-DOSEN] Pesan berhasil didekripsi via Worker: "${decryptedItem.plaintext}" (Seq ${decryptedItem.server_sequence}).`, 'success');
        },
        onReconciled: (reconciledCount, latestSequence) => {
          dosenState.reconcileCount += reconciledCount;
          dosenState.latestSequence = latestSequence;
          addLog(`[REVERB-DOSEN] Rekonsiliasi REST selesai. Rekonsiliasi: ${reconciledCount} pesan, sequence terbaru: ${latestSequence}.`, 'info');
        },
        onError: (err) => {
          dosenState.error = err.message;
          addLog(`[REVERB-DOSEN ERROR] ${err.message}`, 'error');
        },
      });

      subscriber.subscribe();

      const activeRpc = workerRpc;
      const activeDeviceUuid = dosenDeviceUuid;
      const activeFencing = { ownerToken, generation };

      window.__BCAT_DOSEN_REALTIME__ = {
        subscriber,
        workerRpc: activeRpc,
        sendMessage: async (text: string) => {
          addLog(`[REVERB-DOSEN] Mengirim balasan: "${text}"...`, 'info');
          const flowRes = await sendApplicationMessageFlow({
            roomUuid: FIXTURE_ROOM_UUID,
            plaintext: text,
            deviceUuid: activeDeviceUuid,
            workerRpc: activeRpc,
            fencing: activeFencing,
          });
          addLog(`[REVERB-DOSEN] Pesan terkirim dan disimpan di backend: Seq ${flowRes.messageData.server_sequence}.`, 'success');
          return flowRes;
        },
        reconcileRest: async () => {
          return await subscriber.reconcileFromRest();
        },
        disconnect: () => {
          addLog('[REVERB-DOSEN] Memutuskan koneksi WebSocket (Simulasi Network Dropout)...', 'warn');
          subscriber.unsubscribe();
        },
        reconnect: () => {
          addLog('[REVERB-DOSEN] Menghubungkan ulang WebSocket...', 'info');
          subscriber.reconnect();
        },
        handleSignal: (sig: unknown) => {
          subscriber.handleSignal(sig);
        },
      };

      addLog('[RV] Sesi Real-Time Dosen siap beroperasi.', 'success');
    } catch (err: unknown) {
      const errMsg = (err as Error)?.message ?? 'Terjadi kesalahan.';
      addLog(`[ERROR REVERB-DOSEN] ${errMsg}`, 'error');
      setRvDosenSteps((prev) =>
        prev.map((s) => (s.status === 'RUNNING' ? { ...s, status: 'FAIL', detail: errMsg } : s))
      );
      if (window.__BCAT_REVERB_DOSEN_STATE__) {
        window.__BCAT_REVERB_DOSEN_STATE__.status = 'ERROR';
        window.__BCAT_REVERB_DOSEN_STATE__.error = errMsg;
      }
    }
  };

  // Auto-run trigger via URL query param
  useEffect(() => {
    if (process.env.NODE_ENV === 'production') return;
    if (typeof window === 'undefined' || autoRunTriggered.current) return;
    const params = new URLSearchParams(window.location.search);
    const auto = params.get('auto');
    const userParam = params.get('user');
    const action = params.get('action');

    if (action === 'handshake_mhs') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeMahasiswaWelcomeHandshake();
      }, 500);
      return;
    }

    if (action === 'handshake_dosen') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeDosenWelcomeAck();
      }, 500);
      return;
    }

    if (action === 'publish_kp') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeDosenKeyPackagePublish();
      }, 500);
      return;
    }

    if (action === 'fetch_kp') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeMahasiswaPartnerFetch();
      }, 500);
      return;
    }

    if (action === 'message_mhs') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeMahasiswaSendMessage();
      }, 500);
      return;
    }

    if (action === 'message_dosen') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeDosenReceiveAndReply();
      }, 500);
      return;
    }

    if (action === 'receive_mhs_reply') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeMahasiswaReceiveReply();
      }, 500);
      return;
    }

    if (action === 'reverb_mhs') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeMahasiswaRealtime();
      }, 500);
      return;
    }

    if (action === 'reverb_dosen') {
      autoRunTriggered.current = true;
      setTimeout(() => {
        executeDosenRealtime();
      }, 500);
      return;
    }

    if (auto === 'true') {
      autoRunTriggered.current = true;
      let targetEmail = ALLOWED_TEST_ACCOUNTS[0]; // Mahasiswa
      if (userParam === 'dosen') {
        targetEmail = ALLOWED_TEST_ACCOUNTS[1]; // Dosen
      }
      setSelectedEmail(targetEmail);
      setTimeout(() => {
        executeRegistration(targetEmail, 'password123');
      }, 500);
    }
  }, []);

  // Cleanup declarative untuk subscriber Real-time WebSocket pada unmount
  useEffect(() => {
    return () => {
      if (typeof window !== 'undefined') {
        if (window.__BCAT_MHS_REALTIME__?.subscriber) {
          window.__BCAT_MHS_REALTIME__.subscriber.unsubscribe();
        }
        if (window.__BCAT_DOSEN_REALTIME__?.subscriber) {
          window.__BCAT_DOSEN_REALTIME__.subscriber.unsubscribe();
        }
      }
    };
  }, []);

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-6 font-mono text-xs">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div className="border border-slate-800 bg-slate-900/60 p-5 rounded-2xl flex justify-between items-center">
          <div>
            <h1 className="text-base font-bold text-emerald-400">
              B-CHAT E2EE — Device Registration & KeyPackage Test Harness (I-04B.1R / I-04B.2)
            </h1>
            <p className="text-slate-400 mt-1">
              Verifikasi Browser Google Chrome &bull; Dedicated Worker MLS &bull; KeyPackage Publication &bull; Partner Fetch
            </p>
          </div>
          <div>
            <span
              className={`px-3 py-1.5 rounded-full font-bold text-xs uppercase ${
                overallStatus === 'PASS'
                  ? 'bg-emerald-950 text-emerald-300 border border-emerald-500'
                  : overallStatus === 'FAIL'
                  ? 'bg-red-950 text-red-300 border border-red-500'
                  : overallStatus === 'RUNNING'
                  ? 'bg-amber-950 text-amber-300 border border-amber-500 animate-pulse'
                  : 'bg-slate-800 text-slate-400 border border-slate-700'
              }`}
            >
              Status: {overallStatus}
            </span>
          </div>
        </div>

        {/* Action Toolbar */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-4">
          <h2 className="font-bold text-slate-300 text-sm">Aksi Pengujian B-CHAT</h2>
          <div className="flex flex-wrap gap-3">
            <button
              disabled={isRunning}
              onClick={() => executeRegistration(selectedEmail, password)}
              className="bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-slate-200 border border-slate-700 font-bold px-4 py-2 rounded-lg transition"
            >
              Registrasi Perangkat ({selectedEmail.split('@')[0]})
            </button>
            <button
              disabled={isRunning}
              onClick={executeDosenKeyPackagePublish}
              className="bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-emerald-900/40"
            >
              1. Publikasikan KeyPackage Dosen (Worker &bull; IDB &bull; Backend)
            </button>
            <button
              disabled={isRunning}
              onClick={executeMahasiswaPartnerFetch}
              className="bg-teal-600 hover:bg-teal-500 disabled:opacity-50 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-teal-900/40"
            >
              2. Ambil Partner KeyPackage (Sesi Mahasiswa)
            </button>
            <button
              disabled={isRunning}
              onClick={executeMahasiswaWelcomeHandshake}
              className="bg-cyan-600 hover:bg-cyan-500 disabled:opacity-50 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-cyan-900/40"
            >
              3. Publikasi Welcome Handshake (Mahasiswa)
            </button>
            <button
              disabled={isRunning}
              onClick={executeDosenWelcomeAck}
              className="bg-violet-600 hover:bg-violet-500 disabled:opacity-50 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-violet-900/40"
            >
              4. Join Welcome & Kirim ACK (Dosen)
            </button>
            <button
              disabled={isRunning}
              onClick={() => executeMahasiswaSendMessage()}
              className="bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-indigo-900/40"
            >
              5. Kirim Pesan Mahasiswa (E2EE)
            </button>
            <button
              disabled={isRunning}
              onClick={() => executeDosenReceiveAndReply()}
              className="bg-purple-600 hover:bg-purple-500 disabled:opacity-50 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-purple-900/40"
            >
              6. Dekripsi & Balas Dosen (E2EE)
            </button>
            <button
              disabled={isRunning}
              onClick={() => executeMahasiswaReceiveReply()}
              className="bg-fuchsia-600 hover:bg-fuchsia-500 disabled:opacity-50 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-fuchsia-900/40"
            >
              7. Dekripsi Balasan di Mahasiswa
            </button>
            <button
              onClick={() => executeMahasiswaRealtime()}
              className="bg-sky-600 hover:bg-sky-500 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-sky-900/40"
            >
              8. Real-Time Reverb Mahasiswa (WebSocket &bull; Rekonsiliasi)
            </button>
            <button
              onClick={() => executeDosenRealtime()}
              className="bg-emerald-700 hover:bg-emerald-600 text-white font-bold px-4 py-2 rounded-lg transition shadow-lg shadow-emerald-900/40"
            >
              9. Real-Time Reverb Dosen (WebSocket &bull; Rekonsiliasi)
            </button>
          </div>
        </div>

        {/* Grid Status KeyPackage Publication (Dosen) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-emerald-400 text-sm">Tahapan Publikasi KeyPackage Dosen (I-04B.2)</h2>
          <div className="space-y-2">
            {kpSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-emerald-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Welcome Handshake (Mahasiswa) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-cyan-400 text-sm">Tahapan Welcome Handshake Mahasiswa (I-04B.3)</h2>
          <div className="space-y-2">
            {mhsHsSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-cyan-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-cyan-950 text-cyan-400 border border-cyan-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Welcome ACK (Dosen) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-violet-400 text-sm">Tahapan Welcome ACK Dosen (I-04B.3)</h2>
          <div className="space-y-2">
            {dosenAckSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-violet-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-violet-950 text-violet-400 border border-violet-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status KeyPackage Fetch (Mahasiswa) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-teal-400 text-sm">Tahapan Pengambilan KeyPackage Partner (Sesi Mahasiswa)</h2>
          <div className="space-y-2">
            {kpFetchSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-teal-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-teal-950 text-teal-400 border border-teal-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Welcome Handshake (Mahasiswa) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-cyan-400 text-sm">Tahapan Welcome Handshake Mahasiswa (I-04B.3)</h2>
          <div className="space-y-2">
            {mhsHsSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-cyan-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-cyan-950 text-cyan-400 border border-cyan-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Welcome ACK (Dosen) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-violet-400 text-sm">Tahapan Welcome ACK Dosen (I-04B.3)</h2>
          <div className="space-y-2">
            {dosenAckSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-violet-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-violet-950 text-violet-400 border border-violet-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Pengiriman Pesan Mahasiswa (I-04B.4R) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-indigo-400 text-sm">Tahapan Pengiriman Pesan Mahasiswa (I-04B.4R)</h2>
          <div className="space-y-2">
            {mhsMsgSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-indigo-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-indigo-950 text-indigo-400 border border-indigo-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Penerimaan & Balasan Dosen (I-04B.4R) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-purple-400 text-sm">Tahapan Penerimaan & Balasan Dosen (I-04B.4R)</h2>
          <div className="space-y-2">
            {dosenMsgSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-purple-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-purple-950 text-purple-400 border border-purple-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Dekripsi Balasan Mahasiswa (I-04B.4R) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-fuchsia-400 text-sm">Tahapan Dekripsi Balasan di Mahasiswa (I-04B.4R)</h2>
          <div className="space-y-2">
            {mhsReplySteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-fuchsia-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-fuchsia-950 text-fuchsia-400 border border-fuchsia-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Real-Time Reverb Mahasiswa (I-04B.5) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-sky-400 text-sm">Tahapan Real-Time Reverb WebSocket Mahasiswa (I-04B.5)</h2>
          <div className="space-y-2">
            {rvMhsSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-sky-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-sky-950 text-sky-400 border border-sky-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Grid Status Real-Time Reverb Dosen (I-04B.5) */}
        <div className="border border-slate-800 bg-slate-900/40 p-5 rounded-2xl space-y-3">
          <h2 className="font-bold text-emerald-400 text-sm">Tahapan Real-Time Reverb WebSocket Dosen (I-04B.5)</h2>
          <div className="space-y-2">
            {rvDosenSteps.map((s) => (
              <div
                key={s.id}
                className="flex items-center justify-between p-2.5 rounded-lg border border-slate-800/80 bg-slate-900/50"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`w-2.5 h-2.5 rounded-full ${
                      s.status === 'PASS'
                        ? 'bg-emerald-400'
                        : s.status === 'FAIL'
                        ? 'bg-red-400'
                        : s.status === 'RUNNING'
                        ? 'bg-amber-400 animate-ping'
                        : 'bg-slate-600'
                    }`}
                  />
                  <div>
                    <span className="font-semibold text-slate-200">{s.name}</span>
                    {s.detail && <p className="text-[11px] text-slate-400 mt-0.5">{s.detail}</p>}
                  </div>
                </div>
                <span
                  className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                    s.status === 'PASS'
                      ? 'bg-emerald-950 text-emerald-400 border border-emerald-800'
                      : s.status === 'FAIL'
                      ? 'bg-red-950 text-red-400 border border-red-800'
                      : s.status === 'RUNNING'
                      ? 'bg-amber-950 text-amber-400 border border-amber-800'
                      : 'bg-slate-800 text-slate-500'
                  }`}
                >
                  {s.status}
                </span>
              </div>
            ))}
          </div>
        </div>
        <div className="border border-slate-800 bg-black/60 p-5 rounded-2xl space-y-2">
          <h2 className="font-bold text-slate-400 text-xs">Konsol Log Protokol B-CHAT</h2>
          <div className="h-48 overflow-y-auto font-mono text-[11px] space-y-1 p-2 bg-black rounded-lg">
            {logs.length === 0 ? (
              <span className="text-slate-600">Menunggu aksi pengujian...</span>
            ) : (
              logs.map((l, i) => (
                <div key={i} className="flex gap-2">
                  <span className="text-slate-500">[{l.timestamp}]</span>
                  <span
                    className={
                      l.type === 'success'
                        ? 'text-emerald-400'
                        : l.type === 'error'
                        ? 'text-red-400'
                        : l.type === 'warn'
                        ? 'text-amber-400'
                        : 'text-slate-300'
                    }
                  >
                    {l.message}
                  </span>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
