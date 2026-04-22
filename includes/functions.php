<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function e(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hash_ip(?string $ip): string {
    return hash_hmac('sha256', (string) $ip, CT_APP_SECRET);
}

function client_ip(): string {
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function client_ua(): string {
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function audit(?int $userId, string $action, ?string $targetType = null, ?int $targetId = null): void {
    try {
        $stmt = db()->prepare(
            "INSERT INTO audit_log (user_id, action, target_type, target_id, ip_hash, user_agent)
             VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([
            $userId,
            $action,
            $targetType,
            $targetId,
            hash_ip(client_ip()),
            client_ua(),
        ]);
    } catch (Throwable $e) {
        // Audit darf nie den Hauptflow killen.
    }
}

/* ---------- CSRF ---------- */

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    $sent = (string) ($_POST['_csrf'] ?? '');
    $known = (string) ($_SESSION['_csrf'] ?? '');
    if ($known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('CSRF-Pruefung fehlgeschlagen. Bitte Seite neu laden.');
    }
}

/* ---------- XP / Level / Badges ---------- */

function ct_levels(): array {
    return [
        ['Rookie Analyst',      0],
        ['Junior Defender',     200],
        ['Threat Spotter',      500],
        ['Patch Pilot',         1000],
        ['Risk Reader',         1750],
        ['Policy Apprentice',   2750],
        ['Audit Ranger',        4000],
        ['Control Crafter',     5500],
        ['Governance Guide',    7500],
        ['Incident Hunter',     10000],
        ['Compliance Captain',  13000],
        ['Security Strategist', 16500],
        ['Risk Architect',      20500],
        ['Programme Lead',      25000],
        ['Crisis Commander',    30000],
        ['Board Whisperer',     36000],
        ['Resilience Maestro',  43000],
        ['Cyber Sage',          51000],
        ['CISM Candidate',      60000],
        ['CISM Legend',         75000],
    ];
}

function user_total_xp(int $userId): int {
    $stmt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_log WHERE user_id=?");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function level_for_xp(int $xp): array {
    $levels = ct_levels();
    $idx = 0;
    for ($i = 0; $i < count($levels); $i++) {
        if ($xp >= $levels[$i][1]) { $idx = $i; }
    }
    $current = $levels[$idx];
    $next    = $levels[$idx + 1] ?? null;
    return [
        'index'         => $idx + 1,
        'name'          => $current[0],
        'threshold'     => $current[1],
        'next_name'     => $next[0] ?? null,
        'next_threshold'=> $next[1] ?? null,
        'progress_pct'  => $next ? min(100, (int) round(($xp - $current[1]) / max(1, $next[1] - $current[1]) * 100)) : 100,
        'xp_to_next'    => $next ? max(0, $next[1] - $xp) : 0,
    ];
}

function award_xp(int $userId, int $amount, string $sourceType, ?int $sourceId, string $reason): void {
    if ($amount === 0) return;
    $stmt = db()->prepare("INSERT INTO xp_log (user_id, source_type, source_id, amount, reason) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $sourceType, $sourceId, $amount, $reason]);
}

/* ---------- Streak ---------- */

function recompute_streak(int $userId): array {
    $stmt = db()->prepare(
        "SELECT DISTINCT session_date
         FROM study_sessions
         WHERE user_id = ?
         ORDER BY session_date DESC
         LIMIT 400"
    );
    $stmt->execute([$userId]);
    $dates = array_map(static fn($r) => $r['session_date'], $stmt->fetchAll());

    $current = 0;
    $longest = 0;
    $lastActivity = $dates[0] ?? null;

    if ($dates) {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
        $cursor = $dates[0];
        if ($cursor === $today || $cursor === $yesterday) {
            $current = 1;
            $expected = (new DateTimeImmutable($cursor))->modify('-1 day')->format('Y-m-d');
            for ($i = 1; $i < count($dates); $i++) {
                if ($dates[$i] === $expected) {
                    $current++;
                    $expected = (new DateTimeImmutable($expected))->modify('-1 day')->format('Y-m-d');
                } else {
                    break;
                }
            }
        }

        $run = 1;
        for ($i = 1; $i < count($dates); $i++) {
            $prev = (new DateTimeImmutable($dates[$i - 1]))->modify('-1 day')->format('Y-m-d');
            if ($dates[$i] === $prev) {
                $run++;
            } else {
                if ($run > $longest) $longest = $run;
                $run = 1;
            }
        }
        if ($run > $longest) $longest = $run;
        if ($current > $longest) $longest = $current;
    }

    db()->prepare(
        "INSERT INTO user_streaks (user_id, current_streak, longest_streak, last_activity_date)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE current_streak=VALUES(current_streak), longest_streak=GREATEST(longest_streak,VALUES(longest_streak)), last_activity_date=VALUES(last_activity_date)"
    )->execute([$userId, $current, $longest, $lastActivity]);

    return ['current' => $current, 'longest' => $longest, 'last' => $lastActivity];
}

function get_streak(int $userId): array {
    $stmt = db()->prepare("SELECT current_streak, longest_streak, last_activity_date FROM user_streaks WHERE user_id=?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return recompute_streak($userId) + ['current' => 0, 'longest' => 0, 'last' => null];
    }
    return [
        'current' => (int) $row['current_streak'],
        'longest' => (int) $row['longest_streak'],
        'last'    => $row['last_activity_date'],
    ];
}

/* ---------- Session XP-Berechnung ---------- */

function compute_session_xp(int $correct, int $wrong, int $streakBefore, ?DateTimeImmutable $when = null): int {
    $xp = $correct * CT_XP_QUESTION_CORRECT + $wrong * CT_XP_QUESTION_WRONG;
    if ($streakBefore > 0) {
        $xp += CT_XP_STREAK_BONUS * min($streakBefore, 30);
    }
    return $xp;
}

function award_cpe_xp(int $userId, float $hours, bool $doubles, int $entryId, string $category): int {
    $effective = $doubles ? $hours * 2 : $hours;
    $xp = (int) round($effective * CT_XP_CPE_HOUR);
    if (in_array($category, ['talk', 'article'], true)) {
        $xp += CT_XP_TALK_OR_ARTICLE;
    }
    award_xp($userId, $xp, 'cpe', $entryId, "CPE: $category ($hours h)");
    return $xp;
}

/* ---------- Badge-Pruefung ---------- */

function evaluate_badges(int $userId): array {
    $pdo = db();
    $unlocked = [];

    $existing = $pdo->prepare("SELECT b.code FROM user_badges ub JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id=?");
    $existing->execute([$userId]);
    $have = array_flip(array_map(static fn($r) => $r['code'], $existing->fetchAll()));

    $codeIds = [];
    foreach ($pdo->query("SELECT id, code FROM badges")->fetchAll() as $b) {
        $codeIds[$b['code']] = (int) $b['id'];
    }

    $unlock = static function (string $code) use (&$unlocked, $have, $codeIds, $pdo, $userId): void {
        if (isset($have[$code]) || empty($codeIds[$code])) return;
        $stmt = $pdo->prepare("INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)");
        $stmt->execute([$userId, $codeIds[$code]]);
        if ($stmt->rowCount() > 0) {
            $unlocked[] = $code;
        }
    };

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM study_sessions WHERE user_id=?");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) $unlock('first_step');

    $streak = get_streak($userId);
    if ($streak['current'] >= 7 || $streak['longest'] >= 7) $unlock('on_track');
    if ($streak['current'] >= 30 || $streak['longest'] >= 30) $unlock('unstoppable');

    $stmt = $pdo->prepare(
        "SELECT domain_id, SUM(questions_total) total, SUM(questions_correct) correct
         FROM study_sessions WHERE user_id=? AND domain_id IS NOT NULL GROUP BY domain_id"
    );
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $t = (int) $row['total'];
        if ($t >= 50 && ($row['correct'] / $t) >= 0.8) {
            $unlock('domain_master');
            break;
        }
    }

    $stmt = $pdo->prepare("SELECT SUM(credited_hours) FROM cpe_entries WHERE user_id=? AND YEAR(entry_date)=YEAR(CURDATE())");
    $stmt->execute([$userId]);
    $yearHours = (float) $stmt->fetchColumn();
    if ($yearHours >= 20) $unlock('cpe_champion');

    $stmt = $pdo->prepare("SELECT SUM(questions_total) FROM study_sessions WHERE user_id=?");
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() >= 500) $unlock('question_grind');

    $stmt = $pdo->prepare("SELECT 1 FROM study_sessions WHERE user_id=? AND HOUR(created_at) >= 22 LIMIT 1");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn()) $unlock('night_owl');

    $stmt = $pdo->prepare("SELECT 1 FROM cpe_evidence WHERE user_id=? LIMIT 1");
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn()) $unlock('evidence_keeper');

    return $unlocked;
}

/* ---------- Lookup-Helfer ---------- */

function get_user(int $userId): ?array {
    $stmt = db()->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function user_certifications(int $userId): array {
    $stmt = db()->prepare(
        "SELECT c.*, uc.target_exam_date
         FROM user_certifications uc
         JOIN certifications c ON c.id = uc.certification_id
         WHERE uc.user_id=? AND c.is_active=1
         ORDER BY c.name"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function all_certifications(): array {
    return db()->query("SELECT * FROM certifications WHERE is_active=1 ORDER BY name")->fetchAll();
}

function domains_for(int $certId): array {
    $stmt = db()->prepare("SELECT * FROM domains WHERE certification_id=? ORDER BY sort_order, name");
    $stmt->execute([$certId]);
    return $stmt->fetchAll();
}

function cpe_categories(): array {
    return db()->query("SELECT * FROM cpe_categories ORDER BY sort_order, name")->fetchAll();
}

function active_announcements(): array {
    return db()->query(
        "SELECT * FROM announcements
         WHERE expires_at IS NULL OR expires_at > NOW()
         ORDER BY created_at DESC"
    )->fetchAll();
}

/* ---------- Theme ---------- */

function valid_themes(): array {
    return ['light', 'dark', 'hc'];
}

function theme_meta(string $theme): array {
    $m = [
        'light' => ['label' => 'Hell',          'icon' => '☀️', 'desc' => 'Warmes Off-White, gut bei Tageslicht.', 'color' => '#FAF7F0'],
        'dark'  => ['label' => 'Dunkel',        'icon' => '🌙', 'desc' => 'Schonend abends und im Dunkeln.',      'color' => '#0B1220'],
        'hc'    => ['label' => 'High Contrast', 'icon' => '⚡', 'desc' => 'Maximaler Kontrast, dicke Konturen.',  'color' => '#000000'],
    ];
    return $m[$theme] ?? $m['light'];
}

function current_theme(?array $user = null): string {
    $valid = valid_themes();
    if ($user && !empty($user['theme']) && in_array($user['theme'], $valid, true)) {
        return $user['theme'];
    }
    $cookie = (string) ($_COOKIE['ct_theme'] ?? '');
    if (in_array($cookie, $valid, true)) return $cookie;
    return 'light';
}

function set_theme(?int $userId, string $theme): bool {
    if (!in_array($theme, valid_themes(), true)) return false;
    if ($userId) {
        try {
            db()->prepare("UPDATE users SET theme=? WHERE id=?")->execute([$theme, $userId]);
        } catch (Throwable $e) {
            // Spalte evtl. noch nicht migriert — Cookie reicht dann.
        }
    }
    if (!headers_sent()) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('ct_theme', $theme, [
            'expires'  => time() + 60 * 60 * 24 * 365,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => $secure,
            'httponly' => false,
        ]);
        $_COOKIE['ct_theme'] = $theme;
    }
    return true;
}

/* ---------- Primary Certification ---------- */

function primary_certification_id(int $userId): ?int {
    $stmt = db()->prepare("SELECT primary_certification_id FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $v = $stmt->fetchColumn();
    return $v ? (int) $v : null;
}

function set_primary_certification(int $userId, ?int $certId): void {
    if ($certId !== null) {
        $check = db()->prepare("SELECT 1 FROM user_certifications WHERE user_id=? AND certification_id=?");
        $check->execute([$userId, $certId]);
        if (!$check->fetchColumn()) {
            db()->prepare("INSERT IGNORE INTO user_certifications (user_id, certification_id) VALUES (?,?)")
                ->execute([$userId, $certId]);
        }
    }
    db()->prepare("UPDATE users SET primary_certification_id=? WHERE id=?")->execute([$certId, $userId]);
}

function default_certification_id(int $userId, array $userCerts): ?int {
    $primary = primary_certification_id($userId);
    if ($primary) {
        foreach ($userCerts as $c) {
            if ((int) $c['id'] === $primary) return $primary;
        }
    }
    return $userCerts ? (int) $userCerts[0]['id'] : null;
}

/* ---------- CPE Evidence Upload ---------- */

function upload_dir_abs(): string {
    return dirname(__DIR__) . '/' . CT_UPLOAD_DIR_REL;
}

function ensure_upload_dir(int $userId): ?string {
    $base = upload_dir_abs();
    $dir = $base . '/' . $userId;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    }
    if (!is_writable($dir)) return null;
    return $dir;
}

/**
 * Nimmt EIN Element aus einem $_FILES-Array (multi-upload), validiert, speichert.
 * Gibt das DB-Insert-Ready-Array zurueck oder einen string-Fehler.
 */
function store_evidence_file(int $userId, array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload fehlgeschlagen (Error-Code ' . (int) ($file['error'] ?? -1) . ').'];
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > CT_UPLOAD_MAX_BYTES) {
        return ['error' => 'Datei zu gross (max ' . (CT_UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB).'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'Ungueltiger Upload.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($file['tmp_name']);
    if (!isset(CT_UPLOAD_ALLOWED_MIME[$mime])) {
        return ['error' => 'Dateityp nicht erlaubt (PDF, JPG, PNG, WebP, HEIC).'];
    }
    $ext = CT_UPLOAD_ALLOWED_MIME[$mime];

    $dir = ensure_upload_dir($userId);
    if (!$dir) {
        return ['error' => 'Upload-Verzeichnis nicht beschreibbar. Bitte Admin informieren.'];
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $absPath = $dir . '/' . $stored;
    if (!@move_uploaded_file($file['tmp_name'], $absPath)) {
        return ['error' => 'Datei konnte nicht abgelegt werden.'];
    }
    @chmod($absPath, 0644);

    $orig = (string) ($file['name'] ?? 'datei');
    if (mb_strlen($orig) > 200) $orig = mb_substr($orig, 0, 200);

    return [
        'original_name' => $orig,
        'stored_path'   => CT_UPLOAD_DIR_REL . '/' . $userId . '/' . $stored,
        'mime'          => $mime,
        'byte_size'     => $size,
    ];
}

function delete_evidence_file(string $relativePath): void {
    $abs = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    $base = realpath(upload_dir_abs());
    $real = realpath($abs);
    if ($base && $real && str_starts_with($real, $base) && is_file($real)) {
        @unlink($real);
    }
}

function evidence_for_entry(int $entryId): array {
    $stmt = db()->prepare("SELECT * FROM cpe_evidence WHERE cpe_entry_id=? ORDER BY id");
    $stmt->execute([$entryId]);
    return $stmt->fetchAll();
}

function evidence_icon(string $mime): string {
    if (str_starts_with($mime, 'image/')) return '🖼️';
    if ($mime === 'application/pdf')      return '📄';
    return '📎';
}

/* ---------- Pruefungs-Countdown & Lernempfehlungen ---------- */

function days_until(?string $isoDate): ?int {
    if (!$isoDate) return null;
    $d = DateTime::createFromFormat('Y-m-d', $isoDate);
    if (!$d) return null;
    $today = new DateTimeImmutable('today');
    $exam  = DateTimeImmutable::createFromMutable($d);
    return (int) $today->diff($exam)->format('%r%a');
}

function exam_countdown(int $userId, ?int $primaryCertId): ?array {
    if (!$primaryCertId) return null;
    $stmt = db()->prepare(
        "SELECT uc.target_exam_date, uc.started_at, c.code, c.name
         FROM user_certifications uc JOIN certifications c ON c.id = uc.certification_id
         WHERE uc.user_id=? AND uc.certification_id=?"
    );
    $stmt->execute([$userId, $primaryCertId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['target_exam_date'])) return null;

    $days = days_until($row['target_exam_date']);
    if ($days === null) return null;

    $startedAt = $row['started_at'] ? (new DateTimeImmutable((string) $row['started_at']))->format('Y-m-d') : null;
    $totalSpan = null;
    $progress  = null;
    if ($startedAt) {
        $start = days_until($startedAt);
        if ($start !== null && $days !== null) {
            $totalSpan = max(1, abs($days) + abs($start));
            $progress  = max(0, min(100, (int) round((abs($start) / $totalSpan) * 100)));
        }
    }

    return [
        'code'            => (string) $row['code'],
        'name'            => (string) $row['name'],
        'target_date'     => (string) $row['target_exam_date'],
        'days_remaining'  => $days,
        'progress_pct'    => $progress,
    ];
}

function study_recommendations(int $userId, ?int $primaryCertId): array {
    if (!$primaryCertId) return [];
    $pdo = db();
    $recs = [];

    $stmt = $pdo->prepare(
        "SELECT d.id, d.name,
                COALESCE(SUM(s.questions_total),0)   AS total,
                COALESCE(SUM(s.questions_correct),0) AS correct,
                MAX(s.session_date) AS last_seen
         FROM domains d
         LEFT JOIN study_sessions s ON s.domain_id = d.id AND s.user_id = ?
         WHERE d.certification_id = ?
         GROUP BY d.id, d.name"
    );
    $stmt->execute([$userId, $primaryCertId]);
    $domainRows = $stmt->fetchAll();

    $weakest = null;
    foreach ($domainRows as $r) {
        $t = (int) $r['total'];
        if ($t < 10) continue;
        $pct = ((int) $r['correct']) / $t;
        if ($weakest === null || $pct < $weakest['pct']) {
            $weakest = ['name' => (string) $r['name'], 'pct' => $pct];
        }
    }
    if ($weakest) {
        $recs[] = [
            'icon'  => '🎯',
            'title' => 'Schwaechste Domain',
            'body'  => sprintf('%s — aktuell %d%% richtig. Ein paar Fragen heute heben den Schnitt schnell.', $weakest['name'], (int) round($weakest['pct'] * 100)),
        ];
    }

    $unseen = null;
    $stale  = null;
    foreach ($domainRows as $r) {
        if ($r['last_seen'] === null) {
            if ($unseen === null) $unseen = (string) $r['name'];
        } else {
            $sinceDays = abs((int) days_until((string) $r['last_seen']));
            if ($sinceDays >= 7 && ($stale === null || $sinceDays > $stale['days'])) {
                $stale = ['name' => (string) $r['name'], 'days' => $sinceDays];
            }
        }
    }
    if ($unseen !== null) {
        $recs[] = [
            'icon'  => '🆕',
            'title' => 'Noch nie geuebt',
            'body'  => sprintf('%s steht noch leer. Heute den ersten Stich setzen.', $unseen),
        ];
    } elseif ($stale !== null) {
        $recs[] = [
            'icon'  => '🌫️',
            'title' => 'Vernachlaessigte Domain',
            'body'  => sprintf('%s — zuletzt vor %d Tagen. Kurz aufwaermen?', $stale['name'], $stale['days']),
        ];
    }

    $countdown = exam_countdown($userId, $primaryCertId);
    if ($countdown && $countdown['days_remaining'] > 0) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(questions_total),0) FROM study_sessions
             WHERE user_id=? AND certification_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)"
        );
        $stmt->execute([$userId, $primaryCertId]);
        $perDay = (int) $stmt->fetchColumn() / 14;

        $expected = $countdown['days_remaining'] <= 30 ? 8 : ($countdown['days_remaining'] <= 90 ? 5 : 3);
        if ($perDay < $expected - 1) {
            $recs[] = [
                'icon'  => '⏳',
                'title' => 'Pace',
                'body'  => sprintf('Pruefung in %d Tagen, dein Schnitt liegt bei %.1f Fragen/Tag. Empfohlen: ~%d/Tag.', $countdown['days_remaining'], $perDay, $expected),
            ];
        } else {
            $recs[] = [
                'icon'  => '✅',
                'title' => 'Im Pace',
                'body'  => sprintf('%.1f Fragen/Tag im Schnitt, passt zum Pruefungsziel in %d Tagen.', $perDay, $countdown['days_remaining']),
            ];
        }
    }

    $streak = get_streak($userId);
    if ((int) $streak['current'] === 0) {
        $stmt = $pdo->prepare("SELECT MAX(session_date) FROM study_sessions WHERE user_id=?");
        $stmt->execute([$userId]);
        $last = (string) ($stmt->fetchColumn() ?: '');
        if ($last !== '') {
            $missed = abs((int) days_until($last));
            if ($missed >= 1 && $missed <= 3) {
                $recs[] = [
                    'icon'  => '🔥',
                    'title' => 'Streak retten',
                    'body'  => sprintf('Streak ist seit %d Tag%s pausiert. 5 Fragen reichen schon.', $missed, $missed === 1 ? '' : 'en'),
                ];
            }
        }
    }

    return array_slice($recs, 0, 4);
}

/* ---------- Flash ---------- */

function flash(string $message, string $type = 'info'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
}

function flashes(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}
