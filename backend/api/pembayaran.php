<?php
declare(strict_types=1);
$action = $_GET['action'] ?? '';
if ($action === 'notify') define('SKIP_CSRF', true);   // webhook gateway: diverifikasi lewat signature, bukan CSRF
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/validation.php';

// ---------- Integrasi Midtrans (Snap) ----------
function pay_mode(): string { return (string)cfg('payment.mode', 'mock'); }
function midtrans_key(): string { return (string)cfg('payment.midtrans.server_key', ''); }
function midtrans_snap_url(): string {
    return cfg('payment.midtrans.is_production') ? 'https://app.midtrans.com/snap/v1/transactions'
                                                  : 'https://app.sandbox.midtrans.com/snap/v1/transactions';
}
function midtrans_api_url(): string {
    return cfg('payment.midtrans.is_production') ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2';
}

function http_json(string $method, string $url, ?array $payload = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(midtrans_key() . ':'),
        ],
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) { error_log('[PSB] curl error: ' . $err); return [0, []]; }
    $data = json_decode((string)$res, true);
    return [$code, is_array($data) ? $data : []];
}

function map_midtrans_status(array $n): string {
    $ts    = $n['transaction_status'] ?? '';
    $fraud = $n['fraud_status'] ?? 'accept';
    if ($ts === 'settlement') return 'paid';
    if ($ts === 'capture')    return $fraud === 'accept' ? 'paid' : 'pending';
    if ($ts === 'pending')    return 'pending';
    if ($ts === 'expire')     return 'expired';
    return 'failed';           // deny, cancel, failure, dll.
}

/** Terapkan status pembayaran secara atomik & idempoten. Jika lunas, siswa lanjut ke verifikasi. */
function apply_payment_status(array $bayar, string $status, ?string $metode, array $raw): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT status, siswa_id FROM pembayaran WHERE id = ? FOR UPDATE');
        $st->execute([$bayar['id']]);
        $cur = $st->fetch();
        if (!$cur || $cur['status'] === 'paid') { $pdo->commit(); return; }   // sudah lunas: abaikan
        $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare('UPDATE pembayaran SET status = ?, metode = COALESCE(?, metode), paid_at = ?, raw_response = ? WHERE id = ?')
            ->execute([$status, $metode, $paidAt, mb_substr((string)json_encode($raw), 0, 60000), $bayar['id']]);
        if ($status === 'paid') {
            $pdo->prepare("UPDATE calon_siswa SET status = 'menunggu_verifikasi' WHERE id = ? AND status = 'menunggu_pembayaran'")
                ->execute([$cur['siswa_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function bayar_payload(array $r): array {
    return [
        'order_id' => $r['order_id'], 'jumlah' => (int)$r['jumlah'], 'status' => $r['status'], 'gateway' => $r['gateway'],
        'metode' => $r['metode'], 'paid_at' => $r['paid_at'], 'redirect_url' => $r['status'] === 'pending' ? $r['redirect_url'] : null,
    ];
}

function bayar_by_order(string $orderId): ?array {
    $st = db()->prepare('SELECT p.*, s.user_id FROM pembayaran p JOIN calon_siswa s ON s.id = p.siswa_id WHERE p.order_id = ?');
    $st->execute([$orderId]);
    return $st->fetch() ?: null;
}

$pdo = db();

switch ($action) {

    // ---- Pendaftar memulai pembayaran ----
    case 'create':
        only('POST');
        $u = require_role('pendaftar');
        $st = $pdo->prepare('SELECT s.*, u.email FROM calon_siswa s JOIN users u ON u.id = s.user_id WHERE s.user_id = ?');
        $st->execute([$u['id']]);
        $s = $st->fetch();
        if (!$s) fail('Isi formulir pendaftaran terlebih dahulu', 409);
        if ($s['status'] !== 'menunggu_pembayaran') fail('Pembayaran sudah diselesaikan', 409);

        // Pakai ulang transaksi pending yang masih berlaku (< 23 jam) agar tidak menumpuk
        $st = $pdo->prepare("SELECT * FROM pembayaran WHERE siswa_id = ? AND status = 'pending' AND created_at > ? ORDER BY id DESC LIMIT 1");
        $st->execute([$s['id'], date('Y-m-d H:i:s', time() - 23 * 3600)]);
        $old = $st->fetch();
        if ($old && $old['redirect_url']) json_out(bayar_payload($old));

        $amount  = (int)cfg('app.biaya_pendaftaran');
        $orderId = 'PSB' . $s['id'] . '-' . time() . bin2hex(random_bytes(2));
        $token = null;

        if (pay_mode() === 'midtrans') {
            if (midtrans_key() === '') fail('Kunci Midtrans belum dikonfigurasi di server', 500);
            [$code, $res] = http_json('POST', midtrans_snap_url(), [
                'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
                'item_details'        => [['id' => 'BIAYA-PSB', 'price' => $amount, 'quantity' => 1,
                                           'name' => 'Biaya Pendaftaran PSB ' . cfg('app.tahun_ajaran')]],
                'customer_details'    => ['first_name' => mb_substr($s['nama'], 0, 50), 'email' => $s['email'], 'phone' => $s['no_hp']],
                'callbacks'           => ['finish' => frontend_url('pay-result.html')],
            ]);
            if ($code < 200 || $code >= 300 || empty($res['redirect_url'])) {
                error_log('[PSB] Midtrans create gagal: HTTP ' . $code . ' ' . json_encode($res));
                fail('Gagal membuat transaksi pembayaran. Coba lagi nanti.', 502);
            }
            $token = $res['token'] ?? null;
            $url   = $res['redirect_url'];
        } else {
            $url = frontend_url('pay-simulasi.html') . '?order_id=' . rawurlencode($orderId);
        }

        $pdo->prepare('INSERT INTO pembayaran (siswa_id, order_id, jumlah, status, gateway, snap_token, redirect_url) VALUES (?,?,?,?,?,?,?)')
            ->execute([$s['id'], $orderId, $amount, 'pending', pay_mode(), $token, $url]);
        json_out(bayar_payload(bayar_by_order($orderId)), 201);

    // ---- Cek / sinkron status ----
    case 'status':
        only('GET');
        $u   = require_login();
        $row = bayar_by_order(str_field($_GET, 'order_id'));
        if (!$row) fail('Transaksi tidak ditemukan', 404);
        if ($u['role'] !== 'admin' && (int)$row['user_id'] !== (int)$u['id']) fail('Anda tidak memiliki akses', 403);

        if ($row['status'] === 'pending' && $row['gateway'] === 'midtrans' && midtrans_key() !== '') {
            [$code, $res] = http_json('GET', midtrans_api_url() . '/' . rawurlencode($row['order_id']) . '/status');
            if ($code === 200 && in_array((string)($res['status_code'] ?? ''), ['200', '201', '202'], true) && isset($res['transaction_status'])) {
                if ((int)round((float)($res['gross_amount'] ?? 0)) === (int)$row['jumlah']) {
                    apply_payment_status($row, map_midtrans_status($res), $res['payment_type'] ?? null, $res);
                    $row = bayar_by_order($row['order_id']);
                }
            }
        }
        json_out(bayar_payload($row));

    // ---- Simulasi (hanya mode mock) ----
    case 'simulate':
        only('POST');
        if (pay_mode() !== 'mock') fail('Simulasi hanya tersedia pada mode mock', 403);
        $u   = require_role('pendaftar');
        $in  = body();
        $row = bayar_by_order(str_field($in, 'order_id'));
        if (!$row || (int)$row['user_id'] !== (int)$u['id']) fail('Transaksi tidak ditemukan', 404);
        if ($row['status'] !== 'pending') fail('Transaksi sudah diproses', 409);
        $result = str_field($in, 'result') === 'paid' ? 'paid' : 'failed';
        apply_payment_status($row, $result, 'simulasi', ['simulated' => true, 'result' => $result]);
        json_out(bayar_payload(bayar_by_order($row['order_id'])));

    // ---- Webhook Midtrans ----
    case 'notify':
        only('POST');
        if (pay_mode() !== 'midtrans' || midtrans_key() === '') fail('Tidak tersedia', 404);
        $n       = body();
        $orderId = (string)($n['order_id'] ?? '');
        $gross   = (string)($n['gross_amount'] ?? '');
        $expect  = hash('sha512', $orderId . (string)($n['status_code'] ?? '') . $gross . midtrans_key());
        if (!hash_equals($expect, (string)($n['signature_key'] ?? ''))) fail('Signature tidak valid', 403);
        $row = bayar_by_order($orderId);
        if (!$row) fail('Transaksi tidak ditemukan', 404);
        if ((int)round((float)$gross) !== (int)$row['jumlah']) fail('Jumlah tidak sesuai', 400);
        apply_payment_status($row, map_midtrans_status($n), isset($n['payment_type']) ? (string)$n['payment_type'] : null, $n);
        json_out(null);

    // ---- Daftar pembayaran (admin) ----
    case 'list':
        only('GET');
        require_role('admin');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per  = 15;
        $off  = ($page - 1) * $per;
        $st   = str_field($_GET, 'status');
        $w    = in_array($st, ['pending', 'paid', 'failed', 'expired'], true) ? ' WHERE p.status = ?' : '';
        $prm  = $w ? [$st] : [];
        $c = $pdo->prepare('SELECT COUNT(*) FROM pembayaran p' . $w);
        $c->execute($prm);
        $total = (int)$c->fetchColumn();
        $q = $pdo->prepare("SELECT p.order_id, p.jumlah, p.status, p.gateway, p.metode, p.paid_at, p.created_at, s.nama, s.no_pendaftaran
                            FROM pembayaran p JOIN calon_siswa s ON s.id = p.siswa_id $w ORDER BY p.id DESC LIMIT $per OFFSET $off");
        $q->execute($prm);
        json_out(['items' => $q->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))]);

    default:
        fail('Aksi tidak dikenal', 404);
}
