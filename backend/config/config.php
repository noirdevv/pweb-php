<?php
// Konfigurasi aplikasi. Rahasia (kunci gateway, password DB) sebaiknya lewat environment variable.
return [
    'app' => [
        'nama_sekolah'      => 'SMK Coding',
        'tahun_ajaran'      => '2026/2027',
        'kode_tahun'        => '2026',
        'biaya_pendaftaran' => 150000,           // Rupiah
        'pendaftaran_buka'  => '2026-01-01',
        'pendaftaran_tutup' => '2027-07-31',
        'usia_min'          => 12,
        'usia_maks'         => 19,
        'timezone'          => 'Asia/Jakarta',
        'frontend_url'      => '',               // kosong = dideteksi otomatis (.../frontend)
        'debug'             => false,
    ],
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'name'    => getenv('DB_NAME') ?: 'pendaftaran_siswa',
        'user'    => getenv('DB_USER') ?: 'root',
        'pass'    => getenv('DB_PASS') ?: '',     // XAMPP default: kosong
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name'       => 'PSBSESSID',
        'cookie_secure'      => false,            // ubah ke true jika memakai HTTPS
        'allowed_origins'    => [],               // isi jika frontend di domain berbeda
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
    ],
    'payment' => [
        'mode'     => getenv('PAYMENT_MODE') ?: 'mock',   // 'mock' (simulasi) | 'midtrans'
        'midtrans' => [
            'is_production' => false,
            'server_key'    => getenv('MIDTRANS_SERVER_KEY') ?: '',
        ],
    ],
];
