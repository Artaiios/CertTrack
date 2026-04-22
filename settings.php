<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo  = db();
$userCerts = user_certifications((int) $user['id']);
$allCerts  = all_certifications();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $error = 'Name darf nicht leer sein.';
        } elseif (mb_strlen($name) > 120) {
            $error = 'Name zu lang (max 120 Zeichen).';
        } else {
            $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name, (int) $user['id']]);
            flash('Profil gespeichert.', 'success');
            audit((int) $user['id'], 'profile_updated');
        }
    }

    elseif ($action === 'set_primary') {
        $cid = (int) ($_POST['certification_id'] ?? 0);
        if ($cid > 0) {
            $valid = array_map('intval', array_column($userCerts, 'id'));
            if (!in_array($cid, $valid, true)) {
                $error = 'Du bist diesem Thema nicht zugeordnet.';
            } else {
                set_primary_certification((int) $user['id'], $cid);
                flash('Hauptthema gesetzt.', 'success');
            }
        } else {
            set_primary_certification((int) $user['id'], null);
            flash('Hauptthema entfernt.', 'info');
        }
    }

    elseif ($action === 'self_enroll') {
        $cid = (int) ($_POST['certification_id'] ?? 0);
        if ($cid > 0) {
            $check = $pdo->prepare("SELECT 1 FROM certifications WHERE id=? AND is_active=1");
            $check->execute([$cid]);
            if (!$check->fetchColumn()) {
                $error = 'Diese Zertifizierung gibt es nicht (mehr).';
            } else {
                $exam = trim((string) ($_POST['target_exam_date'] ?? ''));
                $examOk = $exam === '' ? null : (DateTime::createFromFormat('Y-m-d', $exam) ? $exam : null);
                $pdo->prepare(
                    "INSERT INTO user_certifications (user_id, certification_id, target_exam_date)
                     VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE target_exam_date = VALUES(target_exam_date)"
                )->execute([(int) $user['id'], $cid, $examOk]);
                flash('Zertifizierung in deinem Katalog.', 'success');
                audit((int) $user['id'], 'self_enroll', 'certification', $cid);
            }
        }
    }

    elseif ($action === 'unenroll') {
        $cid = (int) ($_POST['certification_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM user_certifications WHERE user_id=? AND certification_id=?");
        $stmt->execute([(int) $user['id'], $cid]);
        $pdo->prepare("UPDATE users SET primary_certification_id=NULL WHERE id=? AND primary_certification_id=?")
            ->execute([(int) $user['id'], $cid]);
        flash('Zertifizierung entfernt.', 'info');
    }

    if (!$error) {
        header('Location: settings.php');
        exit;
    }
}

$primary = primary_certification_id((int) $user['id']);
$enrolledIds = array_map('intval', array_column($userCerts, 'id'));
$availableCerts = array_filter($allCerts, static fn($c) => !in_array((int) $c['id'], $enrolledIds, true));
$themeNow = current_theme($user);

layout_head('Einstellungen', $user);
layout_nav($user);
?>
<main class="max-w-3xl mx-auto px-4 sm:px-6 py-8 space-y-8">

    <header>
        <h1 class="text-3xl mb-1">Einstellungen</h1>
        <p class="text-muted text-sm">Profil, Hauptthema und persoenlicher Katalog.</p>
    </header>

    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif ?>

    <section class="card p-6">
        <h2 class="text-xl mb-1">Theme</h2>
        <p class="text-muted text-sm mb-4">Drei Modi. Wechsel jederzeit oben rechts in der Navigation.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <?php foreach (valid_themes() as $tk):
                $tm = theme_meta($tk);
                $isActive = $tk === $themeNow;
            ?>
                <form method="post" action="theme.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return" value="settings.php">
                    <input type="hidden" name="theme" value="<?= e($tk) ?>">
                    <button type="submit"
                            class="w-full text-left rounded-xl p-4 transition border"
                            style="background: <?= e($tm['color']) ?>; border-color: <?= $isActive ? 'var(--brand)' : 'var(--line)' ?>; border-width: <?= $isActive ? '2px' : '1px' ?>; color: <?= $tk === 'light' ? '#1A1F2E' : '#FFFFFF' ?>;<?= $tk === 'hc' ? ' outline: 1px solid #FFEB3B; outline-offset: -4px;' : '' ?>">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-2xl"><?= $tm['icon'] ?></span>
                            <?php if ($isActive): ?>
                                <span class="text-xs font-semibold" style="color: <?= $tk === 'light' ? 'var(--brand)' : ($tk === 'hc' ? '#FFEB3B' : '#60A5FA') ?>;">★ aktiv</span>
                            <?php endif ?>
                        </div>
                        <div class="font-serif text-lg font-semibold"><?= e($tm['label']) ?></div>
                        <div class="text-xs mt-1 opacity-80"><?= e($tm['desc']) ?></div>
                    </button>
                </form>
            <?php endforeach ?>
        </div>
    </section>

    <section class="card p-6">
        <h2 class="text-xl mb-4">Profil</h2>
        <form method="post" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_profile">
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">Name</label>
                <input type="text" name="name" value="<?= e($user['name']) ?>" maxlength="120" required class="input w-full px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs uppercase tracking-wider text-muted mb-1">E-Mail</label>
                <div class="text-sm"><?= e($user['email']) ?> <span class="chip ml-2">nicht aenderbar</span></div>
            </div>
            <button class="btn btn-primary">Profil speichern</button>
        </form>
    </section>

    <section class="card p-6">
        <h2 class="text-xl mb-1">Hauptthema</h2>
        <p class="text-muted text-sm mb-4">Wird im Dashboard, Session-Log und in der Fortschrittsansicht vorausgewaehlt.</p>
        <?php if (!$userCerts): ?>
            <p class="text-sm text-muted">Du bist noch keinem Zertifizierungsthema zugeordnet. Waehl unten eines aus dem Katalog aus oder bitte den Admin.</p>
        <?php else: ?>
            <form method="post" class="flex flex-wrap items-center gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_primary">
                <select name="certification_id" class="input px-3 py-2 text-sm">
                    <option value="0">— kein Hauptthema —</option>
                    <?php foreach ($userCerts as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= $primary === (int) $c['id'] ? 'selected' : '' ?>>
                            <?= e($c['code']) ?> — <?= e($c['name']) ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <button class="btn btn-primary">Speichern</button>
            </form>
        <?php endif ?>
    </section>

    <section class="card p-6">
        <h2 class="text-xl mb-1">Mein Katalog</h2>
        <p class="text-muted text-sm mb-4">Zertifizierungen, die du verfolgst. Du kannst neue selbst hinzufuegen oder welche entfernen.</p>

        <?php if ($userCerts): ?>
            <ul class="space-y-2 mb-6">
                <?php foreach ($userCerts as $c): ?>
                    <li class="flex flex-wrap items-center justify-between gap-3 border border-soft rounded-lg p-3">
                        <div>
                            <div class="font-medium"><?= e($c['code']) ?> · <span class="text-muted font-normal"><?= e($c['name']) ?></span></div>
                            <?php if (!empty($c['target_exam_date'])): ?>
                                <div class="text-xs text-muted">Zielpruefung: <?= e($c['target_exam_date']) ?></div>
                            <?php endif ?>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($primary === (int) $c['id']): ?>
                                <span class="chip chip-ember">★ Hauptthema</span>
                            <?php endif ?>
                            <form method="post" onsubmit="return confirm('Diese Zertifizierung aus deinem Katalog entfernen? Sessions und CPEs bleiben erhalten.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="unenroll">
                                <input type="hidden" name="certification_id" value="<?= (int) $c['id'] ?>">
                                <button class="text-xs text-red-700 hover:underline">entfernen</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <?php if ($availableCerts): ?>
            <h3 class="text-sm font-semibold mb-2 mt-2">Hinzufuegen</h3>
            <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="self_enroll">
                <select name="certification_id" required class="input px-3 py-2 text-sm sm:col-span-2">
                    <?php foreach ($availableCerts as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['code']) ?> — <?= e($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <input type="date" name="target_exam_date" class="input px-3 py-2 text-sm" placeholder="Zieldatum (optional)">
                <button class="btn btn-primary sm:col-span-3 justify-self-start">Zum Katalog hinzufuegen</button>
            </form>
        <?php else: ?>
            <p class="text-xs text-muted mt-2">Du hast bereits alle verfuegbaren Zertifizierungen im Katalog.</p>
        <?php endif ?>
    </section>
</main>
<?php layout_foot();
