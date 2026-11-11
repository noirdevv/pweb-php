<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/validation.php';

$method = only('GET', 'POST', 'PUT', 'DELETE');
$pdo    = db();
$id     = (int)($_GET['id'] ?? 0);

if ($method === 'GET') {
    $u     = current_user();
    $all   = $u && $u['role'] === 'admin' && isset($_GET['all']);
    $sql   = "SELECT j.id, j.kode, j.nama, j.kuota, j.aktif,
                (SELECT COUNT(*) FROM calon_siswa s WHERE s.jurusan_id = j.id AND s.status = 'diterima') AS terisi,
                (SELECT COUNT(*) FROM calon_siswa s WHERE s.jurusan_id = j.id) AS pendaftar
              FROM jurusan j " . ($all ? '' : 'WHERE j.aktif = 1 ') . 'ORDER BY j.id';
    $rows = array_map(function ($r) {
        return [
            'id' => (int)$r['id'], 'kode' => $r['kode'], 'nama' => $r['nama'], 'kuota' => (int)$r['kuota'],
            'aktif' => (bool)$r['aktif'], 'terisi' => (int)$r['terisi'], 'pendaftar' => (int)$r['pendaftar'],
        ];
    }, $pdo->query($sql)->fetchAll());

    json_out([
        'jurusan' => $rows,
        'info' => [
            'nama_sekolah' => cfg('app.nama_sekolah'),
            'tahun_ajaran' => cfg('app.tahun_ajaran'),
            'biaya'        => (int)cfg('app.biaya_pendaftaran'),
            'buka'         => cfg('app.pendaftaran_buka'),
            'tutup'        => cfg('app.pendaftaran_tutup'),
            'dibuka'       => pendaftaran_dibuka(),
            'usia_min'     => (int)cfg('app.usia_min'),
            'usia_maks'    => (int)cfg('app.usia_maks'),
            'mode_bayar'   => cfg('payment.mode'),
        ],
    ]);
}

require_role('admin');

if ($method === 'DELETE') {
    if ($id <= 0) fail('ID tidak valid', 422);
    $st = $pdo->prepare('SELECT COUNT(*) FROM calon_siswa WHERE jurusan_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) fail('Jurusan sudah dipakai pendaftar. Nonaktifkan saja.', 409);
    $pdo->prepare('DELETE FROM jurusan WHERE id = ?')->execute([$id]);
    json_out(null);
}

// POST / PUT
$in    = body();
$kode  = strtoupper(str_field($in, 'kode'));
$nama  = str_field($in, 'nama');
$kuota = $in['kuota'] ?? null;
$aktif = !empty($in['aktif']) ? 1 : 0;
$err   = [];
if (!preg_match('/^[A-Z0-9]{2,10}$/', $kode)) $err['kode'] = 'Kode 2–10 huruf/angka';
if (!len_between($nama, 3, 80))                $err['nama'] = 'Nama 3–80 karakter';
if (!is_numeric($kuota) || (int)$kuota < 0 || (int)$kuota > 1000) $err['kuota'] = 'Kuota 0–1000';
if ($err) fail('Data tidak valid', 422, $err);

try {
    if ($method === 'POST') {
        $pdo->prepare('INSERT INTO jurusan (kode, nama, kuota, aktif) VALUES (?, ?, ?, ?)')
            ->execute([$kode, $nama, (int)$kuota, $aktif]);
        json_out(['id' => (int)$pdo->lastInsertId()], 201);
    }
    if ($id <= 0) fail('ID tidak valid', 422);
    $pdo->prepare('UPDATE jurusan SET kode = ?, nama = ?, kuota = ?, aktif = ? WHERE id = ?')
        ->execute([$kode, $nama, (int)$kuota, $aktif, $id]);
    json_out(null);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') fail('Kode jurusan sudah dipakai', 409, ['kode' => 'Kode sudah dipakai']);
    throw $e;
}
