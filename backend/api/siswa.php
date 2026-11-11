<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/validation.php';

$user    = require_login();
$method  = only('GET', 'POST', 'PUT', 'PATCH', 'DELETE');
$pdo     = db();
$id      = (int)($_GET['id'] ?? 0);
$isAdmin = $user['role'] === 'admin';

function siswa_find(string $where, $param): ?array {
    $st = db()->prepare(SISWA_SELECT . " WHERE $where LIMIT 1");
    $st->execute([$param]);
    return $st->fetch() ?: null;
}

function pembayaran_terakhir(int $siswaId): ?array {
    $st = db()->prepare('SELECT order_id, jumlah, status, gateway, metode, paid_at, created_at
                         FROM pembayaran WHERE siswa_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$siswaId]);
    return $st->fetch() ?: null;
}

function siswa_update(int $id, array $d): void {
    db()->prepare('UPDATE calon_siswa SET nama=?, tempat_lahir=?, tanggal_lahir=?, alamat=?, jenis_kelamin=?,
                   agama=?, sekolah_asal=?, no_hp=?, nama_wali=?, jurusan_id=? WHERE id=?')
        ->execute([$d['nama'], $d['tempat_lahir'], $d['tanggal_lahir'], $d['alamat'], $d['jenis_kelamin'],
                   $d['agama'], $d['sekolah_asal'], $d['no_hp'], $d['nama_wali'], $d['jurusan_id'], $id]);
}

// =====================================================================
// ADMIN
// =====================================================================
if ($isAdmin) {
    if ($method === 'GET' && $id > 0) {
        $s = siswa_find('s.id = ?', $id);
        if (!$s) fail('Data tidak ditemukan', 404);
        json_out(['siswa' => $s, 'pembayaran' => pembayaran_terakhir($id)]);
    }

    if ($method === 'GET') {
        $where = []; $params = [];
        $q = str_field($_GET, 'q');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(s.nama LIKE ? OR s.no_pendaftaran LIKE ? OR s.sekolah_asal LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $status = str_field($_GET, 'status');
        if ($status !== '' && in_array($status, STATUS_VALID, true)) { $where[] = 's.status = ?'; $params[] = $status; }
        $jur = (int)($_GET['jurusan_id'] ?? 0);
        if ($jur > 0) { $where[] = 's.jurusan_id = ?'; $params[] = $jur; }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = min(50, max(5, (int)($_GET['per_page'] ?? 10)));
        $off  = ($page - 1) * $per;
        $order = (($_GET['sort'] ?? '') === 'nama') ? 's.nama ASC' : 's.id DESC';

        $st = $pdo->prepare('SELECT COUNT(*) FROM calon_siswa s' . $w);
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $st = $pdo->prepare(SISWA_SELECT . $w . " ORDER BY $order LIMIT $per OFFSET $off");
        $st->execute($params);
        json_out(['items' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))]);
    }

    if ($id <= 0) fail('ID tidak valid', 422);

    if ($method === 'PUT') {
        if (!siswa_find('s.id = ?', $id)) fail('Data tidak ditemukan', 404);
        [$d, $err] = validate_siswa(body());
        if ($err) fail('Data tidak valid', 422, $err);
        siswa_update($id, $d);
        json_out(['siswa' => siswa_find('s.id = ?', $id)]);
    }

    if ($method === 'PATCH') {
        $in      = body();
        $target  = str_field($in, 'status');
        $catatan = mb_substr(str_field($in, 'catatan_admin'), 0, 255);
        if (!in_array($target, ['menunggu_verifikasi', 'diterima', 'ditolak'], true)) fail('Status tidak valid', 422);
        if ($target === 'ditolak' && $catatan === '') fail('Alasan penolakan wajib diisi', 422, ['catatan_admin' => 'Wajib diisi']);

        $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT * FROM calon_siswa WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) { $pdo->rollBack(); fail('Data tidak ditemukan', 404); }
        if ($s['status'] === 'menunggu_pembayaran') {
            $pdo->rollBack();
            fail('Pendaftar belum menyelesaikan pembayaran', 409);
        }
        if ($target === 'diterima') {
            // Kunci baris jurusan agar pengecekan kuota aman dari balapan (race condition)
            $st = $pdo->prepare('SELECT kuota FROM jurusan WHERE id = ? FOR UPDATE');
            $st->execute([$s['jurusan_id']]);
            $kuota = (int)$st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM calon_siswa WHERE jurusan_id = ? AND status = 'diterima' AND id <> ?");
            $st->execute([$s['jurusan_id'], $id]);
            if ((int)$st->fetchColumn() >= $kuota) {
                $pdo->rollBack();
                fail('Kuota jurusan sudah penuh', 409);
            }
        }
        $pdo->prepare('UPDATE calon_siswa SET status = ?, catatan_admin = ? WHERE id = ?')
            ->execute([$target, $catatan !== '' ? $catatan : null, $id]);
        $pdo->commit();
        json_out(['siswa' => siswa_find('s.id = ?', $id)]);
    }

    if ($method === 'DELETE') {
        $st = $pdo->prepare('DELETE FROM calon_siswa WHERE id = ?');
        $st->execute([$id]);
        if ($st->rowCount() === 0) fail('Data tidak ditemukan', 404);
        json_out(null);
    }
    fail('Metode tidak diizinkan', 405);
}

// =====================================================================
// PENDAFTAR
// =====================================================================
if ($id > 0 || $method === 'PATCH' || $method === 'DELETE') fail('Anda tidak memiliki akses', 403);

$mine = siswa_find('s.user_id = ?', (int)$user['id']);

if ($method === 'GET') {
    json_out(['siswa' => $mine, 'pembayaran' => $mine ? pembayaran_terakhir((int)$mine['id']) : null]);
}

if ($method === 'POST') {
    if (!pendaftaran_dibuka()) fail('Pendaftaran belum dibuka atau sudah ditutup', 403);
    if ($mine) fail('Anda sudah mengisi formulir pendaftaran', 409);
    [$d, $err] = validate_siswa(body());
    if ($err) fail('Data tidak valid', 422, $err);

    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO calon_siswa (user_id, nama, tempat_lahir, tanggal_lahir, alamat, jenis_kelamin,
                   agama, sekolah_asal, no_hp, nama_wali, jurusan_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$user['id'], $d['nama'], $d['tempat_lahir'], $d['tanggal_lahir'], $d['alamat'], $d['jenis_kelamin'],
                   $d['agama'], $d['sekolah_asal'], $d['no_hp'], $d['nama_wali'], $d['jurusan_id']]);
    $newId = (int)$pdo->lastInsertId();
    $nomor = 'PSB-' . cfg('app.kode_tahun') . '-' . str_pad((string)$newId, 4, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE calon_siswa SET no_pendaftaran = ? WHERE id = ?')->execute([$nomor, $newId]);
    $pdo->commit();
    json_out(['siswa' => siswa_find('s.id = ?', $newId), 'pembayaran' => null], 201);
}

if ($method === 'PUT') {
    if (!$mine) fail('Data pendaftaran belum ada', 404);
    if ($mine['status'] !== 'menunggu_pembayaran') fail('Data tidak dapat diubah setelah pembayaran diselesaikan', 403);
    [$d, $err] = validate_siswa(body());
    if ($err) fail('Data tidak valid', 422, $err);
    siswa_update((int)$mine['id'], $d);
    json_out(['siswa' => siswa_find('s.id = ?', (int)$mine['id']), 'pembayaran' => pembayaran_terakhir((int)$mine['id'])]);
}
fail('Metode tidak diizinkan', 405);
