<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo = db();

$userCerts = user_certifications((int) $user['id']);
$cats = cpe_categories();
$catById = [];
foreach ($cats as $c) { $catById[(int) $c['id']] = $c; }

$awarded = null;
$error = null;
$uploadIssues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $eid = (int) ($_POST['entry_id'] ?? 0);
        $owns = $pdo->prepare("SELECT 1 FROM cpe_entries WHERE id=? AND user_id=?");
        $owns->execute([$eid, (int) $user['id']]);
        if ($owns->fetchColumn()) {
            $files = $pdo->prepare("SELECT stored_path FROM cpe_evidence WHERE cpe_entry_id=?");
            $files->execute([$eid]);
            foreach ($files->fetchAll() as $f) {
                delete_evidence_file((string) $f['stored_path']);
            }
            $pdo->prepare("DELETE FROM cpe_entries WHERE id=? AND user_id=?")->execute([$eid, (int) $user['id']]);
            $pdo->prepare("DELETE FROM xp_log WHERE source_type='cpe' AND source_id=? AND user_id=?")
                ->execute([$eid, (int) $user['id']]);
            audit((int) $user['id'], 'cpe_deleted', 'cpe', $eid);
            flash('Eintrag samt Anhaengen geloescht.', 'success');
        }
        header('Location: cpe.php');
        exit;
    }

    if ($action === 'delete_evidence') {
        $evId = (int) ($_POST['evidence_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM cpe_evidence WHERE id=? AND user_id=?");
        $stmt->execute([$evId, (int) $user['id']]);
        $row = $stmt->fetch();
        if ($row) {
            delete_evidence_file((string) $row['stored_path']);
            $pdo->prepare("DELETE FROM cpe_evidence WHERE id=?")->execute([$evId]);
            audit((int) $user['id'], 'evidence_deleted', 'cpe_evidence', $evId);
            flash('Anhang geloescht.', 'success');
        }
        header('Location: cpe.php');
        exit;
    }

    if ($action === 'add_evidence') {
        $eid = (int) ($_POST['entry_id'] ?? 0);
        $owns = $pdo->prepare("SELECT 1 FROM cpe_entries WHERE id=? AND user_id=?");
        $owns->execute([$eid, (int) $user['id']]);
        if (!$owns->fetchColumn()) {
            flash('Eintrag nicht gefunden.', 'error');
            header('Location: cpe.php');
            exit;
        }
        $added = 0;
        if (!empty($_FILES['evidence']['name'][0])) {
            $files = $_FILES['evidence'];
            $count = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                if ((int) $files['size'][$i] === 0) continue;
                $single = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
                $r = store_evidence_file((int) $user['id'], $single);
                if (isset($r['error'])) {
                    $uploadIssues[] = $files['name'][$i] . ': ' . $r['error'];
                    continue;
                }
                $pdo->prepare(
                    "INSERT INTO cpe_evidence (cpe_entry_id, user_id, original_name, stored_path, mime, byte_size)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$eid, (int) $user['id'], $r['original_name'], $r['stored_path'], $r['mime'], $r['byte_size']]);
                $added++;
            }
        }
        evaluate_badges((int) $user['id']);
        if ($added) flash($added . ' Datei(en) hinzugefuegt.', 'success');
        if ($uploadIssues) flash(implode(' · ', $uploadIssues), 'warning');
        header('Location: cpe.php');
        exit;
    }

    // Default: create
    $catId   = (int) ($_POST['category_id'] ?? 0);
    $certId  = (int) ($_POST['certification_id'] ?? 0) ?: null;
    $date    = (string) ($_POST['entry_date'] ?? '');
    $hours   = (float) str_replace(',', '.', (string) ($_POST['hours'] ?? '0'));
    $desc    = trim((string) ($_POST['description'] ?? ''));
    if (mb_strlen($desc) > 500) $desc = mb_substr($desc, 0, 500);

    if (!isset($catById[$catId])) {
        $error = 'Bitte gueltige Kategorie waehlen.';
    } elseif (!DateTime::createFromFormat('Y-m-d', $date)) {
        $error = 'Bitte gueltiges Datum waehlen.';
    } elseif ($hours <= 0 || $hours > 100) {
        $error = 'Stunden zwischen 0,1 und 100 erlaubt.';
    } else {
        if ($certId) {
            $valid = array_map('intval', array_column($userCerts, 'id'));
            if (!in_array($certId, $valid, true)) $certId = null;
        }
        $cat = $catById[$catId];
        $doubles = (int) $cat['doubles'] === 1;
        $credited = $doubles ? $hours * 2 : $hours;

        $stmt = $pdo->prepare(
            "INSERT INTO cpe_entries (user_id, certification_id, category_id, entry_date, hours, credited_hours, description)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([(int) $user['id'], $certId, $catId, $date, $hours, $credited, $desc]);
        $eid = (int) $pdo->lastInsertId();
        $xp = award_cpe_xp((int) $user['id'], $hours, $doubles, $eid, (string) $cat['code']);

        $evidenceCount = 0;
        if (!empty($_FILES['evidence']['name'][0])) {
            $files = $_FILES['evidence'];
            $count = is_array($files['name']) ? count($files['name']) : 0;
            for ($i = 0; $i < $count; $i++) {
                if ((int) $files['size'][$i] === 0) continue;
                $single = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
                $r = store_evidence_file((int) $user['id'], $single);
                if (isset($r['error'])) {
                    $uploadIssues[] = $files['name'][$i] . ': ' . $r['error'];
                    continue;
                }
                $pdo->prepare(
                    "INSERT INTO cpe_evidence (cpe_entry_id, user_id, original_name, stored_path, mime, byte_size)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$eid, (int) $user['id'], $r['original_name'], $r['stored_path'], $r['mime'], $r['byte_size']]);
                $evidenceCount++;
            }
            if ($evidenceCount > 0) {
                $bonus = CT_XP_EVIDENCE_BONUS;
                award_xp((int) $user['id'], $bonus, 'cpe_evidence', $eid, "Evidence beigefuegt ($evidenceCount)");
                $xp += $bonus;
            }
        }
        $pdo->prepare("UPDATE cpe_entries SET xp_awarded=? WHERE id=?")->execute([$xp, $eid]);

        $unlocked = evaluate_badges((int) $user['id']);
        $awarded = ['xp' => $xp, 'badges' => $unlocked, 'evidence' => $evidenceCount];
        audit((int) $user['id'], 'cpe_logged', 'cpe', $eid);
    }
}

$year = (int) date('Y');
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(credited_hours),0) FROM cpe_entries WHERE user_id=? AND YEAR(entry_date)=?"
);
$stmt->execute([(int) $user['id'], $year]);
$yearHours = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(credited_hours),0) FROM cpe_entries WHERE user_id=? AND entry_date >= DATE_SUB(CURDATE(), INTERVAL 3 YEAR)"
);
$stmt->execute([(int) $user['id']]);
$threeYearHours = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT e.*, cat.name AS cat_name, cat.doubles, c.code AS cert_code
     FROM cpe_entries e
     JOIN cpe_categories cat ON cat.id = e.category_id
     LEFT JOIN certifications c ON c.id = e.certification_id
     WHERE e.user_id=?
     ORDER BY e.entry_date DESC, e.id DESC
     LIMIT 50"
);
$stmt->execute([(int) $user['id']]);
$entries = $stmt->fetchAll();

$evidenceByEntry = [];
if ($entries) {
    $ids = array_map(static fn($r) => (int) $r['id'], $entries);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $ev = $pdo->prepare("SELECT * FROM cpe_evidence WHERE cpe_entry_id IN ($place) ORDER BY id");
    $ev->execute($ids);
    foreach ($ev->fetchAll() as $row) {
        $evidenceByEntry[(int) $row['cpe_entry_id']][] = $row;
    }
}

$yearlyTarget = 20;
$totalTarget  = 120;
foreach ($userCerts as $c) {
    $yearlyTarget = max($yearlyTarget, (int) $c['cpe_yearly_target']);
    $totalTarget  = max($totalTarget,  (int) $c['cpe_total_target']);
}

$primaryCertId = primary_certification_id((int) $user['id']);

layout_head('CPE', $user);
layout_nav($user);
?>
<main class="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <?php if ($awarded): ?>
        <div class="card p-5 animate-popin" style="border-color:var(--brand)">
            <div class="text-2xl stat-num" style="color:var(--brand)">+<?= (int) $awarded['xp'] ?> XP</div>
            <div class="text-sm text-muted mt-1">
                CPE erfasst.
                <?= $awarded['evidence'] ? ' ' . (int) $awarded['evidence'] . ' Datei(en) angehaengt.' : '' ?>
                <?= $awarded['badges'] ? ' Neue Badges: ' . e(implode(', ', $awarded['badges'])) : '' ?>
            </div>
        </div>
    <?php endif ?>

    <?php if ($uploadIssues && !$awarded): ?>
        <div class="alert alert-warning"><?= e(implode(' · ', $uploadIssues)) ?></div>
    <?php endif ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif ?>

    <section class="card p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl mb-1">Nachweis exportieren</h2>
                <p class="text-xs text-muted">Fuer ISACA / ISC2 / CompTIA: Liste plus Originalbelege als ZIP, oder druckfreundliche Uebersicht.</p>
            </div>
            <form method="get" action="cpe_export.php" class="flex flex-wrap gap-2 items-end">
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Jahr</label>
                    <select name="year" class="input px-2 py-1.5 text-sm">
                        <option value="">alle</option>
                        <?php for ($y = $year; $y >= $year - 5; $y--): ?>
                            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs uppercase tracking-wider text-muted mb-1">Cert</label>
                    <select name="cert" class="input px-2 py-1.5 text-sm">
                        <option value="0">alle</option>
                        <?php foreach ($userCerts as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $primaryCertId ? 'selected' : '' ?>>
                                <?= e($c['code']) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <button type="submit" name="format" value="print" class="btn btn-ghost" title="Druckansicht / Browser-PDF">🖨 Drucken / PDF</button>
                <button type="submit" name="format" value="csv" class="btn btn-ghost" title="CSV (Excel-kompatibel)">📊 CSV</button>
                <button type="submit" name="format" value="zip" class="btn btn-primary" title="ZIP mit Liste + Belegen">📦 ZIP komplett</button>
            </form>
        </div>
    </section>

    <section class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="card p-5">
            <div class="text-xs uppercase tracking-wider text-muted mb-1">Jahr <?= $year ?></div>
            <div class="text-3xl stat-num" style="color:var(--brand)"><?= number_format($yearHours, 1, ',', '.') ?> h</div>
            <div class="text-xs text-muted mt-1">Ziel: <?= $yearlyTarget ?> h</div>
            <div class="mt-3 progress-track h-2">
                <div class="progress-bar" style="width: <?= min(100, $yearlyTarget ? round($yearHours/$yearlyTarget*100) : 0) ?>%; background:var(--brand)"></div>
            </div>
        </div>
        <div class="card p-5">
            <div class="text-xs uppercase tracking-wider text-muted mb-1">Letzte 3 Jahre</div>
            <div class="text-3xl stat-num text-success"><?= number_format($threeYearHours, 1, ',', '.') ?> h</div>
            <div class="text-xs text-muted mt-1">Ziel: <?= $totalTarget ?> h</div>
            <div class="mt-3 progress-track h-2">
                <div class="progress-bar" style="width: <?= min(100, $totalTarget ? round($threeYearHours/$totalTarget*100) : 0) ?>%; background:var(--success)"></div>
            </div>
        </div>
    </section>

    <section class="card p-6">
        <h1 class="text-2xl mb-1">CPE-Aktivitaet erfassen</h1>
        <p class="text-muted text-sm mb-5">Webinar, Konferenz, Vortrag, Lesen, Ehrenamt — und gleich Belege mit hochladen.</p>
        <form method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Datum</label>
                <input type="date" name="entry_date" value="<?= e(date('Y-m-d')) ?>" required class="input w-full px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Stunden</label>
                <input type="number" name="hours" min="0.1" max="100" step="0.25" required class="input w-full px-3 py-2 text-sm" inputmode="decimal">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Kategorie</label>
                <select name="category_id" required class="input w-full px-3 py-2 text-sm">
                    <?php foreach ($cats as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?><?= $c['doubles'] ? ' (zaehlt doppelt)' : '' ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Zuordnung</label>
                <select name="certification_id" class="input w-full px-3 py-2 text-sm">
                    <option value="0">Allgemein (alle Zertifikate)</option>
                    <?php foreach ($userCerts as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $primaryCertId ? 'selected' : '' ?>>
                            <?= e($c['code']) ?> — nur fuer dieses Zertifikat
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Beschreibung</label>
                <input type="text" name="description" maxlength="500" placeholder="z.B. Webinar 'Zero Trust Architectures'" class="input w-full px-3 py-2 text-sm">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs uppercase tracking-wider text-muted mb-2">Beleg / Evidence (PDF, JPG, PNG, WebP, HEIC · max <?= (int) (CT_UPLOAD_MAX_BYTES/1024/1024) ?> MB pro Datei)</label>
                <label class="btn btn-ghost w-full cursor-pointer justify-center">
                    <span>📁 Datei waehlen, Foto aufnehmen oder Galerie</span>
                    <input type="file" name="evidence[]" multiple accept="image/*,application/pdf" class="hidden" id="evFiles" onchange="updateEvLabel(this)">
                </label>
                <div id="evFilesLabel" class="text-xs text-muted mt-2"></div>
                <p class="text-xs text-muted mt-2">Optional. Auf dem Smartphone oeffnet das einen Picker mit Kamera, Galerie und Dateien. Du kannst Belege auch spaeter pro Eintrag nachreichen.</p>
            </div>
            <div class="md:col-span-2 pt-2">
                <button class="btn btn-primary">Erfassen &amp; XP einsacken</button>
            </div>
        </form>
        <script>
            function updateEvLabel(input) {
                const lbl = document.getElementById('evFilesLabel');
                if (!lbl) return;
                if (!input.files || !input.files.length) { lbl.textContent = ''; return; }
                const names = Array.from(input.files).map(f => f.name + ' (' + (f.size/1024).toFixed(0) + ' KB)').join(', ');
                lbl.textContent = '✓ ' + names;
            }
        </script>
    </section>

    <section class="card p-6">
        <h2 class="text-2xl mb-4">Letzte Eintraege</h2>
        <?php if (!$entries): ?>
            <p class="text-sm text-muted">Noch nichts erfasst.</p>
        <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($entries as $en):
                    $ev = $evidenceByEntry[(int) $en['id']] ?? [];
                ?>
                    <li class="border border-soft rounded-lg p-4">
                        <div class="flex flex-wrap justify-between items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium"><?= e($en['entry_date']) ?></span>
                                    <span class="chip"><?= e($en['cat_name']) ?></span>
                                    <?php if ($en['cert_code']): ?>
                                        <span class="chip chip-brand"><?= e($en['cert_code']) ?></span>
                                    <?php else: ?>
                                        <span class="chip">allgemein</span>
                                    <?php endif ?>
                                    <?php if ($en['doubles']): ?><span class="chip chip-warning">×2</span><?php endif ?>
                                </div>
                                <?php if ($en['description']): ?>
                                    <div class="text-sm mt-2"><?= e($en['description']) ?></div>
                                <?php endif ?>
                                <div class="text-xs text-muted mt-2">
                                    <?= number_format((float) $en['hours'], 2, ',', '.') ?> h
                                    · angerechnet <?= number_format((float) $en['credited_hours'], 2, ',', '.') ?> h
                                    · <span style="color:var(--brand)">+<?= (int) $en['xp_awarded'] ?> XP</span>
                                </div>
                            </div>
                            <form method="post" onsubmit="return confirm('Eintrag samt Anhaengen loeschen?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>">
                                <button class="text-xs text-red-700 hover:underline">loeschen</button>
                            </form>
                        </div>

                        <?php if ($ev): ?>
                            <ul class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <?php foreach ($ev as $f): ?>
                                    <li class="flex items-center justify-between gap-2 border border-soft rounded p-2 bg-soft">
                                        <a href="evidence.php?id=<?= (int) $f['id'] ?>" target="_blank" rel="noopener" class="flex items-center gap-2 min-w-0 flex-1">
                                            <span class="text-lg leading-none"><?= evidence_icon((string) $f['mime']) ?></span>
                                            <span class="truncate text-sm"><?= e($f['original_name']) ?></span>
                                            <span class="text-xs text-muted shrink-0"><?= number_format((int) $f['byte_size']/1024, 0, ',', '.') ?> KB</span>
                                        </a>
                                        <div class="flex items-center gap-1 shrink-0">
                                            <a href="evidence.php?id=<?= (int) $f['id'] ?>&dl=1" class="text-xs text-muted hover:text-brand" title="Herunterladen">⬇</a>
                                            <form method="post" onsubmit="return confirm('Anhang loeschen?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_evidence">
                                                <input type="hidden" name="evidence_id" value="<?= (int) $f['id'] ?>">
                                                <button class="text-xs text-red-700 hover:underline" title="Loeschen">×</button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>

                        <details class="mt-3">
                            <summary class="text-xs text-brand cursor-pointer hover:underline">+ Beleg nachreichen</summary>
                            <form method="post" enctype="multipart/form-data" class="mt-2 flex flex-col sm:flex-row gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_evidence">
                                <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>">
                                <input type="file" name="evidence[]" multiple accept="image/*,application/pdf" required class="text-sm flex-1">
                                <button class="btn btn-primary">Hochladen</button>
                            </form>
                        </details>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
</main>
<?php layout_foot();
