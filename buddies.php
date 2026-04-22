<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo = db();
$uid = (int) $user['id'];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'invite') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte gueltige E-Mail-Adresse eingeben.';
        } elseif ($email === strtolower((string) $user['email'])) {
            $error = 'Du kannst dich nicht selbst einladen.';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $stmt->execute([$email]);
            $targetId = (int) ($stmt->fetchColumn() ?: 0);
            if (!$targetId) {
                $error = 'Diese Person hat noch keinen CertTrack-Account. Bitte den Admin, einen User anzulegen.';
            } else {
                $check = $pdo->prepare(
                    "SELECT id FROM buddies WHERE
                     (user_id=? AND buddy_user_id=?) OR (user_id=? AND buddy_user_id=?)"
                );
                $check->execute([$uid, $targetId, $targetId, $uid]);
                if ($check->fetchColumn()) {
                    $error = 'Es gibt schon eine Verbindung oder Anfrage mit dieser Person.';
                } else {
                    $pdo->prepare("INSERT INTO buddies (user_id, buddy_user_id, status) VALUES (?,?,'pending')")
                        ->execute([$uid, $targetId]);
                    flash('Einladung an ' . $email . ' verschickt.', 'success');
                    audit($uid, 'buddy_invited', 'user', $targetId);
                }
            }
        }
    }

    elseif ($action === 'respond') {
        $bid = (int) ($_POST['buddy_id'] ?? 0);
        $resp = (string) ($_POST['response'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM buddies WHERE id=? AND buddy_user_id=? AND status='pending'");
        $stmt->execute([$bid, $uid]);
        if ($stmt->fetch()) {
            if ($resp === 'accept') {
                $pdo->prepare("UPDATE buddies SET status='accepted', accepted_at=NOW() WHERE id=?")->execute([$bid]);
                flash('Lernpartnerschaft angenommen.', 'success');
            } else {
                $pdo->prepare("UPDATE buddies SET status='declined' WHERE id=?")->execute([$bid]);
                flash('Anfrage abgelehnt.', 'info');
            }
        }
    }

    elseif ($action === 'remove') {
        $bid = (int) ($_POST['buddy_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM buddies WHERE id=? AND (user_id=? OR buddy_user_id=?)");
        $stmt->execute([$bid, $uid, $uid]);
        if ($stmt->rowCount()) flash('Verbindung beendet.', 'info');
    }

    elseif ($action === 'message') {
        $toId = (int) ($_POST['to_user_id'] ?? 0);
        $msg  = trim((string) ($_POST['message'] ?? ''));
        if (mb_strlen($msg) < 1 || mb_strlen($msg) > 280) {
            $error = 'Nachricht muss 1–280 Zeichen lang sein.';
        } else {
            $check = $pdo->prepare(
                "SELECT 1 FROM buddies WHERE status='accepted'
                 AND ((user_id=? AND buddy_user_id=?) OR (user_id=? AND buddy_user_id=?))"
            );
            $check->execute([$uid, $toId, $toId, $uid]);
            if (!$check->fetchColumn()) {
                $error = 'Mit dieser Person bist du nicht verbunden.';
            } else {
                $pdo->prepare("INSERT INTO buddy_messages (from_user_id, to_user_id, message) VALUES (?,?,?)")
                    ->execute([$uid, $toId, $msg]);
                flash('Nachricht gesendet.', 'success');
                audit($uid, 'buddy_message_sent', 'user', $toId);
            }
        }
    }

    if (!$error) {
        header('Location: buddies.php');
        exit;
    }
}

$stmt = $pdo->prepare(
    "SELECT b.id, b.share_progress, b.user_id, b.buddy_user_id, b.status,
            u.id AS other_id, u.name AS other_name, u.email AS other_email,
            us.current_streak, us.longest_streak,
            (SELECT COALESCE(SUM(amount),0) FROM xp_log WHERE user_id=u.id) AS xp
     FROM buddies b
     JOIN users u ON u.id = b.buddy_user_id
     LEFT JOIN user_streaks us ON us.user_id = u.id
     WHERE b.user_id=? AND b.status='accepted'
     UNION
     SELECT b.id, 1 AS share_progress, b.user_id, b.buddy_user_id, b.status,
            u.id AS other_id, u.name AS other_name, u.email AS other_email,
            us.current_streak, us.longest_streak,
            (SELECT COALESCE(SUM(amount),0) FROM xp_log WHERE user_id=u.id) AS xp
     FROM buddies b
     JOIN users u ON u.id = b.user_id
     LEFT JOIN user_streaks us ON us.user_id = u.id
     WHERE b.buddy_user_id=? AND b.status='accepted'"
);
$stmt->execute([$uid, $uid]);
$accepted = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT b.id, u.name, u.email FROM buddies b JOIN users u ON u.id = b.user_id
     WHERE b.buddy_user_id=? AND b.status='pending'"
);
$stmt->execute([$uid]);
$incoming = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT b.id, u.name, u.email FROM buddies b JOIN users u ON u.id = b.buddy_user_id
     WHERE b.user_id=? AND b.status='pending'"
);
$stmt->execute([$uid]);
$outgoing = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT m.*, u.name AS from_name FROM buddy_messages m
     JOIN users u ON u.id = m.from_user_id
     WHERE m.to_user_id=? ORDER BY m.created_at DESC LIMIT 30"
);
$stmt->execute([$uid]);
$messages = $stmt->fetchAll();

$pdo->prepare("UPDATE buddy_messages SET read_at=NOW() WHERE to_user_id=? AND read_at IS NULL")->execute([$uid]);

$myStreak = get_streak($uid);
$myXp = user_total_xp($uid);

layout_head('Lernpartner', $user);
layout_nav($user);
?>
<main class="max-w-5xl mx-auto px-4 sm:px-6 py-6 space-y-6">

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif ?>

    <section class="card p-6">
        <h1 class="text-2xl mb-1">Lernpartner einladen</h1>
        <p class="text-muted text-sm mb-4">Per E-Mail einladen. Die Person muss vorher vom Admin angelegt sein.</p>
        <form method="post" class="flex flex-col sm:flex-row gap-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="invite">
            <input type="email" name="email" required placeholder="email@beispiel.de" class="input flex-1 px-3 py-2 text-sm">
            <button class="btn btn-primary">Einladen</button>
        </form>
    </section>

    <?php if ($incoming): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-3 text-warning">Offene Anfragen an dich</h2>
            <ul class="divide-y divider">
                <?php foreach ($incoming as $inc): ?>
                    <li class="py-3 flex items-center justify-between">
                        <div>
                            <div><?= e($inc['name'] ?: $inc['email']) ?></div>
                            <div class="text-xs text-muted"><?= e($inc['email']) ?></div>
                        </div>
                        <div class="flex gap-2">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="respond">
                                <input type="hidden" name="buddy_id" value="<?= (int) $inc['id'] ?>">
                                <input type="hidden" name="response" value="accept">
                                <button class="btn btn-primary text-xs px-3 py-1.5">Annehmen</button>
                            </form>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="respond">
                                <input type="hidden" name="buddy_id" value="<?= (int) $inc['id'] ?>">
                                <input type="hidden" name="response" value="decline">
                                <button class="btn btn-ghost text-xs px-3 py-1.5">Ablehnen</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>

    <?php if ($outgoing): ?>
        <section class="card p-6">
            <h2 class="text-2xl mb-3">Deine offenen Einladungen</h2>
            <ul class="text-sm text-muted space-y-1">
                <?php foreach ($outgoing as $o): ?>
                    <li>→ <?= e($o['email']) ?> (wartet)</li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endif ?>

    <section class="card p-6">
        <h2 class="text-2xl mb-4">Streak-Duell</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase text-muted">
                    <tr>
                        <th class="text-left py-2">Wer</th>
                        <th class="text-right py-2">Streak</th>
                        <th class="text-right py-2">Best</th>
                        <th class="text-right py-2">XP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="table-row bg-soft">
                        <td class="py-2 font-semibold text-brand">Du</td>
                        <td class="py-2 text-right text-ember">🔥 <?= (int) $myStreak['current'] ?></td>
                        <td class="py-2 text-right"><?= (int) $myStreak['longest'] ?></td>
                        <td class="py-2 text-right"><?= number_format($myXp, 0, ',', '.') ?></td>
                        <td></td>
                    </tr>
                    <?php if (!$accepted): ?>
                        <tr><td colspan="5" class="py-3 text-muted text-sm">Noch keine Partner. Lad jemanden ein.</td></tr>
                    <?php endif ?>
                    <?php foreach ($accepted as $b): ?>
                        <tr class="table-row">
                            <td class="py-2">
                                <?= e($b['other_name'] ?: $b['other_email']) ?>
                            </td>
                            <td class="py-2 text-right">🔥 <?= (int) ($b['current_streak'] ?? 0) ?></td>
                            <td class="py-2 text-right"><?= (int) ($b['longest_streak'] ?? 0) ?></td>
                            <td class="py-2 text-right"><?= number_format((int) ($b['xp'] ?? 0), 0, ',', '.') ?></td>
                            <td class="py-2 text-right">
                                <details>
                                    <summary class="cursor-pointer text-xs">Aktion</summary>
                                    <div class="mt-2 space-y-2">
                                        <form method="post" class="flex gap-2">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="message">
                                            <input type="hidden" name="to_user_id" value="<?= (int) $b['other_id'] ?>">
                                            <input type="text" name="message" maxlength="280" placeholder="Kurz motivieren …" class="input flex-1 px-2 py-1 text-xs">
                                            <button class="btn btn-primary text-xs px-3 py-1">Senden</button>
                                        </form>
                                        <form method="post" onsubmit="return confirm('Verbindung wirklich beenden?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="buddy_id" value="<?= (int) $b['id'] ?>">
                                            <button class="text-xs text-red-700 hover:underline">Verbindung loeschen</button>
                                        </form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card p-6">
        <h2 class="text-2xl mb-3">Nachrichten</h2>
        <?php if (!$messages): ?>
            <p class="text-sm text-muted">Noch keine Nachrichten.</p>
        <?php else: ?>
            <ul class="divide-y divider text-sm">
                <?php foreach ($messages as $m): ?>
                    <li class="py-3">
                        <div class="text-xs text-muted"><?= e($m['from_name']) ?> · <?= e($m['created_at']) ?></div>
                        <div class="mt-1"><?= e($m['message']) ?></div>
                    </li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>
</main>
<?php layout_foot();
