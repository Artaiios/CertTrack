<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo = db();

$userCerts = user_certifications((int) $user['id']);
$primaryCertId = primary_certification_id((int) $user['id']);
$defaultCertId = default_certification_id((int) $user['id'], $userCerts);
$selectedCertId = (int) ($_GET['cert'] ?? $defaultCertId);
$selectedCert = null;
foreach ($userCerts as $c) {
    if ((int) $c['id'] === $selectedCertId) { $selectedCert = $c; break; }
}
if (!$selectedCert && $userCerts) {
    $selectedCert = $userCerts[0];
    $selectedCertId = (int) $selectedCert['id'];
}

$domainStats = [];
if ($selectedCertId) {
    $stmt = $pdo->prepare(
        "SELECT d.id, d.name,
                COALESCE(SUM(s.questions_total),0) total,
                COALESCE(SUM(s.questions_correct),0) correct
         FROM domains d
         LEFT JOIN study_sessions s ON s.domain_id = d.id AND s.user_id=?
         WHERE d.certification_id = ?
         GROUP BY d.id
         ORDER BY d.sort_order, d.name"
    );
    $stmt->execute([(int) $user['id'], $selectedCertId]);
    $domainStats = $stmt->fetchAll();
}

$stmt = $pdo->prepare(
    "SELECT DATE(created_at) d, SUM(amount) a
     FROM xp_log WHERE user_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
     GROUP BY DATE(created_at) ORDER BY d"
);
$stmt->execute([(int) $user['id']]);
$rows = $stmt->fetchAll();

$xpSeries = [];
$priorStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM xp_log WHERE user_id=? AND created_at < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
$priorStmt->execute([(int) $user['id']]);
$cum = (int) $priorStmt->fetchColumn();
foreach ($rows as $r) {
    $cum += (int) $r['a'];
    $xpSeries[] = ['date' => $r['d'], 'xp' => $cum];
}

$stmt = $pdo->prepare(
    "SELECT DAYOFWEEK(session_date) dow, COALESCE(SUM(questions_total),0) qs, COUNT(*) sessions
     FROM study_sessions WHERE user_id=? AND session_date >= DATE_SUB(CURDATE(), INTERVAL 84 DAY)
     GROUP BY DAYOFWEEK(session_date)"
);
$stmt->execute([(int) $user['id']]);
$dowMap = [];
foreach ($stmt->fetchAll() as $r) { $dowMap[(int) $r['dow']] = (int) $r['qs']; }
$dayNames = ['So','Mo','Di','Mi','Do','Fr','Sa'];
$heat = [];
$max = 0;
for ($i = 1; $i <= 7; $i++) {
    $v = (int) ($dowMap[$i] ?? 0);
    if ($v > $max) $max = $v;
    $heat[] = ['name' => $dayNames[$i-1], 'q' => $v];
}

$stmt = $pdo->prepare(
    "SELECT u.id, u.name, us.current_streak, us.longest_streak,
            (SELECT COALESCE(SUM(amount),0) FROM xp_log WHERE user_id=u.id) AS xp
     FROM buddies b
     JOIN users u ON u.id = b.buddy_user_id
     LEFT JOIN user_streaks us ON us.user_id=u.id
     WHERE b.user_id=? AND b.status='accepted' AND b.share_progress=1
     UNION
     SELECT u.id, u.name, us.current_streak, us.longest_streak,
            (SELECT COALESCE(SUM(amount),0) FROM xp_log WHERE user_id=u.id) AS xp
     FROM buddies b
     JOIN users u ON u.id = b.user_id
     LEFT JOIN user_streaks us ON us.user_id=u.id
     WHERE b.buddy_user_id=? AND b.status='accepted' AND b.share_progress=1"
);
$stmt->execute([(int) $user['id'], (int) $user['id']]);
$buddyStats = $stmt->fetchAll();

$myXp = user_total_xp((int) $user['id']);
$myStreak = get_streak((int) $user['id']);

layout_head('Fortschritt', $user);
layout_nav($user);
?>
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <section class="card p-5">
        <form method="get" class="flex items-center gap-3 text-sm flex-wrap">
            <label class="text-muted">Thema:</label>
            <select name="cert" onchange="this.form.submit()" class="input px-3 py-1.5">
                <?php foreach ($userCerts as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $c['id']==$selectedCertId?'selected':'' ?>>
                        <?= e($c['name']) ?><?= (int) $c['id'] === $primaryCertId ? ' ★' : '' ?>
                    </option>
                <?php endforeach ?>
            </select>
            <?php if ($selectedCertId && $selectedCertId !== $primaryCertId): ?>
                <a href="settings.php" class="text-xs text-muted">★ als Hauptthema setzen?</a>
            <?php endif ?>
        </form>
    </section>

    <section class="card p-6">
        <h2 class="text-2xl mb-4">Domain-Fortschritt<?= $selectedCert ? ' · ' . e($selectedCert['code']) : '' ?></h2>
        <?php if (!$domainStats): ?>
            <p class="text-sm text-muted">Keine Domains konfiguriert oder noch keine Daten.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($domainStats as $d):
                    $t = (int) $d['total'];
                    $c = (int) $d['correct'];
                    $pct = $t > 0 ? round($c / $t * 100) : 0;
                    $colour = $pct >= 80 ? '#047857' : ($pct >= 60 ? '#1E3A8A' : ($pct >= 40 ? '#B45309' : '#B91C1C'));
                ?>
                    <div>
                        <div class="flex justify-between text-sm mb-1">
                            <span><?= e($d['name']) ?></span>
                            <span class="text-muted text-xs"><?= $c ?>/<?= $t ?> · <strong style="color:var(--ink)"><?= $pct ?>%</strong></span>
                        </div>
                        <div class="progress-track h-2">
                            <div class="progress-bar" style="width: <?= $pct ?>%; background:<?= $colour ?>"></div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endif ?>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="card p-6">
            <h2 class="text-2xl mb-3">XP-Verlauf (90 Tage)</h2>
            <canvas id="xpChart" height="160"></canvas>
        </div>
        <div class="card p-6">
            <h2 class="text-2xl mb-1">Wann lernst du?</h2>
            <p class="text-xs text-muted mb-4">Fragen pro Wochentag (letzte 12 Wochen)</p>
            <div class="grid grid-cols-7 gap-2 text-center text-xs">
                <?php foreach ($heat as $h):
                    $intensity = $max > 0 ? min(1, $h['q'] / $max) : 0;
                    $alpha = 0.10 + $intensity * 0.85;
                    $strong = $intensity > 0.55 ? ' heat-strong' : '';
                ?>
                    <div>
                        <div class="heat-cell<?= $strong ?> rounded h-16 flex items-end justify-center pb-1 font-semibold"
                             style="--alpha: <?= number_format($alpha, 2, '.', '') ?>;">
                            <?= (int) $h['q'] ?>
                        </div>
                        <div class="text-muted mt-1"><?= $h['name'] ?></div>
                    </div>
                <?php endforeach ?>
            </div>
            <style>
                .heat-strong { color:#fff; }
                [data-theme="hc"] .heat-strong { color:#000; font-weight:700; }
            </style>
        </div>
    </section>

    <?php if ($buddyStats): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-3">Im Vergleich mit Lernpartnern</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-muted">
                        <tr>
                            <th class="text-left py-2">Wer</th>
                            <th class="text-right py-2">Aktueller Streak</th>
                            <th class="text-right py-2">Bestmarke</th>
                            <th class="text-right py-2">XP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="table-row bg-soft">
                            <td class="py-2 font-semibold text-brand">Du</td>
                            <td class="py-2 text-right">🔥 <?= (int) $myStreak['current'] ?></td>
                            <td class="py-2 text-right"><?= (int) $myStreak['longest'] ?></td>
                            <td class="py-2 text-right"><?= number_format($myXp, 0, ',', '.') ?></td>
                        </tr>
                        <?php foreach ($buddyStats as $b): ?>
                            <tr class="table-row">
                                <td class="py-2"><?= e($b['name'] ?: '???') ?></td>
                                <td class="py-2 text-right">🔥 <?= (int) ($b['current_streak'] ?? 0) ?></td>
                                <td class="py-2 text-right"><?= (int) ($b['longest_streak'] ?? 0) ?></td>
                                <td class="py-2 text-right"><?= number_format((int) ($b['xp'] ?? 0), 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    const xpData = <?= json_encode($xpSeries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const ctx = document.getElementById('xpChart');
    const themeName = document.documentElement.dataset.theme || 'light';
    const palette = {
        light: { line:'#1E3A8A', fill:'rgba(30,58,138,.12)',  text:'#64748b', grid:'rgba(100,116,139,.10)' },
        dark:  { line:'#60A5FA', fill:'rgba(96,165,250,.18)', text:'#94a3b8', grid:'rgba(148,163,184,.12)' },
        hc:    { line:'#FFEB3B', fill:'rgba(255,235,59,.20)', text:'#FFFFFF', grid:'rgba(255,255,255,.25)' }
    };
    const c = palette[themeName] || palette.light;
    if (ctx && xpData.length) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: xpData.map(p => p.date),
                datasets: [{
                    label: 'XP gesamt',
                    data: xpData.map(p => p.xp),
                    borderColor: c.line,
                    backgroundColor: c.fill,
                    fill: true,
                    tension: 0.25,
                    pointRadius: 0,
                    borderWidth: themeName === 'hc' ? 3 : 2,
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: c.text, maxTicksLimit: 8 }, grid: { color: c.grid } },
                    y: { ticks: { color: c.text }, grid: { color: c.grid } }
                }
            }
        });
    } else if (ctx) {
        ctx.replaceWith(Object.assign(document.createElement('div'), {
            className: 'text-sm text-muted',
            textContent: 'Noch keine XP-Daten in den letzten 90 Tagen.'
        }));
    }
</script>
<?php layout_foot();
