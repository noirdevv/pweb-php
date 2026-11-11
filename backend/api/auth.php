<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/validation.php';

function public_user(?array $u): ?array {
    return $u ? ['id' => (int)$u['id'], 'nama' => $u['nama'], 'email' => $u['email'], 'role' => $u['role']] : null;
}

function start_session_for(int $uid): void {
    session_regenerate_id(true);       // cegah session fixation
    $_SESSION['uid'] = $uid;
    csrf_rotate();
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'csrf':
    case 'me':
        only('GET');
        json_out(['user' => public_user(current_user())], 200, ['csrf' => csrf_token()]);

    case 'register':
        only('POST');
        $in    = body();
        $nama  = str_field($in, 'nama');
        $email = strtolower(str_field($in, 'email'));
        $pass  = is_string($in['password'] ?? null) ? $in['password'] : '';
        $err   = [];
        if (!len_between($nama, 3, 64)) $err['nama'] = 'Nama 3–64 karakter';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) $err['email'] = 'Email tidak valid';
        if (strlen($pass) < 8 || strlen($pass) > 72 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass)) {
            $err['password'] = 'Kata sandi 8–72 karakter, kombinasi huruf dan angka';
        }
        if ($err) fail('Data tidak valid', 422, $err);

        $st = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) fail('Email sudah terdaftar', 409, ['email' => 'Email sudah terdaftar']);

        $st = db()->prepare("INSERT INTO users (nama, email, password_hash, role) VALUES (?, ?, ?, 'pendaftar')");
        $st->execute([$nama, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        start_session_for((int)db()->lastInsertId());
        json_out(['user' => public_user(current_user())], 201, ['csrf' => csrf_token()]);

    case 'login':
        only('POST');
        $in    = body();
        $email = strtolower(str_field($in, 'email'));
        $pass  = is_string($in['password'] ?? null) ? $in['password'] : '';
        $ip    = client_ip();
        $pdo   = db();

        // Rate limiting: batasi percobaan gagal per kombinasi email + IP
        $cutoff = date('Y-m-d H:i:s', time() - 60 * (int)cfg('security.lockout_minutes'));
        $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at > ?');
        $st->execute([substr($email, 0, 120), $ip, $cutoff]);
        if ((int)$st->fetchColumn() >= (int)cfg('security.max_login_attempts')) {
            fail('Terlalu banyak percobaan login. Coba lagi dalam ' . cfg('security.lockout_minutes') . ' menit.', 429);
        }

        $st = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();
        if (!$user || !password_verify($pass, $user['password_hash'])) {
            $pdo->prepare('INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, ?)')
                ->execute([substr($email, 0, 120), $ip, date('Y-m-d H:i:s')]);
            fail('Email atau kata sandi salah', 401);
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
        start_session_for((int)$user['id']);
        json_out(['user' => public_user(current_user())], 200, ['csrf' => csrf_token()]);

    case 'logout':
        only('POST');
        $_SESSION = [];
        session_regenerate_id(true);
        json_out(null, 200, ['csrf' => csrf_token()]);

    default:
        fail('Aksi tidak dikenal', 404);
}
