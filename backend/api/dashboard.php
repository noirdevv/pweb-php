<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/validation.php';

only('GET');
require_role('admin');
$pdo = db();

$per_status = array_fill_keys(STATUS_VALID, 0);
foreach ($pdo->query('SELECT status, COUNT(*) AS c FROM calon_siswa GROUP BY status') as $r) {
    $per_status[$r['status']] = (int)$r['c'];
}

$pendapatan = (int)$pdo->query("SELECT COALESCE(SUM(jumlah), 0) FROM pembayaran WHERE status = 'paid'")->fetchColumn();

$jurusan = $pdo->query("SELECT j.kode, j.nama, j.kuota,
        (SELECT COUNT(*) FROM calon_siswa s WHERE s.jurusan_id = j.id) AS pendaftar,
        (SELECT COUNT(*) FROM calon_siswa s WHERE s.jurusan_id = j.id AND s.status = 'diterima') AS diterima
    FROM jurusan j WHERE j.aktif = 1 ORDER BY j.id")->fetchAll();

$terbaru = $pdo->query("SELECT s.id, s.no_pendaftaran, s.nama, s.status, s.created_at, j.kode AS jurusan_kode
    FROM calon_siswa s JOIN jurusan j ON j.id = s.jurusan_id ORDER BY s.id DESC LIMIT 5")->fetchAll();

json_out([
    'total'      => array_sum($per_status),
    'per_status' => $per_status,
    'pendapatan' => $pendapatan,
    'jurusan'    => $jurusan,
    'terbaru'    => $terbaru,
]);
