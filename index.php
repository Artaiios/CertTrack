<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

start_session();
security_headers();

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$sent = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = (string) ($_POST['email'] ?? '');
    $consent = !empty($_POST['consent']);
    if (!$consent) {
        $error = 'Bitte stimme dem Audit-Logging zu, um dich einzuloggen.';
    } else {
        $r = create_magic_link($email);
        if (!($r['ok'] ?? false)) {
            $error = 'Bitte eine gueltige E-Mail-Adresse eingeben.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email=?");
            $stmt->execute([strtolower(trim($email))]);
            $uid = (int) ($stmt->fetchColumn() ?: 0);
            if ($uid) {
                $pdo->prepare("UPDATE users SET consent_audit=1, consent_at=NOW() WHERE id=? AND consent_audit=0")->execute([$uid]);
            }
            $sent = true;
        }
    }
}

layout_head('Login');
?>
<div class="min-h-[85vh] flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="text-6xl mb-3 serif" style="color:var(--brand)">CertTrack</div>
            <p class="text-muted">Lern-Tracker mit Streak, XP und Lernpartnern.</p>
        </div>

        <div class="card p-8">
            <?php if ($sent): ?>
                <h1 class="text-2xl mb-3">Schau in dein Postfach</h1>
                <div class="alert alert-success">
                    Wenn die Adresse hinterlegt ist, ist der Login-Link unterwegs. <?= CT_MAGIC_TTL_MIN ?> Minuten gueltig, einmal verwendbar.
                </div>
                <p class="text-xs text-muted mt-4"><a href="index.php">Andere Adresse versuchen</a></p>
            <?php else: ?>
                <h1 class="text-2xl mb-2">Login</h1>
                <p class="text-muted text-sm mb-6">Kein Passwort. Du bekommst einen Magic Link per E-Mail.</p>

                <?php if ($error): ?>
                    <div class="alert alert-error mb-4"><?= e($error) ?></div>
                <?php endif ?>

                <form method="post" class="space-y-4">
                    <?= csrf_field() ?>
                    <div>
                        <label class="block text-xs uppercase tracking-wider text-muted mb-1">E-Mail</label>
                        <input type="email" name="email" required autocomplete="email" inputmode="email" class="input w-full px-3 py-2 text-sm">
                    </div>
                    <label class="flex gap-2 items-start text-xs text-muted">
                        <input type="checkbox" name="consent" value="1" class="mt-0.5">
                        <span>Ich stimme zu, dass Login-Vorgaenge zwecks Sicherheit pseudonymisiert protokolliert werden (IP nur als HMAC-Hash, nie im Klartext).</span>
                    </label>
                    <button class="btn btn-primary w-full justify-center">Magic Link senden</button>
                </form>
            <?php endif ?>
        </div>

        <p class="text-center text-xs text-muted mt-6">
            Erste Einrichtung? <a href="install.php">Installer</a>.
        </p>
    </div>
</div>
<?php layout_foot();
