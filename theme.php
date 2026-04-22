<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

csrf_check();

$theme = (string) ($_POST['theme'] ?? 'light');
$user  = current_user();
set_theme($user ? (int) $user['id'] : null, $theme);
if ($user) audit((int) $user['id'], 'theme_changed', 'theme', null);

$ref = (string) ($_POST['return'] ?? 'dashboard.php');
if (!preg_match('/^[a-z0-9_]+\.php(\?[A-Za-z0-9=&_\-]*)?$/i', $ref)) {
    $ref = $user ? 'dashboard.php' : 'index.php';
}

header('Location: ' . $ref);
exit;
