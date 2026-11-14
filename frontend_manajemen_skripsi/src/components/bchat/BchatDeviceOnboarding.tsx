'use client';

/**
 * B-CHAT E2EE — Device Onboarding Modal (I-05A.UI-ONBOARDING Tahap B)
 *
 * Menangani seluruh alur pendaftaran perangkat B-CHAT E2EE dari UI utama:
 * - Pemeriksaan status perangkat lokal + backend
 * - Registrasi perangkat baru
 * - Managed Replacement dengan konfirmasi eksplisit pengguna
 * - Rekonsiliasi staged key yang sudah ACTIVE di backend
 * - Publikasi KeyPackage setelah registrasi
 * - Retry publikasi KeyPackage tanpa registrasi ulang
 *
 * Invariants keamanan:
 * - Password tidak pernah disimpan dalam state komponen setelah digunakan
 * - Private key tidak pernah ditampilkan ke UI
 * - Staged key dipertahankan pada kegagalan jaringan (tidak dihapus otomatis)
 * - Replacement membutuhkan konfirmasi eksplisit; tidak otomatis
 * - Tombol aksi dinonaktifkan saat request sedang berjalan (cegah double-submit)
 */

import React, { useState, useCallback, useEffect, useRef } from 'react';
import {
  Shield,
  ShieldCheck,
  ShieldAlert,
  Loader2,
  KeyRound,
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  Eye,
  EyeOff,
  Smartphone,
  WifiOff,
} from 'lucide-react';
import {
  findActiveLocalDevice,
  registerDeviceFlow,
  promoteStagedIdentityKeyToActive,
  generateSecureDeviceUuid,
  makeDeviceIdentityKeyId,
  BchatDeviceRegistrationError,
} from '@/lib/bchat/device/bchat-device-registration';
import {
  getDeviceStatus,
  BchatApiError,
} from '@/lib/bchat/api/bchat-api-client';
import type {
  BchatDeviceStatusData,
  BchatActiveDeviceInfo,
} from '@/lib/bchat/api/bchat-api-types';
import {
  idbGet,
  idbPut,
  idbGetAll,
} from '@/lib/bchat/storage/bchat-idb-core';
import {
  BCAT_STORES,
  type KeystoreRecord,
  type TabLeaseRecord,
  type PendingKeyPackageRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import {
  createBchatWorkerClient,
  type BchatWorkerClient,
} from '@/lib/bchat/bchat-poc-rpc';
import {
  publishKeyPackagesFlow,
} from '@/lib/bchat/keypackage/bchat-keypackage-service';
import axios from '@/lib/axios';

// -----------------------------------------------------------------------------
// TIPE STATE ONBOARDING
// -----------------------------------------------------------------------------

export type OnboardingPhase =
  | 'CHECKING'               // Pemeriksaan awal (lokal + backend)
  | 'DEVICE_READY'           // Perangkat sudah terdaftar dan ACTIVE
  | 'NEEDS_REGISTRATION'     // Belum ada perangkat ACTIVE → perlu daftar
  | 'NEEDS_PASSWORD'         // Menunggu password dari pengguna
  | 'STEP_UP_IN_PROGRESS'    // Proses step-up berjalan
  | 'REGISTRATION_IN_PROGRESS' // Proses registrasi berjalan
  | 'RECONCILING'            // Memeriksa apakah staged key sudah ACTIVE
  | 'NEEDS_REPLACEMENT_CONFIRM' // Ada active device lain → perlu konfirmasi
  | 'REPLACEMENT_IN_PROGRESS' // Proses replacement berjalan
  | 'INITIALIZING_WORKER'    // Inisialisasi MLS worker
  | 'GENERATING_KEYPACKAGES'  // Pembangkitan KeyPackage via worker
  | 'PUBLISHING_KEYPACKAGES' // Publikasi KeyPackage setelah registrasi
  | 'KEYPACKAGE_PUBLISH_FAILED' // Publikasi gagal → retry tersedia
  | 'TRANSIENT_ERROR'        // Gagal sementara, bisa dicoba kembali
  | 'PERMANENT_ERROR';       // Gagal permanen, perlu tindakan pengguna

export interface StagedKeyInfo {
  deviceUuid: string;
  publicKeyHex?: string;
}

export interface OnboardingResult {
  deviceUuid: string;
  keyVersion: number;
  registeredAt: string;
}

export interface BchatDeviceOnboardingProps {
  /** Dipanggil ketika perangkat siap (lokal + backend konsisten & KeyPackage siap) */
  onDeviceReady: (deviceUuid: string) => void;
  /** Dipanggil saat pengguna membatalkan onboarding */
  onCancel?: () => void;
  /** Nama perangkat default (dari browser) */
  defaultDeviceName?: string;
  /** Worker RPC untuk publikasi KeyPackage — opsional; jika null, diinstansiasi otomatis */
  workerRpc?: BchatWorkerClient | null;
  /** Data akun yang sedang aktif untuk otentikasi worker INIT_PEER */
  currentUser?: { id?: number | string; email?: string; role?: string } | null;
}

// -----------------------------------------------------------------------------
// HELPER: Ambil staged device dari IndexedDB (KEYSTORE)
// -----------------------------------------------------------------------------

async function findStagedDeviceInKeystore(): Promise<StagedKeyInfo | null> {
  try {
    // Scan semua record di KEYSTORE untuk menemukan yang statusnya 'staged' + Ed25519 + DEVICE_IDENTITY
    const result = await new Promise<StagedKeyInfo | null>((resolve, reject) => {
      // Gunakan idbGet tidak bisa scan semua; kita perlu openCursor
      // Buat request langsung via IDBFactory
      const openReq = indexedDB.open('unuha_bchat_secure_storage');
      openReq.onsuccess = () => {
        const db = openReq.result;
        const tx = db.transaction('keystore', 'readonly');
        const store = tx.objectStore('keystore');
        const cursorReq = store.openCursor();
        let found: StagedKeyInfo | null = null;
        cursorReq.onsuccess = () => {
          const cursor = cursorReq.result;
          if (!cursor) {
            db.close();
            resolve(found);
            return;
          }
          const rec = cursor.value as KeystoreRecord;
          if (
            rec.status === 'staged' &&
            rec.algorithm === 'Ed25519' &&
            rec.metadata?.key_type === 'DEVICE_IDENTITY' &&
            typeof rec.metadata?.device_uuid === 'string'
          ) {
            found = {
              deviceUuid: rec.metadata.device_uuid as string,
              publicKeyHex: rec.metadata?.public_key_hex as string | undefined,
            };
            db.close();
            resolve(found);
            return;
          }
          cursor.continue();
        };
        cursorReq.onerror = () => { db.close(); reject(cursorReq.error); };
        tx.onerror = () => { db.close(); reject(tx.error); };
      };
      openReq.onerror = () => reject(openReq.error);
    });
    return result;
  } catch {
    return null;
  }
}

// -----------------------------------------------------------------------------
// HELPER: Dapatkan browser name sederhana
// -----------------------------------------------------------------------------

function getBrowserName(): string {
  if (typeof navigator === 'undefined') return 'Browser Device';
  const ua = navigator.userAgent;
  if (ua.includes('Chrome') && !ua.includes('Edg')) return 'Chrome';
  if (ua.includes('Firefox')) return 'Firefox';
  if (ua.includes('Safari') && !ua.includes('Chrome')) return 'Safari';
  if (ua.includes('Edg')) return 'Edge';
  return 'Browser Device';
}

// -----------------------------------------------------------------------------
// HELPER: Cek jumlah KeyPackage aktif di IndexedDB (PENDING_KEY_PACKAGES)
// -----------------------------------------------------------------------------

async function countActiveKeyPackagesInStorage(deviceUuid: string): Promise<number> {
  try {
    const all = await idbGetAll<PendingKeyPackageRecord>(BCAT_STORES.PENDING_KEY_PACKAGES);
    const valid = all.filter(
      (p) =>
        p.device_uuid?.toLowerCase() === deviceUuid.toLowerCase() &&
        p.metadata?.status === 'pending' &&
        p.metadata?.publication_status === 'published'
    );
    return valid.length;
  } catch {
    return 0;
  }
}

// -----------------------------------------------------------------------------
// KOMPONEN UTAMA
// -----------------------------------------------------------------------------

export function BchatDeviceOnboarding({
  onDeviceReady,
  onCancel,
  defaultDeviceName,
  workerRpc,
  currentUser,
}: BchatDeviceOnboardingProps) {
  const [phase, setPhase] = useState<OnboardingPhase>('CHECKING');
  const [statusMessage, setStatusMessage] = useState('Memeriksa status perangkat...');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Password input state
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [passwordError, setPasswordError] = useState<string | null>(null);

  // Data dari backend & lokal
  const [backendStatus, setBackendStatus] = useState<BchatDeviceStatusData | null>(null);
  const [existingActiveDevice, setExistingActiveDevice] = useState<BchatActiveDeviceInfo | null>(null);
  const [stagedKey, setStagedKey] = useState<StagedKeyInfo | null>(null);
  const [registeredDeviceUuid, setRegisteredDeviceUuid] = useState<string | null>(null);

  // Guard pengiriman ganda & internal worker
  const isSubmittingRef = useRef(false);
  const sessionRef = useRef(0);
  const internalWorkerRef = useRef<BchatWorkerClient | null>(null);

  // Bersihkan worker saat unmount
  useEffect(() => {
    return () => {
      if (internalWorkerRef.current) {
        internalWorkerRef.current.terminate();
        internalWorkerRef.current = null;
      }
    };
  }, []);

  const deviceName = defaultDeviceName || `${getBrowserName()} Device`;

  // ---------------------------------------------------------------------------
  // HELPER: Inisialisasi Worker & Publikasi KeyPackage
  // ---------------------------------------------------------------------------

  const runKeyPackagePublish = useCallback(
    async (devUuid: string, session: number) => {
      // 1. Cek apakah di storage lokal sudah ada KeyPackage aktif & terpublikasi
      setStatusMessage('Memeriksa ketersediaan paket kunci E2EE...');
      const existingCount = await countActiveKeyPackagesInStorage(devUuid);
      if (existingCount >= 1) {
        if (session !== sessionRef.current) return;
        setRegisteredDeviceUuid(devUuid);
        setPhase('DEVICE_READY');
        onDeviceReady(devUuid);
        return;
      }

      try {
        // 2. Inisialisasi MLS Worker
        setPhase('INITIALIZING_WORKER');
        setStatusMessage('Menginisialisasi MLS Worker RFC 9420...');

        // Ambil tab lease untuk fencing
        const existingLease = await idbGet<TabLeaseRecord>(BCAT_STORES.TAB_LEASES, devUuid);
        const nextGen = existingLease?.generation
          ? String(BigInt(existingLease.generation) + BigInt(1))
          : '1';
        const ownerToken = `onboard_${typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : Date.now()}`;

        const leaseRecord: TabLeaseRecord = {
          device_uuid: devUuid,
          owner_token: ownerToken,
          generation: nextGen,
          acquired_at: Date.now(),
          expires_at: Date.now() + 3600000,
        };
        await idbPut(BCAT_STORES.TAB_LEASES, leaseRecord);

        const fencing = { ownerToken, generation: nextGen };

        // Siapkan client worker
        let client = workerRpc || internalWorkerRef.current;
        if (!client) {
          client = createBchatWorkerClient();
          internalWorkerRef.current = client;
        }

        // Ambil identitas user
        let userRole = currentUser?.role;
        let userEmail = currentUser?.email;
        if (!userRole || !userEmail) {
          try {
            const userRes = await axios.get('/api/user');
            const u = userRes.data?.data || userRes.data;
            if (u) {
              userRole = userRole || u.role;
              userEmail = userEmail || u.email;
            }
          } catch {
            // ignore
          }
        }
        userRole = userRole || 'mahasiswa';
        userEmail = userEmail || 'user@bchat.internal';

        await client.sendRequest('INIT_PEER', {
          role: userRole,
          identity: userEmail,
          deviceUuid: devUuid,
          fencing,
        });

        if (session !== sessionRef.current) return;

        // 3. Pembangkitan KeyPackage
        setPhase('GENERATING_KEYPACKAGES');
        setStatusMessage('Membangkitkan paket kunci MLS (RFC 9420)...');

        // 4. Publikasi ke Backend
        setPhase('PUBLISHING_KEYPACKAGES');
        setStatusMessage('Mempublikasikan paket kunci ke server...');

        await publishKeyPackagesFlow(client, devUuid, 5, {
          fencing,
          deviceUuid: devUuid,
        });

        if (session !== sessionRef.current) return;

        // Bersihkan worker internal jika dibuat lokal
        if (internalWorkerRef.current && !workerRpc) {
          internalWorkerRef.current.terminate();
          internalWorkerRef.current = null;
        }

        setRegisteredDeviceUuid(devUuid);
        setPhase('DEVICE_READY');
        onDeviceReady(devUuid);
      } catch (err) {
        if (session !== sessionRef.current) return;
        if (internalWorkerRef.current && !workerRpc) {
          internalWorkerRef.current.terminate();
          internalWorkerRef.current = null;
        }
        // KeyPackage gagal → jangan registrasi ulang
        setPhase('KEYPACKAGE_PUBLISH_FAILED');
        setErrorMessage(
          err instanceof Error
            ? `Gagal mempublikasikan paket kunci: ${err.message}`
            : 'Gagal mempublikasikan paket kunci MLS.'
        );
      }
    },
    [currentUser, onDeviceReady, workerRpc]
  );

  // ---------------------------------------------------------------------------
  // FASE PEMERIKSAAN AWAL
  // ---------------------------------------------------------------------------

  const runInitialCheck = useCallback(async () => {
    sessionRef.current += 1;
    const session = sessionRef.current;

    setPhase('CHECKING');
    setStatusMessage('Memeriksa status perangkat...');
    setErrorMessage(null);

    try {
      // 1. Cek lokal IndexedDB
      setStatusMessage('Membaca identitas lokal...');
      const localDevice = await findActiveLocalDevice();
      if (session !== sessionRef.current) return;

      // 2. Ambil status dari backend
      setStatusMessage('Memeriksa status backend...');
      let backendRes: BchatDeviceStatusData;
      try {
        const statusRes = await getDeviceStatus(
          localDevice ? { device_uuid: localDevice.deviceUuid } : undefined
        );
        backendRes = statusRes.data;
      } catch (err) {
        if (session !== sessionRef.current) return;
        // Jaringan/server tidak dapat diakses
        const isNetwork = err instanceof BchatApiError && err.status === 0;
        setPhase('TRANSIENT_ERROR');
        setErrorMessage(
          isNetwork
            ? 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda dan coba kembali.'
            : 'Gagal mengambil status perangkat dari server. Coba kembali beberapa saat lagi.'
        );
        return;
      }

      if (session !== sessionRef.current) return;
      setBackendStatus(backendRes);

      // 3. Analisis kondisi
      if (localDevice) {
        const localUuid = localDevice.deviceUuid.toLowerCase();

        if (backendRes.has_active_device && backendRes.active_device) {
          const backendActiveUuid = backendRes.active_device.device_uuid.toLowerCase();

          if (backendActiveUuid === localUuid) {
            // ✅ Kondisi A: lokal ACTIVE = backend ACTIVE
            setRegisteredDeviceUuid(localDevice.deviceUuid);
            await runKeyPackagePublish(localDevice.deviceUuid, session);
            return;
          } else {
            // ❌ Kondisi C: ada active device LAIN di backend → perlu replacement
            setExistingActiveDevice(backendRes.active_device);
            setPhase('NEEDS_REPLACEMENT_CONFIRM');
            setStatusMessage('');
            return;
          }
        } else if (!backendRes.has_active_device) {
          // Backend tidak punya active device, tapi lokal ada "active" key
          // Ini seharusnya tidak terjadi, tapi kita anggap perlu registrasi ulang
          setPhase('NEEDS_REGISTRATION');
          setStatusMessage('');
          return;
        }
      }

      // Tidak ada lokal device — cek apakah ada staged key yang bisa direkonsiliasi
      const staged = await findStagedDeviceInKeystore();
      if (session !== sessionRef.current) return;

      if (staged) {
        setStagedKey(staged);
        setStatusMessage('Memeriksa registrasi sebelumnya...');
        setPhase('RECONCILING');

        // Cek apakah staged UUID sudah ACTIVE di backend
        try {
          const reconRes = await getDeviceStatus({ device_uuid: staged.deviceUuid });
          if (session !== sessionRef.current) return;

          const queried = reconRes.data.queried_device;
          if (queried?.is_active) {
            // ✅ Kondisi D: staged UUID sudah ACTIVE → promosikan lokal
            setStatusMessage('Merekonsiliasi registrasi sebelumnya...');
            const activeInfo = reconRes.data.active_device;
            await promoteStagedIdentityKeyToActive(
              staged.deviceUuid,
              activeInfo?.key_version ?? 1,
              activeInfo?.status ?? 'ACTIVE',
              activeInfo?.registered_at ?? new Date().toISOString()
            );
            if (session !== sessionRef.current) return;
            setRegisteredDeviceUuid(staged.deviceUuid);
            setStagedKey(null);
            await runKeyPackagePublish(staged.deviceUuid, session);
            return;
          } else if (reconRes.data.has_active_device && reconRes.data.active_device) {
            // Backend punya active device lain, staged belum terdaftar
            setExistingActiveDevice(reconRes.data.active_device);
            setPhase('NEEDS_REPLACEMENT_CONFIRM');
            setStatusMessage('');
            return;
          } else {
            // Staged belum terdaftar & belum ada active → perlu registrasi
            setPhase('NEEDS_REGISTRATION');
            setStatusMessage('');
            return;
          }
        } catch {
          if (session !== sessionRef.current) return;
          // Jaringan gagal → pertahankan staged key, tampilkan error retry
          setPhase('TRANSIENT_ERROR');
          setErrorMessage(
            'Tidak dapat memeriksa status registrasi sebelumnya. Staged key dipertahankan. Coba kembali.'
          );
          return;
        }
      }

      // Tidak ada lokal, tidak ada staged → perlu registrasi baru
      if (backendRes.has_active_device && backendRes.active_device) {
        // Ada active device lain di backend (browser lain)
        setExistingActiveDevice(backendRes.active_device);
        setPhase('NEEDS_REPLACEMENT_CONFIRM');
      } else {
        setPhase('NEEDS_REGISTRATION');
      }
      setStatusMessage('');
    } catch (err) {
      if (session !== sessionRef.current) return;
      setPhase('TRANSIENT_ERROR');
      setErrorMessage(
        (err instanceof Error ? err.message : 'Terjadi kesalahan tidak terduga saat pemeriksaan awal.')
      );
    }
  }, [onDeviceReady, runKeyPackagePublish]);

  // Jalankan pemeriksaan saat mount
  useEffect(() => {
    runInitialCheck();
  }, []);

  // Saat phase DEVICE_READY, trigger callback
  useEffect(() => {
    if (phase === 'DEVICE_READY' && registeredDeviceUuid) {
      onDeviceReady(registeredDeviceUuid);
    }
  }, [phase, registeredDeviceUuid, onDeviceReady]);

  // ---------------------------------------------------------------------------
  // SUBMIT REGISTRASI BARU
  // ---------------------------------------------------------------------------

  const handleRegisterSubmit = useCallback(async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (isSubmittingRef.current) return;

    const pwd = password.trim();
    setPasswordError(null);
    if (!pwd) {
      setPasswordError('Password wajib diisi untuk registrasi perangkat.');
      return;
    }

    isSubmittingRef.current = true;
    sessionRef.current += 1;
    const session = sessionRef.current;

    try {
      setPhase('REGISTRATION_IN_PROGRESS');
      setStatusMessage('Membangkitkan identitas kriptografi...');
      setErrorMessage(null);

      const result = await registerDeviceFlow({
        password: pwd,
        deviceName,
        confirmReplacement: false,
      });

      // Clear password dari memori
      setPassword('');

      if (session !== sessionRef.current) return;

      setRegisteredDeviceUuid(result.deviceUuid);
      setStatusMessage('Perangkat berhasil didaftarkan. Mempublikasikan paket kunci...');
      setPhase('PUBLISHING_KEYPACKAGES');

      await runKeyPackagePublish(result.deviceUuid, session);
    } catch (err) {
      if (session !== sessionRef.current) return;
      setPassword(''); // Clear password on error too
      handleRegistrationError(err);
    } finally {
      isSubmittingRef.current = false;
    }
  }, [password, deviceName, runKeyPackagePublish]);

  // ---------------------------------------------------------------------------
  // SUBMIT REPLACEMENT
  // ---------------------------------------------------------------------------

  const handleReplacementSubmit = useCallback(async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (isSubmittingRef.current) return;
    if (!existingActiveDevice) return;

    const pwd = password.trim();
    setPasswordError(null);
    if (!pwd) {
      setPasswordError('Password wajib diisi untuk mengganti perangkat.');
      return;
    }

    isSubmittingRef.current = true;
    sessionRef.current += 1;
    const session = sessionRef.current;

    try {
      setPhase('REPLACEMENT_IN_PROGRESS');
      setStatusMessage('Membangkitkan identitas kriptografi baru...');
      setErrorMessage(null);

      const result = await registerDeviceFlow({
        password: pwd,
        deviceName,
        confirmReplacement: true,
        expectedActiveDeviceUuid: existingActiveDevice.device_uuid,
      });

      setPassword('');
      if (session !== sessionRef.current) return;

      setRegisteredDeviceUuid(result.deviceUuid);
      setExistingActiveDevice(null);
      setStatusMessage('Perangkat berhasil didaftarkan. Mempublikasikan paket kunci...');
      setPhase('PUBLISHING_KEYPACKAGES');

      await runKeyPackagePublish(result.deviceUuid, session);
    } catch (err) {
      if (session !== sessionRef.current) return;
      setPassword('');

      // HTTP 409: UUID sudah stale (perangkat berubah sejak kita cek)
      if (err instanceof BchatApiError && err.status === 409) {
        const code = err.errorCode;
        if (code === 'EXPECTED_DEVICE_UUID_MISMATCH') {
          // Re-fetch status untuk tampilkan kondisi terbaru
          setStatusMessage('UUID perangkat berubah. Memperbarui status...');
          setPhase('CHECKING');
          await runInitialCheck();
          return;
        }
        if (code === 'REPLACEMENT_CONFIRMATION_REQUIRED') {
          setPhase('NEEDS_REPLACEMENT_CONFIRM');
          setErrorMessage('Konfirmasi penggantian perangkat diperlukan.');
          return;
        }
      }

      handleRegistrationError(err);
    } finally {
      isSubmittingRef.current = false;
    }
  }, [password, deviceName, existingActiveDevice, runInitialCheck, runKeyPackagePublish]);

  // ---------------------------------------------------------------------------
  // HELPER: Retry publikasi KeyPackage saja
  // ---------------------------------------------------------------------------

  const handleRetryKeyPackage = useCallback(async () => {
    if (isSubmittingRef.current || !registeredDeviceUuid) return;
    isSubmittingRef.current = true;
    sessionRef.current += 1;
    const session = sessionRef.current;

    try {
      await runKeyPackagePublish(registeredDeviceUuid, session);
    } finally {
      isSubmittingRef.current = false;
    }
  }, [registeredDeviceUuid, runKeyPackagePublish]);

  // ---------------------------------------------------------------------------
  // HELPER: Tangani error registrasi
  // ---------------------------------------------------------------------------

  const handleRegistrationError = (err: unknown) => {
    if (err instanceof BchatApiError) {
      if (err.status === 401) {
        setPhase('PERMANENT_ERROR');
        setErrorMessage('Sesi login telah berakhir. Silakan login kembali.');
        return;
      }
      if (err.status === 403 || err.errorCode === 'WRONG_PASSWORD') {
        setPhase('NEEDS_PASSWORD');
        setPasswordError('Password salah. Coba kembali.');
        return;
      }
      if (err.status === 0) {
        setPhase('TRANSIENT_ERROR');
        setErrorMessage('Koneksi terputus saat registrasi. Staged key dipertahankan. Coba kembali.');
        return;
      }
      setPhase('TRANSIENT_ERROR');
      setErrorMessage(err.message || 'Registrasi gagal. Coba kembali.');
      return;
    }
    if (err instanceof BchatDeviceRegistrationError) {
      if (err.errorCode === 'PROMOTION_FAILED_AFTER_BACKEND_SUCCESS') {
        // Backend sudah daftarkan, tapi lokal gagal dipromosikan → rekonsiliasi
        setPhase('RECONCILING');
        setStatusMessage('Merekonsiliasi...');
        runInitialCheck();
        return;
      }
      setPhase('TRANSIENT_ERROR');
      setErrorMessage(err.message || 'Terjadi kesalahan registrasi lokal.');
      return;
    }
    setPhase('TRANSIENT_ERROR');
    setErrorMessage(
      err instanceof Error ? err.message : 'Terjadi kesalahan tidak terduga.'
    );
  };

  // ---------------------------------------------------------------------------
  // RENDER
  // ---------------------------------------------------------------------------

  return (
    <div className="flex flex-col items-center justify-center min-h-[320px] p-6 bg-white rounded-2xl border border-slate-200 shadow-sm max-w-md mx-auto">
      {/* CHECKING */}
      {(phase === 'CHECKING' || phase === 'RECONCILING') && (
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="relative">
            <ShieldCheck className="w-12 h-12 text-blue-500" />
            <Loader2 className="w-5 h-5 text-blue-400 animate-spin absolute -bottom-1 -right-1" />
          </div>
          <p className="text-sm font-medium text-slate-700">{statusMessage}</p>
          <p className="text-xs text-slate-400">Proses ini berlangsung beberapa detik...</p>
        </div>
      )}

      {/* DEVICE_READY */}
      {phase === 'DEVICE_READY' && (
        <div className="flex flex-col items-center gap-4 text-center">
          <CheckCircle2 className="w-12 h-12 text-green-500" />
          <p className="text-sm font-semibold text-slate-700">Perangkat siap</p>
          <p className="text-xs text-slate-400">Memuat ruang chat...</p>
        </div>
      )}

      {/* INITIALIZING_WORKER */}
      {phase === 'INITIALIZING_WORKER' && (
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="relative">
            <Shield className="w-12 h-12 text-indigo-500" />
            <Loader2 className="w-5 h-5 text-indigo-400 animate-spin absolute -bottom-1 -right-1" />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-700">Inisialisasi MLS Worker</p>
            <p className="text-xs text-slate-500 mt-1">{statusMessage}</p>
          </div>
          <p className="text-[11px] text-slate-400">Menyiapkan modul kriptografi RFC 9420...</p>
        </div>
      )}

      {/* GENERATING_KEYPACKAGES */}
      {phase === 'GENERATING_KEYPACKAGES' && (
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="relative">
            <KeyRound className="w-12 h-12 text-indigo-500" />
            <Loader2 className="w-5 h-5 text-indigo-400 animate-spin absolute -bottom-1 -right-1" />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-700">Pembuatan Paket Kunci</p>
            <p className="text-xs text-slate-500 mt-1">{statusMessage}</p>
          </div>
          <p className="text-[11px] text-slate-400">Kunci privat disimpan terenkripsi di browser...</p>
        </div>
      )}

      {/* PUBLISHING_KEYPACKAGES */}
      {phase === 'PUBLISHING_KEYPACKAGES' && (
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="relative">
            <ShieldCheck className="w-12 h-12 text-indigo-500" />
            <Loader2 className="w-5 h-5 text-indigo-400 animate-spin absolute -bottom-1 -right-1" />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-700">Publikasi Paket Kunci</p>
            <p className="text-xs text-slate-500 mt-1">{statusMessage}</p>
          </div>
          <p className="text-[11px] text-slate-400">Mengunggah paket kunci publik ke server...</p>
        </div>
      )}

      {/* REGISTRATION/STEP_UP IN PROGRESS */}
      {(phase === 'REGISTRATION_IN_PROGRESS' || phase === 'STEP_UP_IN_PROGRESS' || phase === 'REPLACEMENT_IN_PROGRESS') && (
        <div className="flex flex-col items-center gap-4 text-center">
          <div className="relative">
            <KeyRound className="w-12 h-12 text-indigo-500" />
            <Loader2 className="w-5 h-5 text-indigo-400 animate-spin absolute -bottom-1 -right-1" />
          </div>
          <p className="text-sm font-medium text-slate-700">{statusMessage || 'Mendaftarkan perangkat...'}</p>
          <p className="text-xs text-slate-400">Jangan tutup tab ini.</p>
        </div>
      )}

      {/* NEEDS_REGISTRATION */}
      {phase === 'NEEDS_REGISTRATION' && (
        <div className="w-full flex flex-col gap-4">
          <div className="flex items-start gap-3">
            <Smartphone className="w-8 h-8 text-blue-500 flex-shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-semibold text-slate-800">Daftarkan Browser Ini</p>
              <p className="text-xs text-slate-500 mt-1">
                Browser ini belum memiliki identitas perangkat B-CHAT E2EE. Daftar sekali untuk mengaktifkan enkripsi ujung-ke-ujung.
              </p>
            </div>
          </div>

          <form onSubmit={handleRegisterSubmit} className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-slate-600">Password Akun</label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => { setPassword(e.target.value); setPasswordError(null); }}
                  placeholder="Masukkan password akun Anda"
                  autoComplete="current-password"
                  className="w-full px-3 py-2 pr-10 text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                  tabIndex={-1}
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {passwordError && (
                <p className="text-xs text-red-500">{passwordError}</p>
              )}
            </div>

            <div className="flex gap-2">
              <button
                type="submit"
                disabled={isSubmittingRef.current}
                className="flex-1 py-2 px-4 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-lg transition"
              >
                Daftarkan Perangkat
              </button>
              {onCancel && (
                <button
                  type="button"
                  onClick={onCancel}
                  className="py-2 px-4 bg-white hover:bg-slate-50 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg transition"
                >
                  Batal
                </button>
              )}
            </div>
          </form>

          <p className="text-[11px] text-slate-400 text-center">
            Password digunakan sekali untuk otorisasi kriptografi dan tidak disimpan.
          </p>
        </div>
      )}

      {/* NEEDS_REPLACEMENT_CONFIRM */}
      {phase === 'NEEDS_REPLACEMENT_CONFIRM' && existingActiveDevice && (
        <div className="w-full flex flex-col gap-4">
          <div className="flex items-start gap-3">
            <AlertTriangle className="w-8 h-8 text-amber-500 flex-shrink-0 mt-0.5" />
            <div>
              <p className="text-sm font-semibold text-slate-800">Perangkat Aktif Sudah Ada</p>
              <p className="text-xs text-slate-500 mt-1">
                Akun Anda sudah memiliki perangkat aktif. Mendaftar dari browser ini akan <span className="font-medium text-amber-700">mencabut</span> perangkat tersebut.
              </p>
            </div>
          </div>

          {/* Info perangkat lama */}
          <div className="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800 space-y-1">
            <p><span className="font-medium">Nama:</span> {existingActiveDevice.device_name}</p>
            <p><span className="font-medium">Terdaftar:</span> {new Date(existingActiveDevice.registered_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>
            <p><span className="font-medium">Status:</span> {existingActiveDevice.status}</p>
          </div>

          {errorMessage && (
            <div className="bg-red-50 border border-red-200 rounded-lg p-2.5 text-xs text-red-700">
              {errorMessage}
            </div>
          )}

          <form onSubmit={handleReplacementSubmit} className="flex flex-col gap-3">
            <div className="flex flex-col gap-1">
              <label className="text-xs font-medium text-slate-600">Password Akun (untuk konfirmasi)</label>
              <div className="relative">
                <input
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => { setPassword(e.target.value); setPasswordError(null); }}
                  placeholder="Masukkan password akun Anda"
                  autoComplete="current-password"
                  className="w-full px-3 py-2 pr-10 text-sm border border-slate-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword((v) => !v)}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                  tabIndex={-1}
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
              {passwordError && <p className="text-xs text-red-500">{passwordError}</p>}
            </div>

            <div className="flex gap-2">
              <button
                type="submit"
                disabled={isSubmittingRef.current}
                className="flex-1 py-2 px-4 bg-amber-500 hover:bg-amber-600 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-lg transition"
              >
                Ya, Ganti Perangkat
              </button>
              {onCancel && (
                <button
                  type="button"
                  onClick={onCancel}
                  className="py-2 px-4 bg-white hover:bg-slate-50 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg transition"
                >
                  Batal
                </button>
              )}
            </div>
          </form>

          <p className="text-[11px] text-slate-400 text-center">
            Setelah diganti, perangkat lama tidak dapat lagi digunakan untuk mengakses ruang chat.
          </p>
        </div>
      )}

      {/* KEYPACKAGE_PUBLISH_FAILED */}
      {phase === 'KEYPACKAGE_PUBLISH_FAILED' && (
        <div className="w-full flex flex-col gap-4 text-center">
          <ShieldAlert className="w-10 h-10 text-orange-400 mx-auto" />
          <div>
            <p className="text-sm font-semibold text-slate-800">Paket Kunci Belum Terunggah</p>
            <p className="text-xs text-slate-500 mt-1">
              Perangkat sudah terdaftar, namun gagal mengunggah paket kunci E2EE. Klik retry untuk mencoba lagi tanpa registrasi ulang.
            </p>
          </div>
          {errorMessage && (
            <p className="text-xs text-red-500 bg-red-50 border border-red-100 rounded-lg p-2">{errorMessage}</p>
          )}
          <div className="flex gap-2 justify-center">
            <button
              onClick={handleRetryKeyPackage}
              disabled={isSubmittingRef.current}
              className="inline-flex items-center gap-2 py-2 px-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-semibold rounded-lg transition"
            >
              <RefreshCw className="w-4 h-4" /> Retry Unggah Kunci
            </button>
            <button
              onClick={() => {
                // Lewati keypackage dan lanjutkan
                if (registeredDeviceUuid) {
                  setPhase('DEVICE_READY');
                  onDeviceReady(registeredDeviceUuid);
                }
              }}
              className="py-2 px-4 bg-white border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50 transition"
            >
              Lanjutkan Tanpa Kunci
            </button>
          </div>
        </div>
      )}

      {/* TRANSIENT_ERROR */}
      {phase === 'TRANSIENT_ERROR' && (
        <div className="w-full flex flex-col gap-4 text-center">
          <WifiOff className="w-10 h-10 text-slate-400 mx-auto" />
          <div>
            <p className="text-sm font-semibold text-slate-800">Gagal Sementara</p>
            <p className="text-xs text-slate-500 mt-1">{errorMessage}</p>
          </div>
          <div className="flex gap-2 justify-center">
            <button
              onClick={runInitialCheck}
              className="inline-flex items-center gap-2 py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg transition"
            >
              <RefreshCw className="w-4 h-4" /> Coba Lagi
            </button>
            {onCancel && (
              <button
                onClick={onCancel}
                className="py-2 px-4 bg-white border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50 transition"
              >
                Batal
              </button>
            )}
          </div>
        </div>
      )}

      {/* PERMANENT_ERROR */}
      {phase === 'PERMANENT_ERROR' && (
        <div className="w-full flex flex-col gap-4 text-center">
          <ShieldAlert className="w-10 h-10 text-red-500 mx-auto" />
          <div>
            <p className="text-sm font-semibold text-slate-800">Tindakan Diperlukan</p>
            <p className="text-xs text-slate-500 mt-1">{errorMessage}</p>
          </div>
          {onCancel && (
            <button
              onClick={onCancel}
              className="py-2 px-4 bg-white border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-50 transition mx-auto"
            >
              Tutup
            </button>
          )}
        </div>
      )}
    </div>
  );
}

export default BchatDeviceOnboarding;

