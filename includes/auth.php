<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('CT_SID');
    session_start();
}

function security_headers(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' https://cdn.tailwindcss.com https://cdn.jsdelivr.net 'unsafe-inline'; "
        . "style-src 'self' https://cdn.tailwindcss.com https://fonts.googleapis.com 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

function current_user(): ?array {
    start_session();
    if (empty($_SESSION['uid'])) return null;
    $u = get_user((int) $_SESSION['uid']);
    return $u ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: index.php');
        exit;
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Kein Zugriff.');
    }
    return $u;
}

function token_hash(string $rawToken): string {
    return hash_hmac('sha256', $rawToken, CT_APP_SECRET);
}

function create_magic_link(string $email): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'reason' => 'invalid_email'];
    }
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    if (!$userId) {
        // Aus Datenschutz-Sicht keine Auskunft geben, aber kein Token erzeugen.
        return ['ok' => true, 'silent' => true];
    }
    $userId = (int) $userId;

    $raw = bin2hex(random_bytes(32));
    $hash = token_hash($raw);
    $expires = (new DateTimeImmutable('+' . CT_MAGIC_TTL_MIN . ' minutes'))->format('Y-m-d H:i:s');

    $pdo->prepare(
        "INSERT INTO magic_links (user_id, token_hash, expires_at, requested_ip_hash, requested_ua)
         VALUES (?,?,?,?,?)"
    )->execute([$userId, $hash, $expires, hash_ip(client_ip()), client_ua()]);

    audit($userId, 'magic_link_requested');

    $url = CT_BASE_URL . '/auth.php?token=' . $raw . '&u=' . $userId;
    $sent = send_magic_mail($email, $url);
    return ['ok' => true, 'silent' => false, 'sent' => $sent];
}

function send_magic_mail(string $to, string $url): bool {
    $cfg = ct_config()['mail'] ?? [];
    $subject = 'Dein CertTrack Login-Link';
    $body = "Hi,\n\n"
          . "klick diesen Link, um dich bei CertTrack einzuloggen. Er ist " . CT_MAGIC_TTL_MIN . " Minuten gueltig und kann nur einmal verwendet werden:\n\n"
          . $url . "\n\n"
          . "Wenn du das nicht warst, ignoriere diese Mail einfach.\n\n"
          . "— CertTrack";

    $phpMailer = __DIR__ . '/PHPMailer/PHPMailer.php';
    if (file_exists($phpMailer) && !empty($cfg['host'])) {
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        require_once $phpMailer;
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->Port = (int) ($cfg['port'] ?? 587);
            $mail->SMTPAuth = true;
            $mail->Username = $cfg['user'] ?? '';
            $mail->Password = $cfg['pass'] ?? '';
            $mail->SMTPSecure = $cfg['secure'] ?? 'tls';
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($cfg['from'] ?? $cfg['user'], $cfg['fromName'] ?? 'CertTrack');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('CertTrack mail failed: ' . $e->getMessage());
            return false;
        }
    }

    // Fallback: PHP mail()
    $headers = "From: " . ($cfg['from'] ?? 'noreply@localhost') . "\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

function consume_magic_link(string $rawToken, int $userId): ?array {
    $pdo = db();
    $hash = token_hash($rawToken);
    $stmt = $pdo->prepare(
        "SELECT id, user_id, expires_at, used_at FROM magic_links
         WHERE token_hash=? AND user_id=? LIMIT 1"
    );
    $stmt->execute([$hash, $userId]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ($row['used_at'] !== null) return null;
    if (strtotime($row['expires_at']) < time()) return null;

    $pdo->prepare("UPDATE magic_links SET used_at=NOW() WHERE id=?")->execute([$row['id']]);

    $u = get_user((int) $row['user_id']);
    if (!$u) return null;

    $pdo->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$u['id']]);

    return $u;
}

function login_user(array $user): void {
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];
    audit((int) $user['id'], 'login_success');
}

function logout_user(): void {
    start_session();
    $uid = $_SESSION['uid'] ?? null;
    if ($uid) audit((int) $uid, 'logout');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
