<?php
declare(strict_types=1);

const AGAMA_VALID  = ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu'];
const STATUS_VALID = ['menunggu_pembayaran', 'menunggu_verifikasi', 'diterima', 'ditolak'];

function len_between(string $v, int $min, int $max): bool {
    $n = mb_strlen($v);
    return $n >= $min && $n <= $max;
}

/** Validasi data calon siswa. Mengembalikan [data_bersih, errors]. */
function validate_siswa(array $in): array {
    $e = [];
    $d = [
        'nama'          => str_field($in, 'nama'),
        'tempat_lahir'  => str_field($in, 'tempat_lahir'),
        'tanggal_lahir' => str_field($in, 'tanggal_lahir'),
        'alamat'        => str_field($in, 'alamat'),
        'jenis_kelamin' => str_field($in, 'jenis_kelamin'),
        'agama'         => str_field($in, 'agama'),
        'sekolah_asal'  => str_field($in, 'sekolah_asal'),
        'no_hp'         => (string)preg_replace('/[\s\-]/', '', str_field($in, 'no_hp')),
        'nama_wali'     => str_field($in, 'nama_wali'),
        'jurusan_id'    => (int)($in['jurusan_id'] ?? 0),
    ];

    if (!len_between($d['nama'], 3, 64))         $e['nama'] = 'Nama lengkap 3–64 karakter';
    if (!len_between($d['tempat_lahir'], 2, 50)) $e['tempat_lahir'] = 'Tempat lahir 2–50 karakter';
    if (!len_between($d['alamat'], 5, 255))      $e['alamat'] = 'Alamat 5–255 karakter';
    if (!len_between($d['sekolah_asal'], 3, 64)) $e['sekolah_asal'] = 'Sekolah asal 3–64 karakter';
    if (!len_between($d['nama_wali'], 3, 64))    $e['nama_wali'] = 'Nama wali 3–64 karakter';

    if (!in_array($d['jenis_kelamin'], ['laki-laki', 'perempuan'], true)) $e['jenis_kelamin'] = 'Pilih jenis kelamin';
    if (!in_array($d['agama'], AGAMA_VALID, true))                          $e['agama'] = 'Pilih agama';
    if (!preg_match('/^(\+62|62|0)8[1-9][0-9]{6,11}$/', $d['no_hp']))       $e['no_hp'] = 'Nomor HP tidak valid (contoh: 081234567890)';

    $dt = DateTime::createFromFormat('!Y-m-d', $d['tanggal_lahir']);
    if (!$dt || $dt->format('Y-m-d') !== $d['tanggal_lahir']) {
        $e['tanggal_lahir'] = 'Tanggal lahir tidak valid';
    } else {
        $today = new DateTime('today');
        $usia  = $dt->diff($today)->y;
        if ($dt > $today || $usia < cfg('app.usia_min') || $usia > cfg('app.usia_maks')) {
            $e['tanggal_lahir'] = 'Usia harus antara ' . cfg('app.usia_min') . '–' . cfg('app.usia_maks') . ' tahun';
        }
    }

    if ($d['jurusan_id'] <= 0) {
        $e['jurusan_id'] = 'Pilih jurusan';
    } else {
        $st = db()->prepare('SELECT aktif FROM jurusan WHERE id = ?');
        $st->execute([$d['jurusan_id']]);
        $row = $st->fetch();
        if (!$row || !(int)$row['aktif']) $e['jurusan_id'] = 'Jurusan tidak tersedia';
    }
    return [$d, $e];
}

function pendaftaran_dibuka(): bool {
    $today = date('Y-m-d');
    return $today >= cfg('app.pendaftaran_buka') && $today <= cfg('app.pendaftaran_tutup');
}

const SISWA_SELECT = "SELECT s.*, j.nama AS jurusan_nama, j.kode AS jurusan_kode, u.email,
    (SELECT p.status FROM pembayaran p WHERE p.siswa_id = s.id ORDER BY p.id DESC LIMIT 1) AS status_bayar
    FROM calon_siswa s
    JOIN jurusan j ON j.id = s.jurusan_id
    JOIN users u ON u.id = s.user_id";
