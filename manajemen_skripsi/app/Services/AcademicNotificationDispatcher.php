<?php

namespace App\Services;

use App\Models\Dosen;
use App\Models\PengajuanDospem;
use App\Models\PengajuanDospemLog;
use App\Models\PengajuanJudul;
use App\Models\PengajuanJudulLog;
use App\Models\PenugasanKaprodi;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AcademicNotificationDispatcher
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * EVT-01: Mahasiswa mengirim pengajuan judul baru.
     * Penerima: Dosen PA terkait.
     */
    public function dispatchEvt01PengajuanJudulBaru(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                if (!$pengajuan->dosen_pa_id) {
                    Log::warning("EVT-01 dilewati: dosen_pa_id kosong pada pengajuan [{$pengajuan->id}].");
                    return;
                }

                $dosenPaUser = User::find($pengajuan->dosen_pa_id);
                if (!$dosenPaUser) {
                    Log::warning("EVT-01 dilewati: Akun Dosen PA [ID: {$pengajuan->dosen_pa_id}] tidak ditemukan.");
                    return;
                }

                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? $log->aktor_nama;
                $mahasiswaNim  = $pengajuan->mahasiswa?->NIM ?? $log->aktor_nomor_induk;

                $this->notificationService->send(
                    $dosenPaUser,
                    'pengajuan_judul',
                    "pengajuan_judul:{$pengajuan->id}:submit:log_{$log->id}",
                    [
                        'title'    => 'Pengajuan Judul Baru',
                        'message'  => "Mahasiswa {$mahasiswaNama} ({$mahasiswaNim}) mengajukan judul skripsi baru: \"{$pengajuan->judul}\".",
                        'url'      => '/dashboarddosen/pembimbing_akademik',
                        'metadata' => [
                            'pengajuan_id' => $pengajuan->id,
                            'log_id'       => $log->id,
                            'event_code'   => 'EVT-01',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-01 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-02: Mahasiswa mengirim ulang judul setelah revisi.
     * Penerima: Dosen PA terkait.
     */
    public function dispatchEvt02PengajuanJudulRevisi(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                if (!$pengajuan->dosen_pa_id) {
                    Log::warning("EVT-02 dilewati: dosen_pa_id kosong pada pengajuan [{$pengajuan->id}].");
                    return;
                }

                $dosenPaUser = User::find($pengajuan->dosen_pa_id);
                if (!$dosenPaUser) {
                    Log::warning("EVT-02 dilewati: Akun Dosen PA [ID: {$pengajuan->dosen_pa_id}] tidak ditemukan.");
                    return;
                }

                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? $log->aktor_nama;
                $mahasiswaNim  = $pengajuan->mahasiswa?->NIM ?? $log->aktor_nomor_induk;

                $this->notificationService->send(
                    $dosenPaUser,
                    'pengajuan_judul',
                    "pengajuan_judul:{$pengajuan->id}:resubmit:log_{$log->id}",
                    [
                        'title'    => 'Perbaikan Pengajuan Judul',
                        'message'  => "Mahasiswa {$mahasiswaNama} ({$mahasiswaNim}) telah mengirimkan perbaikan judul skripsi: \"{$pengajuan->judul}\".",
                        'url'      => '/dashboarddosen/pembimbing_akademik',
                        'metadata' => [
                            'pengajuan_id' => $pengajuan->id,
                            'log_id'       => $log->id,
                            'event_code'   => 'EVT-02',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-02 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-03: Dosen PA menyetujui judul.
     * Penerima: Mahasiswa dan Kaprodi aktif program studi mahasiswa.
     * Keputusan Bisnis 2: Jika Kaprodi aktif tidak ada, transaksi & notif mahasiswa tetap jalan, notif Kaprodi diskip dan catat warning.
     */
    public function dispatchEvt03DosenPaSetujui(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? 'Mahasiswa';
                $mahasiswaNim  = $pengajuan->mahasiswa?->NIM ?? '-';
                $prodi         = $pengajuan->mahasiswa?->prodi;

                // 1. Notifikasi ke Mahasiswa
                if ($mahasiswaUser) {
                    $this->notificationService->send(
                        $mahasiswaUser,
                        'pengajuan_judul',
                        "pengajuan_judul:{$pengajuan->id}:setujui_dosen_pa:log_{$log->id}",
                        [
                            'title'    => 'Judul Disetujui Dosen PA',
                            'message'  => "Pengajuan judul Anda (\"{$pengajuan->judul}\") telah disetujui oleh Dosen PA dan diteruskan ke Kaprodi.",
                            'url'      => '/dashboardmahasiswa/pengajuan_judul',
                            'metadata' => [
                                'pengajuan_id' => $pengajuan->id,
                                'log_id'       => $log->id,
                                'event_code'   => 'EVT-03',
                            ],
                        ]
                    );
                } else {
                    Log::warning("EVT-03: Akun user mahasiswa tidak ditemukan untuk pengajuan [{$pengajuan->id}].");
                }

                // 2. Notifikasi ke Kaprodi Aktif
                $kaprodiUser = $this->resolveActiveKaprodiUser($prodi);

                if (!$kaprodiUser) {
                    // Keputusan Bisnis 2: Catat warning informatif, jangan buat fiktif, jangan blokir
                    Log::warning("EVT-03: Tidak ditemukan penugasan Kaprodi aktif untuk program studi [{$prodi}] pada pengajuan judul [{$pengajuan->id}]. Notifikasi Kaprodi dilewati.");
                    return;
                }

                $this->notificationService->send(
                    $kaprodiUser,
                    'pengajuan_judul',
                    "pengajuan_judul:{$pengajuan->id}:setujui_dosen_pa:log_{$log->id}",
                    [
                        'title'    => 'Menunggu Persetujuan Judul',
                        'message'  => "Terdapat pengajuan judul baru mahasiswa {$mahasiswaNama} ({$mahasiswaNim}) yang telah disetujui Dosen PA dan menunggu persetujuan Anda.",
                        'url'      => '/dashboarddosen/persetujuan_kaprodi',
                        'metadata' => [
                            'pengajuan_id' => $pengajuan->id,
                            'log_id'       => $log->id,
                            'prodi'        => $prodi,
                            'event_code'   => 'EVT-03',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-03 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-04: Dosen PA meminta revisi judul.
     * Penerima: Mahasiswa.
     */
    public function dispatchEvt04DosenPaRevisi(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                if (!$mahasiswaUser) {
                    Log::warning("EVT-04: Akun user mahasiswa tidak ditemukan untuk pengajuan [{$pengajuan->id}].");
                    return;
                }

                $catatan = $log->catatan ? ": \"{$log->catatan}\"" : '.';

                $this->notificationService->send(
                    $mahasiswaUser,
                    'pengajuan_judul',
                    "pengajuan_judul:{$pengajuan->id}:revisi_dosen_pa:log_{$log->id}",
                    [
                        'title'    => 'Revisi Pengajuan Judul',
                        'message'  => "Dosen PA meminta revisi untuk judul skripsi Anda (\"{$pengajuan->judul}\"){$catatan}",
                        'url'      => '/dashboardmahasiswa/pengajuan_judul',
                        'metadata' => [
                            'pengajuan_id' => $pengajuan->id,
                            'log_id'       => $log->id,
                            'event_code'   => 'EVT-04',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-04 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-05: Dosen PA menolak judul.
     * Penerima: Mahasiswa.
     */
    public function dispatchEvt05DosenPaTolak(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                if (!$mahasiswaUser) {
                    Log::warning("EVT-05: Akun user mahasiswa tidak ditemukan untuk pengajuan [{$pengajuan->id}].");
                    return;
                }

                $catatan = $log->catatan ? ": \"{$log->catatan}\"" : '.';

                $this->notificationService->send(
                    $mahasiswaUser,
                    'pengajuan_judul',
                    "pengajuan_judul:{$pengajuan->id}:tolak_dosen_pa:log_{$log->id}",
                    [
                        'title'    => 'Pengajuan Judul Ditolak',
                        'message'  => "Pengajuan judul Anda (\"{$pengajuan->judul}\") ditolak oleh Dosen PA{$catatan}",
                        'url'      => '/dashboardmahasiswa/pengajuan_judul',
                        'metadata' => [
                            'pengajuan_id' => $pengajuan->id,
                            'log_id'       => $log->id,
                            'event_code'   => 'EVT-05',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-05 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-06: Kaprodi menyetujui judul.
     * Penerima: Mahasiswa dan Dosen PA.
     * Keputusan Bisnis 1: Jika Dosen PA merangkap Kaprodi, TETAP kirim notifikasi ke Dosen PA.
     */
    public function dispatchEvt06KaprodiSetujui(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? 'Mahasiswa';
                $dosenPaUser   = $pengajuan->dosen_pa_id ? User::find($pengajuan->dosen_pa_id) : null;

                // 1. Notifikasi Mahasiswa
                if ($mahasiswaUser) {
                    $this->notificationService->send(
                        $mahasiswaUser,
                        'pengajuan_judul',
                        "pengajuan_judul:{$pengajuan->id}:setujui_kaprodi:log_{$log->id}",
                        [
                            'title'    => 'Judul Resmi Disetujui Kaprodi',
                            'message'  => "Selamat! Judul skripsi Anda (\"{$pengajuan->judul}\") telah disetujui resmi oleh Kaprodi.",
                            'url'      => '/dashboardmahasiswa/pengajuan_judul',
                            'metadata' => [
                                'pengajuan_id' => $pengajuan->id,
                                'log_id'       => $log->id,
                                'event_code'   => 'EVT-06',
                            ],
                        ]
                    );
                }

                // 2. Notifikasi Dosen PA (Wajib dikirim sesuai Keputusan Bisnis 1)
                if ($dosenPaUser) {
                    $this->notificationService->send(
                        $dosenPaUser,
                        'pengajuan_judul',
                        "pengajuan_judul:{$pengajuan->id}:setujui_kaprodi:log_{$log->id}",
                        [
                            'title'    => 'Persetujuan Judul oleh Kaprodi',
                            'message'  => "Judul skripsi mahasiswa bimbingan Anda {$mahasiswaNama} (\"{$pengajuan->judul}\") telah disetujui resmi oleh Kaprodi.",
                            'url'      => '/dashboarddosen/pembimbing_akademik',
                            'metadata' => [
                                'pengajuan_id' => $pengajuan->id,
                                'log_id'       => $log->id,
                                'event_code'   => 'EVT-06',
                            ],
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-06 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-07: Kaprodi meminta revisi judul.
     * Penerima: Mahasiswa dan Dosen PA.
     * Keputusan Bisnis 1: Jika Dosen PA merangkap Kaprodi, TETAP kirim notifikasi ke Dosen PA.
     */
    public function dispatchEvt07KaprodiRevisi(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? 'Mahasiswa';
                $dosenPaUser   = $pengajuan->dosen_pa_id ? User::find($pengajuan->dosen_pa_id) : null;
                $catatan       = $log->catatan ? ": \"{$log->catatan}\"" : '.';

                // 1. Notifikasi Mahasiswa
                if ($mahasiswaUser) {
                    $this->notificationService->send(
                        $mahasiswaUser,
                        'pengajuan_judul',
                        "pengajuan_judul:{$pengajuan->id}:revisi_kaprodi:log_{$log->id}",
                        [
                            'title'    => 'Revisi Judul dari Kaprodi',
                            'message'  => "Kaprodi meminta revisi untuk judul skripsi Anda (\"{$pengajuan->judul}\"){$catatan}",
                            'url'      => '/dashboardmahasiswa/pengajuan_judul',
                            'metadata' => [
                                'pengajuan_id' => $pengajuan->id,
                                'log_id'       => $log->id,
                                'event_code'   => 'EVT-07',
                            ],
                        ]
                    );
                }

                // 2. Notifikasi Dosen PA (CC/info, wajib dikirim sesuai Keputusan Bisnis 1)
                if ($dosenPaUser) {
                    $this->notificationService->send(
                        $dosenPaUser,
                        'pengajuan_judul',
                        "pengajuan_judul:{$pengajuan->id}:revisi_kaprodi:log_{$log->id}",
                        [
                            'title'    => 'Kaprodi Meminta Revisi Judul Mahasiswa',
                            'message'  => "Kaprodi meminta revisi untuk judul skripsi mahasiswa bimbingan Anda {$mahasiswaNama} (\"{$pengajuan->judul}\"){$catatan}",
                            'url'      => '/dashboarddosen/pembimbing_akademik',
                            'metadata' => [
                                'pengajuan_id' => $pengajuan->id,
                                'log_id'       => $log->id,
                                'event_code'   => 'EVT-07',
                            ],
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-07 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-08: Kaprodi menolak judul.
     * Penerima: Mahasiswa dan Dosen PA.
     * Keputusan Bisnis 1: Jika Dosen PA merangkap Kaprodi, TETAP kirim notifikasi ke Dosen PA.
     */
    public function dispatchEvt08KaprodiTolak(PengajuanJudul $pengajuan, PengajuanJudulLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? 'Mahasiswa';
                $dosenPaUser   = $pengajuan->dosen_pa_id ? User::find($pengajuan->dosen_pa_id) : null;
                $catatan       = $log->catatan ? ": \"{$log->catatan}\"" : '.';

                // 1. Notifikasi Mahasiswa
                if ($mahasiswaUser) {
                    $this->notificationService->send(
                        $mahasiswaUser,
                        'pengajuan_judul',
                        "pengajuan_judul:{$pengajuan->id}:tolak_kaprodi:log_{$log->id}",
                        [
                            'title'    => 'Judul Ditolak oleh Kaprodi',
                            'message'  => "Pengajuan judul Anda (\"{$pengajuan->judul}\") ditolak oleh Kaprodi{$catatan}",
                            'url'      => '/dashboardmahasiswa/pengajuan_judul',
                            'metadata' => [
                                'pengajuan_id' => $pengajuan->id,
                                'log_id'       => $log->id,
                                'event_code'   => 'EVT-08',
                            ],
                        ]
                    );
                }

                // 2. Notifikasi Dosen PA (CC/info, wajib dikirim sesuai Keputusan Bisnis 1)
                if ($dosenPaUser) {
                    $this->notificationService->send(
                        $dosenPaUser,
                        'pengajuan_judul',
                        "pengajuan_judul:{$pengajuan->id}:tolak_kaprodi:log_{$log->id}",
                        [
                            'title'    => 'Kaprodi Menolak Judul Mahasiswa',
                            'message'  => "Pengajuan judul mahasiswa bimbingan Anda {$mahasiswaNama} (\"{$pengajuan->judul}\") ditolak oleh Kaprodi{$catatan}",
                            'url'      => '/dashboarddosen/pembimbing_akademik',
                            'metadata' => [
                                'pengajuan_id' => $pengajuan->id,
                                'log_id'       => $log->id,
                                'event_code'   => 'EVT-08',
                            ],
                        ]
                    );
                }
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-08 untuk pengajuan [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-09: Mahasiswa mengirim pengajuan Dospem Proposal.
     * Penerima: Semua akun admin yang valid sesuai aturan aplikasi.
     */
    public function dispatchEvt09PengajuanDospemProposal(PengajuanDospem $pengajuan, PengajuanDospemLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? $log->aktor_nama;
                $mahasiswaNim  = $pengajuan->mahasiswa?->NIM ?? $log->aktor_nomor_induk;

                $admins = User::where('role', 'admin')->whereHas('admin')->get();
                if ($admins->isEmpty()) {
                    $admins = User::where('role', 'admin')->get();
                }

                if ($admins->isEmpty()) {
                    Log::warning("EVT-09: Tidak ditemukan akun Admin aktif pada sistem.");
                    return;
                }

                $this->notificationService->sendToMany(
                    $admins,
                    'pengajuan_dospem',
                    "pengajuan_dospem:{$pengajuan->id}:submit:log_{$log->id}",
                    [
                        'title'    => 'Pengajuan Pembimbing Proposal Baru',
                        'message'  => "Mahasiswa {$mahasiswaNama} ({$mahasiswaNim}) mengajukan penetapan Dosen Pembimbing Proposal.",
                        'url'      => '/dashboardadmin/penetapan_dospem',
                        'metadata' => [
                            'pengajuan_dospem_id' => $pengajuan->id,
                            'log_id'              => $log->id,
                            'skripsi_id'          => $pengajuan->skripsi_id,
                            'event_code'          => 'EVT-09',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-09 untuk pengajuan dospem [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-10: Admin menetapkan Dospem Proposal.
     * Penerima: Mahasiswa dan Dosen yang ditetapkan.
     */
    public function dispatchEvt10AdminTetapkanDospem(PengajuanDospem $pengajuan, PengajuanDospemLog $log, Dosen $dosen): void
    {
        DB::afterCommit(function () use ($pengajuan, $log, $dosen) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                $mahasiswaNama = $pengajuan->mahasiswa?->nama_mahasiswa ?? 'Mahasiswa';
                $mahasiswaNim  = $pengajuan->mahasiswa?->NIM ?? '-';

                // 1. Notifikasi Mahasiswa
                if ($mahasiswaUser) {
                    $this->notificationService->send(
                        $mahasiswaUser,
                        'pengajuan_dospem',
                        "pengajuan_dospem:{$pengajuan->id}:tetapkan:log_{$log->id}",
                        [
                            'title'    => 'Dosen Pembimbing Proposal Ditetapkan',
                            'message'  => "Dosen Pembimbing Proposal Anda telah ditetapkan: {$dosen->nama_dosen}.",
                            'url'      => '/dashboardmahasiswa/pengajuan_dospem',
                            'metadata' => [
                                'pengajuan_dospem_id' => $pengajuan->id,
                                'dosen_id'            => $dosen->id_dosen,
                                'log_id'              => $log->id,
                                'event_code'          => 'EVT-10',
                            ],
                        ]
                    );
                }

                // 2. Notifikasi Dosen yang ditetapkan
                $dosenUser = $dosen->user;
                if ($dosenUser) {
                    $this->notificationService->send(
                        $dosenUser,
                        'pengajuan_dospem',
                        "pengajuan_dospem:{$pengajuan->id}:tetapkan:log_{$log->id}",
                        [
                            'title'    => 'Penetapan Bimbingan Proposal Baru',
                            'message'  => "Anda telah ditetapkan oleh Administrator sebagai Dosen Pembimbing Proposal untuk mahasiswa {$mahasiswaNama} ({$mahasiswaNim}).",
                            'url'      => '/dashboarddosen/pembimbing_skripsi',
                            'metadata' => [
                                'pengajuan_dospem_id' => $pengajuan->id,
                                'skripsi_id'          => $pengajuan->skripsi_id,
                                'log_id'              => $log->id,
                                'event_code'          => 'EVT-10',
                            ],
                        ]
                    );
                } else {
                    Log::warning("EVT-10: Akun user untuk dosen [ID: {$dosen->id_dosen}] tidak ditemukan.");
                }
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-10 untuk pengajuan dospem [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * EVT-11: Admin menolak pengajuan Dospem Proposal.
     * Penerima: Mahasiswa.
     */
    public function dispatchEvt11AdminTolakDospem(PengajuanDospem $pengajuan, PengajuanDospemLog $log): void
    {
        DB::afterCommit(function () use ($pengajuan, $log) {
            try {
                $mahasiswaUser = $pengajuan->mahasiswa?->user;
                if (!$mahasiswaUser) {
                    Log::warning("EVT-11: Akun user mahasiswa tidak ditemukan untuk pengajuan dospem [{$pengajuan->id}].");
                    return;
                }

                $catatan = $log->catatan ? ": \"{$log->catatan}\"" : '.';

                $this->notificationService->send(
                    $mahasiswaUser,
                    'pengajuan_dospem',
                    "pengajuan_dospem:{$pengajuan->id}:tolak:log_{$log->id}",
                    [
                        'title'    => 'Pengajuan Pembimbing Proposal Ditolak',
                        'message'  => "Pengajuan Dosen Pembimbing Proposal Anda ditolak oleh Administrator{$catatan}",
                        'url'      => '/dashboardmahasiswa/pengajuan_dospem',
                        'metadata' => [
                            'pengajuan_dospem_id' => $pengajuan->id,
                            'log_id'              => $log->id,
                            'event_code'          => 'EVT-11',
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi EVT-11 untuk pengajuan dospem [{$pengajuan->id}]: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        });
    }

    /**
     * Helper privat untuk me-resolve user Kaprodi aktif berdasarkan program studi.
     */
    protected function resolveActiveKaprodiUser(?string $prodi): ?User
    {
        if (empty($prodi)) {
            return null;
        }

        $today = now()->toDateString();

        $penugasan = PenugasanKaprodi::with('dosen.user')
            ->where('prodi', $prodi)
            ->where('is_aktif', true)
            ->where('tanggal_mulai', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('tanggal_selesai')
                  ->orWhere('tanggal_selesai', '>=', $today);
            })
            ->first();

        return $penugasan?->dosen?->user;
    }
}
