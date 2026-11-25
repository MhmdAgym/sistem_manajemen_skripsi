/**
 * B-CHAT E2EE Protocol Layer — Real-Time Reverb WebSocket Listener (I-04B.5 / I-04B.5S)
 *
 * Mengimplementasikan subscriber event siaran WebSocket Reverb pada saluran privat
 * `bchat.rooms.{roomUuid}` untuk mendengarkan sinyal tipis (thin signal) `.message.sent`
 * dan merekonsiliasi pesan baru melalui REST API (Single Source of Truth) via Coordinator.
 *
 * Invarian Protokol & Keamanan:
 * 1. Thin Signal: Event WebSocket HANYA membawa metadata non-sensitif (room_uuid, server_sequence, message_uuid, sender_id, created_at).
 *    DILARANG mengharapkan atau membawa ciphertext, plaintext, atau material kriptografi pada WebSocket payload.
 * 2. REST as Single Source of Truth: Penerimaan sinyal WebSocket BUKAN bukti bahwa pesan sudah tersimpan atau valid;
 *    klien WAJIB memicu pengambilan ciphertext via REST API (`fetchMessages`).
 * 3. Coordinator Delegation: Seluruh pemrosesan pesan masuk, verifikasi SHA-256, dan operasi dekripsi Worker
 *    didelegasikan secara ketat ke `bchat-message-coordinator.ts`. Subscriber DILARANG memanggil Worker RPC `DECRYPT_MESSAGE` secara langsung.
 * 4. Deduplication & Ratchet Protection: Sinyal duplikat atau sinyal pesan yang sudah diproses diabaikan secara ketat
 *    untuk mencegah double-fetch dan mencegah kesalahan ratchet MLS ("Desired gen in the past").
 * 5. Self-Message Bypassing: Pesan yang dikirim oleh diri sendiri (sender_id === currentUserId) ditandai dan dilewati
 *    dari alur dekripsi Worker karena kunci ratchet pengirim telah dimusnahkan saat enkripsi.
 * 6. Error Isolation & Monotonic Cursor: Kegagalan dekripsi satu pesan tidak memajukan kursor sequence dan tidak memproses
 *    pesan berikutnya dalam batch demi mencegah ketidakkonsistenan ratchet MLS.
 * 7. Persistent Cursor & Reload Safety: Kursor pemrosesan dipersistenkan secara atomik bersama GroupState di IndexedDB,
 *    sehingga reload halaman (F5) memulihkan kursor yang tepat tanpa memicu re-dekripsi pesan masa lalu.
 */

import { getEcho } from '@/lib/echo';
import type {
  BchatWorkerClient,
  FencingRpcInput,
} from '@/lib/bchat/bchat-poc-rpc';
import {
  reconcileInboundMessages,
  getGroupStateCursor,
  updateGroupStateCursor,
  computeContiguousResolvedSequence,
  isHistoryTombstonePlaintext,
  type DecryptedMessageItem,
  type BchatInboundErrorCategory,
  type UnresolvedSequenceDetail,
} from '@/lib/bchat/message/bchat-message-coordinator';

// -----------------------------------------------------------------------------
// SIGNAL TYPES & CONTRACTS
// -----------------------------------------------------------------------------

export interface BchatMessageSentSignal {
  room_uuid: string;
  server_sequence: number;
  message_uuid: string;
  sender_id: number;
  created_at: string;
}

export interface BchatCommitPublishedSignal {
  room_uuid: string;
  commit_uuid: string;
  from_epoch: string | number;
  to_epoch: string | number;
  commit_hash: string;
  added_device_uuid: string | null;
  created_at: string;
}

export type SubscriptionState =
  | 'IDLE'
  | 'CONNECTING'
  | 'RECONNECTING'
  | 'SUBSCRIBED'
  | 'DISCONNECTED'
  | 'ERROR'
  | 'UNSUBSCRIBED';

export type BchatUiConnectionStatus =
  | 'CONNECTED'
  | 'RECONNECTING'
  | 'OFFLINE'
  | 'ERROR';

export const MESSAGE_SYNC_FALLBACK_INTERVAL_MS = 12000;

/**
 * Memetakan status langganan Reverb ke status koneksi UI secara tegas:
 * Status CONNECTED HANYA berlaku ketika langganan channel benar-benar berstatus SUBSCRIBED.
 */
export function mapSubscriptionStateToConnectionStatus(
  state: SubscriptionState
): BchatUiConnectionStatus {
  if (state === 'SUBSCRIBED') {
    return 'CONNECTED';
  }
  if (state === 'CONNECTING' || state === 'RECONNECTING') {
    return 'RECONNECTING';
  }
  if (state === 'ERROR') {
    return 'ERROR';
  }
  return 'OFFLINE';
}

export interface MessageSyncFallbackGuardParams {
  isRoomActive: boolean;
  onboardingGate: string;
  hasLocalGroupState: boolean;
  isUnrecoverableActiveRoom: boolean;
  hasWaitingState: boolean;
  isDocumentVisible: boolean;
  isOnline: boolean;
  hasActiveSubscriber: boolean;
  isSubscriberUnsubscribed: boolean;
  isReconciling: boolean;
  isFallbackInFlight: boolean;
}

/**
 * Guard murni untuk menentukan apakah fallback rekonsiliasi REST berkala boleh dieksekusi:
 * - Hanya saat room ACTIVE dan onboarding PASSED dengan GroupState lokal valid.
 * - Hanya ketika tab visible dan browser online.
 * - Tidak berjalan jika rekonsiliasi sebelumnya masih berlangsung atau subscriber sudah unsubscribed.
 */
export function shouldRunMessageSyncFallback(
  params: MessageSyncFallbackGuardParams
): boolean {
  return (
    params.isRoomActive &&
    params.onboardingGate === 'PASSED' &&
    params.hasLocalGroupState &&
    !params.isUnrecoverableActiveRoom &&
    !params.hasWaitingState &&
    params.isDocumentVisible &&
    params.isOnline &&
    params.hasActiveSubscriber &&
    !params.isSubscriberUnsubscribed &&
    !params.isReconciling &&
    !params.isFallbackInFlight
  );
}

export interface BchatRecoveryState {
  isRecoveryRequired: boolean;
  failedSequence: number | null;
  unresolvedSequences: UnresolvedSequenceDetail[];
  sequenceGaps: number[];
  contiguousSequence: number;
  highestObservedSequence: number;
  detail: string | null;
}

export interface BchatRealtimeSubscriberOptions {
  roomUuid: string;
  currentUserId: number;
  deviceUuid: string;
  workerRpc: BchatWorkerClient;
  fencing: FencingRpcInput;
  /** Contiguous verified cursor awal (seluruh 1..initialSequence sudah sah tanpa celah). */
  initialSequence?: number;
  /** Batas bawah sequence sesi aktif: Math.max(0, (active_session.start_sequence ?? 1) - 1). */
  sessionFloor?: number;
  /** UUID sesi aktif agar rekonsiliasi REST selalu memfilter pesan sesi aktif. */
  sessionUuid?: string | null;
  /** Daftar sequence individual valid yang sudah tersimpan lokal (termasuk di atas celah). */
  knownProcessedSequences?: Iterable<number>;
  /** Daftar sequence yang sudah diketahui gagal (tombstone) dari penyimpanan lokal. */
  knownUnresolvedSequences?: Iterable<UnresolvedSequenceDetail>;
  batchLimit?: number;
  onSubscriptionStatusChange?: (status: SubscriptionState, detail?: string) => void;
  onSignalReceived?: (signal: BchatMessageSentSignal, isDuplicate: boolean) => void;
  onCommitPublished?: (signal: BchatCommitPublishedSignal) => void;
  onMessageDecrypted?: (message: DecryptedMessageItem) => void;
  onReconciled?: (reconciledCount: number, latestSequence: number) => void;
  onRecoveryRequired?: (failedSequence: number, error: string, category?: BchatInboundErrorCategory) => void;
  onRecoveryStateChange?: (state: BchatRecoveryState) => void;
  onError?: (error: Error) => void;
}

const UUID_V4_REGEX =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

/**
 * Validasi ketat format dan metadata sinyal WebSocket BchatMessageSent.
 * Mengembalikan objek typed BchatMessageSentSignal jika sah, atau null jika tidak valid.
 */
export function validateMessageSentSignal(data: unknown): BchatMessageSentSignal | null {
  if (!data || typeof data !== 'object') {
    return null;
  }

  const d = data as Record<string, unknown>;

  if (typeof d.room_uuid !== 'string' || !UUID_V4_REGEX.test(d.room_uuid)) {
    return null;
  }

  if (
    typeof d.server_sequence !== 'number' ||
    !Number.isInteger(d.server_sequence) ||
    d.server_sequence <= 0
  ) {
    return null;
  }

  if (typeof d.message_uuid !== 'string' || !UUID_V4_REGEX.test(d.message_uuid)) {
    return null;
  }

  if (
    typeof d.sender_id !== 'number' ||
    !Number.isInteger(d.sender_id) ||
    d.sender_id <= 0
  ) {
    return null;
  }

  if (typeof d.created_at !== 'string' || Number.isNaN(Date.parse(d.created_at))) {
    return null;
  }

  return {
    room_uuid: d.room_uuid.toLowerCase(),
    server_sequence: d.server_sequence,
    message_uuid: d.message_uuid.toLowerCase(),
    sender_id: d.sender_id,
    created_at: d.created_at,
  };
}

// -----------------------------------------------------------------------------
// REAL-TIME SUBSCRIBER CLASS
// -----------------------------------------------------------------------------

export class BchatRealtimeSubscriber {
  private readonly options: BchatRealtimeSubscriberOptions;
  private readonly sessionFloor: number;
  private readonly sessionUuid: string | null;
  private echoChannel: any = null;
  private boundPusherConnection: any = null;
  private connectionStateHandler: ((states: { previous?: string; current?: string }) => void) | null = null;
  private hasConfirmedSubscriptionOnce: boolean = false;
  /** Contiguous verified cursor: seluruh sequence 1..contiguousSequence telah selesai sah tanpa celah. */
  private contiguousSequence: number;
  /** Sequence tertinggi yang pernah diamati dari server/WS/outbound untuk mendeteksi celah secara eksplisit. */
  private highestObservedSequence: number;
  /** Himpunan sequence individual yang benar-benar telah diproses secara valid (inbound/outbound). */
  private readonly processedSequences: Set<number> = new Set();
  /** Peta sequence yang gagal secara kriptografis/integritas dan membutuhkan tindakan recovery. */
  private readonly unresolvedSequences: Map<number, UnresolvedSequenceDetail> = new Map();
  private readonly hasExplicitLocalKnowledge: boolean;
  private isReconciling: boolean = false;
  private hasPendingReconcileRequest: boolean = false;
  private isRecoveryRequired: boolean = false;
  private recoveryErrorDetail: string | null = null;
  private lastFailedSequence: number | null = null;
  private subscriptionState: SubscriptionState = 'IDLE';

  constructor(options: BchatRealtimeSubscriberOptions) {
    this.options = options;
    this.sessionFloor =
      typeof options.sessionFloor === 'number' &&
      Number.isInteger(options.sessionFloor) &&
      options.sessionFloor > 0
        ? options.sessionFloor
        : 0;
    this.sessionUuid =
      typeof options.sessionUuid === 'string' && options.sessionUuid.trim() !== ''
        ? options.sessionUuid.trim().toLowerCase()
        : null;

    const rawInitial =
      typeof options.initialSequence === 'number' && options.initialSequence > 0
        ? options.initialSequence
        : 0;
    const baseInitial = Math.max(this.sessionFloor, rawInitial);
    this.contiguousSequence = baseInitial;
    this.highestObservedSequence = baseInitial;
    this.hasExplicitLocalKnowledge =
      options.knownProcessedSequences !== undefined ||
      options.knownUnresolvedSequences !== undefined;

    if (baseInitial > 0) {
      for (let s = 1; s <= baseInitial; s++) {
        this.processedSequences.add(s);
      }
    }

    if (options.knownProcessedSequences) {
      for (const seq of Array.from(options.knownProcessedSequences)) {
        if (typeof seq === 'number' && Number.isInteger(seq) && seq > 0) {
          this.processedSequences.add(seq);
          this.highestObservedSequence = Math.max(this.highestObservedSequence, seq);
        }
      }
    }

    if (options.knownUnresolvedSequences) {
      for (const detail of Array.from(options.knownUnresolvedSequences)) {
        if (
          detail &&
          typeof detail.server_sequence === 'number' &&
          Number.isInteger(detail.server_sequence) &&
          detail.server_sequence > this.sessionFloor
        ) {
          this.processedSequences.delete(detail.server_sequence);
          this.unresolvedSequences.set(detail.server_sequence, detail);
          this.highestObservedSequence = Math.max(
            this.highestObservedSequence,
            detail.server_sequence
          );
        }
      }
    }

    this.contiguousSequence = computeContiguousResolvedSequence(
      this.processedSequences,
      Math.max(this.sessionFloor, this.contiguousSequence)
    );

    if (this.unresolvedSequences.size > 0) {
      const sortedUnresolved = this.getUnresolvedSequences();
      const first = sortedUnresolved[0];
      this.isRecoveryRequired = sortedUnresolved.some((u) => u.requiresRecovery);
      this.lastFailedSequence = first?.server_sequence ?? null;
      this.recoveryErrorDetail = first?.error ?? null;
    }
  }

  public getSubscriptionState(): SubscriptionState {
    return this.subscriptionState;
  }

  public getIsReconciling(): boolean {
    return this.isReconciling;
  }

  public getSessionFloor(): number {
    return this.sessionFloor;
  }

  public getSessionUuid(): string | null {
    return this.sessionUuid;
  }

  /** Mengembalikan contiguous verified cursor (tidak pernah melompati celah/pesan gagal). */
  public getLatestSequence(): number {
    return this.contiguousSequence;
  }

  public getContiguousSequence(): number {
    return this.contiguousSequence;
  }

  public getHighestObservedSequence(): number {
    return this.highestObservedSequence;
  }

  public getProcessedSequences(): number[] {
    return Array.from(this.processedSequences).sort((a, b) => a - b);
  }

  public getUnresolvedSequences(): UnresolvedSequenceDetail[] {
    return Array.from(this.unresolvedSequences.values()).sort(
      (a, b) => a.server_sequence - b.server_sequence
    );
  }

  /**
   * Mendeteksi secara eksplisit seluruh sequence gap di rentang (contiguousSequence + 1 .. highestObservedSequence)
   * yang belum berhasil diproses (mencakup pesan yang belum diterima maupun pesan gagal/unresolved).
   */
  public getSequenceGaps(): number[] {
    const gaps: number[] = [];
    for (let s = this.contiguousSequence + 1; s <= this.highestObservedSequence; s++) {
      if (!this.processedSequences.has(s)) {
        gaps.push(s);
      }
    }
    return gaps;
  }

  public getRecoveryState(): BchatRecoveryState {
    return {
      isRecoveryRequired: this.isRecoveryRequired,
      failedSequence: this.lastFailedSequence,
      unresolvedSequences: this.getUnresolvedSequences(),
      sequenceGaps: this.getSequenceGaps(),
      contiguousSequence: this.contiguousSequence,
      highestObservedSequence: this.highestObservedSequence,
      detail: this.recoveryErrorDetail,
    };
  }

  /**
   * Menandai sequence tertentu (misalnya pesan outbound sendiri) telah selesai diproses oleh klien
   * agar tidak direkonsiliasi/didekripsi ulang.
   *
   * INVARIAN KRITIS:
   * Outbound message TIDAK BOLEH memajukan contiguousSequence atau persistent cursor melewati
   * inbound sequence gap atau pesan yang belum terselesaikan!
   */
  public markSequenceProcessed(sequence: number): void {
    if (typeof sequence !== 'number' || !Number.isInteger(sequence) || sequence <= 0) {
      return;
    }

    this.unresolvedSequences.delete(sequence);
    this.processedSequences.add(sequence);
    this.highestObservedSequence = Math.max(this.highestObservedSequence, sequence);

    const prevContiguous = this.contiguousSequence;
    this.contiguousSequence = computeContiguousResolvedSequence(
      this.processedSequences,
      this.contiguousSequence
    );

    if (this.contiguousSequence > prevContiguous) {
      updateGroupStateCursor({
        deviceUuid: this.options.deviceUuid,
        roomUuid: this.options.roomUuid,
        sequence: this.contiguousSequence,
        fencing: {
          ownerToken: this.options.fencing.ownerToken,
          generation: this.options.fencing.generation,
        },
      }).catch((_err) => {
        // noop: safe fail
      });
    }

    // Jika outbound baru berada di atas contiguousSequence + 1 (ada celah inbound tertunda),
    // picu rekonsiliasi REST secara aman agar pesan inbound yang tertinggal segera diproses.
    if (
      sequence > this.contiguousSequence + 1 &&
      this.subscriptionState === 'SUBSCRIBED' &&
      !this.isReconciling
    ) {
      this.reconcileFromRest().catch((_err) => {});
    }

    this.emitRecoveryState();
  }

  /**
   * Menyinkronkan state subscriber dari daftar riwayat lokal yang baru diperbarui
   * (misalnya pasca History Transfer yang sah), menutup celah yang sudah pulih,
   * dan memajukan contiguous cursor secara atomik jika tidak ada lagi celah di bawahnya.
   */
  public async syncFromLocalHistory(items: DecryptedMessageItem[]): Promise<void> {
    for (const item of items) {
      const seq = item.server_sequence;
      if (!Number.isInteger(seq) || seq <= 0) continue;
      this.highestObservedSequence = Math.max(this.highestObservedSequence, seq);

      if (isHistoryTombstonePlaintext(item.plaintext)) {
        if (
          seq > this.sessionFloor &&
          !this.processedSequences.has(seq) &&
          !this.unresolvedSequences.has(seq)
        ) {
          this.unresolvedSequences.set(seq, {
            server_sequence: seq,
            message_uuid: item.message_uuid,
            errorCategory:
              item.plaintext === '[Integritas Gagal]' || item.plaintext === '[Format Corrupt]'
                ? 'PAYLOAD_INTEGRITY_ERROR'
                : 'MLS_CRYPTO_ERROR',
            error: item.error || `Pesan urutan #${seq} belum dapat didekripsi secara kriptografis.`,
            requiresRecovery: true,
          });
        }
      } else {
        this.unresolvedSequences.delete(seq);
        this.processedSequences.add(seq);
      }
    }

    const prevContiguous = this.contiguousSequence;
    this.contiguousSequence = computeContiguousResolvedSequence(
      this.processedSequences,
      this.contiguousSequence
    );

    if (this.contiguousSequence > prevContiguous) {
      await updateGroupStateCursor({
        deviceUuid: this.options.deviceUuid,
        roomUuid: this.options.roomUuid,
        sequence: this.contiguousSequence,
        fencing: {
          ownerToken: this.options.fencing.ownerToken,
          generation: this.options.fencing.generation,
        },
      }).catch(() => {});
    }

    this.refreshRecoveryFlags();
    this.emitRecoveryState();
  }

  /**
   * Menginisialisasi kursor dari IndexedDB GroupState secara asinkron.
   * Tidak akan melompati sequence unresolved atau celah yang sudah terdeteksi dari riwayat lokal.
   */
  public async initializeFromPersistence(): Promise<number> {
    try {
      const persistentCursor = await getGroupStateCursor(
        this.options.deviceUuid,
        this.options.roomUuid
      );
      if (this.contiguousSequence < this.sessionFloor) {
        this.contiguousSequence = this.sessionFloor;
      }
      if (persistentCursor > this.contiguousSequence) {
        const minUnresolved = this.getUnresolvedSequences()[0]?.server_sequence;
        const hasBlockingUnresolved =
          minUnresolved !== undefined &&
          minUnresolved > this.sessionFloor &&
          minUnresolved <= persistentCursor;

        // Jika konstruktor membawa peta riwayat lokal secara eksplisit dan ada gap/unresolved
        // di bawah persistentCursor pada sesi aktif (rentang sessionFloor+1..persistentCursor),
        // jangan lompati gap; namun sequence sesi ARCHIVED (<= sessionFloor) tidak pernah dianggap gap.
        if (!hasBlockingUnresolved && !this.hasExplicitLocalKnowledge) {
          this.contiguousSequence = persistentCursor;
          this.highestObservedSequence = Math.max(this.highestObservedSequence, persistentCursor);
          for (let s = 1; s <= persistentCursor; s++) {
            this.processedSequences.add(s);
          }
        } else if (!hasBlockingUnresolved && this.hasExplicitLocalKnowledge) {
          // Maju dari batas bawah sesi aktif sejauh tidak ada celah pada knownProcessedSequences
          let safeTarget = Math.max(this.contiguousSequence, this.sessionFloor);
          while (safeTarget < persistentCursor && this.processedSequences.has(safeTarget + 1)) {
            safeTarget++;
          }
          this.contiguousSequence = safeTarget;
          this.highestObservedSequence = Math.max(this.highestObservedSequence, persistentCursor);
        } else {
          this.highestObservedSequence = Math.max(this.highestObservedSequence, persistentCursor);
        }
      }
      this.emitRecoveryState();
      return this.contiguousSequence;
    } catch (_err) {
      return this.contiguousSequence;
    }
  }

  /**
   * Berlangganan ke saluran privat Reverb `bchat.rooms.{roomUuid}`.
   * Dilengkapi idempotency guard untuk mencegah pendaftaran listener berulang.
   * Status SUBSCRIBED hanya ditetapkan setelah library mengonfirmasi langganan channel.
   */
  public subscribe(): void {
    if (
      this.subscriptionState === 'SUBSCRIBED' ||
      this.subscriptionState === 'CONNECTING' ||
      this.subscriptionState === 'RECONNECTING'
    ) {
      return;
    }

    const echo = getEcho(this.options.deviceUuid);
    if (!echo) {
      this.updateStatus('ERROR', 'Instance Laravel Echo tidak tersedia.');
      this.options.onError?.(new Error('Instance Laravel Echo tidak tersedia.'));
      return;
    }

    this.updateStatus(
      this.hasConfirmedSubscriptionOnce ? 'RECONNECTING' : 'CONNECTING',
      'Menghubungkan saluran Reverb...'
    );
    const channelName = `bchat.rooms.${this.options.roomUuid}`;

    try {
      const channel = echo.private(channelName);
      this.echoChannel = channel;

      // Pantau transisi koneksi transport Pusher/Reverb secara aman (disconnected, reconnecting, failed)
      // tanpa mengubah mekanisme autentikasi WebSocket yang sudah berjalan.
      const pusherConnection = (echo as any)?.connector?.pusher?.connection;
      if (pusherConnection && typeof pusherConnection.bind === 'function') {
        this.detachConnectionListeners();
        this.boundPusherConnection = pusherConnection;
        this.connectionStateHandler = (states: { previous?: string; current?: string }) => {
          if (this.subscriptionState === 'UNSUBSCRIBED') return;
          const current = states?.current;
          if (current === 'connecting') {
            this.updateStatus(
              this.hasConfirmedSubscriptionOnce ? 'RECONNECTING' : 'CONNECTING',
              this.hasConfirmedSubscriptionOnce
                ? 'Menghubungkan kembali ke saluran Reverb...'
                : 'Menghubungkan saluran Reverb...'
            );
          } else if (current === 'unavailable' || current === 'disconnected') {
            this.updateStatus(
              'DISCONNECTED',
              'Saluran WebSocket terputus. Menunggu sambungan kembali...'
            );
          } else if (current === 'failed') {
            this.updateStatus('ERROR', 'Koneksi WebSocket Reverb gagal.');
          } else if (current === 'connected') {
            // Transport terhubung tidak otomatis berarti private channel sudah terotorisasi & subscribed.
            if (this.echoChannel?.subscription?.subscribed === true) {
              this.hasConfirmedSubscriptionOnce = true;
              this.updateStatus('SUBSCRIBED', 'Terhubung aman via E2EE');
            } else {
              this.updateStatus(
                this.hasConfirmedSubscriptionOnce ? 'RECONNECTING' : 'CONNECTING',
                'Mengonfirmasi langganan saluran privat...'
              );
            }
          }
        };
        pusherConnection.bind('state_change', this.connectionStateHandler);
      }

      channel.subscribed(() => {
        if (this.subscriptionState === 'UNSUBSCRIBED') return;
        this.hasConfirmedSubscriptionOnce = true;
        this.updateStatus('SUBSCRIBED', 'Terhubung aman via E2EE');
        // Saat pertama terhubung atau reconnect: rekonsiliasi pesan yang mungkin terlewat
        this.reconcileFromRest().catch((_err) => {
          // Error ditangani melalui onError callback di dalam reconcileFromRest
        });
      });

      channel.error((err: any) => {
        if (this.subscriptionState === 'UNSUBSCRIBED') return;
        const errMsg = err?.message || JSON.stringify(err) || 'Autentikasi private channel ditolak.';
        this.updateStatus('ERROR', errMsg);
        this.options.onError?.(new Error(`Otorisasi saluran privat gagal: ${errMsg}`));
      });

      // Dengarkan event siaran tipis (Thin Signal) .message.sent
      channel.listen('.message.sent', (rawPayload: unknown) => {
        this.handleSignal(rawPayload);
      });

      // Dengarkan event siaran Commit MLS baru (.bchat.commit.published)
      channel.listen('.bchat.commit.published', (rawPayload: unknown) => {
        if (this.subscriptionState === 'UNSUBSCRIBED') return;
        if (typeof this.options.onCommitPublished === 'function') {
          this.options.onCommitPublished(rawPayload as BchatCommitPublishedSignal);
        }
      });

      // Jika library melaporkan channel sudah dalam status subscribed secara eksplisit
      if (channel?.subscription?.subscribed === true) {
        this.hasConfirmedSubscriptionOnce = true;
        this.updateStatus('SUBSCRIBED', 'Terhubung aman via E2EE');
      }
    } catch (err: any) {
      const errMsg = err?.message || 'Gagal memulai koneksi WebSocket.';
      this.updateStatus('ERROR', errMsg);
      this.options.onError?.(new Error(errMsg));
    }
  }

  /**
   * Melakukan reconnect bersih: membersihkan listener dan saluran lama sebelum berlangganan kembali.
   */
  public reconnect(): void {
    this.unsubscribe();
    this.subscribe();
  }

  /**
   * Menangani sinyal yang masuk dari WebSocket Reverb secara defensif.
   *
   * INVARIAN KRITIS:
   * - Sinyal baru (mis. Seq #13) TIDAK BOLEH dibuang diam-diam hanya karena sequence lebih lama
   *   (mis. Seq #11) sedang dalam status recovery!
   * - Self-message echo TIDAK BOLEH memajukan persistent cursor melampaui celah inbound.
   */
  public handleSignal(rawPayload: unknown): void {
    if (this.subscriptionState === 'UNSUBSCRIBED') {
      return;
    }

    const signal = validateMessageSentSignal(rawPayload);
    if (!signal) {
      // Sinyal tidak valid atau rusak: abaikan demi keamanan
      return;
    }

    if (signal.room_uuid !== this.options.roomUuid.toLowerCase()) {
      return;
    }

    this.highestObservedSequence = Math.max(
      this.highestObservedSequence,
      signal.server_sequence
    );

    // Deteksi duplikasi event:
    // Sudah diproses secara valid, berada di <= contiguousSequence, atau merupakan sinyal ulang
    // untuk sequence gagal permanen yang sudah tercatat di unresolvedSequences.
    const isDuplicate =
      this.processedSequences.has(signal.server_sequence) ||
      signal.server_sequence <= this.contiguousSequence ||
      this.unresolvedSequences.has(signal.server_sequence);

    this.options.onSignalReceived?.(signal, isDuplicate);

    if (isDuplicate) {
      // Jika ini self-message yang sudah diproses lokal, pastikan contiguous cursor ikut tersinkron
      // HANYA bila tidak ada celah di bawahnya (TIDAK melompati gap!).
      if (
        signal.sender_id === this.options.currentUserId &&
        this.processedSequences.has(signal.server_sequence)
      ) {
        const prevContiguous = this.contiguousSequence;
        this.contiguousSequence = computeContiguousResolvedSequence(
          this.processedSequences,
          this.contiguousSequence
        );
        if (this.contiguousSequence > prevContiguous) {
          updateGroupStateCursor({
            deviceUuid: this.options.deviceUuid,
            roomUuid: this.options.roomUuid,
            sequence: this.contiguousSequence,
            fencing: {
              ownerToken: this.options.fencing.ownerToken,
              generation: this.options.fencing.generation,
            },
          }).catch((_err) => {
            // noop: safe fail
          });
        }
      }
      return;
    }

    // Sinyal sah baru (termasuk ketika pesan sebelumnya sedang dalam status recovery):
    // Pemicu untuk rekonsiliasi REST via coordinator agar pesan baru tidak hilang!
    this.reconcileFromRest({ retryUnresolved: false }).catch((_err) => {
      // Error ditangani melalui onError callback di dalam reconcileFromRest
    });
  }

  /**
   * Mengambil pesan baru dari REST API (Single Source of Truth) dan memproses dekripsi inbound melalui coordinator.
   * - Tetap memproses pesan baru meskipun ada sequence lama di unresolvedSequences.
   * - Mempertahankan contiguousSequence agar tidak pernah melompati pesan gagal/celah.
   * - Dilengkapi pencegahan race condition konkurensi (single-flight + pending catch-up).
   */
  public async reconcileFromRest(options?: { retryUnresolved?: boolean }): Promise<number> {
    if (this.subscriptionState === 'UNSUBSCRIBED') {
      return 0;
    }

    const retryUnresolved = Boolean(options?.retryUnresolved);

    if (this.isReconciling) {
      // Hindari konkurensi ganda, tandai request tertunda untuk dieksekusi setelah batch selesai
      this.hasPendingReconcileRequest = true;
      return 0;
    }
    this.isReconciling = true;

    try {
      const reconcileRes = await reconcileInboundMessages({
        roomUuid: this.options.roomUuid,
        deviceUuid: this.options.deviceUuid,
        currentUserId: this.options.currentUserId,
        workerRpc: this.options.workerRpc,
        fencing: this.options.fencing,
        afterSequence: Math.max(this.sessionFloor, this.contiguousSequence),
        sessionFloor: this.sessionFloor,
        sessionUuid: this.sessionUuid ?? undefined,
        batchLimit: this.options.batchLimit,
        processedSequences: this.processedSequences,
        unresolvedSequences: this.unresolvedSequences,
        retryUnresolved,
        onMessageDecrypted: this.options.onMessageDecrypted,
      });

      this.contiguousSequence = reconcileRes.contiguousSequence;
      this.highestObservedSequence = Math.max(
        this.highestObservedSequence,
        reconcileRes.highestSeenSequence
      );

      this.refreshRecoveryFlags();

      if (reconcileRes.hasErrors) {
        const errorMsg =
          reconcileRes.error ||
          `Sebagian pesan membutuhkan pemulihan pada sequence #${reconcileRes.failedSequence}.`;

        if (this.isRecoveryRequired) {
          this.recoveryErrorDetail = errorMsg;
          this.options.onRecoveryRequired?.(
            this.lastFailedSequence ?? reconcileRes.failedSequence ?? 0,
            errorMsg,
            reconcileRes.errorCategory
          );
        }

        this.options.onError?.(new Error(errorMsg));
      }

      this.emitRecoveryState();
      this.options.onReconciled?.(reconcileRes.reconciledCount, this.contiguousSequence);
      return reconcileRes.reconciledCount;
    } catch (err: any) {
      const errorObj = err instanceof Error ? err : new Error(String(err));
      this.options.onError?.(errorObj);
      return 0;
    } finally {
      this.isReconciling = false;
      // Jika ada request sinyal baru yang tiba di tengah rekonsiliasi, jalankan catch-up
      // (termasuk saat ada unresolved sequence lama, karena pesan baru tetap harus diproses!)
      if (
        this.hasPendingReconcileRequest &&
        this.getSubscriptionState() !== 'UNSUBSCRIBED'
      ) {
        this.hasPendingReconcileRequest = false;
        this.reconcileFromRest({ retryUnresolved: false }).catch((_err) => {});
      }
    }
  }

  /**
   * Mekanisme recovery eksplisit: memeriksa ulang sequence yang gagal secara aman
   * (memulihkan jika riwayat lokal sudah terisi atau ratchet valid tersedia, tanpa memaksakan decrypt ilegal).
   */
  public async retryRecovery(): Promise<number> {
    return this.reconcileFromRest({ retryUnresolved: true });
  }

  /**
   * Mengatur ulang status pemulihan (misalnya setelah re-onboarding atau sesi baru).
   */
  public resetRecoveryState(): void {
    this.unresolvedSequences.clear();
    this.isRecoveryRequired = false;
    this.recoveryErrorDetail = null;
    this.lastFailedSequence = null;
    this.hasPendingReconcileRequest = false;
    this.emitRecoveryState();
  }

  private refreshRecoveryFlags(): void {
    const unresolvedList = this.getUnresolvedSequences();
    const blocking = unresolvedList.filter((u) => u.requiresRecovery);
    if (blocking.length > 0) {
      this.isRecoveryRequired = true;
      this.lastFailedSequence = blocking[0].server_sequence;
      this.recoveryErrorDetail = blocking[0].error;
    } else {
      this.isRecoveryRequired = false;
      this.lastFailedSequence = null;
      this.recoveryErrorDetail = null;
    }
  }

  private emitRecoveryState(): void {
    this.options.onRecoveryStateChange?.(this.getRecoveryState());
  }

  public getIsRecoveryRequired(): boolean {
    return this.isRecoveryRequired;
  }

  public getRecoveryErrorDetail(): string | null {
    return this.recoveryErrorDetail;
  }

  public getLastFailedSequence(): number | null {
    return this.lastFailedSequence;
  }

  private detachConnectionListeners(): void {
    if (this.boundPusherConnection && this.connectionStateHandler) {
      try {
        if (typeof this.boundPusherConnection.unbind === 'function') {
          this.boundPusherConnection.unbind('state_change', this.connectionStateHandler);
        }
      } catch (_err) {
        // noop
      }
    }
    this.boundPusherConnection = null;
    this.connectionStateHandler = null;
  }

  /**
   * Berhenti berlangganan dan membersihkan saluran WebSocket.
   */
  public unsubscribe(): void {
    this.updateStatus('UNSUBSCRIBED');
    this.detachConnectionListeners();
    if (this.echoChannel) {
      try {
        if (typeof this.echoChannel.stopListening === 'function') {
          this.echoChannel.stopListening('.message.sent');
          this.echoChannel.stopListening('.bchat.commit.published');
        }
      } catch (_err) {
        // noop
      }
      const echo = getEcho(this.options.deviceUuid);
      if (echo) {
        try {
          echo.leave(`bchat.rooms.${this.options.roomUuid}`);
        } catch (_err) {
          // noop: abaikan error pelepasan channel jika koneksi sudah terputus
        }
      }
      this.echoChannel = null;
    }
  }

  private updateStatus(state: SubscriptionState, detail?: string): void {
    this.subscriptionState = state;
    this.options.onSubscriptionStatusChange?.(state, detail);
  }
}
