'use client';

/**
 * B-CHAT E2EE — Device Pairing & Trust Bootstrap Gate Component (Tahap 2A & 2B)
 *
 * Ditampilkan pada perangkat baru yang telah mendaftar namun belum memperoleh status tepercaya (is_trusted = false):
 * - Memfasilitasi pembuatan permintaan pairing dengan kode 8-karakter dan payload QR.
 * - Menampilkan kode pairing, fingerprint kunci publik target, dan hitung mundur kedaluwarsa.
 * - Melakukan polling otomatis untuk mendeteksi persetujuan kriptografis dari perangkat tepercaya.
 * - Memfasilitasi Verified Trust Bootstrap untuk perangkat aktif pertama via step-up authentication.
 * - Mengalihkan perangkat ke ruang chat aktif segera setelah disetujui.
 */

import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  ShieldAlert,
  ShieldCheck,
  KeyRound,
  Clock,
  Copy,
  Check,
  AlertTriangle,
  Loader2,
  RefreshCw,
  Lock,
  X,
} from 'lucide-react';
import {
  requestPairing,
  getPairingStatus,
  cancelPairing,
} from '@/lib/bchat/device/bchat-pairing-client';
import {
  checkTrustBootstrapEligibility,
  executeTrustBootstrapFlow,
} from '@/lib/bchat/device/bchat-trust-bootstrap-client';
import type {
  BchatPairingRequestData,
  BchatPairingStatus,
} from '@/lib/bchat/api/bchat-api-types';
import { BchatApiError } from '@/lib/bchat/api/bchat-api-client';

export interface BchatDevicePairingGateProps {
  deviceUuid: string;
  onPairingApproved: () => void;
  onCancel?: () => void;
}

export function BchatDevicePairingGate({
  deviceUuid,
  onPairingApproved,
  onCancel,
}: BchatDevicePairingGateProps) {
  const [pairingData, setPairingData] = useState<BchatPairingRequestData | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [pairingStatus, setPairingStatus] = useState<BchatPairingStatus | 'IDLE'>('IDLE');
  const [secondsLeft, setSecondsLeft] = useState<number>(300);
  const [copied, setCopied] = useState<boolean>(false);

  // Trust Bootstrap states
  const [isBootstrapEligible, setIsBootstrapEligible] = useState<boolean>(false);
  const [showBootstrapModal, setShowBootstrapModal] = useState<boolean>(false);
  const [bootstrapPassword, setBootstrapPassword] = useState<string>('');
  const [isBootstrapping, setIsBootstrapping] = useState<boolean>(false);
  const [bootstrapError, setBootstrapError] = useState<string | null>(null);
  const [bootstrapSuccess, setBootstrapSuccess] = useState<boolean>(false);

  const pollTimerRef = useRef<NodeJS.Timeout | null>(null);
  const countdownTimerRef = useRef<NodeJS.Timeout | null>(null);

  // Bersihkan seluruh timer saat komponen unmount
  const clearAllTimers = useCallback(() => {
    if (pollTimerRef.current) {
      clearInterval(pollTimerRef.current);
      pollTimerRef.current = null;
    }
    if (countdownTimerRef.current) {
      clearInterval(countdownTimerRef.current);
      countdownTimerRef.current = null;
    }
  }, []);

  useEffect(() => {
    return () => clearAllTimers();
  }, [clearAllTimers]);

  // Cek kelayakan Trust Bootstrap pada saat inisialisasi
  useEffect(() => {
    let isMounted = true;
    async function checkEligibility() {
      if (!deviceUuid) return;
      try {
        const data = await checkTrustBootstrapEligibility(deviceUuid);
        if (isMounted) {
          setIsBootstrapEligible(Boolean(data.is_eligible));
        }
      } catch {
        // Abaikan kegagalan jaringan saat cek eligibility
      }
    }

    checkEligibility();
    return () => {
      isMounted = false;
    };
  }, [deviceUuid]);

  // Buat permintaan pairing baru
  const handleStartPairing = async () => {
    clearAllTimers();
    setIsLoading(true);
    setErrorMsg(null);
    setPairingStatus('IDLE');

    try {
      const res = await requestPairing(deviceUuid);
      setPairingData(res.data);
      setPairingStatus('PENDING');
      setSecondsLeft(res.data.expires_in_seconds || 300);

      // Jalankan hitung mundur kedaluwarsa
      countdownTimerRef.current = setInterval(() => {
        setSecondsLeft((prev) => {
          if (prev <= 1) {
            clearAllTimers();
            setPairingStatus('EXPIRED');
            return 0;
          }
          return prev - 1;
        });
      }, 1000);

      // Jalankan polling status setiap 2.5 detik
      const pUuid = res.data.pairing_uuid;
      pollTimerRef.current = setInterval(async () => {
        try {
          const statusRes = await getPairingStatus(pUuid);
          const currentStatus = statusRes.data.status;

          if (currentStatus === 'APPROVED') {
            clearAllTimers();
            setPairingStatus('APPROVED');
            setTimeout(() => {
              onPairingApproved();
            }, 1200);
          } else if (currentStatus === 'REJECTED') {
            clearAllTimers();
            setPairingStatus('REJECTED');
          } else if (currentStatus === 'EXPIRED') {
            clearAllTimers();
            setPairingStatus('EXPIRED');
          } else if (currentStatus === 'CANCELED') {
            clearAllTimers();
            setPairingStatus('CANCELED');
          }
        } catch {
          // Abaikan kesalahan jaringan sementara saat polling
        }
      }, 2500);
    } catch (err) {
      if (err instanceof BchatApiError) {
        if (err.errorCode === 'NO_TRUSTED_DEVICE_AVAILABLE') {
          setErrorMsg(
            'Tidak ada perangkat tepercaya yang aktif pada akun ini. Jika ini adalah perangkat pertama Anda, gunakan tombol Verifikasi Perangkat Pertama di bawah.'
          );
        } else if (err.errorCode === 'DEVICE_ALREADY_TRUSTED') {
          onPairingApproved();
          return;
        } else {
          setErrorMsg(err.message || 'Gagal membuat permintaan pairing.');
        }
      } else {
        setErrorMsg('Terjadi kesalahan koneksi saat meminta pairing.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  // Batalkan permintaan pairing
  const handleCancelRequest = async () => {
    if (!pairingData) return;
    clearAllTimers();
    try {
      await cancelPairing(pairingData.pairing_uuid, deviceUuid);
    } catch {
      // Abaikan error pembatalan
    }
    setPairingData(null);
    setPairingStatus('IDLE');
  };

  // Salin kode ke clipboard
  const handleCopyCode = async () => {
    if (!pairingData) return;
    try {
      await navigator.clipboard.writeText(pairingData.pairing_code);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Fallback
    }
  };

  // Eksekusi Trust Bootstrap melalui dialog kata sandi
  const handleBootstrapSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!bootstrapPassword.trim()) {
      setBootstrapError('Kata sandi akun wajib diisi.');
      return;
    }

    setIsBootstrapping(true);
    setBootstrapError(null);

    try {
      await executeTrustBootstrapFlow(deviceUuid, bootstrapPassword.trim());
      setBootstrapSuccess(true);
      setShowBootstrapModal(false);
      setTimeout(() => {
        onPairingApproved();
      }, 1200);
    } catch (err: unknown) {
      if (err instanceof BchatApiError) {
        setBootstrapError(err.message || 'Verifikasi bootstrap gagal.');
      } else if (err instanceof Error) {
        setBootstrapError(err.message);
      } else {
        setBootstrapError('Terjadi kesalahan tidak terduga saat verifikasi.');
      }
    } finally {
      setIsBootstrapping(false);
    }
  };

  // Format detik ke MM:SS
  const formatTimer = (sec: number) => {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  const isApproved = pairingStatus === 'APPROVED' || bootstrapSuccess;

  return (
    <div className="flex flex-col items-center justify-center min-h-[70vh] p-6 max-w-lg mx-auto font-sans">
      <div className="w-full bg-white rounded-3xl border border-slate-200/90 shadow-xl p-6 sm:p-8 space-y-6 text-center animate-in fade-in duration-200">
        {/* Header Icon */}
        <div className="flex justify-center">
          <div
            className={`w-16 h-16 rounded-3xl flex items-center justify-center shadow-xs transition ${
              isApproved
                ? 'bg-emerald-100 text-emerald-600'
                : pairingStatus === 'REJECTED'
                ? 'bg-rose-100 text-rose-600'
                : 'bg-amber-100 text-amber-600'
            }`}
          >
            {isApproved ? (
              <ShieldCheck className="w-8 h-8 animate-in zoom-in" />
            ) : pairingStatus === 'REJECTED' ? (
              <ShieldAlert className="w-8 h-8 animate-in zoom-in" />
            ) : (
              <KeyRound className="w-8 h-8" />
            )}
          </div>
        </div>

        {/* Judul & Penjelasan */}
        <div>
          <h2 className="text-lg font-bold text-slate-900 leading-tight">
            {isApproved
              ? 'Perangkat Berhasil Diotorisasi!'
              : pairingStatus === 'REJECTED'
              ? 'Permintaan Pairing Ditolak'
              : pairingStatus === 'EXPIRED'
              ? 'Sesi Pairing Kedaluwarsa'
              : 'Otorisasi Perangkat B-CHAT'}
          </h2>
          <p className="text-xs text-slate-500 mt-1.5 leading-relaxed">
            {isApproved
              ? 'Kunci identitas perangkat telah diverifikasi dan disetujui. Membuka ruang obrolan...'
              : pairingStatus === 'REJECTED'
              ? 'Permintaan pairing ditolak oleh perangkat tepercaya Anda.'
              : pairingStatus === 'EXPIRED'
              ? 'Waktu pairing telah habis. Silakan buat permintaan baru.'
              : isBootstrapEligible
              ? 'Perangkat ini adalah perangkat aktif pertama Anda. Verifikasi dengan kata sandi akun untuk menetapkannya sebagai perangkat tepercaya utama.'
              : 'Akun Anda sudah memiliki perangkat aktif lain. Demi keamanan E2EE, perangkat baru ini harus disetujui melalui perangkat aktif Anda yang sudah tepercaya.'}
          </p>
        </div>

        {/* Banner Error */}
        {errorMsg && (
          <div className="p-3 bg-rose-50 border border-rose-200 rounded-2xl flex items-center gap-2.5 text-xs text-rose-800 text-left">
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{errorMsg}</span>
          </div>
        )}

        {/* Tampilan Status PENDING: Tampilkan Kode Pairing */}
        {pairingStatus === 'PENDING' && pairingData && (
          <div className="space-y-4 animate-in fade-in">
            {/* Box Kode Pairing */}
            <div className="p-5 bg-emerald-50/70 border border-emerald-200 rounded-2xl space-y-2">
              <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-800">
                Kode Pairing Anda
              </span>
              <div className="flex items-center justify-center gap-3">
                <span className="text-3xl font-extrabold font-mono tracking-widest text-emerald-950 select-all">
                  {pairingData.pairing_code}
                </span>
                <button
                  type="button"
                  onClick={handleCopyCode}
                  className="p-2 bg-white hover:bg-emerald-100 text-emerald-700 border border-emerald-300 rounded-xl transition cursor-pointer shadow-xs"
                  title="Salin kode pairing"
                >
                  {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                </button>
              </div>

              {/* Timer & Polling Indicator */}
              <div className="flex items-center justify-center gap-4 text-xs pt-1">
                <span className="inline-flex items-center gap-1.5 font-bold text-emerald-700">
                  <Clock className="w-3.5 h-3.5" />
                  {formatTimer(secondsLeft)}
                </span>
                <span className="text-slate-300">•</span>
                <span className="inline-flex items-center gap-1.5 text-slate-500 font-medium">
                  <Loader2 className="w-3 h-3 animate-spin text-emerald-600" />
                  Menunggu persetujuan...
                </span>
              </div>
            </div>

            {/* Fingerprint Kunci Publik untuk Pencocokan Manual */}
            <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl text-left space-y-1">
              <span className="text-[10px] font-bold text-slate-500 uppercase tracking-wide">
                Fingerprint Kunci Publik (SHA-256)
              </span>
              <p className="font-mono text-[10px] text-slate-700 break-all bg-white p-2 rounded-lg border border-slate-200 select-all">
                {pairingData.target_public_key_fingerprint}
              </p>
            </div>

            {/* Petunjuk Pengguna */}
            <div className="p-3.5 bg-slate-50 rounded-2xl text-[11px] text-slate-600 text-left space-y-1.5 border border-slate-200/60">
              <p className="font-semibold text-slate-800">Langkah Otorisasi:</p>
              <ol className="list-decimal pl-4 space-y-1 text-slate-600">
                <li>Buka B-CHAT pada perangkat tepercaya Anda (laptop / ponsel).</li>
                <li>Klik tombol <strong>Perangkat</strong> di header obrolan.</li>
                <li>Pilih <strong>Tautkan Perangkat Baru</strong> dan masukkan kode di atas.</li>
                <li>Cocokkan fingerprint kunci dan klik <strong>Setujui Perangkat</strong>.</li>
              </ol>
            </div>

            {/* Tombol Batalkan */}
            <div className="pt-2">
              <button
                type="button"
                onClick={handleCancelRequest}
                className="text-xs font-semibold text-slate-500 hover:text-rose-600 transition cursor-pointer"
              >
                Batalkan Permintaan
              </button>
            </div>
          </div>
        )}

        {/* Tampilan Awal (IDLE) */}
        {pairingStatus === 'IDLE' && !isApproved && (
          <div className="space-y-4">
            {/* Opsi 1: Trust Bootstrap jika eligible */}
            {isBootstrapEligible && (
              <div className="p-4 bg-amber-50/80 border border-amber-200/90 rounded-2xl text-left space-y-3 animate-in fade-in">
                <div className="flex items-start gap-2.5">
                  <div className="w-8 h-8 rounded-xl bg-amber-100 flex items-center justify-center shrink-0 text-amber-700">
                    <KeyRound className="w-4 h-4" />
                  </div>
                  <div>
                    <h4 className="text-xs font-bold text-amber-950">
                      Perangkat Aktif Pertama (Belum Tepercaya)
                    </h4>
                    <p className="text-[11px] text-amber-800 leading-relaxed mt-0.5">
                      Akun Anda belum memiliki perangkat tepercaya. Anda dapat menetapkan perangkat ini sebagai perangkat utama (Trust Root) menggunakan kata sandi akun Anda.
                    </p>
                  </div>
                </div>

                <button
                  type="button"
                  onClick={() => {
                    setShowBootstrapModal(true);
                    setBootstrapError(null);
                    setBootstrapPassword('');
                  }}
                  disabled={isLoading || isBootstrapping}
                  className="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-xl transition cursor-pointer shadow-xs flex items-center justify-center gap-2 disabled:opacity-50"
                >
                  <Lock className="w-3.5 h-3.5" />
                  <span>Verifikasi Perangkat Pertama (Trust Bootstrap)</span>
                </button>
              </div>
            )}

            {isBootstrapEligible && (
              <div className="relative my-2">
                <div className="absolute inset-0 flex items-center">
                  <div className="w-full border-t border-slate-200" />
                </div>
                <div className="relative flex justify-center text-[10px] uppercase font-semibold text-slate-400">
                  <span className="bg-white px-2">Atau Gunakan Pairing</span>
                </div>
              </div>
            )}

            {/* Opsi 2: Permintaan Pairing Standar */}
            <button
              type="button"
              onClick={handleStartPairing}
              disabled={isLoading || isBootstrapping}
              className={`w-full py-3 text-xs font-bold rounded-2xl transition cursor-pointer shadow-sm flex items-center justify-center gap-2 disabled:opacity-50 ${
                isBootstrapEligible
                  ? 'bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200'
                  : 'bg-emerald-600 hover:bg-emerald-700 text-white'
              }`}
            >
              {isLoading ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>Membuat Permintaan...</span>
                </>
              ) : (
                <>
                  <KeyRound className="w-4 h-4" />
                  <span>Minta Kode Pairing</span>
                </>
              )}
            </button>
          </div>
        )}

        {/* Tampilan Saat Expired / Rejected / Canceled: Tombol Coba Lagi */}
        {(pairingStatus === 'EXPIRED' || pairingStatus === 'REJECTED' || pairingStatus === 'CANCELED') && (
          <div className="space-y-3">
            <button
              type="button"
              onClick={handleStartPairing}
              disabled={isLoading || isBootstrapping}
              className="w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-2xl transition cursor-pointer shadow-sm flex items-center justify-center gap-2"
            >
              <RefreshCw className="w-4 h-4" />
              <span>Buat Permintaan Baru</span>
            </button>
          </div>
        )}

        {/* Tombol Kembali (jika ada) */}
        {onCancel && pairingStatus !== 'PENDING' && (
          <div className="pt-2">
            <button
              type="button"
              onClick={onCancel}
              className="text-xs font-medium text-slate-400 hover:text-slate-600 transition"
            >
              Kembali ke Halaman Sebelumnya
            </button>
          </div>
        )}
      </div>

      {/* Modal Dialog Step-Up Authentication Trust Bootstrap */}
      {showBootstrapModal && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs font-sans animate-in fade-in duration-200"
          role="dialog"
          aria-modal="true"
        >
          <div className="relative w-full max-w-md bg-white rounded-3xl shadow-2xl border border-slate-200/90 overflow-hidden flex flex-col p-6 space-y-4 animate-in zoom-in-95 duration-200">
            <div className="flex items-start justify-between">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center shadow-xs">
                  <Lock className="w-5 h-5 text-amber-600" />
                </div>
                <div>
                  <h3 className="text-sm font-bold text-slate-900">
                    Verifikasi Perangkat Pertama
                  </h3>
                  <p className="text-[11px] text-slate-500">
                    Step-Up Authentication & Bukti Kunci Kriptografis
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => {
                  if (!isBootstrapping) setShowBootstrapModal(false);
                }}
                disabled={isBootstrapping}
                className="p-1 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition cursor-pointer disabled:opacity-50"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            <p className="text-xs text-slate-600 leading-relaxed text-left">
              Masukkan kata sandi akun Anda. Browser akan menandatangani tantangan kriptografis sekali pakai menggunakan kunci privat lokal untuk membuktikan kepemilikan perangkat.
            </p>

            {bootstrapError && (
              <div className="p-3 bg-rose-50 border border-rose-200 rounded-xl flex items-center gap-2.5 text-xs text-rose-800 text-left">
                <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                <span>{bootstrapError}</span>
              </div>
            )}

            <form onSubmit={handleBootstrapSubmit} className="space-y-4 pt-1">
              <div className="space-y-1.5 text-left">
                <label className="text-[11px] font-semibold text-slate-700">
                  Kata Sandi Akun
                </label>
                <input
                  type="password"
                  value={bootstrapPassword}
                  onChange={(e) => setBootstrapPassword(e.target.value)}
                  placeholder="Masukkan kata sandi login Anda"
                  disabled={isBootstrapping}
                  autoFocus
                  required
                  className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-500 transition disabled:opacity-50"
                />
              </div>

              <div className="flex items-center justify-end gap-2.5 pt-2">
                <button
                  type="button"
                  onClick={() => setShowBootstrapModal(false)}
                  disabled={isBootstrapping}
                  className="px-4 py-2.5 text-xs font-semibold text-slate-600 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition cursor-pointer disabled:opacity-50"
                >
                  Batal
                </button>
                <button
                  type="submit"
                  disabled={isBootstrapping || !bootstrapPassword.trim()}
                  className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl transition cursor-pointer shadow-xs flex items-center gap-2 disabled:opacity-50"
                >
                  {isBootstrapping ? (
                    <>
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                      <span>Memverifikasi...</span>
                    </>
                  ) : (
                    <>
                      <ShieldCheck className="w-3.5 h-3.5" />
                      <span>Verifikasi & Aktifkan</span>
                    </>
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
