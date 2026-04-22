<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo = db();

$userCerts = user_certifications((int) $user['id']);
$primaryCertId = primary_certification_id((int) $user['id']);
$defaultCertId = default_certification_id((int) $user['id'], $userCerts);
$awarded = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $certId    = (int) ($_POST['certification_id'] ?? 0);
    $domainId  = (int) ($_POST['domain_id'] ?? 0) ?: null;
    $date      = (string) ($_POST['session_date'] ?? '');
    $duration  = max(1, min(720, (int) ($_POST['duration_minutes'] ?? 0)));
    $total     = max(0, min(2000, (int) ($_POST['questions_total'] ?? 0)));
    $correct   = max(0, min($total, (int) ($_POST['questions_correct'] ?? 0)));
    $mood      = (int) ($_POST['mood'] ?? 3);
    if ($mood < 1 || $mood > 5) $mood = 3;
    $notes     = trim((string) ($_POST['notes'] ?? ''));
    if (mb_strlen($notes) > 4000) $notes = mb_substr($notes, 0, 4000);

    $validCertIds = array_map('intval', array_column($userCerts, 'id'));
    $dateOk = (bool) DateTime::createFromFormat('Y-m-d', $date);

    if (!in_array($certId, $validCertIds, true)) {
        $error = 'Bitte ein zugewiesenes Zertifizierungsthema waehlen.';
    } elseif (!$dateOk) {
        $error = 'Bitte ein gueltiges Datum waehlen.';
    } else {
        if ($domainId) {
            $check = $pdo->prepare("SELECT 1 FROM domains WHERE id=? AND certification_id=?");
            $check->execute([$domainId, $certId]);
            if (!$check->fetchColumn()) $domainId = null;
        }

        $streakBefore = get_streak((int) $user['id'])['current'];
        $wrong = $total - $correct;
        $xp = compute_session_xp($correct, $wrong, $streakBefore);

        $stmt = $pdo->prepare(
            "INSERT INTO study_sessions
             (user_id, certification_id, domain_id, session_date, duration_minutes, questions_total, questions_correct, mood, notes, xp_awarded)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([(int) $user['id'], $certId, $domainId, $date, $duration, $total, $correct, $mood, $notes, $xp]);
        $sid = (int) $pdo->lastInsertId();
        award_xp((int) $user['id'], $xp, 'session', $sid, "Session $correct/$total");
        recompute_streak((int) $user['id']);
        $unlocked = evaluate_badges((int) $user['id']);
        $awarded = ['xp' => $xp, 'badges' => $unlocked];
        audit((int) $user['id'], 'session_logged', 'session', $sid);
    }
}

$stmt = $pdo->prepare(
    "SELECT s.*, c.code AS cert_code, d.name AS domain_name
     FROM study_sessions s
     JOIN certifications c ON c.id=s.certification_id
     LEFT JOIN domains d ON d.id=s.domain_id
     WHERE s.user_id=?
     ORDER BY s.session_date DESC, s.id DESC
     LIMIT 20"
);
$stmt->execute([(int) $user['id']]);
$recent = $stmt->fetchAll();

$domainsByCert = [];
foreach ($userCerts as $c) {
    $domainsByCert[(int) $c['id']] = domains_for((int) $c['id']);
}

layout_head('Lernsession', $user);
layout_nav($user);
?>
<main class="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <?php if ($awarded): ?>
        <div class="card p-5 animate-popin" style="border-color:var(--brand)">
            <div class="text-2xl stat-num" style="color:var(--brand)">+<?= (int) $awarded['xp'] ?> XP</div>
            <div class="text-sm text-muted mt-1">Session erfasst. <?= $awarded['badges'] ? 'Neue Badges: ' . e(implode(', ', $awarded['badges'])) : 'Streak gesichert.' ?></div>
        </div>
    <?php endif ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif ?>

    <section class="card p-6">
        <h1 class="text-2xl mb-1">Neue Lernsession</h1>
        <p class="text-muted text-sm mb-5">Volle Erfassung mit Domain, Stimmung und Notizen.</p>

        <?php if (!$userCerts): ?>
            <div class="alert alert-warning">
                Noch keinem Zertifizierungsthema zugeordnet. <a href="settings.php" class="underline">Im Katalog auswaehlen</a>.
            </div>
        <?php else: ?>
            <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?= csrf_field() ?>
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Datum</label>
                    <input type="date" name="session_date" required value="<?= e(date('Y-m-d')) ?>" class="input w-full px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Dauer (Minuten)</label>
                    <input type="number" name="duration_minutes" min="1" max="600" value="30" inputmode="numeric" class="input w-full px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Thema</label>
                    <select name="certification_id" id="certSelect" class="input w-full px-3 py-2 text-sm">
                        <?php foreach ($userCerts as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $defaultCertId ? 'selected' : '' ?>>
                                <?= e($c['name']) ?><?= (int) $c['id'] === $primaryCertId ? ' ★' : '' ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Domain</label>
                    <select name="domain_id" id="domainSelect" class="input w-full px-3 py-2 text-sm">
                        <option value="0">Allgemein / keine Domain</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Fragen gesamt</label>
                    <input type="number" name="questions_total" min="0" max="2000" required inputmode="numeric" class="input w-full px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">davon richtig</label>
                    <input type="number" name="questions_correct" min="0" max="2000" required inputmode="numeric" class="input w-full px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs uppercase tracking-wider text-muted mb-2">Stimmung</label>
                    <div class="flex gap-2 text-2xl" id="moodGroup">
                        <?php foreach ([1=>'😫',2=>'😕',3=>'😐',4=>'🙂',5=>'🚀'] as $val => $emoji): ?>
                            <label class="cursor-pointer">
                                <input type="radio" name="mood" value="<?= $val ?>" <?= $val===3?'checked':'' ?> class="hidden peer">
                                <span class="mood-pill"><?= $emoji ?></span>
                            </label>
                        <?php endforeach ?>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Notizen</label>
                    <textarea name="notes" rows="4" placeholder="Was ist heute aufgefallen? Was bleibt haengen?" class="input w-full px-3 py-2 text-sm"></textarea>
                </div>
                <div class="md:col-span-2">
                    <button class="btn btn-primary">Erfassen &amp; XP einsacken</button>
                </div>
            </form>
            <script>
                const domains = <?= json_encode($domainsByCert, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const certSel = document.getElementById('certSelect');
                const domSel  = document.getElementById('domainSelect');
                function refreshDomains() {
                    const cid = parseInt(certSel.value, 10);
                    const opts = domains[cid] || [];
                    domSel.innerHTML = '<option value="0">Allgemein / keine Domain</option>'
                        + opts.map(d => `<option value="${d.id}">${d.name}</option>`).join('');
                }
                certSel.addEventListener('change', refreshDomains);
                refreshDomains();
            </script>
        <?php endif ?>
    </section>

    <section class="card p-6">
        <h2 class="text-2xl mb-3">Letzte Sessions</h2>
        <?php if (!$recent): ?>
            <p class="text-sm text-muted">Hier landet deine Session-Historie.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-muted">
                        <tr>
                            <th class="text-left py-2">Datum</th>
                            <th class="text-left py-2">Thema</th>
                            <th class="text-left py-2">Domain</th>
                            <th class="text-right py-2">Fragen</th>
                            <th class="text-right py-2">Min.</th>
                            <th class="text-right py-2">Mood</th>
                            <th class="text-right py-2">XP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr class="table-row">
                            <td class="py-2"><?= e($r['session_date']) ?></td>
                            <td class="py-2"><?= e($r['cert_code']) ?></td>
                            <td class="py-2 text-muted"><?= e($r['domain_name'] ?? '–') ?></td>
                            <td class="py-2 text-right"><?= (int) $r['questions_correct'] ?>/<?= (int) $r['questions_total'] ?></td>
                            <td class="py-2 text-right"><?= (int) $r['duration_minutes'] ?></td>
                            <td class="py-2 text-right"><?= ['😫','😕','😐','🙂','🚀'][(int)$r['mood']-1] ?? '😐' ?></td>
                            <td class="py-2 text-right" style="color:var(--brand)">+<?= (int) $r['xp_awarded'] ?></td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php endif ?>
    </section>
</main>
<?php layout_foot();
