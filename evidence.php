<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = require_login();
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Not found'); }

$stmt = db()->prepare(
    "SELECT e.*, c.user_id AS owner_id
     FROM cpe_evidence e
     JOIN cpe_entries c ON c.id = e.cpe_entry_id
     WHERE e.id = ?"
);
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) { http_response_code(404); exit('Not found'); }

$isAdmin = ($user['role'] ?? '') === 'admin';
if ((int) $row['owner_id'] !== (int) $user['id'] && !$isAdmin) {
    http_response_code(403);
    exit('Kein Zugriff.');
}

$base = realpath(dirname(__DIR__) . '/' . CT_UPLOAD_DIR_REL);
if (!$base) { $base = realpath(__DIR__ . '/' . CT_UPLOAD_DIR_REL); }
$abs  = realpath(__DIR__ . '/' . ltrim((string) $row['stored_path'], '/'));

if (!$base || !$abs || !str_starts_with($abs, $base) || !is_file($abs)) {
    http_response_code(404);
    exit('Datei fehlt.');
}

$mime = (string) $row['mime'];
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['original_name']);
$disposition = isset($_GET['dl']) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');

audit((int) $user['id'], 'evidence_view', 'cpe_evidence', $id);
readfile($abs);
