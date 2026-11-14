'use client';

/**
 * B-CHAT E2EE — KEK Device Isolation Test Runner (K01–K03)
 *
 * Halaman verifikasi browser runtime Tahap H-I3-PRE:
 * TEST-K01: Isolasi Dua Perangkat (Device A dan B dalam keystore yang sama).
 * TEST-K02: Orphan KEK Tanpa Metadata Device (Device C mengabaikan entri tanpa metadata).
 * TEST-K03: Isolasi Rotasi KEK (Rotasi A tidak menyentuh B dan orphan; rotasi B tidak menyentuh A dan orphan).
 */

import React, { useState, useEffect } from 'react';
import {
  BCAT_DB_NAME,
  BCAT_DB_VERSION,
  BCAT_STORES,
  type KeystoreRecord,
} from '@/lib/bchat/storage/bchat-idb-schema';
import {
  openBchatDatabase,
  closeBchatDatabase,
  withReadonlyTransaction,
  idbPut,
  idbGet,
} from '@/lib/bchat/storage/bchat-idb-core';
import {
  getOrCreateActiveKek,
  rotateActiveKek,
} from '@/lib/bchat/storage/bchat-crypto-helper';

interface TestCaseStatus {
  id: string;
  name: string;
  status: 'PENDING' | 'RUNNING' | 'PASS' | 'FAIL';
  details?: string;
}

interface TestLog {
  timestamp: string;
  level: 'info' | 'success' | 'warn' | 'error';
  message: string;
}

const INITIAL_TEST_CASES: TestCaseStatus[] = [
  { id: 'TEST-K01', name: 'Isolasi Dua Perangkat dalam Keystore Bersama (Device A & B)', status: 'PENDING' },
  { id: 'TEST-K02', name: 'Penolakan Adopsi Orphan KEK Tanpa Metadata Device (Device C)', status: 'PENDING' },
  { id: 'TEST-K03', name: 'Isolasi Rotasi KEK Multidevice & Perlindungan Entri Orphan', status: 'PENDING' },
];

export default function BchatKekIsolationTestPage() {
  const [testCases, setTestCases] = useState<TestCaseStatus[]>(INITIAL_TEST_CASES);
  const [logs, setLogs] = useState<TestLog[]>([]);
  const [isRunning, setIsRunning] = useState<boolean>(false);
  const [overallResult, setOverallResult] = useState<'IDLE' | 'RUNNING' | 'SUCCESS' | 'FAILED'>('IDLE');

  const addLog = (message: string, level: TestLog['level'] = 'info') => {
    const timestamp = new Date().toLocaleTimeString();
    setLogs((prev) => [...prev, { timestamp, level, message }]);
  };

  const updateTc = (id: string, status: TestCaseStatus['status'], details?: string) => {
    setTestCases((prev) =>
      prev.map((tc) => (tc.id === id ? { ...tc, status, details: details || tc.details } : tc))
    );
  };

  // Expose status ke window agar CDP headless runner dapat membacanya langsung
  useEffect(() => {
    if (typeof window !== 'undefined') {
      (window as any).__KEK_ISOLATION_TEST_RESULTS__ = {
        overallResult,
        testCases,
        logs,
      };
      (window as any).__RUN_TESTS__ = runAllTests;
    }
  }, [overallResult, testCases, logs]);

  const runAllTests = async () => {
    if (isRunning) return;
    setIsRunning(true);
    setOverallResult('RUNNING');
    setTestCases(INITIAL_TEST_CASES);
    setLogs([]);

    addLog('=== MEMULAI RUNTIME TEST SUITE H-I3-PRE (ISOLASI KEK K01–K03) ===', 'info');

    try {
      const db = await openBchatDatabase();
      addLog(`Database '${db.name}' v${db.version} siap digunakan.`, 'info');

      // -----------------------------------------------------------------------
      // TEST-K01: Isolasi Dua Perangkat (Device A & B)
      // -----------------------------------------------------------------------
      updateTc('TEST-K01', 'RUNNING');
      const devA = '550e8400-e29b-41d4-a716-4466554400a1';
      const devB = '550e8400-e29b-41d4-a716-4466554400b1';
      addLog(`[TEST-K01] Menguji isolasi KEK antara Device A (${devA}) dan Device B (${devB})...`, 'info');

      const kekA = await getOrCreateActiveKek(devA);
      const kekB = await getOrCreateActiveKek(devB);

      // Asersi 1: key_id berbeda
      if (kekA.key_id === kekB.key_id) {
        throw new Error('TEST-K01: PELANGGARAN ISOLASI: Device A dan Device B memperoleh key_id yang sama!');
      }

      // Asersi 2: Metadata masing-masing mengikat UUID perangkat yang benar
      if (kekA.metadata?.device_uuid !== devA) {
        throw new Error(`TEST-K01: KEK A tidak mengikat UUID A: ${kekA.metadata?.device_uuid}`);
      }
      if (kekB.metadata?.device_uuid !== devB) {
        throw new Error(`TEST-K01: KEK B tidak mengikat UUID B: ${kekB.metadata?.device_uuid}`);
      }

      // Asersi 3: Status keduanya aktif dan versi 1
      if (kekA.status !== 'active' || kekB.status !== 'active') {
        throw new Error('TEST-K01: Salah satu KEK tidak berstatus active.');
      }
      if (kekA.key_version !== 1 || kekB.key_version !== 1) {
        throw new Error('TEST-K01: Versi awal KEK bukan 1.');
      }

      // Asersi 4: Pemanggilan ulang mengembalikan KEK yang sama persis (tanpa membuat duplikasi)
      const kekA_re = await getOrCreateActiveKek(devA);
      const kekB_re = await getOrCreateActiveKek(devB);

      if (kekA_re.key_id !== kekA.key_id) {
        throw new Error('TEST-K01: Pemanggilan ulang Device A menghasilkan KEK baru atau KEK yang salah!');
      }
      if (kekB_re.key_id !== kekB.key_id) {
        throw new Error('TEST-K01: Pemanggilan ulang Device B menghasilkan KEK baru atau KEK yang salah!');
      }

      // Verifikasi di keystore IDB bahwa tidak ada duplikasi KEK aktif
      const allKeks = await withReadonlyTransaction([BCAT_STORES.KEYSTORE], (_tx, getStore) => {
        return new Promise<KeystoreRecord[]>((resolve, reject) => {
          const req = getStore(BCAT_STORES.KEYSTORE).getAll();
          req.onsuccess = () => resolve(req.result as KeystoreRecord[]);
          req.onerror = () => reject(req.error);
        });
      });

      const activeA = allKeks.filter((r) => r.status === 'active' && r.metadata?.device_uuid === devA);
      const activeB = allKeks.filter((r) => r.status === 'active' && r.metadata?.device_uuid === devB);

      if (activeA.length !== 1) {
        throw new Error(`TEST-K01: Ditemukan ${activeA.length} KEK aktif untuk Device A (diharapkan tepat 1).`);
      }
      if (activeB.length !== 1) {
        throw new Error(`TEST-K01: Ditemukan ${activeB.length} KEK aktif untuk Device B (diharapkan tepat 1).`);
      }

      updateTc(
        'TEST-K01',
        'PASS',
        `Isolasi terbukti: KEK A (${kekA.key_id}) & KEK B (${kekB.key_id}) terpisah 100% tanpa tumpang tindih.`
      );
      addLog('[TEST-K01] PASS: Isolasi dua perangkat terverifikasi secara presisi.', 'success');

      // -----------------------------------------------------------------------
      // TEST-K02: Orphan KEK Tanpa Metadata Device (Device C)
      // -----------------------------------------------------------------------
      updateTc('TEST-K02', 'RUNNING');
      const devC = '550e8400-e29b-41d4-a716-4466554400c2';
      addLog(`[TEST-K02] Menguji penolakan adopsi entri orphan tanpa metadata untuk Device C (${devC})...`, 'info');

      // 1. Bangkitkan CryptoKey sintetis dan simpan record orphan tanpa metadata
      const orphanCryptoKey = await window.crypto.subtle.generateKey(
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
      );

      const orphanId = `kek_orphan_fixture_no_metadata_${Date.now()}`;
      const orphanRecord: KeystoreRecord = {
        key_id: orphanId,
        key_version: 1,
        status: 'active',
        algorithm: 'AES-GCM-256',
        crypto_key: orphanCryptoKey,
        created_at: Date.now(),
        // metadata sengaja diabaikan / undefined
      };
      await idbPut(BCAT_STORES.KEYSTORE, orphanRecord);

      // Snapshot record orphan sebelum pemanggilan
      const snapshotOrphan = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, orphanId);
      if (!snapshotOrphan || snapshotOrphan.metadata !== undefined) {
        throw new Error('TEST-K02: Fixture orphan record gagal disiapkan dengan benar.');
      }

      // 2. Device C meminta KEK aktif
      const kekC = await getOrCreateActiveKek(devC);

      // Asersi 1: KEK Device C bukan KEK orphan
      if (kekC.key_id === orphanId) {
        throw new Error('TEST-K02: PELANGGARAN ISOLASI: Device C mengadopsi record orphan tanpa identitas!');
      }

      // Asersi 2: Metadata KEK C mengikat devC
      if (kekC.metadata?.device_uuid !== devC) {
        throw new Error(`TEST-K02: KEK C tidak mengikat deviceUuidC: ${kekC.metadata?.device_uuid}`);
      }

      // Asersi 3: Record orphan tetap utuh dan tidak dimutasi oleh getOrCreateActiveKek
      const currentOrphan = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, orphanId);
      if (!currentOrphan) {
        throw new Error('TEST-K02: Record orphan hilang dari keystore!');
      }
      if (currentOrphan.status !== 'active') {
        throw new Error(`TEST-K02: Status record orphan termutasi: ${currentOrphan.status}`);
      }
      if (currentOrphan.metadata !== undefined) {
        throw new Error('TEST-K02: Metadata record orphan termutasi atau diklaim paksa oleh Device C!');
      }
      if (currentOrphan.created_at !== snapshotOrphan.created_at) {
        throw new Error('TEST-K02: Timestamp record orphan berubah!');
      }

      updateTc(
        'TEST-K02',
        'PASS',
        `Entri orphan (${orphanId}) diabaikan total; Device C membangkitkan KEK baru (${kekC.key_id}).`
      );
      addLog('[TEST-K02] PASS: Penolakan wildcard fallback untuk entri orphan terbukti 100%.', 'success');

      // -----------------------------------------------------------------------
      // TEST-K03: Isolasi Rotasi KEK Multidevice & Perlindungan Entri Orphan
      // -----------------------------------------------------------------------
      updateTc('TEST-K03', 'RUNNING');
      const devA3 = '550e8400-e29b-41d4-a716-4466554400a3';
      const devB3 = '550e8400-e29b-41d4-a716-4466554400b3';
      addLog('[TEST-K03] Menguji isolasi rotasi multidevice & imutabilitas entri orphan...', 'info');

      // 1. Setup KEK awal A dan B
      const kekA3_v1 = await getOrCreateActiveKek(devA3);
      const kekB3_v1 = await getOrCreateActiveKek(devB3);

      // 2. Injeksi record orphan dengan key_version 5 (sengaja lebih tinggi)
      const orphanRotKey = await window.crypto.subtle.generateKey(
        { name: 'AES-GCM', length: 256 },
        false,
        ['encrypt', 'decrypt']
      );
      const orphanRotId = `kek_orphan_rot_v5_${Date.now()}`;
      const orphanRotRecord: KeystoreRecord = {
        key_id: orphanRotId,
        key_version: 5,
        status: 'active',
        algorithm: 'AES-GCM-256',
        crypto_key: orphanRotKey,
        created_at: Date.now(),
        // metadata undefined
      };
      await idbPut(BCAT_STORES.KEYSTORE, orphanRotRecord);

      // 3. Eksekusi rotasi untuk Perangkat A
      const kekA3_v2 = await rotateActiveKek(devA3);

      // Asersi Rotasi A:
      // a. Versi KEK baru A harus 2 (berdasarkan riwayat A: 1 + 1 = 2, BUKAN 5 + 1 = 6 dari orphan!)
      if (kekA3_v2.key_version !== 2) {
        throw new Error(
          `TEST-K03: Versi KEK baru Device A tidak valid: ${kekA3_v2.key_version} (diharapkan 2, bukan terpengaruh orphan).`
        );
      }
      if (kekA3_v2.status !== 'active') {
        throw new Error(`TEST-K03: KEK baru Device A tidak berstatus active: ${kekA3_v2.status}`);
      }

      // b. KEK v1 A harus berubah menjadi deprecated
      const checkA_v1 = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, kekA3_v1.key_id);
      if (checkA_v1?.status !== 'deprecated') {
        throw new Error(`TEST-K03: KEK v1 Device A tidak dideprekasi: ${checkA_v1?.status}`);
      }

      // c. KEK B3_v1 HARUS TETAP 'active' dan tidak terpengaruh rotasi A
      const checkB_v1 = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, kekB3_v1.key_id);
      if (checkB_v1?.status !== 'active') {
        throw new Error(`TEST-K03: PELANGGARAN ISOLASI: KEK Device B ikut terdeprekasi saat Device A rotasi!`);
      }
      if (checkB_v1.key_version !== 1) {
        throw new Error(`TEST-K03: Versi KEK Device B berubah saat Device A rotasi!`);
      }

      // d. Record orphan HARUS TETAP 'active' dan versi tetap 5 (tidak ikut dideprekasi oleh rotasi A)
      const checkOrphan_postRotA = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, orphanRotId);
      if (checkOrphan_postRotA?.status !== 'active') {
        throw new Error('TEST-K03: PELANGGARAN ISOLASI: Record orphan ikut dideprekasi saat Device A rotasi!');
      }
      if (checkOrphan_postRotA.key_version !== 5) {
        throw new Error('TEST-K03: Versi record orphan termutasi!');
      }

      // 4. Eksekusi rotasi untuk Perangkat B
      const kekB3_v2 = await rotateActiveKek(devB3);

      // Asersi Rotasi B:
      // a. Versi KEK baru B harus 2 (berdasarkan riwayat B: 1 + 1 = 2)
      if (kekB3_v2.key_version !== 2) {
        throw new Error(
          `TEST-K03: Versi KEK baru Device B tidak valid: ${kekB3_v2.key_version} (diharapkan 2).`
        );
      }
      if (kekB3_v2.status !== 'active') {
        throw new Error(`TEST-K03: KEK baru Device B tidak berstatus active: ${kekB3_v2.status}`);
      }

      // b. KEK v1 B harus berubah menjadi deprecated
      const checkB_v1_postRotB = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, kekB3_v1.key_id);
      if (checkB_v1_postRotB?.status !== 'deprecated') {
        throw new Error(`TEST-K03: KEK v1 Device B tidak dideprekasi setelah rotasi B.`);
      }

      // c. KEK v2 A HARUS TETAP 'active' dan tidak terpengaruh rotasi B
      const checkA_v2_postRotB = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, kekA3_v2.key_id);
      if (checkA_v2_postRotB?.status !== 'active') {
        throw new Error('TEST-K03: PELANGGARAN ISOLASI: KEK Device A terpengaruh saat Device B rotasi!');
      }

      // d. Record orphan HARUS TETAP 'active' dan versi tetap 5
      const checkOrphan_postRotB = await idbGet<KeystoreRecord>(BCAT_STORES.KEYSTORE, orphanRotId);
      if (checkOrphan_postRotB?.status !== 'active' || checkOrphan_postRotB.key_version !== 5) {
        throw new Error('TEST-K03: Record orphan termutasi setelah rotasi B!');
      }

      updateTc(
        'TEST-K03',
        'PASS',
        `Rotasi A & B terisolasi penuh: versi dihitung independen (v2), KEK silang & entri orphan (v5) tidak tersentuh.`
      );
      addLog('[TEST-K03] PASS: Isolasi rotasi multidevice & perlindungan orphan terbukti 100%.', 'success');

      // -----------------------------------------------------------------------
      // Finalisasi
      // -----------------------------------------------------------------------
      setOverallResult('SUCCESS');
      addLog('=== SELURUH 3 TEST CASE ISOLASI KEK BERHASIL DILALUI (100% PASS) ===', 'success');
    } catch (err: any) {
      setOverallResult('FAILED');
      addLog(`PENGUJIAN ISOLASI GAGAL: ${err.message}`, 'error');
    } finally {
      setIsRunning(false);
    }
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 p-8">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div className="border-b border-slate-800 pb-4 flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
              <span className="text-cyan-400">B-CHAT</span> KEK Device Isolation Test Suite
            </h1>
            <p className="text-sm text-slate-400 mt-1">
              Verifikasi Browser Runtime Tahap H-I3-PRE • K01 (Isolasi Dua Perangkat) • K02 (Orphan Non-Adopsi) • K03 (Isolasi Rotasi)
            </p>
          </div>
          <div>
            <button
              id="btn-run-isolation-tests"
              onClick={runAllTests}
              disabled={isRunning}
              className="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-50 flex items-center gap-2"
            >
              {isRunning ? 'Menjalankan...' : 'Jalankan Pengujian Isolasi KEK'}
            </button>
          </div>
        </div>

        {/* Status Banner */}
        <div
          id="test-status"
          data-status={overallResult}
          className={`p-4 rounded-xl border flex items-center justify-between ${
            overallResult === 'SUCCESS'
              ? 'bg-emerald-950/40 border-emerald-800/80 text-emerald-200'
              : overallResult === 'FAILED'
              ? 'bg-rose-950/40 border-rose-800/80 text-rose-200'
              : overallResult === 'RUNNING'
              ? 'bg-amber-950/40 border-amber-800/80 text-amber-200'
              : 'bg-slate-900 border-slate-800 text-slate-300'
          }`}
        >
          <div>
            <span className="text-xs uppercase tracking-wider font-semibold text-slate-400">Status Pengujian</span>
            <div className="text-lg font-bold mt-0.5">
              {overallResult === 'IDLE' && 'Siap Dijalankan'}
              {overallResult === 'RUNNING' && 'Sedang Menguji Isolasi Kepemilikan KEK...'}
              {overallResult === 'SUCCESS' && 'LULUS PENUH (3/3 Test Cases PASS)'}
              {overallResult === 'FAILED' && 'PENGUJIAN GAGAL (Periksa Log di Bawah)'}
            </div>
          </div>
          <div className="text-right">
            <span className="text-xs text-slate-400">Total Skenario</span>
            <div className="text-sm font-medium">3 Kasus Uji Kritis</div>
          </div>
        </div>

        {/* Test Cases List */}
        <div className="space-y-3">
          {testCases.map((tc) => (
            <div
              key={tc.id}
              className="p-4 rounded-lg bg-slate-900/80 border border-slate-800 flex flex-col justify-between"
            >
              <div className="flex items-start justify-between gap-2">
                <div>
                  <div className="text-xs font-mono text-cyan-400 font-semibold">{tc.id}</div>
                  <div className="text-sm font-medium text-slate-200 mt-0.5">{tc.name}</div>
                </div>
                <span
                  className={`text-xs px-2.5 py-1 rounded-full font-semibold ${
                    tc.status === 'PASS'
                      ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                      : tc.status === 'FAIL'
                      ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                      : tc.status === 'RUNNING'
                      ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30 animate-pulse'
                      : 'bg-slate-800 text-slate-400'
                  }`}
                >
                  {tc.status}
                </span>
              </div>
              {tc.details && (
                <div className="mt-2 text-xs text-slate-400 border-t border-slate-800/80 pt-2 font-mono">
                  {tc.details}
                </div>
              )}
            </div>
          ))}
        </div>

        {/* Live Logs Terminal */}
        <div className="rounded-xl bg-slate-900 border border-slate-800 overflow-hidden">
          <div className="px-4 py-2.5 bg-slate-800/60 border-b border-slate-800 flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-300 uppercase tracking-wider">Log Eksekusi Browser</span>
            <span className="text-xs text-slate-500 font-mono">{logs.length} entri</span>
          </div>
          <div className="p-4 font-mono text-xs space-y-1.5 max-h-72 overflow-y-auto bg-slate-950/70">
            {logs.length === 0 ? (
              <span className="text-slate-600">Klik &quot;Jalankan Pengujian Isolasi KEK&quot; untuk memulai audit runtime peramban.</span>
            ) : (
              logs.map((log, index) => (
                <div key={index} className="flex items-start gap-2">
                  <span className="text-slate-500 shrink-0">[{log.timestamp}]</span>
                  <span
                    className={`shrink-0 font-bold ${
                      log.level === 'success'
                        ? 'text-emerald-400'
                        : log.level === 'error'
                        ? 'text-rose-400'
                        : log.level === 'warn'
                        ? 'text-amber-400'
                        : 'text-sky-400'
                    }`}
                  >
                    [{log.level.toUpperCase()}]
                  </span>
                  <span className="text-slate-300 break-all">{log.message}</span>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

