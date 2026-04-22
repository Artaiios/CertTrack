<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_admin();
$pdo = db();

$tab = (string) ($_GET['tab'] ?? 'users');
$tabs = ['users' => 'User', 'certs' => 'Zertifizierungen', 'domains' => 'Domains', 'announce' => 'Ankuendigungen'];
if (!isset($tabs[$tab])) $tab = 'users';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'user_create':
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $name  = trim((string) ($_POST['name'] ?? ''));
            $role  = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                $error = 'Name und E-Mail noetig.';
            } else {
                try {
                    $pdo->prepare("INSERT INTO users (email,name,role) VALUES (?,?,?)")->execute([$email, $name, $role]);
                    flash('User angelegt: ' . $email, 'success');
                    audit((int) $user['id'], 'user_create', 'user', (int) $pdo->lastInsertId());
                } catch (PDOException $e) {
                    $error = 'Konnte User nicht anlegen (E-Mail evtl. schon vergeben).';
                }
            }
            break;

        case 'user_role':
            $uid = (int) ($_POST['user_id'] ?? 0);
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            if ($uid !== (int) $user['id']) {
                $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role, $uid]);
                flash('Rolle aktualisiert.', 'success');
            } else {
                $error = 'Eigene Rolle nicht aenderbar.';
            }
            break;

        case 'user_delete':
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid !== (int) $user['id']) {
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
                flash('User geloescht.', 'success');
                audit((int) $user['id'], 'user_delete', 'user', $uid);
            }
            break;

        case 'user_assign_cert':
            $uid = (int) ($_POST['user_id'] ?? 0);
            $cid = (int) ($_POST['certification_id'] ?? 0);
            $exam = trim((string) ($_POST['target_exam_date'] ?? ''));
            $examOk = $exam === '' ? null : (DateTime::createFromFormat('Y-m-d', $exam) ? $exam : null);
            $pdo->prepare(
                "INSERT INTO user_certifications (user_id,certification_id,target_exam_date) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE target_exam_date=VALUES(target_exam_date)"
            )->execute([$uid, $cid, $examOk]);
            $primaryStmt = $pdo->prepare("SELECT primary_certification_id FROM users WHERE id=?");
            $primaryStmt->execute([$uid]);
            if (!$primaryStmt->fetchColumn()) {
                $pdo->prepare("UPDATE users SET primary_certification_id=? WHERE id=?")->execute([$cid, $uid]);
            }
            flash('Zertifikat zugewiesen.', 'success');
            break;

        case 'cert_create':
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $name = trim((string) ($_POST['name'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $yearly = max(0, (int) ($_POST['cpe_yearly_target'] ?? 20));
            $total  = max(0, (int) ($_POST['cpe_total_target'] ?? 120));
            $double = !empty($_POST['double_count_articles']) ? 1 : 0;
            if ($code === '' || $name === '') {
                $error = 'Code und Name benoetigt.';
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO certifications (code,name,description,cpe_yearly_target,cpe_total_target,double_count_articles)
                         VALUES (?,?,?,?,?,?)"
                    )->execute([$code, $name, $desc, $yearly, $total, $double]);
                    flash('Zertifizierung angelegt.', 'success');
                } catch (PDOException $e) { $error = 'Code evtl. schon vorhanden.'; }
            }
            break;

        case 'cert_update':
            $cid = (int) ($_POST['cert_id'] ?? 0);
            $name = trim((string) ($_POST['name'] ?? ''));
            $yearly = max(0, (int) ($_POST['cpe_yearly_target'] ?? 20));
            $total  = max(0, (int) ($_POST['cpe_total_target'] ?? 120));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $pdo->prepare(
                "UPDATE certifications SET name=?, cpe_yearly_target=?, cpe_total_target=?, is_active=? WHERE id=?"
            )->execute([$name, $yearly, $total, $active, $cid]);
            flash('Zertifizierung aktualisiert.', 'success');
            break;

        case 'domain_create':
            $cid  = (int) ($_POST['certification_id'] ?? 0);
            $code = trim((string) ($_POST['code'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $sort = max(0, (int) ($_POST['sort_order'] ?? 0));
            if ($cid <= 0 || $code === '' || $name === '') {
                $error = 'Cert, Code und Name benoetigt.';
            } else {
                try {
                    $pdo->prepare("INSERT INTO domains (certification_id,code,name,sort_order) VALUES (?,?,?,?)")
                        ->execute([$cid, $code, $name, $sort]);
                    flash('Domain hinzugefuegt.', 'success');
                } catch (PDOException $e) { $error = 'Code in dieser Cert schon vorhanden.'; }
            }
            break;

        case 'domain_delete':
            $did = (int) ($_POST['domain_id'] ?? 0);
            $pdo->prepare("DELETE FROM domains WHERE id=?")->execute([$did]);
            flash('Domain geloescht.', 'success');
            break;

        case 'announce_create':
            $title = trim((string) ($_POST['title'] ?? ''));
            $body  = trim((string) ($_POST['body'] ?? ''));
            $exp   = trim((string) ($_POST['expires_at'] ?? ''));
            $expOk = $exp === '' ? null : (DateTime::createFromFormat('Y-m-d', $exp) ? $exp . ' 23:59:59' : null);
            if ($title === '' || $body === '') {
                $error = 'Titel und Text benoetigt.';
            } else {
                $pdo->prepare("INSERT INTO announcements (title,body,expires_at,created_by) VALUES (?,?,?,?)")
                    ->execute([$title, $body, $expOk, (int) $user['id']]);
                flash('Ankuendigung veroeffentlicht.', 'success');
            }
            break;

        case 'announce_delete':
            $aid = (int) ($_POST['announcement_id'] ?? 0);
            $pdo->prepare("DELETE FROM announcements WHERE id=?")->execute([$aid]);
            flash('Ankuendigung geloescht.', 'success');
            break;
    }

    if (!$error) {
        header('Location: admin.php?tab=' . urlencode($tab));
        exit;
    }
}

$users = $pdo->query(
    "SELECT u.id,u.email,u.name,u.role,u.last_login_at,u.created_at, c.code AS primary_code
     FROM users u
     LEFT JOIN certifications c ON c.id = u.primary_certification_id
     ORDER BY u.created_at DESC"
)->fetchAll();
$certs = $pdo->query("SELECT * FROM certifications ORDER BY name")->fetchAll();
$allDomains = $pdo->query(
    "SELECT d.*, c.code AS cert_code, c.name AS cert_name FROM domains d JOIN certifications c ON c.id=d.certification_id ORDER BY c.name, d.sort_order, d.name"
)->fetchAll();
$announcements = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetchAll();

layout_head('Admin', $user);
layout_nav($user);
?>
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 space-y-6">
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif ?>

    <nav class="flex flex-wrap gap-2 text-sm">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="?tab=<?= e($key) ?>" class="nav-link <?= $tab===$key?'active':'' ?>"><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>

    <?php if ($tab === 'users'): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-3">Neuen User anlegen</h2>
            <form method="post" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="user_create">
                <input type="text" name="name" required placeholder="Name" class="input px-3 py-2 text-sm">
                <input type="email" name="email" required placeholder="email@beispiel.de" class="input px-3 py-2 text-sm">
                <select name="role" class="input px-3 py-2 text-sm">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
                <button class="btn btn-primary">Anlegen</button>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="text-2xl mb-3">Alle User</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-muted">
                        <tr>
                            <th class="text-left py-2">Name</th>
                            <th class="text-left py-2">E-Mail</th>
                            <th class="text-left py-2">Rolle</th>
                            <th class="text-left py-2">Hauptthema</th>
                            <th class="text-left py-2">Letzter Login</th>
                            <th class="text-left py-2">Cert zuweisen</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="table-row align-top">
                            <td class="py-2"><?= e($u['name']) ?></td>
                            <td class="py-2"><?= e($u['email']) ?></td>
                            <td class="py-2">
                                <form method="post" class="flex gap-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="user_role">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <select name="role" class="input px-2 py-1 text-xs" <?= $u['id']==$user['id']?'disabled':'' ?>>
                                        <option value="user" <?= $u['role']==='user'?'selected':'' ?>>user</option>
                                        <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>admin</option>
                                    </select>
                                    <?php if ($u['id'] != $user['id']): ?>
                                        <button class="text-xs hover:underline">setzen</button>
                                    <?php endif ?>
                                </form>
                            </td>
                            <td class="py-2 text-xs"><?= e($u['primary_code'] ?: '–') ?></td>
                            <td class="py-2 text-muted text-xs"><?= e($u['last_login_at'] ?? 'nie') ?></td>
                            <td class="py-2">
                                <form method="post" class="flex flex-wrap gap-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="user_assign_cert">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <select name="certification_id" class="input px-2 py-1 text-xs">
                                        <?php foreach ($certs as $c): ?>
                                            <option value="<?= (int) $c['id'] ?>"><?= e($c['code']) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                    <input type="date" name="target_exam_date" class="input px-2 py-1 text-xs">
                                    <button class="text-xs hover:underline">zuweisen</button>
                                </form>
                            </td>
                            <td class="py-2 text-right">
                                <?php if ($u['id'] != $user['id']): ?>
                                    <form method="post" onsubmit="return confirm('User samt allen Daten loeschen?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="user_delete">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button class="text-xs text-red-700 hover:underline">loeschen</button>
                                    </form>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif ?>

    <?php if ($tab === 'certs'): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-3">Neue Zertifizierung</h2>
            <form method="post" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cert_create">
                <input type="text" name="code" required placeholder="Code (z.B. CRISC)" class="input px-3 py-2 text-sm uppercase">
                <input type="text" name="name" required placeholder="Voller Name" class="input px-3 py-2 text-sm sm:col-span-2">
                <textarea name="description" placeholder="Kurzbeschreibung" class="input px-3 py-2 text-sm sm:col-span-3" rows="2"></textarea>
                <input type="number" name="cpe_yearly_target" min="0" value="20" class="input px-3 py-2 text-sm" placeholder="CPE Jahresziel">
                <input type="number" name="cpe_total_target" min="0" value="120" class="input px-3 py-2 text-sm" placeholder="CPE 3-Jahres-Ziel">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="double_count_articles" checked> Vortraege/Artikel zaehlen doppelt</label>
                <button class="btn btn-primary sm:col-span-3 justify-self-start">Anlegen</button>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="text-2xl mb-3">Vorhandene Zertifizierungen</h2>
            <div class="space-y-3">
                <?php foreach ($certs as $c): ?>
                    <form method="post" class="grid grid-cols-1 sm:grid-cols-6 gap-2 table-row pt-3 items-center">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cert_update">
                        <input type="hidden" name="cert_id" value="<?= (int) $c['id'] ?>">
                        <div class="font-semibold serif" style="color:var(--brand)"><?= e($c['code']) ?></div>
                        <input type="text" name="name" value="<?= e($c['name']) ?>" class="input px-2 py-1 text-sm sm:col-span-2">
                        <input type="number" name="cpe_yearly_target" value="<?= (int) $c['cpe_yearly_target'] ?>" class="input px-2 py-1 text-sm" min="0">
                        <input type="number" name="cpe_total_target" value="<?= (int) $c['cpe_total_target'] ?>" class="input px-2 py-1 text-sm" min="0">
                        <label class="flex items-center gap-2 text-xs">
                            <input type="checkbox" name="is_active" <?= $c['is_active']?'checked':'' ?>> aktiv
                        </label>
                        <button class="btn btn-primary text-xs px-3 py-1 sm:col-span-6 justify-self-start">Speichern</button>
                    </form>
                <?php endforeach ?>
            </div>
        </section>
    <?php endif ?>

    <?php if ($tab === 'domains'): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-3">Neue Domain</h2>
            <form method="post" class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="domain_create">
                <select name="certification_id" required class="input px-3 py-2 text-sm">
                    <option value="">Zertifizierung waehlen</option>
                    <?php foreach ($certs as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['code']) ?> — <?= e($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
                <input type="text" name="code" required placeholder="Code" class="input px-3 py-2 text-sm">
                <input type="text" name="name" required placeholder="Domain-Name" class="input px-3 py-2 text-sm sm:col-span-2">
                <input type="number" name="sort_order" value="10" class="input px-3 py-2 text-sm" placeholder="Sort">
                <button class="btn btn-primary sm:col-span-5 justify-self-start">Hinzufuegen</button>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="text-2xl mb-3">Domains</h2>
            <table class="w-full text-sm">
                <thead class="text-xs uppercase text-muted"><tr>
                    <th class="text-left py-2">Cert</th>
                    <th class="text-left py-2">Code</th>
                    <th class="text-left py-2">Name</th>
                    <th class="text-right py-2">Sort</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($allDomains as $d): ?>
                    <tr class="table-row">
                        <td class="py-2"><?= e($d['cert_code']) ?></td>
                        <td class="py-2 text-muted"><?= e($d['code']) ?></td>
                        <td class="py-2"><?= e($d['name']) ?></td>
                        <td class="py-2 text-right text-muted"><?= (int) $d['sort_order'] ?></td>
                        <td class="py-2 text-right">
                            <form method="post" onsubmit="return confirm('Domain loeschen?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="domain_delete">
                                <input type="hidden" name="domain_id" value="<?= (int) $d['id'] ?>">
                                <button class="text-xs text-red-700 hover:underline">loeschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </section>
    <?php endif ?>

    <?php if ($tab === 'announce'): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-3">Neue Ankuendigung</h2>
            <form method="post" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="announce_create">
                <input type="text" name="title" required placeholder="Titel" class="input px-3 py-2 text-sm sm:col-span-3">
                <input type="date" name="expires_at" class="input px-3 py-2 text-sm" placeholder="Ablaufdatum (optional)">
                <textarea name="body" required rows="3" placeholder="Inhalt" class="input px-3 py-2 text-sm sm:col-span-4"></textarea>
                <button class="btn btn-primary sm:col-span-4 justify-self-start">Veroeffentlichen</button>
            </form>
        </section>

        <section class="card p-6">
            <h2 class="text-2xl mb-3">Bestehende Ankuendigungen</h2>
            <?php if (!$announcements): ?>
                <p class="text-sm text-muted">Keine Ankuendigungen.</p>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($announcements as $a): ?>
                        <li class="table-row pt-3 flex items-start justify-between gap-4">
                            <div class="flex-1">
                                <div class="font-semibold serif" style="color:var(--brand)"><?= e($a['title']) ?></div>
                                <div class="text-xs text-muted"><?= e($a['created_at']) ?> · laeuft <?= e($a['expires_at'] ?? 'nie') ?> ab</div>
                                <div class="text-sm mt-1 whitespace-pre-line"><?= e($a['body']) ?></div>
                            </div>
                            <form method="post" onsubmit="return confirm('Ankuendigung loeschen?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="announce_delete">
                                <input type="hidden" name="announcement_id" value="<?= (int) $a['id'] ?>">
                                <button class="text-xs text-red-700 hover:underline">loeschen</button>
                            </form>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </section>
    <?php endif ?>
</main>
<?php layout_foot();
