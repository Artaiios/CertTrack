<?php
declare(strict_types=1);

if (!file_exists(__DIR__ . '/db_credentials.php')) {
    http_response_code(500);
    exit('Konfiguration fehlt: includes/db_credentials.php nicht vorhanden.');
}

$__certtrack_config = require __DIR__ . '/db_credentials.php';

if (!is_array($__certtrack_config) || empty($__certtrack_config['db']) || empty($__certtrack_config['app_secret'])) {
    http_response_code(500);
    exit('Konfiguration unvollstaendig.');
}

define('CT_APP_NAME',  'CertTrack');
define('CT_APP_SECRET', (string) $__certtrack_config['app_secret']);
define('CT_BASE_URL',   rtrim((string) ($__certtrack_config['base_url'] ?? ''), '/'));
define('CT_MAGIC_TTL_MIN', 15);

if (!ini_get('date.timezone')) {
    date_default_timezone_set($__certtrack_config['timezone'] ?? 'Europe/Berlin');
} elseif (!empty($__certtrack_config['timezone'])) {
    date_default_timezone_set($__certtrack_config['timezone']);
}

const CT_XP_QUESTION_CORRECT = 10;
const CT_XP_QUESTION_WRONG   = 2;
const CT_XP_CPE_HOUR         = 50;
const CT_XP_STREAK_BONUS     = 5;
const CT_XP_TALK_OR_ARTICLE  = 100;
const CT_XP_EVIDENCE_BONUS   = 10;

const CT_UPLOAD_DIR_REL    = 'uploads/cpe';
const CT_UPLOAD_MAX_BYTES  = 8 * 1024 * 1024;
const CT_UPLOAD_ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'heic',
    'image/heif'      => 'heif',
];

$GLOBALS['__certtrack_config'] = $__certtrack_config;

function ct_config(): array {
    return $GLOBALS['__certtrack_config'];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = ct_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // SET via exec statt MYSQL_ATTR_INIT_COMMAND: vermeidet Deprecation-Warning auf PHP 8.5+
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");
    return $pdo;
}
