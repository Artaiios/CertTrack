<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

start_session();
security_headers();

$token = (string) ($_GET['token'] ?? '');
$uid   = (int) ($_GET['u'] ?? 0);

$user = null;
$error = null;

if ($token === '' || $uid <= 0 || !ctype_xdigit($token) || strlen($token) !== 64) {
    $error = 'Der Link ist unvollstaendig oder ungueltig.';
} else {
    $user = consume_magic_link($token, $uid);
    if (!$user) {
        $error = 'Der Link ist abgelaufen, schon benutzt oder ungueltig.';
        audit($uid, 'magic_link_failed');
    }
}

if ($user) {
    login_user($user);
    header('Location: dashboard.php');
    exit;
}

layout_head('Login fehlgeschlagen');
?>
<div class="min-h-[80vh] flex items-center justify-center px-4">
    <div class="card w-full max-w-md p-8">
        <h1 class="text-2xl mb-2 text-error">Login nicht moeglich</h1>
        <p class="text-sm mb-6"><?= e($error ?? 'Unbekannter Fehler.') ?></p>
        <a href="index.php" class="btn btn-primary">Neuen Link anfordern</a>
    </div>
</div>
<?php layout_foot();
