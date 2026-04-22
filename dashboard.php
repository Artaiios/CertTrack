<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo = db();

$quickXp = null;
$quickError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_session') {
    csrf_check();
    $certId = (int) ($_POST['certification_id'] ?? 0);
    $total  = max(0, (int) ($_POST['questions_total'] ?? 0));
    $correct = max(0, min($total, (int) ($_POST['questions_correct'] ?? 0)));
    $duration = max(1, (int) ($_POST['duration_minutes'] ?? 15));

    $userCerts = user_certifications((int) $user['id']);
    $validCertIds = array_column($userCerts, 'id');

    if (!in_array($certId, array_map('intval', $validCertIds), true)) {
        $quickError = 'Bitte ein zugewiesenes Zertifizierungsthema waehlen.';
    } elseif ($total <= 0) {
        $quickError = 'Bitte mindestens eine Frage angeben.';
    } else {
        $streakBefore = get_streak((int) $user['id'])['current'];
        $wrong = $total - $correct;
        $xp = compute_session_xp($correct, $wrong, $streakBefore);

        $todayLocal = (new DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = $pdo->prepare(
            "INSERT INTO study_sessions
             (user_id, certification_id, domain_id, session_date, duration_minutes, questions_total, questions_correct, mood, notes, xp_awarded)
             VALUES (?,?,?,?,?,?,?,3,'',?)"
        );
        $stmt->execute([(int) $user['id'], $certId, null, $todayLocal, $duration, $total, $correct, $xp]);
        $sessionId = (int) $pdo->lastInsertId();
        award_xp((int) $user['id'], $xp, 'session', $sessionId, "Schnellsession: $correct/$total richtig");
        recompute_streak((int) $user['id']);
        $unlocked = evaluate_badges((int) $user['id']);
        $quickXp = $xp;
        if ($unlocked) {
            flash('Neue Badges: ' . implode(', ', $unlocked), 'success');
        }
        audit((int) $user['id'], 'quick_session', 'session', $sessionId);
    }
}

$totalXp = user_total_xp((int) $user['id']);
$level   = level_for_xp($totalXp);
$streak  = get_streak((int) $user['id']);
$userCerts = user_certifications((int) $user['id']);
$primaryCertId = primary_certification_id((int) $user['id']);
$defaultCertId = default_certification_id((int) $user['id'], $userCerts);

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(questions_total),0) total, COALESCE(SUM(questions_correct),0) correct, COALESCE(SUM(duration_minutes),0) minutes
     FROM study_sessions WHERE user_id=? AND session_date=?"
);
$stmt->execute([(int) $user['id'], $today]);
$todayStats = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT s.*, c.name AS cert_name, d.name AS domain_name
     FROM study_sessions s
     JOIN certifications c ON c.id = s.certification_id
     LEFT JOIN domains d ON d.id = s.domain_id
     WHERE s.user_id=?
     ORDER BY s.session_date DESC, s.id DESC
     LIMIT 5"
);
$stmt->execute([(int) $user['id']]);
$recent = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT b.* FROM user_badges ub JOIN badges b ON b.id=ub.badge_id WHERE ub.user_id=? ORDER BY ub.unlocked_at DESC LIMIT 8"
);
$stmt->execute([(int) $user['id']]);
$myBadges = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT u.id, u.name, us.current_streak, us.last_activity_date
     FROM buddies b
     JOIN users u ON u.id = b.buddy_user_id
     LEFT JOIN user_streaks us ON us.user_id = u.id
     WHERE b.user_id=? AND b.status='accepted'
     UNION
     SELECT u.id, u.name, us.current_streak, us.last_activity_date
     FROM buddies b
     JOIN users u ON u.id = b.user_id
     LEFT JOIN user_streaks us ON us.user_id = u.id
     WHERE b.buddy_user_id=? AND b.status='accepted'
     ORDER BY current_streak DESC LIMIT 3"
);
$stmt->execute([(int) $user['id'], (int) $user['id']]);
$buddyWidget = $stmt->fetchAll();

$announcements = active_announcements();
$countdown = exam_countdown((int) $user['id'], $primaryCertId);
$recommendations = study_recommendations((int) $user['id'], $primaryCertId);

layout_head('Dashboard', $user);
layout_nav($user);
?>
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <?php if ($quickXp !== null): ?>
        <div class="card p-4 animate-popin flex items-center gap-3" style="border-color:var(--brand)">
            <span class="text-3xl">⚡</span>
            <div>
                <div class="stat-num text-xl" style="color:var(--brand)">+<?= (int) $quickXp ?> XP</div>
                <div class="text-xs text-muted">Session erfasst. Weiter so.</div>
            </div>
        </div>
    <?php elseif ($quickError): ?>
        <div class="alert alert-error"><?= e($quickError) ?></div>
    <?php endif ?>

    <?php if ($countdown): ?>
        <section class="card p-5 flex flex-wrap items-center gap-4">
            <div class="flex-1 min-w-0">
                <div class="text-xs uppercase tracking-wider text-muted mb-1">
                    Countdown · <?= e($countdown['code']) ?>
                </div>
                <?php if ($countdown['days_remaining'] >= 0): ?>
                    <div class="stat-num text-4xl text-brand">
                        <?= (int) $countdown['days_remaining'] ?>
                        <span class="text-sm text-muted font-sans"><?= $countdown['days_remaining'] === 1 ? 'Tag' : 'Tage' ?> bis zur Pruefung</span>
                    </div>
                    <div class="text-xs text-muted mt-1">Termin: <?= e($countdown['target_date']) ?></div>
                    <?php if ($countdown['progress_pct'] !== null): ?>
                        <div class="mt-3 progress-track h-2 max-w-md">
                            <div class="progress-bar" style="width: <?= (int) $countdown['progress_pct'] ?>%"></div>
                        </div>
                    <?php endif ?>
                <?php else: ?>
                    <div class="stat-num text-3xl text-success">Pruefungstermin liegt zurueck</div>
                    <div class="text-xs text-muted mt-1">Datum war <?= e($countdown['target_date']) ?>. <a href="settings.php">Neues Datum setzen?</a></div>
                <?php endif ?>
            </div>
            <div class="text-5xl">🎓</div>
        </section>
    <?php elseif ($primaryCertId): ?>
        <div class="alert alert-info">
            Kein Pruefungsdatum gesetzt fuer dein Hauptthema.
            <a href="settings.php" class="underline">Hier Datum hinterlegen</a> — danach laeuft hier der Countdown.
        </div>
    <?php endif ?>

    <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card p-5">
            <div class="text-xs uppercase tracking-wider text-muted mb-1">Streak</div>
            <div class="flex items-baseline gap-2">
                <div class="stat-num text-5xl <?= $streak['current'] >= 7 ? 'glow-flame animate-flicker inline-block' : '' ?>" style="color:var(--ember)">
                    🔥 <?= (int) $streak['current'] ?>
                </div>
                <div class="text-sm text-muted">Tage</div>
            </div>
            <div class="text-xs text-muted mt-2">Bestmarke: <?= (int) $streak['longest'] ?> Tage</div>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wider text-muted mb-1">Level <?= (int) $level['index'] ?></div>
            <div class="text-2xl serif font-semibold" style="color:var(--brand)"><?= e($level['name']) ?></div>
            <div class="mt-3 progress-track h-2">
                <div class="progress-bar" style="width: <?= (int) $level['progress_pct'] ?>%; background:var(--brand)"></div>
            </div>
            <div class="text-xs text-muted mt-2">
                <?= number_format($totalXp, 0, ',', '.') ?> XP
                <?php if ($level['next_name']): ?>
                    · <?= number_format($level['xp_to_next'], 0, ',', '.') ?> bis <?= e($level['next_name']) ?>
                <?php else: ?>
                    · Endgegner geknackt.
                <?php endif ?>
            </div>
        </div>

        <div class="card p-5">
            <div class="text-xs uppercase tracking-wider text-muted mb-1">Heute</div>
            <div class="stat-num text-4xl text-success">
                <?= (int) $todayStats['correct'] ?>/<?= (int) $todayStats['total'] ?>
            </div>
            <div class="text-xs text-muted mt-2">
                Fragen · <?= (int) $todayStats['minutes'] ?> Min. Lernzeit
            </div>
        </div>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 card p-6">
            <div class="flex items-baseline justify-between mb-3 gap-2">
                <h2 class="text-2xl">Schnellerfassung</h2>
                <?php if ($userCerts): ?>
                    <a href="settings.php" class="text-xs text-muted hover:text-brand">Hauptthema aendern</a>
                <?php endif ?>
            </div>
            <p class="text-xs text-muted mb-4">Eben 20 Fragen gemacht? Hier rein, weiter im Tag.</p>
            <?php if (!$userCerts): ?>
                <div class="alert alert-warning">
                    Noch keinem Zertifizierungsthema zugeordnet.
                    <a href="settings.php" class="underline">Jetzt im Katalog auswaehlen</a> oder vom Admin zuweisen lassen.
                </div>
            <?php else: ?>
                <form method="post" class="grid grid-cols-2 sm:grid-cols-5 gap-3 items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="quick_session">
                    <div class="col-span-2 sm:col-span-2">
                        <label class="block text-xs uppercase tracking-wider text-muted mb-1">Thema</label>
                        <select name="certification_id" class="input w-full px-2 py-2 text-sm">
                            <?php foreach ($userCerts as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $defaultCertId ? 'selected' : '' ?>>
                                    <?= e($c['code']) ?><?= (int) $c['id'] === $primaryCertId ? ' ★' : '' ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wider text-muted mb-1">Fragen</label>
                        <input type="number" name="questions_total" min="1" max="500" required inputmode="numeric" class="input w-full px-2 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wider text-muted mb-1">Richtig</label>
                        <input type="number" name="questions_correct" min="0" max="500" required inputmode="numeric" class="input w-full px-2 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs uppercase tracking-wider text-muted mb-1">Min.</label>
                        <input type="number" name="duration_minutes" min="1" max="600" value="15" inputmode="numeric" class="input w-full px-2 py-2 text-sm">
                    </div>
                    <button class="btn btn-primary col-span-2 sm:col-span-5 justify-center">Erfassen</button>
                </form>
                <p class="text-xs text-muted mt-3">Mehr Details (Domain, Notizen, Stimmung)? <a href="session_log.php">Volle Session-Maske</a></p>
            <?php endif ?>
        </div>

        <div class="card p-6">
            <h2 class="text-2xl mb-3">Lernpartner</h2>
            <?php if (!$buddyWidget): ?>
                <p class="text-sm text-muted">Noch keine Partner. <a href="buddies.php">Jemanden einladen</a></p>
            <?php else: ?>
                <ul class="space-y-3 text-sm">
                    <?php foreach ($buddyWidget as $b): ?>
                        <li class="flex items-center justify-between">
                            <div>
                                <div class="font-medium"><?= e($b['name'] ?: '???') ?></div>
                                <div class="text-xs text-muted">letzte Aktivitaet: <?= e($b['last_activity_date'] ?: '–') ?></div>
                            </div>
                            <div class="font-semibold" style="color:var(--ember)">🔥 <?= (int) ($b['current_streak'] ?? 0) ?></div>
                        </li>
                    <?php endforeach ?>
                </ul>
                <a href="buddies.php" class="text-xs block mt-4">Alle Partner ansehen</a>
            <?php endif ?>
        </div>
    </section>

    <?php if ($recommendations): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-4">Heute empfohlen</h2>
            <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php foreach ($recommendations as $r): ?>
                    <li class="flex gap-3 p-3 rounded-lg border border-soft bg-soft">
                        <span class="text-2xl shrink-0"><?= $r['icon'] ?></span>
                        <div class="min-w-0">
                            <div class="font-semibold text-sm"><?= e($r['title']) ?></div>
                            <div class="text-xs text-muted mt-1"><?= e($r['body']) ?></div>
                        </div>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>

    <?php if ($announcements): ?>
        <section>
            <h2 class="text-2xl mb-3">Ankuendigungen</h2>
            <div class="space-y-3">
                <?php foreach ($announcements as $a): ?>
                    <div class="card p-4">
                        <div class="font-semibold serif text-lg" style="color:var(--brand)"><?= e($a['title']) ?></div>
                        <div class="text-sm mt-1 whitespace-pre-line"><?= e($a['body']) ?></div>
                    </div>
                <?php endforeach ?>
            </div>
        </section>
    <?php endif ?>

    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card p-6">
            <h2 class="text-2xl mb-3">Letzte Sessions</h2>
            <?php if (!$recent): ?>
                <p class="text-sm text-muted">Noch nichts erfasst. Der erste Klick ist der schwerste.</p>
            <?php else: ?>
                <ul class="divide-y divider text-sm">
                    <?php foreach ($recent as $r): ?>
                        <li class="py-2 flex items-center justify-between">
                            <div>
                                <div><?= e($r['session_date']) ?> · <?= e($r['cert_name']) ?></div>
                                <div class="text-xs text-muted"><?= e($r['domain_name'] ?? 'Allgemein') ?> · <?= (int) $r['questions_correct'] ?>/<?= (int) $r['questions_total'] ?> · <?= (int) $r['duration_minutes'] ?> Min</div>
                            </div>
                            <div class="text-xs font-semibold" style="color:var(--brand)">+<?= (int) $r['xp_awarded'] ?> XP</div>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>

        <div class="card p-6">
            <h2 class="text-2xl mb-3">Badges</h2>
            <?php if (!$myBadges): ?>
                <p class="text-sm text-muted">Noch keine Badges. Erste Session schaltet "Erster Schritt" frei.</p>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <?php foreach ($myBadges as $b): ?>
                        <div class="rounded-lg border border-soft bg-soft p-3 text-center">
                            <div class="text-2xl"><?= e($b['icon'] ?: '🏅') ?></div>
                            <div class="text-xs font-semibold mt-1"><?= e($b['name']) ?></div>
                            <div class="text-[10px] text-muted mt-1 leading-tight"><?= e($b['description']) ?></div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        </div>
    </section>
</main>
<?php layout_foot();
