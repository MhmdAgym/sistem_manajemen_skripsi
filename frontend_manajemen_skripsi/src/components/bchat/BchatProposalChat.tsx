'use client';

/**
 * B-CHAT E2EE — Antarmuka Chat Bimbingan Proposal (I-05A)
 *
 * Komponen UI interaktif untuk ruang obrolan privat 1:1 antara Mahasiswa Proposal
 * dan Dosen Pembimbing Proposal dengan perlindungan End-to-End Encryption (E2EE) MLS:
 * - Header identitas mitra (nama, avatar, judul skripsi, status koneksi real-time).
 * - Daftar pesan kronologis dengan bubble terpisah (mahasiswa di kanan, dosen di kiri),
 *   indikator status transmisi (Terkirim, Diterima, Gagal), dan stempel waktu.
 * - Input pesan teks dengan penanganan pintasan Enter / Shift+Enter dan indikator loading.
 * - Persistensi riwayat lokal terenkripsi (AES-256-GCM via KEK) yang bertahan terhadap
 *   navigasi, reload (F5), dan login ulang pada perangkat yang sama.
 * - Bebas dari kebocoran plaintext ke localStorage, sessionStorage, atau backend.
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import {
  Send,
  Lock,
  ShieldCheck,
  Check,
  CheckCheck,
  AlertCircle,
  Loader2,
  RefreshCw,
  User,
  ArrowDown,
  ArrowRight,
  Info,
  Clock,
  ShieldAlert,
  AlertTriangle,
  Paperclip,
  FileText,
  Download,
  FileCheck,
  FileX,
  X,
  Smartphone,
  History,
} from 'lucide-react';
import { useAuth } from '@/hooks/auth';
import {
  fetchRooms,
  fetchRoomDetail,
  getDeviceStatus,
} from '@/lib/bchat/api/bchat-api-client';
import type {
  BchatRoomSummary,
  BchatRoomDetail,
} from '@/lib/bchat/api/bchat-api-types';
import {
  createBchatWorkerClient,
  type BchatWorkerClient,
  type FencingRpcInput,
} from '@/lib/bchat/bchat-poc-rpc';
import {
  findActiveLocalDevice,
} from '@/lib/bchat/device/bchat-device-registration';
import {
  publishWelcomeHandshakeFlow,
  fetchChallengeAndAckHandshakeFlow,
  BchatHandshakeCoordinatorError,
} from '@/lib/bchat/handshake/bchat-handshake-coordinator';
import {
  sendApplicationMessageFlow,
  getGroupStateCursor,
  updateGroupStateCursor,
  loadEncryptedHistory,
  computeContiguousResolvedSequence,
  isHistoryTombstonePlaintext,
  type DecryptedMessageItem,
  type UnresolvedSequenceDetail,
} from '@/lib/bchat/message/bchat-message-coordinator';
import {
  BchatRealtimeSubscriber,
  type SubscriptionState,
  type BchatRecoveryState,
} from '@/lib/bchat/message/bchat-realtime-listener';
import {
  idbGet,
  idbPut,
} from '@/lib/bchat/storage/bchat-idb-core';
import {
  saveEncryptedHistoryItem,
} from '@/lib/bchat/storage/bchat-encrypted-history';
import {
  BCAT_STORES,
  type TabLeaseRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import { BchatDeviceOnboarding } from '@/components/bchat/BchatDeviceOnboarding';
import { BchatDeviceManagementModal } from '@/components/bchat/BchatDeviceManagementModal';
import { BchatDevicePairingGate } from '@/components/bchat/BchatDevicePairingGate';
import { BchatHistoryTransferModal } from '@/components/bchat/BchatHistoryTransferModal';
import {
  fetchPendingHistoryTransfers,
  type BchatHistoryTransferData,
} from '@/lib/bchat/history/bchat-history-transfer-client';
import {
  ATTACHMENT_CANONICAL,
  BchatAttachmentCoordinator,
  parseAttachmentManifest,
  formatAttachmentSize,
  type BchatAttachmentItemManifest,
  type BchatAttachmentManifest,
} from '@/lib/bchat/attachment';

// -----------------------------------------------------------------------------
// ONBOARDING GATE TYPE
// -----------------------------------------------------------------------------

type OnboardingGate = 'PENDING' | 'ONBOARDING' | 'PAIRING' | 'PASSED';

// -----------------------------------------------------------------------------
// KONSTANTA & REGEX
// -----------------------------------------------------------------------------

const CANONICAL_UUID_REGEX = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// -----------------------------------------------------------------------------
// FORMATTER WAKTU
// -----------------------------------------------------------------------------

function formatMessageTime(dateStr?: string | null): string {
  if (!dateStr) return '';
  try {
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  } catch {
    return '';
  }
}

// -----------------------------------------------------------------------------
// KOMPONEN UTAMA BCHAT PROPOSAL CHAT
// -----------------------------------------------------------------------------

export interface BchatProposalChatProps {
  initialRoomUuid?: string;
  onBack?: () => void;
}

export function BchatProposalChat({ initialRoomUuid, onBack }: BchatProposalChatProps) {
  const router = useRouter();
  const { user } = useAuth({ middleware: 'auth' });
  const userRef = useRef(user);
  userRef.current = user;

  // Onboarding Gate
  const [onboardingGate, setOnboardingGate] = useState<OnboardingGate>('ONBOARDING');

  // State Multi-Advisee Room Selection (Dosen)
  const [selectedRoomUuid, setSelectedRoomUuid] = useState<string | null>(initialRoomUuid || null);
  const [availableProposalRooms, setAvailableProposalRooms] = useState<BchatRoomSummary[]>([]);
  const [isSelectingAdvisee, setIsSelectingAdvisee] = useState<boolean>(false);

  // Sinkronkan selectedRoomUuid jika initialRoomUuid berubah dari luar (URL query param)
  useEffect(() => {
    if (initialRoomUuid) {
      setSelectedRoomUuid(initialRoomUuid);
      setIsSelectingAdvisee(false);
    } else {
      setSelectedRoomUuid(null);
    }
  }, [initialRoomUuid]);

  // State Room & Kredensial
  const [deviceUuid, setDeviceUuid] = useState<string | null>(null);
  const [isDeviceModalOpen, setIsDeviceModalOpen] = useState<boolean>(false);
  const [roomSummary, setRoomSummary] = useState<BchatRoomSummary | null>(null);
  const [roomDetail, setRoomDetail] = useState<BchatRoomDetail | null>(null);

  // State History Transfer E2EE (Tahap 2B)
  const [isHistoryTransferModalOpen, setIsHistoryTransferModalOpen] = useState<boolean>(false);
  const [incomingTransferRequest, setIncomingTransferRequest] = useState<BchatHistoryTransferData | null>(null);

  // State Pesan & Input
  const [messages, setMessages] = useState<DecryptedMessageItem[]>([]);
  const [inputText, setInputText] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);

  // State Lampiran E2EE (Stage 4)
  const [pendingFiles, setPendingFiles] = useState<File[]>([]);
  const [attachmentError, setAttachmentError] = useState<string | null>(null);
  const [uploadProgress, setUploadProgress] = useState<{
    currentFileIndex: number;
    totalFiles: number;
    percent: number;
    status: string;
  } | null>(null);
  const [downloadStateMap, setDownloadStateMap] = useState<
    Record<
      string,
      {
        status: 'IDLE' | 'DOWNLOADING' | 'VERIFYING' | 'READY' | 'ERROR';
        percent?: number;
        blobUrl?: string;
        error?: string;
      }
    >
  >({});
  const fileInputRef = useRef<HTMLInputElement>(null);

  // State Koneksi & Status Real-Time
  const [connectionStatus, setConnectionStatus] = useState<
    'INITIALIZING' | 'CONNECTED' | 'RECONNECTING' | 'OFFLINE' | 'ERROR'
  >('INITIALIZING');
  const [statusDetail, setStatusDetail] = useState<string>('Menyiapkan sesi E2EE...');
  const [initError, setInitError] = useState<string | null>(null);
  const [recoveryState, setRecoveryState] = useState<BchatRecoveryState | null>(null);
  const [isRetryingRecovery, setIsRetryingRecovery] = useState<boolean>(false);

  // State Handshake & Polling Otomatis
  const [waitingState, setWaitingState] = useState<{
    type: 'AWAIT_PARTNER_ACK' | 'AWAIT_PARTNER_WELCOME' | 'AWAIT_PARTNER_DEVICE';
    title: string;
    message: string;
  } | null>(null);
  const [isUnrecoverableActiveRoom, setIsUnrecoverableActiveRoom] = useState<boolean>(false);
  const handshakeInProgressRef = useRef<boolean>(false);
  const pollingTimerRef = useRef<NodeJS.Timeout | null>(null);

  // Referensi Lifecycle & Scroll
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const scrollContainerRef = useRef<HTMLDivElement>(null);
  const [showScrollBottom, setShowScrollBottom] = useState(false);
  const workerClientRef = useRef<BchatWorkerClient | null>(null);
  const subscriberRef = useRef<BchatRealtimeSubscriber | null>(null);
  const fencingRef = useRef<FencingRpcInput | null>(null);
  const sessionIdRef = useRef(0);

  const scrollToBottom = useCallback((smooth = true) => {
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({
        behavior: smooth ? 'smooth' : 'auto',
        block: 'end',
      });
    }
  }, []);

  // Deteksi posisi scroll untuk menampilkan tombol floating scroll-down
  const handleScroll = () => {
    if (!scrollContainerRef.current) return;
    const { scrollTop, scrollHeight, clientHeight } = scrollContainerRef.current;
    const isNearBottom = scrollHeight - scrollTop - clientHeight < 120;
    setShowScrollBottom(!isNearBottom);
  };

  // Test Harness Hook untuk pengujian otomatis (dev / test port 3001)
  useEffect(() => {
    if (typeof window !== 'undefined' && (process.env.NEXT_PUBLIC_IS_TEST_HARNESS === 'true' || window.location.port === '3001')) {
      (window as unknown as Record<string, unknown>).__bchatHarness = {
        saveEncryptedHistoryItem,
        loadEncryptedHistory,
        setMessages,
        getMessages: () => messages,
        deviceUuid,
        roomSummary,
        connectionStatus,
        isUnrecoverableActiveRoom,
        initError,
        openTransferModal: () => setIsHistoryTransferModalOpen(true),
        workerClient: workerClientRef.current,
      };
    }
  }, [messages, deviceUuid, roomSummary, connectionStatus, isUnrecoverableActiveRoom, initError]);

  // ---------------------------------------------------------------------------
  // INISIALISASI PROTOKOL & PERSIAPAN SESI
  // ---------------------------------------------------------------------------

  const initializeChatSession = useCallback(async () => {
    const currentUser = userRef.current;
    if (!currentUser) return;

    sessionIdRef.current += 1;
    const currentSessionId = sessionIdRef.current;

    // Bersihkan timer polling sebelumnya jika masih aktif
    if (pollingTimerRef.current) {
      clearInterval(pollingTimerRef.current);
      pollingTimerRef.current = null;
    }

    setConnectionStatus('INITIALIZING');
    setStatusDetail('Mendeteksi perangkat aktif...');
    setInitError(null);
    setWaitingState(null);
    setIsUnrecoverableActiveRoom(false);

    try {
      // 1. Deteksi Perangkat Aktif Lokal di IndexedDB Keystore
      const localDevice = await findActiveLocalDevice();
      if (sessionIdRef.current !== currentSessionId) return;

      if (!localDevice) {
        // Perangkat belum terdaftar — alihkan ke onboarding gate
        setOnboardingGate('ONBOARDING');
        return;
      }
      const activeDevUuid = localDevice.deviceUuid;
      setDeviceUuid(activeDevUuid);

      // 1b. Periksa status kepercayaan perangkat aktif di backend (Tahap 2A)
      try {
        const devStatusRes = await getDeviceStatus({ device_uuid: activeDevUuid });
        if (sessionIdRef.current !== currentSessionId) return;
        const matched = devStatusRes.data.active_devices?.find(
          (d) => d.device_uuid.toLowerCase() === activeDevUuid.toLowerCase()
        ) || devStatusRes.data.active_device;

        if (matched && matched.is_trusted === false) {
          setOnboardingGate('PAIRING');
          return;
        }
      } catch {
        // Jika status perangkat tidak dapat dijangkau, biarkan alur berlanjut
      }

      setOnboardingGate('PASSED');

      // 2. Ambil Daftar Room B-CHAT dari REST API
      setStatusDetail('Mengambil data ruang bimbingan...');
      const roomsRes = await fetchRooms();
      if (sessionIdRef.current !== currentSessionId) return;
      const allRooms = roomsRes.data || [];
      const proposalRooms = allRooms.filter((r) => r.tahap?.toLowerCase() === 'proposal');
      setAvailableProposalRooms(proposalRooms);

      // Resolusi target room berdasarkan parameter eksplisit atau seleksi
      const effectiveRoomUuid = selectedRoomUuid || initialRoomUuid;
      let targetRoom: BchatRoomSummary | undefined;

      if (effectiveRoomUuid) {
        // Validasi format UUID kanonis
        const trimmedUuid = effectiveRoomUuid.trim();
        if (!CANONICAL_UUID_REGEX.test(trimmedUuid)) {
          setConnectionStatus('ERROR');
          setInitError('Format ID ruang obrolan tidak valid. Akses ditolak.');
          return;
        }

        // Cari room dengan UUID yang sama secara case-insensitive
        targetRoom = allRooms.find(
          (r) => r.room_uuid.toLowerCase() === trimmedUuid.toLowerCase()
        );

        if (!targetRoom) {
          // FAIL-CLOSED: JANGAN PERNAH fallback ke allRooms[0] atau room lain!
          setConnectionStatus('ERROR');
          setInitError(
            'Ruang obrolan tidak ditemukan atau Anda tidak memiliki akses ke bimbingan mahasiswa ini.'
          );
          return;
        }
      } else {
        // initialRoomUuid TIDAK tersedia
        if (proposalRooms.length === 1) {
          // Hanya ada 1 room Proposal -> pilih otomatis
          targetRoom = proposalRooms[0];
        } else if (proposalRooms.length > 1) {
          // Ada beberapa room Proposal -> tampilkan pemilih mahasiswa (Advisee Selector)
          setIsSelectingAdvisee(true);
          setConnectionStatus('CONNECTED');
          return;
        } else {
          // Tidak ada room Proposal sama sekali
          setConnectionStatus('ERROR');
          setInitError(
            'Tidak ditemukan ruang obrolan bimbingan Proposal yang aktif. Pastikan penugasan pembimbing Anda telah disetujui.'
          );
          return;
        }
      }

      setRoomSummary(targetRoom);
      const roomUuid = targetRoom.room_uuid;

      // 3. Ambil Detail Room Aktual dari REST API
      setStatusDetail('Mengambil status ruang obrolan...');
      let detailRes;
      try {
        detailRes = await fetchRoomDetail(roomUuid);
      } catch (apiErr: any) {
        if (sessionIdRef.current !== currentSessionId) return;
        setConnectionStatus('ERROR');
        if (apiErr?.status === 403 || apiErr?.errorCode === 'FORBIDDEN' || apiErr?.message?.includes('403')) {
          setInitError('Akses ditolak. Anda tidak memiliki otorisasi untuk mengakses ruang bimbingan mahasiswa ini.');
        } else if (apiErr?.status === 404 || apiErr?.errorCode === 'NOT_FOUND' || apiErr?.message?.includes('404')) {
          setInitError('Ruang obrolan tidak ditemukan atau telah ditutup.');
        } else {
          setInitError(apiErr?.message || 'Gagal memuat status ruang obrolan.');
        }
        return;
      }
      if (sessionIdRef.current !== currentSessionId) return;
      let currentDetail = detailRes.data;
      setRoomDetail(currentDetail);

      // 4. Peroleh Tab Lease Fencing
      setStatusDetail('Mengamankan tab lease...');
      const existingLease = await idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, activeDevUuid);
      if (sessionIdRef.current !== currentSessionId) return;

      const nextGen = existingLease?.generation
        ? String(BigInt(existingLease.generation) + BigInt(1))
        : '1';
      const ownerToken = `tab_${typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : Date.now()}`;

      const leaseRecord: TabLeaseRecord = {
        device_uuid: activeDevUuid,
        owner_token: ownerToken,
        generation: nextGen,
        acquired_at: Date.now(),
        expires_at: Date.now() + 3600000,
      };
      await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);
      if (sessionIdRef.current !== currentSessionId) return;

      const fencing: FencingRpcInput = {
        ownerToken,
        generation: nextGen,
      };
      fencingRef.current = fencing;

      // Bersihkan worker / subscriber sebelumnya jika sesi diinisialisasi ulang
      if (workerClientRef.current) {
        workerClientRef.current.terminate();
        workerClientRef.current = null;
      }
      if (subscriberRef.current) {
        subscriberRef.current.unsubscribe();
        subscriberRef.current = null;
      }

      // 5. Instansiasi Dedicated MLS Worker
      setStatusDetail('Memulai worker MLS RFC 9420...');
      const workerClient = createBchatWorkerClient();
      if (sessionIdRef.current !== currentSessionId) {
        workerClient.terminate();
        return;
      }
      workerClientRef.current = workerClient;

      await workerClient.sendRequest('INIT_PEER', {
        role: currentUser.role || 'mahasiswa',
        identity: currentUser.email,
        deviceUuid: activeDevUuid,
        fencing,
      });
      if (sessionIdRef.current !== currentSessionId) return;

      // Helper untuk polling otomatis saat menunggu status/rekan chat
      const startWaitingPolling = (
        waitingType: 'AWAIT_PARTNER_ACK' | 'AWAIT_PARTNER_WELCOME' | 'AWAIT_PARTNER_DEVICE'
      ) => {
        if (pollingTimerRef.current) {
          clearInterval(pollingTimerRef.current);
          pollingTimerRef.current = null;
        }
        pollingTimerRef.current = setInterval(async () => {
          if (sessionIdRef.current !== currentSessionId) {
            if (pollingTimerRef.current) clearInterval(pollingTimerRef.current);
            return;
          }
          try {
            const checkRes = await fetchRoomDetail(roomUuid);
            if (sessionIdRef.current !== currentSessionId) return;
            const updated = checkRes.data;
            if (
              updated.status === 'ACTIVE' ||
              (updated.action_hint?.action_required === true &&
                updated.action_hint?.action_type !== 'NONE') ||
              (waitingType === 'AWAIT_PARTNER_DEVICE' && updated.partner?.has_active_device === true)
            ) {
              if (pollingTimerRef.current) {
                clearInterval(pollingTimerRef.current);
                pollingTimerRef.current = null;
              }
              initializeChatSession();
            }
          } catch {
            // Abaikan kegagalan jaringan saat polling background
          }
        }, 5000);
      };

      // 6. Evaluasi Status Room & Orkestrasi Handshake Otomatis
      if (currentDetail.status === 'PENDING_WELCOME_ACK') {
        const actionHint = currentDetail.action_hint;
        const hasActiveChallenge = currentDetail.handshake_context?.has_active_challenge === true;
        const canHandshake = currentDetail.capabilities?.can_handshake === true;

        // KASUS A: Mahasiswa inisiator mempublikasikan Welcome (PUBLISH_WELCOME)
        if (
          actionHint?.action_type === 'PUBLISH_WELCOME' &&
          actionHint?.action_required === true &&
          canHandshake
        ) {
          // Periksa apakah dosen pembimbing sudah memiliki perangkat aktif terdaftar
          if (currentDetail.partner?.has_active_device === false) {
            setWaitingState({
              type: 'AWAIT_PARTNER_DEVICE',
              title: 'Menunggu Perangkat Dosen',
              message:
                'Dosen pembimbing belum mendaftarkan perangkat aman B-CHAT. Ruang percakapan akan otomatis disiapkan setelah dosen membuka aplikasi dan mengaktifkan perangkat.',
            });
            startWaitingPolling('AWAIT_PARTNER_DEVICE');
            return;
          }

          if (handshakeInProgressRef.current) return;
          handshakeInProgressRef.current = true;

          try {
            setStatusDetail('Membuat ruang percakapan aman (MLS Welcome)...');
            await publishWelcomeHandshakeFlow(
              workerClient,
              roomUuid,
              activeDevUuid,
              { fencing }
            );
            if (sessionIdRef.current !== currentSessionId) return;

            // Refresh status room setelah publikasi Welcome
            const refreshedDetail = await fetchRoomDetail(roomUuid);
            if (sessionIdRef.current !== currentSessionId) return;
            currentDetail = refreshedDetail.data;
            setRoomDetail(currentDetail);

            if (currentDetail.status !== 'ACTIVE') {
              // Sukses publikasi, sekarang menunggu konfirmasi dosen (AWAIT_PARTNER_ACK)
              setWaitingState({
                type: 'AWAIT_PARTNER_ACK',
                title: 'Menunggu Konfirmasi Pembimbing',
                message:
                  'Kunci percakapan aman (Welcome message) telah berhasil dikirim ke server. Ruang obrolan akan aktif segera setelah dosen pembimbing mengonfirmasi dari perangkatnya.',
              });
              startWaitingPolling('AWAIT_PARTNER_ACK');
              return;
            }
          } catch (pubErr: any) {
            if (sessionIdRef.current !== currentSessionId) return;
            let errMsg = 'Gagal mempublikasikan jabat tangan aman.';
            if (pubErr instanceof BchatHandshakeCoordinatorError) {
              if (pubErr.errorCode === 'FETCH_PARTNER_KEY_PACKAGE_FAILED') {
                errMsg =
                  'Dosen pembimbing belum memiliki paket kunci aktif di server. Menunggu dosen mengunggah paket kunci.';
              } else if (pubErr.errorCode === 'WELCOME_PUBLISH_IN_TRANSIT') {
                errMsg =
                  'Publikasi jabat tangan sedang dalam pengiriman. Silakan periksa kembali beberapa saat lagi.';
              } else {
                errMsg = pubErr.message;
              }
            } else if (pubErr?.message) {
              errMsg = pubErr.message;
            }
            setConnectionStatus('ERROR');
            setInitError(errMsg);
            return;
          } finally {
            handshakeInProgressRef.current = false;
          }
        }
        // KASUS B: Dosen penerima mengambil challenge dan mengirim Welcome ACK (FETCH_CHALLENGE_AND_ACK)
        else if (
          actionHint?.action_type === 'FETCH_CHALLENGE_AND_ACK' &&
          actionHint?.action_required === true &&
          hasActiveChallenge &&
          canHandshake
        ) {
          if (handshakeInProgressRef.current) return;
          handshakeInProgressRef.current = true;

          try {
            setStatusDetail('Bergabung ke ruang percakapan aman (MLS Join & ACK)...');
            await fetchChallengeAndAckHandshakeFlow(
              workerClient,
              roomUuid,
              activeDevUuid,
              {
                fencing,
                userId: currentUser.id,
              }
            );
            if (sessionIdRef.current !== currentSessionId) return;

            // Refresh status room setelah ACK terkirim
            const refreshedDetail = await fetchRoomDetail(roomUuid);
            if (sessionIdRef.current !== currentSessionId) return;
            currentDetail = refreshedDetail.data;
            setRoomDetail(currentDetail);

            if (currentDetail.status !== 'ACTIVE') {
              throw new Error('Aktivasi ruang obrolan belum tuntas di server.');
            }
          } catch (ackErr: any) {
            if (sessionIdRef.current !== currentSessionId) return;
            let errMsg = 'Gagal memproses konfirmasi jabat tangan aman.';
            if (ackErr instanceof BchatHandshakeCoordinatorError) {
              errMsg = ackErr.message;
            } else if (ackErr?.message) {
              errMsg = ackErr.message;
            }
            setConnectionStatus('ERROR');
            setInitError(errMsg);
            return;
          } finally {
            handshakeInProgressRef.current = false;
          }
        }
        // KASUS C: Mahasiswa menunggu konfirmasi dosen (AWAIT_PARTNER_ACK)
        else if (
          actionHint?.action_type === 'AWAIT_PARTNER_ACK' &&
          actionHint?.action_required === false
        ) {
          setWaitingState({
            type: 'AWAIT_PARTNER_ACK',
            title: 'Menunggu Konfirmasi Pembimbing',
            message:
              'Kunci percakapan aman telah dikirim. Menunggu dosen pembimbing mengonfirmasi ruang bimbingan dari perangkatnya.',
          });
          startWaitingPolling('AWAIT_PARTNER_ACK');
          return;
        }
        // KASUS D: Dosen menunggu inisiasi mahasiswa (AWAIT_PARTNER_WELCOME)
        else if (
          actionHint?.action_type === 'AWAIT_PARTNER_WELCOME' &&
          actionHint?.action_required === false
        ) {
          setWaitingState({
            type: 'AWAIT_PARTNER_WELCOME',
            title: 'Menunggu Inisiasi Mahasiswa',
            message:
              'Mahasiswa bimbingan belum menginisiasi ruang obrolan aman ini. Ruang obrolan akan otomatis disiapkan setelah mahasiswa memulai inisiasi dari perangkatnya.',
          });
          startWaitingPolling('AWAIT_PARTNER_WELCOME');
          return;
        }
        // KASUS E: Status PENDING_WELCOME_ACK namun kombinasi action hint tidak valid atau izin ditolak
        else {
          setConnectionStatus('ERROR');
          if (!canHandshake) {
            setInitError(
              'Anda tidak memiliki izin (capability) untuk melakukan jabat tangan aman pada ruang ini.'
            );
          } else {
            setInitError(
              `Kondisi jabat tangan tidak sesuai (action_type: ${actionHint?.action_type || 'NONE'}, action_required: ${String(actionHint?.action_required)}).`
            );
          }
          return;
        }
      } else if (currentDetail.status !== 'ACTIVE') {
        // Status room di luar ACTIVE dan PENDING_WELCOME_ACK (misal ARCHIVED, SUSPENDED)
        setConnectionStatus('ERROR');
        setInitError(
          `Ruang obrolan berstatus ${currentDetail.status}. Percakapan tidak dapat diakses saat ini.`
        );
        return;
      }

      // 6.5 Muat Riwayat Pesan Terenkripsi dari IndexedDB Lokal (jika ada)
      setStatusDetail('Memeriksa riwayat pesan lokal...');
      const localHistoryItems = await loadEncryptedHistory(activeDevUuid, roomUuid).catch(() => []);
      if (sessionIdRef.current !== currentSessionId) return;
      if (localHistoryItems.length > 0) {
        setMessages(localHistoryItems);
      }

      // 7. Restore GroupState MLS (Room berstatus ACTIVE)
      setStatusDetail('Memulihkan status kriptografi room...');
      const restoreRes = await workerClient.restoreGroupState({
        deviceUuid: activeDevUuid,
        roomUuid,
        fencing,
      });
      if (sessionIdRef.current !== currentSessionId) return;

      if (restoreRes.status !== 'FOUND') {
        if (restoreRes.status === 'NOT_FOUND') {
          // Jika sudah ada riwayat lokal yang diimpor, tampilkan riwayat dan aktifkan mode baca
          if (localHistoryItems.length > 0) {
            setConnectionStatus('CONNECTED');
            setStatusDetail('Riwayat obrolan aman dimuat dari penyimpanan lokal');
            // Tetap lanjutkan inisialisasi subscriber agar multi-device realtime sync bekerja
          } else {
            // Room ACTIVE namun GroupState tidak ada di IndexedDB perangkat ini
            setIsUnrecoverableActiveRoom(true);
            setInitError(
              'Ruang obrolan ini sudah aktif pada perangkat lain, namun kunci sesi lokal (GroupState) tidak ditemukan pada browser ini. Harap gunakan perangkat yang sebelumnya digunakan atau sinkronkan riwayat dari perangkat tepercaya Anda.'
            );
            setConnectionStatus('ERROR');
            return;
          }
        } else {
          // Fail-closed untuk status abnormal lainnya (QUARANTINED, FENCING_STALE, RESTORE_ERROR)
          throw new Error(
            `Sesi GroupState tidak dapat dipulihkan (${restoreRes.status}: ${restoreRes.message || 'Kegagalan keystore lokal'}).`
          );
        }
      }

      // 8. Muat Riwayat Pesan Terenkripsi dari IndexedDB (AES-256-GCM via KEK)
      setStatusDetail('Mendekripsi riwayat pesan lokal...');
      const historyItems = localHistoryItems.length > 0 ? localHistoryItems : await loadEncryptedHistory(activeDevUuid, roomUuid);
      if (sessionIdRef.current !== currentSessionId) return;

      setMessages(historyItems);

      // Pisahkan riwayat valid (bukan tombstone) dari pesan gagal/tombstone untuk menjaga
      // Contiguous Verified Cursor agar tidak pernah melompati celah atau pesan yang gagal didekripsi.
      const validHistorySeqs = historyItems
        .filter((item) => !isHistoryTombstonePlaintext(item.plaintext))
        .map((item) => item.server_sequence);
      const tombstoneUnresolved: UnresolvedSequenceDetail[] = historyItems
        .filter((item) => isHistoryTombstonePlaintext(item.plaintext))
        .map((item) => ({
          server_sequence: item.server_sequence,
          message_uuid: item.message_uuid,
          errorCategory:
            item.plaintext === '[Integritas Gagal]' || item.plaintext === '[Format Corrupt]'
              ? 'PAYLOAD_INTEGRITY_ERROR'
              : 'MLS_CRYPTO_ERROR',
          error:
            item.error ||
            `Pesan urutan #${item.server_sequence} belum dapat didekripsi pada perangkat ini.`,
          requiresRecovery: true,
        }));

      const contiguousHistorySeq = computeContiguousResolvedSequence(validHistorySeqs, 0);

      // Ambil kursor persisten dari GroupState jika ada.
      // HANYA gunakan persistentCursor jika tidak melompati tombstone/celah pada riwayat lokal.
      const persistentCursor = await getGroupStateCursor(activeDevUuid, roomUuid);
      const validHistorySet = new Set<number>(validHistorySeqs);
      const minTombstoneSeq = tombstoneUnresolved.reduce(
        (min, item) => Math.min(min, item.server_sequence),
        Number.POSITIVE_INFINITY
      );
      const hasGapBelowPersistent =
        historyItems.length > 0 &&
        (minTombstoneSeq <= persistentCursor ||
          Array.from({ length: persistentCursor }, (_, idx) => idx + 1).some(
            (seq) => !validHistorySet.has(seq)
          ));

      const startingCursor = hasGapBelowPersistent
        ? contiguousHistorySeq
        : Math.max(persistentCursor, contiguousHistorySeq);

      // Sinkronkan kursor GroupState ke disk HANYA jika contiguousHistorySeq yang terverifikasi lebih tinggi
      if (contiguousHistorySeq > persistentCursor) {
        await updateGroupStateCursor({
          deviceUuid: activeDevUuid,
          roomUuid,
          sequence: contiguousHistorySeq,
          fencing,
        }).catch(() => {});
      }
      if (sessionIdRef.current !== currentSessionId) return;

      // 9. Siapkan Reverb WebSocket Real-Time Subscriber
      setStatusDetail('Menghubungkan saluran Reverb real-time...');
      const subscriber = new BchatRealtimeSubscriber({
        roomUuid,
        currentUserId: Number(currentUser.id),
        deviceUuid: activeDevUuid,
        workerRpc: workerClient,
        fencing,
        initialSequence: startingCursor,
        knownProcessedSequences: validHistorySeqs,
        knownUnresolvedSequences: tombstoneUnresolved,
        onSubscriptionStatusChange: (status: SubscriptionState, detail?: string) => {
          if (sessionIdRef.current !== currentSessionId) return;
          if (status === 'SUBSCRIBED') {
            setConnectionStatus('CONNECTED');
            if (!subscriberRef.current?.getIsRecoveryRequired()) {
              setStatusDetail('Terhubung aman via E2EE');
            }
          } else if (status === 'CONNECTING') {
            setConnectionStatus('RECONNECTING');
            setStatusDetail(detail || 'Menghubungkan kembali...');
          } else if (status === 'ERROR') {
            setConnectionStatus('ERROR');
            setStatusDetail(detail || 'Gangguan saluran WebSocket');
          } else {
            setConnectionStatus('OFFLINE');
            setStatusDetail(detail || 'Terputus');
          }
        },
        onMessageDecrypted: (newMsg: DecryptedMessageItem) => {
          if (sessionIdRef.current !== currentSessionId) return;
          setMessages((prev) => {
            const existingIdx = prev.findIndex(
              (m) => m.server_sequence === newMsg.server_sequence
            );
            if (existingIdx !== -1) {
              const existing = prev[existingIdx];
              const existingIsTombstone =
                !!existing.error || isHistoryTombstonePlaintext(existing.plaintext);
              const incomingIsValid =
                !newMsg.error && !isHistoryTombstonePlaintext(newMsg.plaintext);
              // Hanya timpa jika pesan sebelumnya berupa tombstone dan kini pulih menjadi plaintext sah
              if (existingIsTombstone && incomingIsValid) {
                const next = [...prev];
                next[existingIdx] = newMsg;
                return next;
              }
              return prev;
            }
            const updated = [...prev, newMsg].sort(
              (a, b) => a.server_sequence - b.server_sequence
            );
            return updated;
          });
          setTimeout(() => scrollToBottom(true), 100);
        },
        onError: (err: Error) => {
          if (sessionIdRef.current !== currentSessionId) return;
          setStatusDetail(`Error: ${err.message}`);
        },
        onRecoveryRequired: (failedSeq: number) => {
          if (sessionIdRef.current !== currentSessionId) return;
          setStatusDetail(
            `Pemulihan dibutuhkan untuk pesan urutan #${failedSeq}. Pesan baru tetap dapat diproses.`
          );
        },
        onRecoveryStateChange: (state: BchatRecoveryState) => {
          if (sessionIdRef.current !== currentSessionId) return;
          setRecoveryState(state);
        },
      });

      if (sessionIdRef.current !== currentSessionId) {
        subscriber.unsubscribe();
        return;
      }
      subscriberRef.current = subscriber;

      // Inisialisasi kursor subscriber dari IndexedDB GroupState
      await subscriber.initializeFromPersistence();
      if (sessionIdRef.current !== currentSessionId) return;

      // Berlangganan ke private channel Reverb
      subscriber.subscribe();

      // Rekonsiliasi pesan dari REST API via subscriber (Single Source of Truth) untuk menutup celah saat offline
      await subscriber.reconcileFromRest();
      if (sessionIdRef.current !== currentSessionId) return;

      const currentRecState = subscriber.getRecoveryState();
      setRecoveryState(currentRecState);
      setConnectionStatus('CONNECTED');
      if (currentRecState.isRecoveryRequired && currentRecState.failedSequence) {
        setStatusDetail(
          `Pemulihan dibutuhkan untuk pesan urutan #${currentRecState.failedSequence}. Pesan baru tetap dapat diproses.`
        );
      } else {
        setStatusDetail('Terhubung aman via E2EE');
      }
      setTimeout(() => scrollToBottom(false), 200);
    } catch (err: any) {
      if (sessionIdRef.current !== currentSessionId) return;
      setConnectionStatus('ERROR');
      setInitError(err?.message || 'Gagal menyiapkan sesi obrolan E2EE.');
    }
  }, [initialRoomUuid, selectedRoomUuid, scrollToBottom]);

  // Handler Pemilihan Mahasiswa Bimbingan (Multi-Advisee)
  const handleSelectAdvisee = (chosenRoomUuid: string) => {
    setIsSelectingAdvisee(false);
    setInitError(null);
    setSelectedRoomUuid(chosenRoomUuid);
    setRoomSummary(null);
    setRoomDetail(null);
    setMessages([]);
    router.push(`?room=${encodeURIComponent(chosenRoomUuid)}`);
  };

  const handleSwitchAdvisee = () => {
    setIsSelectingAdvisee(true);
    setSelectedRoomUuid(null);
    setRoomSummary(null);
    setRoomDetail(null);
    setMessages([]);
    router.push('?');
  };

  // Jalankan inisialisasi pada mount saat user.id siap (hanya 1x per mount, kebal terhadap SWR revalidation)
  useEffect(() => {
    if (!user?.id || onboardingGate !== 'PASSED') return;
    initializeChatSession();

    // Cleanup saat unmount
    return () => {
      sessionIdRef.current += 1;
      if (pollingTimerRef.current) {
        clearInterval(pollingTimerRef.current);
        pollingTimerRef.current = null;
      }
      handshakeInProgressRef.current = false;
      if (subscriberRef.current) {
        subscriberRef.current.unsubscribe();
        subscriberRef.current = null;
      }
      if (workerClientRef.current) {
        workerClientRef.current.terminate();
        workerClientRef.current = null;
      }
    };
  }, [user?.id, onboardingGate, initializeChatSession]);

  // Polling deteksi transfer riwayat masuk untuk perangkat ini (Perangkat Sumber - Tahap 2B)
  useEffect(() => {
    if (!deviceUuid || !user?.id) return;

    let isMounted = true;
    const checkIncomingTransfers = async () => {
      try {
        const res = await fetchPendingHistoryTransfers(deviceUuid);
        if (!isMounted) return;
        const pending = res.data || [];
        if (pending.length > 0) {
          const activePending = pending.find((t) => t.status === 'REQUESTED');
          if (activePending) {
            setIncomingTransferRequest(activePending);
          }
        }
      } catch (_e) {
        // Abaikan kegagalan jaringan berkala saat polling
      }
    };

    checkIncomingTransfers();
    const timer = setInterval(checkIncomingTransfers, 3500);

    return () => {
      isMounted = false;
      clearInterval(timer);
    };
  }, [deviceUuid, user?.id]);

  // ---------------------------------------------------------------------------
  // MANAJEMEN LAMPIRAN E2EE (STAGE 4)
  // ---------------------------------------------------------------------------

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFiles = Array.from(e.target.files || []);
    if (selectedFiles.length === 0) return;

    setAttachmentError(null);

    // Validasi jumlah berkas total
    const combinedCount = pendingFiles.length + selectedFiles.length;
    if (combinedCount > ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT) {
      setAttachmentError(
        `Maksimal ${ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT} berkas per pesan (saat ini ${pendingFiles.length}, dipilih ${selectedFiles.length}).`
      );
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    // Validasi tiap berkas individual
    for (const file of selectedFiles) {
      if (file.size === 0) {
        setAttachmentError(`Berkas "${file.name}" berukuran 0 byte. Berkas kosong ditolak mutlak.`);
        if (fileInputRef.current) fileInputRef.current.value = '';
        return;
      }
      if (file.size > ATTACHMENT_CANONICAL.MAX_FILE_SIZE_BYTES) {
        setAttachmentError(
          `Berkas "${file.name}" (${formatAttachmentSize(file.size)}) melebihi batas maksimal 10 MB per berkas.`
        );
        if (fileInputRef.current) fileInputRef.current.value = '';
        return;
      }
    }

    // Validasi ukuran total gabungan
    const currentTotalSize = pendingFiles.reduce((acc, f) => acc + f.size, 0);
    const newTotalSize = selectedFiles.reduce((acc, f) => acc + f.size, 0);
    if (currentTotalSize + newTotalSize > ATTACHMENT_CANONICAL.MAX_TOTAL_SIZE_BYTES) {
      setAttachmentError(
        `Total ukuran berkas (${formatAttachmentSize(currentTotalSize + newTotalSize)}) melebihi kuota 25 MB per pesan.`
      );
      if (fileInputRef.current) fileInputRef.current.value = '';
      return;
    }

    setPendingFiles((prev) => [...prev, ...selectedFiles]);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  const handleRemovePendingFile = (indexToRemove: number) => {
    setPendingFiles((prev) => prev.filter((_, idx) => idx !== indexToRemove));
    setAttachmentError(null);
  };

  const handleDownloadAttachment = async (
    msg: DecryptedMessageItem,
    att: BchatAttachmentItemManifest
  ) => {
    if (!roomSummary) return;
    if (!att.cekBase64 && !workerClientRef.current) return;

    const key = `${msg.message_uuid}_${att.attachmentUuid}`;

    // Jika sudah ready dengan blobUrl, buka / simpan langsung
    const currentStatus = downloadStateMap[key];
    if (currentStatus?.status === 'READY' && currentStatus.blobUrl) {
      const link = document.createElement('a');
      link.href = currentStatus.blobUrl;
      link.download = att.fileName;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      return;
    }

    setDownloadStateMap((prev) => ({
      ...prev,
      [key]: { status: 'DOWNLOADING', percent: 0 },
    }));

    try {
      const preDerivedCekBytes = att.cekBase64
        ? Uint8Array.from(atob(att.cekBase64), (c) => c.charCodeAt(0))
        : undefined;

      const assembledBytes = await BchatAttachmentCoordinator.downloadAttachment({
        roomUuid: roomSummary.room_uuid,
        messageUuid: msg.message_uuid,
        attachmentUuid: att.attachmentUuid,
        uploadSessionId: att.uploadSessionId,
        totalChunks: att.totalChunks,
        expectedTotalSize: att.fileSizeBytes,
        expectedFileSha256: att.fileHashSha256,
        workerClient: workerClientRef.current,
        preDerivedCekBytes,
        onProgress: (p) => {
          setDownloadStateMap((prev) => ({
            ...prev,
            [key]: {
              status: p.percent === 100 ? 'VERIFYING' : 'DOWNLOADING',
              percent: p.percent,
            },
          }));
        },
      });

      setDownloadStateMap((prev) => ({
        ...prev,
        [key]: { status: 'VERIFYING', percent: 100 },
      }));

      const blob = new Blob([assembledBytes as unknown as BlobPart], {
        type: att.mimeType || 'application/octet-stream',
      });
      const blobUrl = URL.createObjectURL(blob);

      setDownloadStateMap((prev) => ({
        ...prev,
        [key]: { status: 'READY', blobUrl },
      }));

      // Otomatis picu download
      const link = document.createElement('a');
      link.href = blobUrl;
      link.download = att.fileName;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    } catch (err: any) {
      setDownloadStateMap((prev) => ({
        ...prev,
        [key]: {
          status: 'ERROR',
          error: err?.message || 'Gagal mengunduh atau memverifikasi berkas.',
        },
      }));
    }
  };

  // ---------------------------------------------------------------------------
  // PENGIRIMAN PESAN
  // ---------------------------------------------------------------------------

  const handleSendMessage = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();

    const text = inputText.trim();
    const hasPendingFiles = pendingFiles.length > 0;

    if (!text && !hasPendingFiles) {
      return;
    }

    if (
      isSending ||
      !roomSummary ||
      !deviceUuid ||
      !workerClientRef.current ||
      !fencingRef.current ||
      roomDetail?.status !== 'ACTIVE' ||
      connectionStatus !== 'CONNECTED'
    ) {
      return;
    }

    setIsSending(true);
    setSendError(null);
    setAttachmentError(null);

    try {
      const roomUuid = roomSummary.room_uuid;
      const clientMessageUuid =
        typeof crypto !== 'undefined' && crypto.randomUUID
          ? crypto.randomUUID()
          : 'msg_' + Date.now();

      let messageContentType: 1 | 2 = 1;
      let finalPlaintext = text;

      if (hasPendingFiles) {
        messageContentType = 2; // ATTACHMENT_MANIFEST
        const manifestAttachments: BchatAttachmentItemManifest[] = [];

        for (let i = 0; i < pendingFiles.length; i++) {
          const file = pendingFiles[i];
          const attachmentUuid =
            typeof crypto !== 'undefined' && crypto.randomUUID
              ? crypto.randomUUID()
              : 'att_' + Date.now() + '_' + i;

          setUploadProgress({
            currentFileIndex: i + 1,
            totalFiles: pendingFiles.length,
            percent: 0,
            status: `Mengunggah berkas ${i + 1} dari ${pendingFiles.length} (${file.name})...`,
          });

          const fileBytes = new Uint8Array(await file.arrayBuffer());

          const uploadRes = await BchatAttachmentCoordinator.uploadAttachment({
            roomUuid,
            messageUuid: clientMessageUuid,
            attachmentUuid,
            fileBytes,
            fileName: file.name,
            workerClient: workerClientRef.current,
            onProgress: (p) => {
              setUploadProgress({
                currentFileIndex: i + 1,
                totalFiles: pendingFiles.length,
                percent: p.percent,
                status: `Mengunggah berkas ${i + 1} dari ${pendingFiles.length} (${p.percent}%)...`,
              });
            },
          });

          let cekBase64: string | undefined;
          if (uploadRes.cekBytes && uploadRes.cekBytes.length === ATTACHMENT_CANONICAL.CEK_LENGTH_BYTES) {
            let bin = '';
            for (let b = 0; b < uploadRes.cekBytes.length; b++) {
              bin += String.fromCharCode(uploadRes.cekBytes[b]);
            }
            cekBase64 = btoa(bin);
          }

          manifestAttachments.push({
            attachmentUuid: uploadRes.attachmentUuid,
            uploadSessionId: uploadRes.sessionUuid,
            fileName: file.name,
            mimeType: file.type || 'application/octet-stream',
            fileSizeBytes: uploadRes.plaintextBytes,
            totalChunks: uploadRes.totalChunks,
            fileHashSha256: uploadRes.fileHashSha256,
            ...(cekBase64 ? { cekBase64 } : {}),
          });
        }

        const manifest: BchatAttachmentManifest = {
          version: '1.0.0',
          type: 'ATTACHMENT_MANIFEST',
          text: text || undefined,
          attachments: manifestAttachments,
        };

        finalPlaintext = JSON.stringify(manifest);
      }

      const sendResult = await sendApplicationMessageFlow({
        roomUuid,
        plaintext: finalPlaintext,
        deviceUuid,
        workerRpc: workerClientRef.current,
        fencing: fencingRef.current,
        clientMessageUuid,
        contentType: messageContentType,
      });

      const newMsgItem: DecryptedMessageItem = {
        server_sequence: sendResult.messageData.server_sequence,
        message_uuid: sendResult.clientMessageUuid,
        sender_id: Number(user?.id),
        epoch: parseInt(sendResult.epoch, 10),
        generation: 0,
        content_type: messageContentType,
        is_self: true,
        plaintext: finalPlaintext,
        client_sent_at: sendResult.messageData.client_sent_at,
        created_at: sendResult.messageData.created_at,
      };

      setMessages((prev) => {
        if (prev.some((m) => m.server_sequence === newMsgItem.server_sequence)) {
          return prev;
        }
        return [...prev, newMsgItem].sort(
          (a, b) => a.server_sequence - b.server_sequence
        );
      });

      if (subscriberRef.current) {
        subscriberRef.current.markSequenceProcessed(newMsgItem.server_sequence);
      }

      setInputText('');
      setPendingFiles([]);
      setUploadProgress(null);
      setTimeout(() => scrollToBottom(true), 50);
    } catch (err: any) {
      setSendError(err?.message || 'Pesan gagal dikirim. Periksa koneksi Anda.');
    } finally {
      setIsSending(false);
      setUploadProgress(null);
    }
  };

  const handleRetryRecovery = async () => {
    if (!subscriberRef.current || isRetryingRecovery) return;
    setIsRetryingRecovery(true);
    try {
      await subscriberRef.current.retryRecovery();
      const updatedState = subscriberRef.current.getRecoveryState();
      setRecoveryState(updatedState);
      if (!updatedState.isRecoveryRequired) {
        setStatusDetail('Terhubung aman via E2EE');
      }
    } finally {
      setIsRetryingRecovery(false);
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  // ---------------------------------------------------------------------------
  // RENDER: ERROR / LOADING AWAL
  // ---------------------------------------------------------------------------

  // Gate: Tampilkan onboarding jika perangkat belum terdaftar / perlu registrasi
  if (onboardingGate === 'PENDING' || onboardingGate === 'ONBOARDING') {
    return (
      <BchatDeviceOnboarding
        currentUser={user ? { id: user.id, email: user.email, role: user.role } : null}
        onDeviceReady={(devUuid) => {
          setDeviceUuid(devUuid);
          setOnboardingGate('PASSED');
          initializeChatSession();
        }}
        onCancel={onBack}
        workerRpc={workerClientRef.current}
      />
    );
  }

  // Gate: Tampilkan pairing jika perangkat aktif namun belum tepercaya (Tahap 2A)
  if (onboardingGate === 'PAIRING' && deviceUuid) {
    return (
      <BchatDevicePairingGate
        deviceUuid={deviceUuid}
        onPairingApproved={() => {
          setOnboardingGate('PASSED');
          initializeChatSession();
        }}
        onCancel={onBack}
      />
    );
  }

  // Layar Pemilih Mahasiswa Bimbingan (Multi-Advisee Selector)
  if (isSelectingAdvisee && !roomSummary) {
    return (
      <div className="p-6 sm:p-8 bg-white rounded-3xl border border-slate-200/80 shadow-sm max-w-2xl mx-auto my-8 font-sans space-y-6">
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 rounded-2xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600 shadow-2xs">
              <User className="w-6 h-6 text-emerald-600" />
            </div>
            <div>
              <h2 className="font-bold text-base text-slate-900">Pilih Mahasiswa Bimbingan</h2>
              <p className="text-xs text-slate-500 mt-0.5">
                Anda membimbing beberapa mahasiswa proposal. Pilih ruang bimbingan yang ingin dibuka:
              </p>
            </div>
          </div>
          {onBack && (
            <button
              onClick={onBack}
              className="px-3 py-1.5 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl text-xs font-semibold transition cursor-pointer"
            >
              Kembali
            </button>
          )}
        </div>

        <div className="divide-y divide-slate-100 border border-slate-100 rounded-2xl overflow-hidden">
          {availableProposalRooms.map((room) => {
            const studentName = room.partner?.name || 'Mahasiswa Bimbingan';
            const judul = room.assignment?.judul_skripsi || 'Bimbingan Proposal Skripsi';
            const isActive = room.status === 'ACTIVE';

            return (
              <button
                key={room.room_uuid}
                onClick={() => handleSelectAdvisee(room.room_uuid)}
                className="w-full text-left p-4 hover:bg-emerald-50/50 transition flex items-center justify-between gap-4 group cursor-pointer"
              >
                <div className="min-w-0 flex items-center gap-3.5">
                  <div className="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 text-white font-bold text-xs flex items-center justify-center shrink-0 shadow-2xs">
                    {studentName.charAt(0).toUpperCase()}
                  </div>
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <p className="font-bold text-xs text-slate-900 group-hover:text-emerald-700 transition truncate">
                        {studentName}
                      </p>
                      <span
                        className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold ${
                          isActive
                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                            : 'bg-amber-50 text-amber-700 border border-amber-200'
                        }`}
                      >
                        {isActive ? 'Aktif' : 'Menunggu Jabat Tangan'}
                      </span>
                    </div>
                    <p className="text-[11px] text-slate-500 truncate mt-0.5" title={judul}>
                      {judul}
                    </p>
                  </div>
                </div>

                <div className="shrink-0 flex items-center gap-1.5 text-xs font-bold text-emerald-600 group-hover:translate-x-1 transition">
                  <span>Buka Chat</span>
                  <ArrowRight className="w-3.5 h-3.5" />
                </div>
              </button>
            );
          })}
        </div>
      </div>
    );
  }

  // Layar Tunggu (Mahasiswa menunggu konfirmasi dosen, Dosen menunggu inisiasi mahasiswa, dsb.)
  if (waitingState && messages.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[500px] p-8 text-center bg-white rounded-3xl border border-slate-100 shadow-sm space-y-4 max-w-lg mx-auto my-12 font-sans">
        <div className="relative">
          <div className="w-16 h-16 rounded-3xl bg-amber-50 border border-amber-100 flex items-center justify-center text-amber-600 shadow-sm">
            <Clock className="w-8 h-8 text-amber-600 animate-pulse" />
          </div>
          <div className="absolute -bottom-1 -right-1 p-1 bg-white rounded-full shadow">
            <Loader2 className="w-4 h-4 animate-spin text-amber-600" />
          </div>
        </div>
        <div>
          <h3 className="font-bold text-slate-800 text-base">{waitingState.title}</h3>
          <p className="text-xs text-slate-500 mt-2 leading-relaxed max-w-md mx-auto">{waitingState.message}</p>
        </div>
        <div className="inline-flex items-center gap-1.5 px-3 py-1 bg-slate-50 border border-slate-200/80 rounded-full text-[11px] text-slate-500">
          <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
          <span>Memeriksa status secara otomatis setiap 5 detik...</span>
        </div>
        <div className="pt-2 flex justify-center gap-3">
          {onBack && (
            <button
              onClick={onBack}
              className="px-4 py-2 border border-slate-200 text-slate-700 text-xs font-bold rounded-xl hover:bg-slate-50 transition"
            >
              Kembali
            </button>
          )}
          <button
            onClick={initializeChatSession}
            className="px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition flex items-center gap-1.5 shadow-sm"
          >
            <RefreshCw className="w-3.5 h-3.5" /> Periksa Status Sekarang
          </button>
        </div>
      </div>
    );
  }

  // Layar Room ACTIVE namun GroupState NOT_FOUND (Fail-Closed, dilarang handshake ulang)
  if (isUnrecoverableActiveRoom && messages.length === 0) {
    return (
      <div className="p-8 text-center bg-white rounded-3xl border border-amber-200 shadow-sm space-y-4 max-w-lg mx-auto my-12 font-sans">
        <div className="w-14 h-14 mx-auto rounded-3xl bg-amber-50 border border-amber-200 flex items-center justify-center text-amber-600">
          <AlertTriangle className="w-7 h-7" />
        </div>
        <div>
          <h3 className="font-bold text-slate-800 text-base">Sesi Kriptografi Tidak Ditemukan</h3>
          <p className="text-xs text-slate-600 mt-2 leading-relaxed">
            {initError || 'Ruang obrolan ini sudah aktif pada perangkat lain, namun kunci sesi lokal (GroupState) tidak ditemukan pada browser ini.'}
          </p>
          <div className="mt-3 p-3 bg-slate-50 border border-slate-200 rounded-xl text-left text-[11px] text-slate-600 space-y-1">
            <p className="font-semibold text-slate-700 flex items-center gap-1">
              <ShieldAlert className="w-3.5 h-3.5 text-amber-600" /> Perlindungan Forward Secrecy MLS:
            </p>
            <p>
              Untuk mencegah penyadapan, protokol enkripsi E2EE tidak mengizinkan pembuatan ulang grup secara sepihak saat percakapan telah aktif. Anda dapat memindahkan riwayat obrolan aman dari perangkat tepercaya Anda yang lain.
            </p>
          </div>
        </div>
        <div className="pt-2 flex flex-col sm:flex-row justify-center gap-3">
          {roomSummary && deviceUuid && user && (
            <button
              type="button"
              onClick={() => setIsHistoryTransferModalOpen(true)}
              className="px-5 py-2.5 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition shadow-sm flex items-center justify-center gap-2"
            >
              <History className="w-3.5 h-3.5" />
              Sinkronkan Riwayat Chat
            </button>
          )}
          {onBack && (
            <button
              onClick={onBack}
              className="px-5 py-2.5 bg-slate-100 text-slate-700 text-xs font-bold rounded-xl hover:bg-slate-200 transition shadow-sm"
            >
              Kembali ke Menu Bimbingan
            </button>
          )}
        </div>

        {/* Modal Transfer Riwayat saat di layar Sesi Kriptografi Tidak Ditemukan */}
        {roomSummary && deviceUuid && user && (
          <BchatHistoryTransferModal
            isOpen={isHistoryTransferModalOpen || Boolean(incomingTransferRequest)}
            onClose={() => {
              setIsHistoryTransferModalOpen(false);
              setIncomingTransferRequest(null);
            }}
            roomUuid={roomSummary.room_uuid}
            currentDeviceUuid={deviceUuid}
            userId={Number(user.id)}
            incomingTransfer={incomingTransferRequest}
            onIncomingHandled={() => setIncomingTransferRequest(null)}
            onTransferSuccess={async () => {
              try {
                const freshHistory = await loadEncryptedHistory(deviceUuid, roomSummary.room_uuid);
                setMessages(freshHistory);
                setIsUnrecoverableActiveRoom(false);
                setConnectionStatus('CONNECTED');
                setTimeout(() => scrollToBottom(true), 100);
                initializeChatSession();
              } catch (_e) {
                // Abaikan jika pemuatan riwayat pasca transfer tertunda
              }
            }}
          />
        )}
      </div>
    );
  }

  if (connectionStatus === 'INITIALIZING' && messages.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[500px] p-8 text-center bg-white rounded-3xl border border-slate-100 shadow-sm space-y-4 font-sans">
        <div className="relative">
          <div className="w-16 h-16 rounded-3xl bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600 shadow-sm">
            <Lock className="w-8 h-8 animate-pulse text-emerald-600" />
          </div>
          <div className="absolute -bottom-1 -right-1 p-1 bg-white rounded-full shadow">
            <Loader2 className="w-4 h-4 animate-spin text-emerald-600" />
          </div>
        </div>
        <div>
          <h3 className="font-bold text-slate-800 text-base">Menyiapkan Ruang Bimbingan E2EE</h3>
          <p className="text-xs text-slate-500 mt-1 max-w-sm">{statusDetail}</p>
        </div>
      </div>
    );
  }

  if (initError && messages.length === 0) {
    return (
      <div className="p-8 text-center bg-white rounded-3xl border border-rose-100 shadow-sm space-y-4 max-w-lg mx-auto my-12 font-sans">
        <div className="w-14 h-14 mx-auto rounded-3xl bg-rose-50 border border-rose-100 flex items-center justify-center text-rose-600">
          <AlertCircle className="w-7 h-7" />
        </div>
        <div>
          <h3 className="font-bold text-slate-800 text-base">Tidak Dapat Membuka Ruang Chat</h3>
          <p className="text-xs text-slate-600 mt-2 leading-relaxed">{initError}</p>
        </div>
        <div className="pt-2 flex justify-center gap-3">
          {onBack && (
            <button
              onClick={onBack}
              className="px-4 py-2 border border-slate-200 text-slate-700 text-xs font-bold rounded-xl hover:bg-slate-50 transition"
            >
              Kembali
            </button>
          )}
          <button
            onClick={initializeChatSession}
            className="px-4 py-2 bg-emerald-600 text-white text-xs font-bold rounded-xl hover:bg-emerald-700 transition flex items-center gap-1.5 shadow-sm"
          >
            <RefreshCw className="w-3.5 h-3.5" /> Coba Lagi
          </button>
        </div>
      </div>
    );
  }

  // Identitas Partner
  const partnerName = roomDetail?.partner?.name || roomSummary?.partner?.name || 'Dosen Pembimbing Proposal';
  const partnerRole = 'Dosen Pembimbing Proposal';
  const judulSkripsi = roomSummary?.assignment?.judul_skripsi || 'Bimbingan Proposal Skripsi';

  return (
    <div className="flex flex-col h-[calc(90vh-4rem)] max-h-[850px] bg-slate-50/50 rounded-3xl border border-slate-200/80 shadow-sm overflow-hidden font-sans">
      {/* --------------------------------------------------------------------- */}
      {/* HEADER IDENTITAS & STATUS KONEKSI */}
      {/* --------------------------------------------------------------------- */}
      <div className="bg-white px-5 py-3.5 border-b border-slate-100 flex items-center justify-between shrink-0 shadow-xs">
        <div className="flex items-center gap-3 min-w-0">
          {/* Avatar Dosen */}
          <div className="relative shrink-0">
            <div className="w-11 h-11 rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-500 text-white font-bold text-sm flex items-center justify-center shadow-sm">
              {partnerName.charAt(0).toUpperCase()}
            </div>
            {/* Status Indicator Dot */}
            <span
              className={`absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full border-2 border-white ${
                connectionStatus === 'CONNECTED'
                  ? 'bg-emerald-500'
                  : connectionStatus === 'RECONNECTING'
                  ? 'bg-amber-500 animate-pulse'
                  : 'bg-rose-500'
              }`}
            />
          </div>

          {/* Nama & Context */}
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <h2 className="font-bold text-sm text-slate-900 truncate leading-tight">
                {partnerName}
              </h2>
              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/60 shrink-0">
                <ShieldCheck className="w-3 h-3 text-emerald-600" /> E2EE MLS
              </span>
            </div>
            <p className="text-[11px] text-slate-500 truncate mt-0.5" title={judulSkripsi}>
              {partnerRole} • <span className="text-slate-400">{judulSkripsi}</span>
            </p>
          </div>
        </div>

        {/* Status Pill & Aksi Header */}
        <div className="flex items-center gap-2 shrink-0">
          {/* Tombol Sinkronisasi Riwayat Chat (Tahap 2B) */}
          <button
            onClick={() => setIsHistoryTransferModalOpen(true)}
            className="inline-flex items-center gap-1.5 px-3 py-1 bg-teal-50 hover:bg-teal-100 text-teal-700 text-[11px] font-bold rounded-full transition cursor-pointer border border-teal-200 shadow-2xs"
            title="Sinkronkan Riwayat Chat dari Perangkat Tepercaya Lain"
          >
            <History className="w-3.5 h-3.5 text-teal-600" />
            <span className="hidden sm:inline">Sinkronkan Riwayat</span>
          </button>

          {/* Tombol Manajemen Perangkat */}
          <button
            onClick={() => setIsDeviceModalOpen(true)}
            className="inline-flex items-center gap-1.5 px-3 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold rounded-full transition cursor-pointer"
            title="Kelola Perangkat B-CHAT"
          >
            <Smartphone className="w-3.5 h-3.5 text-slate-500" />
            <span className="hidden sm:inline">Perangkat</span>
          </button>

          {/* Tombol Ganti Mahasiswa (jika dosen membimbing > 1 mahasiswa) */}
          {availableProposalRooms.length > 1 && (
            <button
              onClick={handleSwitchAdvisee}
              className="inline-flex items-center gap-1.5 px-3 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold rounded-full transition cursor-pointer"
              title="Pilih Mahasiswa Bimbingan Lain"
            >
              <User className="w-3.5 h-3.5 text-slate-500" />
              <span className="hidden sm:inline">Ganti Mahasiswa</span>
            </button>
          )}

          {/* Connection Status Pill */}
          <div
            className={`flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold border transition ${
              connectionStatus === 'CONNECTED'
                ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                : connectionStatus === 'RECONNECTING'
                ? 'bg-amber-50 text-amber-700 border-amber-200 animate-pulse'
                : 'bg-rose-50 text-rose-700 border-rose-200'
            }`}
            title={statusDetail}
          >
            <span
              className={`w-2 h-2 rounded-full ${
                connectionStatus === 'CONNECTED'
                  ? 'bg-emerald-500'
                  : connectionStatus === 'RECONNECTING'
                  ? 'bg-amber-500'
                  : 'bg-rose-500'
              }`}
            />
            <span className="inline">
              {connectionStatus === 'CONNECTED'
                ? 'Terhubung'
                : connectionStatus === 'RECONNECTING'
                ? 'Menghubungkan...'
                : 'Terputus'}
            </span>
          </div>

          {onBack && (
            <button
              onClick={onBack}
              className="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-xl transition text-xs font-semibold"
              title="Kembali ke Riwayat"
            >
              Tutup
            </button>
          )}
        </div>
      </div>

      {/* --------------------------------------------------------------------- */}
      {/* DAFTAR PESAN (MESSAGE CONTAINER) */}
      {/* --------------------------------------------------------------------- */}
      <div
        ref={scrollContainerRef}
        onScroll={handleScroll}
        className="flex-1 p-4 sm:p-6 overflow-y-auto space-y-3 relative bg-slate-50/60"
      >
        {/* Banner Kriptografi E2EE */}
        <div className="text-center py-2">
          <div className="inline-flex items-center gap-1.5 px-3 py-1 bg-white/90 border border-slate-200/80 rounded-full text-[11px] text-slate-500 shadow-2xs">
            <Lock className="w-3 h-3 text-emerald-600" />
            <span>Pesan dalam obrolan ini terenkripsi end-to-end (RFC 9420 MLS). Hanya Anda dan Dosen Pembimbing yang dapat membacanya.</span>
          </div>
        </div>

        {/* Banner Status Recovery Sequence (Eksplisit & Actionable — Tidak Terkunci Diam-Diam) */}
        {recoveryState?.isRecoveryRequired && recoveryState.unresolvedSequences.length > 0 && (
          <div
            data-bchat-recovery-banner="true"
            className="mx-auto max-w-2xl rounded-2xl border border-amber-300 bg-amber-50/95 px-4 py-3 text-xs text-amber-900 shadow-2xs"
          >
            <div className="flex items-start gap-2.5">
              <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
              <div className="flex-1 space-y-1.5">
                <p className="font-semibold text-amber-900">
                  Pemulihan Diperlukan pada Pesan Urutan{' '}
                  {recoveryState.unresolvedSequences
                    .map((u) => `#${u.server_sequence}`)
                    .join(', ')}
                </p>
                <p className="text-[11px] text-amber-800 leading-relaxed">
                  Kunci ratchet lokal atau verifikasi kriptografis untuk pesan tersebut tidak tersedia di perangkat ini (Forward Secrecy RFC 9420). Pesan baru tetap diterima secara real-time tanpa melompati urutan yang belum pulih.
                </p>
                <div className="flex flex-wrap items-center gap-2 pt-1">
                  <button
                    type="button"
                    onClick={handleRetryRecovery}
                    disabled={isRetryingRecovery}
                    className="inline-flex items-center gap-1.5 px-3 py-1 bg-white hover:bg-amber-100/60 border border-amber-300 text-amber-900 text-[11px] font-semibold rounded-lg transition disabled:opacity-50 cursor-pointer"
                  >
                    {isRetryingRecovery ? (
                      <Loader2 className="w-3 h-3 animate-spin" />
                    ) : (
                      <RefreshCw className="w-3 h-3" />
                    )}
                    <span>Coba Rekonsiliasi Ulang</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setIsHistoryTransferModalOpen(true)}
                    className="inline-flex items-center gap-1.5 px-3 py-1 bg-amber-600 hover:bg-amber-700 text-white text-[11px] font-semibold rounded-lg transition cursor-pointer"
                  >
                    <History className="w-3 h-3" />
                    <span>Sinkronkan Riwayat dari Perangkat Lain</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* State Kosong */}
        {messages.length === 0 && (
          <div className="flex flex-col items-center justify-center h-48 text-center text-slate-400 space-y-2">
            <div className="w-12 h-12 rounded-2xl bg-white border border-slate-200/70 flex items-center justify-center text-slate-400 shadow-2xs">
              <User className="w-6 h-6" />
            </div>
            <p className="text-xs font-medium">Belum ada pesan dalam ruang konsultasi ini.</p>
            <p className="text-[11px] text-slate-400 max-w-xs">
              Mulai komunikasi mengenai revisi proposal, rumusan masalah, atau jadwal bimbingan.
            </p>
            <button
              onClick={() => setIsHistoryTransferModalOpen(true)}
              className="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-slate-50 border border-slate-200 text-teal-700 hover:text-teal-800 text-xs font-semibold rounded-xl shadow-2xs transition cursor-pointer"
            >
              <History className="w-3.5 h-3.5 text-teal-600" />
              <span>Sinkronkan Riwayat dari Perangkat Lain</span>
            </button>
          </div>
        )}

        {/* Daftar Bubble Pesan */}
        {messages.map((msg) => {
          const isSelf = msg.is_self;
          const timeStr = formatMessageTime(msg.created_at || msg.client_sent_at);
          const hasError = !!msg.error || isHistoryTombstonePlaintext(msg.plaintext);

          // Klasifikasi status pesan kriptografis (termasuk tombstone yang dimuat ulang dari IndexedDB)
          const isIntegrityError =
            msg.plaintext === '[Integritas Gagal]' ||
            msg.plaintext === '[Format Corrupt]' ||
            (!!msg.error && /integritas|sha-256|hash|base64|tamper/i.test(msg.error));

          const isPastRatchetUnavailable =
            !isIntegrityError &&
            (msg.plaintext === '[Dekripsi Gagal]' ||
              (!!msg.error &&
                /past|generation|ratchet|expired|kunci|dekripsi mls gagal/i.test(msg.error)));

          // Kasus 1: Peringatan Integritas Kriptografis (Fail-Closed)
          if (isIntegrityError) {
            return (
              <div
                key={`${msg.server_sequence}_${msg.message_uuid}`}
                data-bchat-message-bubble="true"
                data-bchat-sequence={msg.server_sequence}
                data-bchat-status="integrity-error"
                className={`flex flex-col ${isSelf ? 'items-end' : 'items-start'}`}
              >
                <div className="max-w-[85%] sm:max-w-[70%] px-4 py-2.5 rounded-2xl text-xs leading-relaxed transition-all shadow-xs bg-rose-50 border border-rose-300 text-rose-800 rounded-bl-xs">
                  <div className="flex items-center gap-1.5 text-rose-700 font-semibold mb-1 text-[11px]">
                    <AlertCircle className="w-3.5 h-3.5 shrink-0" />
                    <span>Peringatan Integritas Kriptografis (Fail-Closed)</span>
                  </div>
                  <p data-bchat-message-text="true" className="whitespace-pre-wrap break-words text-rose-700">
                    Payload pesan #{msg.server_sequence} tidak valid atau verifikasi SHA-256 gagal. Pesan diblokir demi keamanan.
                  </p>
                  {msg.error && (
                    <p className="text-[10px] text-rose-500 font-mono mt-1 break-all">
                      Detail: {msg.error}
                    </p>
                  )}
                  <div className="flex items-center justify-between mt-1 pt-1 border-t border-rose-200/60 text-[10px] text-rose-400">
                    <span>Sequence #{msg.server_sequence}</span>
                    <span>{timeStr}</span>
                  </div>
                </div>
              </div>
            );
          }

          // Kasus 2: Pesan Sesi Lampau (Kunci Ratchet Forward Secrecy Tidak Tersedia)
          if (isPastRatchetUnavailable) {
            return (
              <div
                key={`${msg.server_sequence}_${msg.message_uuid}`}
                data-bchat-message-bubble="true"
                data-bchat-sequence={msg.server_sequence}
                data-bchat-status="unrecoverable-ratchet"
                className={`flex flex-col ${isSelf ? 'items-end' : 'items-start'}`}
              >
                <div className="max-w-[85%] sm:max-w-[70%] px-4 py-2.5 rounded-2xl text-xs leading-relaxed transition-all shadow-xs bg-slate-50 border border-dashed border-amber-300 text-slate-700 rounded-bl-xs">
                  <div className="flex items-center gap-1.5 text-amber-800 font-semibold mb-1 text-[11px]">
                    <Lock className="w-3.5 h-3.5 text-amber-600 shrink-0" />
                    <span>Pesan Terenkripsi Sesi Sebelumnya</span>
                  </div>
                  <p data-bchat-message-text="true" className="whitespace-pre-wrap break-words text-slate-600 italic">
                    Kunci ratchet lokal tidak tersedia pada perangkat ini (Forward Secrecy RFC 9420). Pesan lampau ini hanya dapat dibaca pada perangkat yang memprosesnya saat transmisi asli.
                  </p>
                  <div className="flex items-center justify-between mt-1 pt-1 border-t border-slate-200 text-[10px] text-slate-400">
                    <span>Sequence #{msg.server_sequence} • Epoch {msg.epoch}</span>
                    <span>{timeStr}</span>
                  </div>
                </div>
              </div>
            );
          }

          // Kasus 3: Pesan Terdekripsi (Teks atau Lampiran Manifest)
          const isManifestCandidate =
            msg.content_type === 2 ||
            (typeof msg.plaintext === 'string' &&
              msg.plaintext.trim().startsWith('{') &&
              msg.plaintext.includes('"ATTACHMENT_MANIFEST"'));
          const manifest = isManifestCandidate ? parseAttachmentManifest(msg.plaintext) : null;
          const isCorruptManifest = isManifestCandidate && !manifest && !hasError;

          return (
            <div
              key={`${msg.server_sequence}_${msg.message_uuid}`}
              data-bchat-message-bubble="true"
              data-bchat-sequence={msg.server_sequence}
              data-bchat-status={hasError || isCorruptManifest ? 'error' : manifest ? 'attachment' : 'normal'}
              className={`flex flex-col ${isSelf ? 'items-end' : 'items-start'}`}
            >
              <div
                className={`max-w-[85%] sm:max-w-[70%] px-4 py-2.5 rounded-2xl text-xs leading-relaxed transition-all shadow-xs ${
                  isSelf
                    ? 'bg-emerald-600 text-white rounded-br-xs'
                    : 'bg-white text-slate-800 border border-slate-200/80 rounded-bl-xs'
                } ${hasError || isCorruptManifest ? 'border-rose-400 bg-rose-50 text-rose-800' : ''}`}
              >
                {/* Sender Tag untuk Pengirim Peer */}
                {!isSelf && (
                  <p className="text-[10px] font-bold text-emerald-700 mb-1">
                    {partnerName}
                  </p>
                )}

                {/* Kasus 3A: Manifest Rusak */}
                {isCorruptManifest && (
                  <div className="space-y-1">
                    <p className="text-rose-700 font-semibold flex items-center gap-1 text-[11px]">
                      <AlertCircle className="w-3.5 h-3.5" />
                      <span>Manifest Lampiran Rusak</span>
                    </p>
                    <p className="text-[11px] text-rose-600">
                      Struktur manifest lampiran terenkripsi tidak valid atau tidak sesuai spesifikasi kanonikal.
                    </p>
                  </div>
                )}

                {/* Kasus 3B: Manifest Lampiran Valid */}
                {manifest && (
                  <div className="space-y-2">
                    {/* Teks Pesan / Keterangan Pendamping Jika Ada */}
                    {manifest.text && (
                      <p data-bchat-message-text="true" className="whitespace-pre-wrap break-words">
                        {manifest.text}
                      </p>
                    )}

                    {/* Kartu Daftar Lampiran Terenkripsi */}
                    <div className="space-y-1.5 pt-1">
                      {manifest.attachments.map((att) => {
                        const key = `${msg.message_uuid}_${att.attachmentUuid}`;
                        const dl = downloadStateMap[key] || { status: 'IDLE' };

                        return (
                          <div
                            key={att.attachmentUuid}
                            data-bchat-attachment-item={att.attachmentUuid}
                            className={`p-2.5 rounded-xl border flex items-center justify-between gap-3 transition ${
                              isSelf
                                ? 'bg-emerald-700/60 border-emerald-500/50 text-white'
                                : 'bg-slate-50 border-slate-200/90 text-slate-800'
                            }`}
                          >
                            <div className="min-w-0 flex items-center gap-2.5">
                              <div
                                className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
                                  isSelf
                                    ? 'bg-emerald-800/80 text-emerald-200'
                                    : 'bg-white border border-slate-200 text-emerald-600'
                                }`}
                              >
                                <FileText className="w-4 h-4" />
                              </div>
                              <div className="min-w-0">
                                <p
                                  className="font-semibold text-xs truncate max-w-[180px] sm:max-w-[260px]"
                                  title={att.fileName}
                                >
                                  {att.fileName}
                                </p>
                                <p
                                  className={`text-[10px] mt-0.5 ${
                                    isSelf ? 'text-emerald-200' : 'text-slate-400'
                                  }`}
                                >
                                  {formatAttachmentSize(att.fileSizeBytes)} • {att.totalChunks} chunk
                                </p>
                              </div>
                            </div>

                            <div className="shrink-0 flex items-center gap-1.5">
                              {dl.status === 'IDLE' && (
                                <button
                                  type="button"
                                  onClick={() => handleDownloadAttachment(msg, att)}
                                  className={`px-2.5 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 transition cursor-pointer shadow-xs ${
                                    isSelf
                                      ? 'bg-white/20 hover:bg-white/30 text-white'
                                      : 'bg-emerald-600 hover:bg-emerald-700 text-white'
                                  }`}
                                  title="Unduh & Verifikasi Integritas E2EE"
                                >
                                  <Download className="w-3.5 h-3.5" />
                                  <span className="hidden sm:inline">Unduh</span>
                                </button>
                              )}

                              {dl.status === 'DOWNLOADING' && (
                                <div className="flex items-center gap-1.5 text-xs font-medium px-2 py-1">
                                  <Loader2 className="w-3.5 h-3.5 animate-spin text-emerald-300" />
                                  <span className="text-[11px]">{dl.percent ?? 0}%</span>
                                </div>
                              )}

                              {dl.status === 'VERIFYING' && (
                                <div className="flex items-center gap-1.5 text-xs font-medium px-2 py-1">
                                  <Loader2 className="w-3.5 h-3.5 animate-spin text-amber-300" />
                                  <span className="text-[11px]">Verifikasi...</span>
                                </div>
                              )}

                              {dl.status === 'READY' && (
                                <button
                                  type="button"
                                  onClick={() => handleDownloadAttachment(msg, att)}
                                  className="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-emerald-100 hover:bg-emerald-200 text-emerald-800 flex items-center gap-1.5 transition cursor-pointer shadow-xs"
                                  title="Buka / Simpan Kembali Berkas"
                                >
                                  <FileCheck className="w-3.5 h-3.5 text-emerald-700" />
                                  <span className="hidden sm:inline">Buka</span>
                                </button>
                              )}

                              {dl.status === 'ERROR' && (
                                <button
                                  type="button"
                                  onClick={() => handleDownloadAttachment(msg, att)}
                                  className="px-2 py-1 rounded-lg text-[11px] font-semibold bg-rose-100 hover:bg-rose-200 text-rose-800 flex items-center gap-1 transition cursor-pointer"
                                  title={dl.error || 'Gagal verifikasi. Klik untuk coba lagi.'}
                                >
                                  <FileX className="w-3.5 h-3.5 text-rose-600" />
                                  <span>Ulangi</span>
                                </button>
                              )}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Kasus 3C: Pesan Teks Normal */}
                {!isManifestCandidate && (
                  <p data-bchat-message-text="true" className="whitespace-pre-wrap break-words">
                    {msg.plaintext}
                    {hasError ? ` [Detail: ${msg.error}]` : ''}
                  </p>
                )}

                {/* Metadata Bawah (Waktu & Indikator Transmisi) */}
                <div
                  className={`flex items-center justify-end gap-1 mt-1 text-[10px] ${
                    isSelf ? 'text-emerald-100/90' : 'text-slate-400'
                  }`}
                >
                  <span>{timeStr}</span>
                  {isSelf && (
                    <span title="Pesan terkirim via MLS">
                      <CheckCheck className="w-3.5 h-3.5 text-emerald-200" />
                    </span>
                  )}
                  {hasError && (
                    <span title={msg.error} className="text-rose-600 font-bold">
                      <AlertCircle className="w-3 h-3 inline" />
                    </span>
                  )}
                </div>
              </div>
            </div>
          );
        })}

        <div ref={messagesEndRef} />

        {/* Floating Scroll to Bottom Button */}
        {showScrollBottom && (
          <button
            onClick={() => scrollToBottom(true)}
            className="fixed bottom-24 right-8 p-2.5 bg-white text-slate-700 border border-slate-200 rounded-full shadow-lg hover:bg-slate-50 transition z-20 flex items-center justify-center"
            title="Scroll ke pesan terbaru"
          >
            <ArrowDown className="w-4 h-4" />
          </button>
        )}
      </div>

      {/* --------------------------------------------------------------------- */}
      {/* AREA INPUT PESAN */}
      {/* --------------------------------------------------------------------- */}
      <div className="bg-white p-3 sm:p-4 border-t border-slate-100 shrink-0">
        {sendError && (
          <div className="mb-2 p-2 px-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs flex items-center justify-between">
            <span className="truncate">{sendError}</span>
            <button
              type="button"
              onClick={() => setSendError(null)}
              className="text-rose-500 hover:text-rose-700 font-bold ml-2 text-xs cursor-pointer"
            >
              ✕
            </button>
          </div>
        )}

        {attachmentError && (
          <div className="mb-2 p-2 px-3 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-xs flex items-center justify-between">
            <span className="truncate">{attachmentError}</span>
            <button
              type="button"
              onClick={() => setAttachmentError(null)}
              className="text-rose-500 hover:text-rose-700 font-bold ml-2 text-xs cursor-pointer"
            >
              ✕
            </button>
          </div>
        )}

        {/* Progress Bar Unggah Ciphertext Chunk */}
        {uploadProgress && (
          <div className="mb-2.5 p-2.5 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs space-y-1.5 text-emerald-800">
            <div className="flex items-center justify-between font-semibold">
              <span className="flex items-center gap-1.5">
                <Loader2 className="w-3.5 h-3.5 animate-spin text-emerald-600" />
                {uploadProgress.status}
              </span>
              <span>{uploadProgress.percent}%</span>
            </div>
            <div className="w-full bg-emerald-200/60 rounded-full h-1.5 overflow-hidden">
              <div
                className="bg-emerald-600 h-1.5 transition-all duration-200"
                style={{ width: `${uploadProgress.percent}%` }}
              />
            </div>
          </div>
        )}

        {/* Preview Berkas Lampiran Sebelum Dikirim */}
        {pendingFiles.length > 0 && (
          <div className="mb-2.5 p-2.5 bg-slate-50 border border-slate-200/90 rounded-2xl space-y-2">
            <div className="flex items-center justify-between text-xs text-slate-600 px-1">
              <span className="font-semibold text-slate-700">
                Lampiran ({pendingFiles.length}/{ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT})
              </span>
              <span className="text-[11px] text-slate-500 font-medium">
                Total: {formatAttachmentSize(pendingFiles.reduce((acc, f) => acc + f.size, 0))} / 25 MB
              </span>
            </div>
            <div className="flex flex-wrap gap-2">
              {pendingFiles.map((file, idx) => (
                <div
                  key={`${file.name}_${idx}`}
                  className="inline-flex items-center gap-2 px-2.5 py-1.5 bg-white border border-slate-200/90 rounded-xl text-xs shadow-2xs group"
                >
                  <FileText className="w-3.5 h-3.5 text-emerald-600 shrink-0" />
                  <span className="truncate max-w-[130px] sm:max-w-[180px] font-medium text-slate-800" title={file.name}>
                    {file.name}
                  </span>
                  <span className="text-[10px] text-slate-400">({formatAttachmentSize(file.size)})</span>
                  <button
                    type="button"
                    disabled={isSending}
                    onClick={() => handleRemovePendingFile(idx)}
                    className="text-slate-400 hover:text-rose-600 transition ml-0.5 cursor-pointer disabled:opacity-40"
                    title="Hapus berkas"
                  >
                    <X className="w-3.5 h-3.5" />
                  </button>
                </div>
              ))}
            </div>
          </div>
        )}

        <form onSubmit={handleSendMessage} className="flex items-end gap-2">
          {/* Hidden Native File Input */}
          <input
            type="file"
            multiple
            ref={fileInputRef}
            onChange={handleFileSelect}
            className="hidden"
          />

          {/* Tombol Lampiran Berkas (Paperclip) */}
          <button
            type="button"
            onClick={() => fileInputRef.current?.click()}
            disabled={
              isSending ||
              connectionStatus !== 'CONNECTED' ||
              roomDetail?.status !== 'ACTIVE' ||
              pendingFiles.length >= ATTACHMENT_CANONICAL.MAX_ATTACHMENT_COUNT
            }
            className="p-3 text-slate-500 hover:text-emerald-600 hover:bg-slate-100 rounded-2xl transition disabled:opacity-40 disabled:cursor-not-allowed shrink-0 flex items-center justify-center cursor-pointer"
            title="Pilih Lampiran Berkas (Maks. 5 berkas, 10 MB per berkas, total 25 MB)"
          >
            <Paperclip className="w-4 h-4" />
          </button>

          <div className="flex-1 relative">
            <textarea
              value={inputText}
              onChange={(e) => setInputText(e.target.value)}
              onKeyDown={handleKeyDown}
              disabled={isSending || connectionStatus !== 'CONNECTED' || roomDetail?.status !== 'ACTIVE'}
              placeholder={
                connectionStatus === 'OFFLINE'
                  ? 'Koneksi terputus. Menunggu jaringan...'
                  : roomDetail?.status !== 'ACTIVE'
                  ? 'Ruang obrolan belum aktif...'
                  : pendingFiles.length > 0
                  ? 'Tambahkan pesan pendamping lampiran (opsional)...'
                  : 'Ketik pesan konsultasi proposal... (Enter untuk kirim, Shift+Enter untuk baris baru)'
              }
              rows={1}
              className="w-full px-4 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-2xl resize-none focus:outline-hidden focus:ring-2 focus:ring-emerald-500/30 focus:border-emerald-600 transition disabled:opacity-60 disabled:cursor-not-allowed max-h-24 overflow-y-auto"
            />
          </div>

          <button
            type="submit"
            disabled={
              (!inputText.trim() && pendingFiles.length === 0) ||
              isSending ||
              connectionStatus !== 'CONNECTED' ||
              roomDetail?.status !== 'ACTIVE'
            }
            className="p-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-2xl shadow-sm transition disabled:opacity-40 disabled:cursor-not-allowed shrink-0 flex items-center justify-center cursor-pointer"
            title={pendingFiles.length > 0 ? 'Kirim Lampiran E2EE' : 'Kirim Pesan E2EE'}
          >
            {isSending ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <Send className="w-4 h-4" />
            )}
          </button>
        </form>
      </div>

      {/* MODAL MANAJEMEN PERANGKAT (TAHAP 1B) */}
      <BchatDeviceManagementModal
        isOpen={isDeviceModalOpen}
        onClose={() => setIsDeviceModalOpen(false)}
        currentDeviceUuid={deviceUuid}
        onRequestHistoryTransfer={() => {
          setIsDeviceModalOpen(false);
          setIsHistoryTransferModalOpen(true);
        }}
      />

      {/* MODAL TRANSFER RIWAYAT CHAT E2EE (TAHAP 2B) */}
      {roomSummary && deviceUuid && user && (
        <BchatHistoryTransferModal
          isOpen={isHistoryTransferModalOpen || Boolean(incomingTransferRequest)}
          onClose={() => {
            setIsHistoryTransferModalOpen(false);
            setIncomingTransferRequest(null);
          }}
          roomUuid={roomSummary.room_uuid}
          currentDeviceUuid={deviceUuid}
          userId={Number(user.id)}
          incomingTransfer={incomingTransferRequest}
          onIncomingHandled={() => setIncomingTransferRequest(null)}
          onTransferSuccess={async () => {
            // Muat ulang riwayat dari IndexedDB lokal, sinkronkan cursor kontigu subscriber, dan perbarui tampilan chat
            try {
              const freshHistory = await loadEncryptedHistory(deviceUuid, roomSummary.room_uuid);
              setMessages(freshHistory);
              if (subscriberRef.current) {
                await subscriberRef.current.syncFromLocalHistory(freshHistory);
                setRecoveryState(subscriberRef.current.getRecoveryState());
              }
              setIsUnrecoverableActiveRoom(false);
              setConnectionStatus('CONNECTED');
              setTimeout(() => scrollToBottom(true), 100);
              initializeChatSession();
            } catch (_e) {
              // Abaikan jika pemuatan riwayat pasca transfer tertunda
            }
          }}
        />
      )}
    </div>
  );
}

export default BchatProposalChat;
