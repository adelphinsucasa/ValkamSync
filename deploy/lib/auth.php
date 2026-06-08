<?php
declare(strict_types=1);

/**
 * ValkamSync — Auth helpers.
 *
 * Credentials: vsync_auth (bcrypt password + bcrypt PIN).
 * Sessions:    vsync_sessions (random 64-char hex token, TTL per "remember").
 * Transport:   HttpOnly SameSite=Strict cookie `vsync_tok`.
 */

function auth_check_session(PDO $pdo): bool
{
    $token = $_COOKIE['vsync_tok'] ?? '';
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return false;

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql    = $driver === 'sqlite'
        ? "SELECT id FROM vsync_sessions WHERE token = ? AND expires_at > datetime('now')"
        : "SELECT id FROM vsync_sessions WHERE token = ? AND expires_at > NOW()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$token]);
    return $stmt->fetchColumn() !== false;
}

function auth_login(PDO $pdo, array $body): array
{
    $mode     = $body['mode']     ?? '';
    $remember = !empty($body['remember']);

    if ($mode === 'password') {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'missing_fields'];
        }
        $stmt = $pdo->prepare("SELECT password_hash FROM vsync_auth WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            usleep(600_000); // 0.6 s anti-brute-force delay
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }
    } elseif ($mode === 'pin') {
        $pin = $body['pin'] ?? '';
        if (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 8) {
            return ['ok' => false, 'error' => 'invalid_pin_format'];
        }
        $stmt = $pdo->query("SELECT pin_hash FROM vsync_auth LIMIT 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($pin, (string) $row['pin_hash'])) {
            usleep(600_000);
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }
    } else {
        return ['ok' => false, 'error' => 'invalid_mode'];
    }

    // Create session token
    $token     = bin2hex(random_bytes(32));           // 64 hex chars
    $ttl       = $remember ? 30 * 86400 : 4 * 3600;  // 30 days vs 4 hours
    $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttl);

    $stmt = $pdo->prepare(
        "INSERT INTO vsync_sessions (token, remember, expires_at) VALUES (?, ?, ?)"
    );
    $stmt->execute([$token, $remember ? 1 : 0, $expiresAt]);

    $isHttps = !empty($_SERVER['HTTPS'])
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie('vsync_tok', $token, [
        'expires'  => $remember ? time() + $ttl : 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $isHttps,
    ]);

    return ['ok' => true];
}

function auth_logout(PDO $pdo): array
{
    $token = $_COOKIE['vsync_tok'] ?? '';
    if (strlen($token) === 64 && ctype_xdigit($token)) {
        $stmt = $pdo->prepare("DELETE FROM vsync_sessions WHERE token = ?");
        $stmt->execute([$token]);
    }
    // Expire the cookie
    setcookie('vsync_tok', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => true,
    ]);
    return ['ok' => true];
}

function auth_pin_change(PDO $pdo, array $body): array
{
    $pin = $body['pin'] ?? '';
    if (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 8) {
        return ['ok' => false, 'error' => 'pin_invalid'];
    }
    $row = $pdo->query("SELECT id FROM vsync_auth LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['ok' => false, 'error' => 'no_user'];

    $hash = password_hash($pin, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE vsync_auth SET pin_hash = ?, updated_at = ? WHERE id = ?");
    $stmt->execute([$hash, date('Y-m-d H:i:s'), $row['id']]);
    return ['ok' => true];
}
