<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/validation.php';

only('GET');
require_role('admin');

function csv_safe($v): string {
    $v = (string)$v;
    // Cegah CSV/formula injection di Excel
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

$status = str_field($_GET, 'status');
$params = [];
$where  = '';
if ($status !== '' && in_array($status, STATUS_VALID, true)) { $where = ' WHERE s.status = ?'; $params[] = $status; }

$st = db()->prepare(SISWA_SELECT . $where . ' ORDER BY s.id');
$st->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="pendaftar-psb-' . date('Ymd-His') . '.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
$head = ['No Pendaftaran', 'Nama', 'Email', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Kelamin', 'Agama', 'Alamat',
         'Sekolah Asal', 'No HP', 'Nama Wali', 'Jurusan', 'Status', 'Status Bayar', 'Tanggal Daftar'];
fputcsv($out, $head, ',', '"', '');
while ($s = $st->fetch()) {
    fputcsv($out, array_map('csv_safe', [
        $s['no_pendaftaran'], $s['nama'], $s['email'], $s['tempat_lahir'], $s['tanggal_lahir'], $s['jenis_kelamin'],
        $s['agama'], $s['alamat'], $s['sekolah_asal'], $s['no_hp'], $s['nama_wali'], $s['jurusan_kode'],
        $s['status'], $s['status_bayar'] ?? '-', $s['created_at'],
    ]), ',', '"', '');
}
fclose($out);
