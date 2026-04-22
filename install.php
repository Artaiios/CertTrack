<?php
declare(strict_types=1);

/**
 * CertTrack Installer
 * Idempotent: kann mehrfach ausgefuehrt werden, zerstoert keine Daten.
 * Nach dem Setup loeschen oder per .htaccess blocken.
 */

if (!file_exists(__DIR__ . '/includes/db_credentials.php')) {
    http_response_code(500);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>CertTrack Installer</title>';
    echo '<pre style="font-family:monospace;padding:2rem;color:#1f2937;background:#fafaf7">';
    echo "FEHLER: includes/db_credentials.php fehlt.\n\n";
    echo "Lege die Datei aus der Vorlage includes/db_credentials.example.php an,\n";
    echo "fuelle DB- und SMTP-Daten aus, lade sie hoch und rufe install.php erneut auf.\n";
    echo '</pre>';
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$messages = [];
$errors = [];
$adminCreated = null;

try {
    $pdo = db();
} catch (Throwable $e) {
    $errors[] = 'Konnte keine DB-Verbindung aufbauen: ' . $e->getMessage();
}

if (!$errors) {
    $statements = [
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL DEFAULT '',
            role ENUM('admin','user') NOT NULL DEFAULT 'user',
            primary_certification_id INT UNSIGNED NULL,
            theme ENUM('light','dark','hc') NOT NULL DEFAULT 'light',
            consent_audit TINYINT(1) NOT NULL DEFAULT 0,
            consent_at DATETIME NULL,
            settings JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at DATETIME NULL,
            INDEX idx_users_email (email),
            INDEX idx_users_primary (primary_certification_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'magic_links' => "CREATE TABLE IF NOT EXISTS magic_links (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            requested_ip_hash CHAR(64) NULL,
            requested_ua VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_token (token_hash),
            INDEX idx_user (user_id),
            CONSTRAINT fk_ml_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'certifications' => "CREATE TABLE IF NOT EXISTS certifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(160) NOT NULL,
            description TEXT NULL,
            cpe_yearly_target SMALLINT UNSIGNED NOT NULL DEFAULT 20,
            cpe_total_target SMALLINT UNSIGNED NOT NULL DEFAULT 120,
            double_count_articles TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'domains' => "CREATE TABLE IF NOT EXISTS domains (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            certification_id INT UNSIGNED NOT NULL,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(200) NOT NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_cert_code (certification_id, code),
            CONSTRAINT fk_dom_cert FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'user_certifications' => "CREATE TABLE IF NOT EXISTS user_certifications (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            certification_id INT UNSIGNED NOT NULL,
            target_exam_date DATE NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_cert (user_id, certification_id),
            CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_uc_cert FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'study_sessions' => "CREATE TABLE IF NOT EXISTS study_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            certification_id INT UNSIGNED NOT NULL,
            domain_id INT UNSIGNED NULL,
            session_date DATE NOT NULL,
            duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            questions_total SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            questions_correct SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            mood TINYINT UNSIGNED NOT NULL DEFAULT 3,
            notes TEXT NULL,
            xp_awarded INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, session_date),
            INDEX idx_user_cert (user_id, certification_id),
            INDEX idx_domain (domain_id),
            CONSTRAINT fk_ss_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ss_cert FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE CASCADE,
            CONSTRAINT fk_ss_dom FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'cpe_categories' => "CREATE TABLE IF NOT EXISTS cpe_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            doubles TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'cpe_entries' => "CREATE TABLE IF NOT EXISTS cpe_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            certification_id INT UNSIGNED NULL,
            category_id INT UNSIGNED NOT NULL,
            entry_date DATE NOT NULL,
            hours DECIMAL(5,2) NOT NULL DEFAULT 0,
            credited_hours DECIMAL(5,2) NOT NULL DEFAULT 0,
            description VARCHAR(500) NOT NULL DEFAULT '',
            xp_awarded INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_date (user_id, entry_date),
            INDEX idx_user_cert (user_id, certification_id),
            CONSTRAINT fk_cpe_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_cpe_cert FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE SET NULL,
            CONSTRAINT fk_cpe_cat FOREIGN KEY (category_id) REFERENCES cpe_categories(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'cpe_evidence' => "CREATE TABLE IF NOT EXISTS cpe_evidence (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cpe_entry_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            byte_size INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entry (cpe_entry_id),
            INDEX idx_user (user_id),
            CONSTRAINT fk_ev_entry FOREIGN KEY (cpe_entry_id) REFERENCES cpe_entries(id) ON DELETE CASCADE,
            CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'xp_log' => "CREATE TABLE IF NOT EXISTS xp_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            source_type VARCHAR(40) NOT NULL,
            source_id BIGINT UNSIGNED NULL,
            amount INT NOT NULL,
            reason VARCHAR(160) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            CONSTRAINT fk_xp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'badges' => "CREATE TABLE IF NOT EXISTS badges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            description VARCHAR(255) NOT NULL,
            icon VARCHAR(40) NOT NULL DEFAULT '',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'user_badges' => "CREATE TABLE IF NOT EXISTS user_badges (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            badge_id INT UNSIGNED NOT NULL,
            unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_badge (user_id, badge_id),
            CONSTRAINT fk_ub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ub_badge FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'user_streaks' => "CREATE TABLE IF NOT EXISTS user_streaks (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            current_streak SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            longest_streak SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_activity_date DATE NULL,
            CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'buddies' => "CREATE TABLE IF NOT EXISTS buddies (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            buddy_user_id INT UNSIGNED NOT NULL,
            status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
            share_progress TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME NULL,
            UNIQUE KEY uniq_pair (user_id, buddy_user_id),
            CONSTRAINT fk_bd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_bd_buddy FOREIGN KEY (buddy_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'buddy_messages' => "CREATE TABLE IF NOT EXISTS buddy_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            from_user_id INT UNSIGNED NOT NULL,
            to_user_id INT UNSIGNED NOT NULL,
            message VARCHAR(280) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            INDEX idx_to_read (to_user_id, read_at),
            CONSTRAINT fk_bm_from FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_bm_to FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'announcements' => "CREATE TABLE IF NOT EXISTS announcements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            body TEXT NOT NULL,
            expires_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_an_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'audit_log' => "CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id BIGINT UNSIGNED NULL,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $tbl => $sql) {
        try {
            $pdo->exec($sql);
            $messages[] = "Tabelle <code>$tbl</code> bereit.";
        } catch (Throwable $e) {
            $errors[] = "Fehler bei <code>$tbl</code>: " . $e->getMessage();
        }
    }
}

if (!$errors) {
    $colExists = function (string $tbl, string $col) use ($pdo): bool {
        $s = $pdo->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $s->execute([$tbl, $col]);
        return (bool) $s->fetchColumn();
    };

    if (!$colExists('users', 'primary_certification_id')) {
        try {
            $pdo->exec(
                "ALTER TABLE users
                 ADD COLUMN primary_certification_id INT UNSIGNED NULL AFTER role,
                 ADD INDEX idx_users_primary (primary_certification_id),
                 ADD CONSTRAINT fk_users_primary FOREIGN KEY (primary_certification_id) REFERENCES certifications(id) ON DELETE SET NULL"
            );
            $messages[] = "Spalte <code>users.primary_certification_id</code> ergaenzt.";
        } catch (Throwable $e) {
            $errors[] = "Migration users.primary_certification_id: " . $e->getMessage();
        }
    }

    if (!$colExists('users', 'theme')) {
        try {
            $pdo->exec(
                "ALTER TABLE users ADD COLUMN theme ENUM('light','dark','hc') NOT NULL DEFAULT 'light' AFTER primary_certification_id"
            );
            $messages[] = "Spalte <code>users.theme</code> ergaenzt (light/dark/hc).";
        } catch (Throwable $e) {
            $errors[] = "Migration users.theme: " . $e->getMessage();
        }
    }
}

if (!$errors) {
    $cpeCats = [
        ['webinar',     'Webinar / Online-Kurs',     0, 10],
        ['conference',  'Konferenz / Meetup',        0, 20],
        ['reading',     'Fachbuch / Artikel lesen',  0, 30],
        ['talk',        'Vortrag halten',            1, 40],
        ['article',     'Fachartikel schreiben',     1, 50],
        ['volunteer',   'Ehrenamt / Mitarbeit',      0, 60],
        ['mentoring',   'Mentoring / Lehre',         0, 70],
        ['exam',        'Pruefung / Re-Zertifikat',  0, 80],
        ['lab',         'Hands-on Lab / CTF',        0, 85],
        ['other',       'Sonstige Aktivitaet',       0, 99],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO cpe_categories (code,name,doubles,sort_order) VALUES (?,?,?,?)");
    foreach ($cpeCats as $c) { $stmt->execute($c); }
    $messages[] = "CPE-Kategorien geseedet.";

    $badges = [
        ['first_step',     'Erster Schritt',  'Erste Lernsession erfasst.',                              '🌱', 10],
        ['on_track',       'Auf Kurs',        '7 Tage Streak in Folge.',                                  '🔥', 20],
        ['unstoppable',    'Unaufhaltsam',    '30 Tage Streak in Folge.',                                 '🚀', 30],
        ['domain_master',  'Domain Master',   'Mehr als 80% richtig in einer Domain (>= 50 Fragen).',     '🎯', 40],
        ['cpe_champion',   'CPE Champion',    'Jahresziel an CPE-Stunden erreicht.',                      '🏆', 50],
        ['question_grind', 'Vielfrager',      '500 Fragen insgesamt beantwortet.',                        '❓', 60],
        ['night_owl',      'Nachteule',       'Lernsession nach 22 Uhr erfasst.',                         '🦉', 70],
        ['evidence_keeper','Beleg-Sammler',   'Erstes Evidence-Dokument zu einer CPE hochgeladen.',       '📎', 80],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO badges (code,name,description,icon,sort_order) VALUES (?,?,?,?,?)");
    foreach ($badges as $b) { $stmt->execute($b); }
    $messages[] = "Badges geseedet.";

    $certTemplates = [
        [
            'code' => 'CISM',
            'name' => 'CISM — Certified Information Security Manager',
            'description' => 'ISACA. Vier Domains rund um Information Security Management.',
            'yearly' => 20, 'total' => 120, 'double' => 1,
            'domains' => [
                ['governance',  'Information Security Governance'],
                ['risk',        'Information Security Risk Management'],
                ['programme',   'Information Security Programme Development'],
                ['incident',    'Incident Management'],
            ],
        ],
        [
            'code' => 'CISSP',
            'name' => 'CISSP — Certified Information Systems Security Professional',
            'description' => 'ISC2. Acht Domains, Common Body of Knowledge.',
            'yearly' => 40, 'total' => 120, 'double' => 1,
            'domains' => [
                ['srm',     'Security and Risk Management'],
                ['asset',   'Asset Security'],
                ['arch',    'Security Architecture and Engineering'],
                ['comm',    'Communication and Network Security'],
                ['iam',     'Identity and Access Management'],
                ['assess',  'Security Assessment and Testing'],
                ['ops',     'Security Operations'],
                ['sdl',     'Software Development Security'],
            ],
        ],
        [
            'code' => 'CISA',
            'name' => 'CISA — Certified Information Systems Auditor',
            'description' => 'ISACA. Fuenf Domains zu IS-Audit, Governance und Schutz.',
            'yearly' => 20, 'total' => 120, 'double' => 1,
            'domains' => [
                ['audit',   'Information Systems Auditing Process'],
                ['govit',   'Governance and Management of IT'],
                ['acq',     'IS Acquisition, Development and Implementation'],
                ['ops',     'IS Operations and Business Resilience'],
                ['protect', 'Protection of Information Assets'],
            ],
        ],
        [
            'code' => 'CRISC',
            'name' => 'CRISC — Certified in Risk and Information Systems Control',
            'description' => 'ISACA. Vier Domains zu IT-Risk und Controls.',
            'yearly' => 20, 'total' => 120, 'double' => 1,
            'domains' => [
                ['gov',     'Governance'],
                ['assess',  'IT Risk Assessment'],
                ['response','Risk Response and Reporting'],
                ['itsec',   'Information Technology and Security'],
            ],
        ],
        [
            'code' => 'CCSP',
            'name' => 'CCSP — Certified Cloud Security Professional',
            'description' => 'ISC2. Sechs Domains zu Cloud Security.',
            'yearly' => 30, 'total' => 90, 'double' => 1,
            'domains' => [
                ['arch',    'Cloud Concepts, Architecture and Design'],
                ['data',    'Cloud Data Security'],
                ['infra',   'Cloud Platform and Infrastructure Security'],
                ['app',     'Cloud Application Security'],
                ['ops',     'Cloud Security Operations'],
                ['legal',   'Legal, Risk and Compliance'],
            ],
        ],
        [
            'code' => 'SECP',
            'name' => 'CompTIA Security+ (SY0-701)',
            'description' => 'CompTIA. Fuenf Domains, 50 CEUs / 3 Jahre.',
            'yearly' => 17, 'total' => 50, 'double' => 1,
            'domains' => [
                ['concepts','General Security Concepts'],
                ['threats', 'Threats, Vulnerabilities and Mitigations'],
                ['arch',    'Security Architecture'],
                ['ops',     'Security Operations'],
                ['mgmt',    'Security Program Management and Oversight'],
            ],
        ],
        [
            'code' => 'CYSAP',
            'name' => 'CompTIA CySA+ (CS0-003)',
            'description' => 'CompTIA. Vier Domains zu Security Operations und IR.',
            'yearly' => 20, 'total' => 60, 'double' => 1,
            'domains' => [
                ['ops',     'Security Operations'],
                ['vuln',    'Vulnerability Management'],
                ['ir',      'Incident Response and Management'],
                ['comm',    'Reporting and Communication'],
            ],
        ],
        [
            'code' => 'OSCP',
            'name' => 'OSCP — Offensive Security Certified Professional',
            'description' => 'OffSec. Hands-on Pentesting, kein klassisches CPE-Programm.',
            'yearly' => 0, 'total' => 0, 'double' => 0,
            'domains' => [
                ['recon',   'Information Gathering & Recon'],
                ['scan',    'Vulnerability Scanning'],
                ['web',     'Web Application Attacks'],
                ['priv',    'Privilege Escalation'],
                ['ad',      'Active Directory Attacks'],
                ['exploit', 'Exploit Development & Buffer Overflows'],
            ],
        ],
        [
            'code' => 'AWS-SCS',
            'name' => 'AWS Certified Security – Specialty (SCS-C02)',
            'description' => 'AWS. Sechs Bereiche zu Cloud Security in AWS.',
            'yearly' => 0, 'total' => 0, 'double' => 0,
            'domains' => [
                ['ir',      'Threat Detection and Incident Response'],
                ['logmon',  'Security Logging and Monitoring'],
                ['infra',   'Infrastructure Security'],
                ['iam',     'Identity and Access Management'],
                ['data',    'Data Protection'],
                ['govern',  'Management and Security Governance'],
            ],
        ],
        [
            'code' => 'ISO27001-LA',
            'name' => 'ISO/IEC 27001 Lead Auditor',
            'description' => 'IRCA / Exemplar Global. ISMS-Auditing nach ISO 27001.',
            'yearly' => 30, 'total' => 120, 'double' => 1,
            'domains' => [
                ['isms',    'ISMS-Konzepte und Anforderungen'],
                ['risk',    'Risk Management'],
                ['annexa',  'Annex A Controls'],
                ['process', 'Audit-Prozess'],
                ['report',  'Reporting & Follow-up'],
            ],
        ],
    ];

    $insCert = $pdo->prepare(
        "INSERT IGNORE INTO certifications (code,name,description,cpe_yearly_target,cpe_total_target,double_count_articles)
         VALUES (?,?,?,?,?,?)"
    );
    $insDom = $pdo->prepare(
        "INSERT IGNORE INTO domains (certification_id,code,name,sort_order) VALUES (?,?,?,?)"
    );

    $cismId = null;
    foreach ($certTemplates as $tpl) {
        $insCert->execute([$tpl['code'], $tpl['name'], $tpl['description'], $tpl['yearly'], $tpl['total'], $tpl['double']]);
        $idStmt = $pdo->prepare("SELECT id FROM certifications WHERE code = ?");
        $idStmt->execute([$tpl['code']]);
        $cid = (int) $idStmt->fetchColumn();
        if (!$cid) continue;
        if ($tpl['code'] === 'CISM') $cismId = $cid;
        $sort = 10;
        foreach ($tpl['domains'] as $d) {
            $insDom->execute([$cid, $d[0], $d[1], $sort]);
            $sort += 10;
        }
    }
    $messages[] = count($certTemplates) . ' Zertifizierungs-Templates samt Domains bereit.';

    $uploadsBase = __DIR__ . '/uploads/cpe';
    if (!is_dir($uploadsBase)) {
        if (@mkdir($uploadsBase, 0755, true)) {
            $messages[] = "Upload-Verzeichnis <code>uploads/cpe/</code> angelegt.";
        } else {
            $errors[] = "Konnte <code>uploads/cpe/</code> nicht anlegen. Bitte manuell per FTP erstellen, Rechte 755.";
        }
    }
    if (is_dir($uploadsBase) && !is_writable($uploadsBase)) {
        $errors[] = "Upload-Verzeichnis <code>uploads/cpe/</code> nicht beschreibbar. Bitte chmod 755 setzen.";
    }
    $htaccessUploads = __DIR__ . '/uploads/.htaccess';
    if (!file_exists($htaccessUploads)) {
        @file_put_contents($htaccessUploads, "Require all denied\n");
    }

    $hasAdmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();

    if ($hasAdmin === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = strtolower(trim((string) ($_POST['admin_email'] ?? '')));
        $name  = trim((string) ($_POST['admin_name'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Bitte eine gueltige E-Mail-Adresse angeben.';
        } elseif ($name === '') {
            $errors[] = 'Bitte einen Namen angeben.';
        } else {
            $ins = $pdo->prepare("INSERT INTO users (email,name,role,primary_certification_id) VALUES (?,?,'admin',?)");
            $ins->execute([$email, $name, $cismId]);
            $uid = (int) $pdo->lastInsertId();
            $adminCreated = $email;
            $messages[] = "Admin-User <code>" . htmlspecialchars($email) . "</code> angelegt.";
            if ($cismId) {
                $pdo->prepare("INSERT IGNORE INTO user_certifications (user_id,certification_id) VALUES (?,?)")
                    ->execute([$uid, $cismId]);
            }
            $hasAdmin = 1;
        }
    }
}

?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CertTrack — Installer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
    body { background:#FAF7F0; color:#1A1F2E; font-family:'Inter', system-ui, sans-serif; }
    h1, h2, .serif { font-family:'Fraunces', Georgia, serif; letter-spacing:-.01em; }
    .card { background:#FFFFFF; border:1px solid #E8E2D2; border-radius:14px; box-shadow:0 1px 2px rgba(20,30,40,.04); }
    .input { background:#FFFFFF; border:1px solid #D9D2BE; color:#1A1F2E; border-radius:8px; }
    .input:focus { outline:none; border-color:#1E3A8A; box-shadow:0 0 0 3px rgba(30,58,138,.15); }
    .btn-primary { background:#1E3A8A; color:#fff; border-radius:8px; }
    .btn-primary:hover { background:#1E40AF; }
</style>
</head>
<body class="min-h-screen">
<div class="max-w-2xl mx-auto p-6 sm:p-10">
    <h1 class="text-4xl font-bold text-[#1E3A8A] mb-1">CertTrack</h1>
    <p class="text-stone-600 mb-8">Installer · idempotent · mehrfaches Ausfuehren ist sicher.</p>

    <?php if ($errors): ?>
        <div class="rounded-xl p-4 mb-6 border border-red-200 bg-red-50">
            <h2 class="font-semibold text-red-700 mb-2 serif">Fehler</h2>
            <ul class="list-disc pl-5 space-y-1 text-sm text-red-800">
                <?php foreach ($errors as $err): ?><li><?= $err ?></li><?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>

    <?php if ($messages): ?>
        <div class="rounded-xl p-4 mb-6 border border-emerald-200 bg-emerald-50">
            <h2 class="font-semibold text-emerald-700 mb-2 serif">Erledigt</h2>
            <ul class="list-disc pl-5 space-y-1 text-sm text-emerald-900">
                <?php foreach ($messages as $msg): ?><li><?= $msg ?></li><?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>

    <?php if (!$errors && empty($hasAdmin)): ?>
        <div class="card p-6 mb-6">
            <h2 class="text-2xl mb-3 serif">Admin-User anlegen</h2>
            <p class="text-sm text-stone-600 mb-4">Es gibt noch keinen Admin. Login spaeter ueber Magic Link an diese E-Mail.</p>
            <form method="post" class="space-y-3">
                <div>
                    <label class="block text-sm mb-1 text-stone-700">Name</label>
                    <input type="text" name="admin_name" required class="input w-full px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm mb-1 text-stone-700">E-Mail</label>
                    <input type="email" name="admin_email" required class="input w-full px-3 py-2 text-sm">
                </div>
                <button class="btn-primary font-semibold px-4 py-2 text-sm">Admin anlegen</button>
            </form>
        </div>
    <?php elseif (!$errors): ?>
        <div class="card p-6 mb-6">
            <h2 class="text-2xl mb-2 serif">Setup abgeschlossen</h2>
            <p class="text-sm text-stone-600 mb-4">CertTrack ist startklar.</p>
            <a href="index.php" class="btn-primary inline-block font-semibold px-4 py-2 text-sm">Zum Login</a>
        </div>
        <div class="rounded-xl p-4 text-sm bg-amber-50 border border-amber-200 text-amber-900">
            <strong>Bitte jetzt:</strong> <code>install.php</code> loeschen oder per <code>.htaccess</code> mit <code>Deny from all</code> sperren.
        </div>
    <?php endif ?>
</div>
</body>
</html>
