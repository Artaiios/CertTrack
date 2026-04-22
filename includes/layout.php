<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function layout_head(string $title, ?array $user = null): void {
    security_headers();
    $pageTitle = $title ? ($title . ' — CertTrack') : 'CertTrack';
    $theme = current_theme($user);
    $themeMeta = theme_meta($theme);
    ?><!DOCTYPE html>
<html lang="de" data-theme="<?= e($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="<?= e($themeMeta['color']) ?>">
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: { extend: {
      fontFamily: {
        sans:  ['Inter','ui-sans-serif','system-ui','-apple-system','Segoe UI','sans-serif'],
        serif: ['Fraunces','ui-serif','Georgia','serif']
      },
      keyframes: {
        flicker: { '0%,100%':{ transform:'rotate(-2deg)' }, '50%':{ transform:'rotate(3deg) scale(1.05)' } },
        popin:   { '0%':{ transform:'scale(.85)', opacity:0 }, '100%':{ transform:'scale(1)', opacity:1 } }
      },
      animation: {
        flicker: 'flicker 1.6s ease-in-out infinite',
        popin:   'popin .4s cubic-bezier(.2,1.4,.4,1)'
      }
    } }
  }
</script>
<style>
    /* ============================================================
       Tokens — werden je [data-theme] ueberschrieben
       ============================================================ */
    :root {
        --bg: #FAF7F0;
        --bg-alt: #F2EBDC;
        --bg-soft: #FCFAF5;
        --card: #FFFFFF;
        --line: #E8E2D2;
        --line-soft: #F0EADB;
        --ink: #1A1F2E;
        --ink-soft: #5B6275;
        --brand: #1E3A8A;
        --brand-strong: #1E40AF;
        --brand-soft: #DBEAFE;
        --brand-soft-fg: #1E3A8A;
        --brand-rgb: 30,58,138;
        --ember: #C2410C;
        --ember-soft: #FED7AA;
        --ember-soft-fg: #9A3412;
        --success: #047857;
        --success-soft: #ECFDF5;
        --success-soft-fg: #065F46;
        --success-line: #A7F3D0;
        --warning: #B45309;
        --warning-soft: #FFFBEB;
        --warning-soft-fg: #92400E;
        --warning-line: #FDE68A;
        --error: #B91C1C;
        --error-soft: #FEF2F2;
        --error-soft-fg: #991B1B;
        --error-line: #FECACA;
        --input-bg: #FFFFFF;
        --input-border: #D9D2BE;
        --focus-ring: rgba(30,58,138,.20);
        --shadow-card: 0 1px 2px rgba(20,30,40,.04);
        --border-w: 1px;
        --link: var(--brand);
        --link-hover: var(--brand-strong);
    }
    [data-theme="dark"] {
        --bg: #0B1220;
        --bg-alt: #1F2937;
        --bg-soft: #111827;
        --card: #111827;
        --line: #1F2937;
        --line-soft: #1F2937;
        --ink: #E2E8F0;
        --ink-soft: #94A3B8;
        --brand: #60A5FA;
        --brand-strong: #3B82F6;
        --brand-soft: #1E3A8A;
        --brand-soft-fg: #DBEAFE;
        --brand-rgb: 96,165,250;
        --ember: #FB923C;
        --ember-soft: #7C2D12;
        --ember-soft-fg: #FED7AA;
        --success: #34D399;
        --success-soft: rgba(6,95,70,.30);
        --success-soft-fg: #6EE7B7;
        --success-line: rgba(110,231,183,.35);
        --warning: #FBBF24;
        --warning-soft: rgba(180,83,9,.30);
        --warning-soft-fg: #FCD34D;
        --warning-line: rgba(252,211,77,.35);
        --error: #F87171;
        --error-soft: rgba(127,29,29,.40);
        --error-soft-fg: #FCA5A5;
        --error-line: rgba(252,165,165,.35);
        --input-bg: #1E293B;
        --input-border: #334155;
        --focus-ring: rgba(96,165,250,.30);
        --shadow-card: 0 1px 2px rgba(0,0,0,.4);
    }
    [data-theme="hc"] {
        --bg: #000000;
        --bg-alt: #000000;
        --bg-soft: #000000;
        --card: #000000;
        --line: #FFFFFF;
        --line-soft: #FFFFFF;
        --ink: #FFFFFF;
        --ink-soft: #FFFFFF;
        --brand: #FFEB3B;
        --brand-strong: #FDD835;
        --brand-soft: #000000;
        --brand-soft-fg: #FFEB3B;
        --brand-rgb: 255,235,59;
        --ember: #FF9100;
        --ember-soft: #000000;
        --ember-soft-fg: #FF9100;
        --success: #00E676;
        --success-soft: #000000;
        --success-soft-fg: #00E676;
        --success-line: #00E676;
        --warning: #FFEB3B;
        --warning-soft: #000000;
        --warning-soft-fg: #FFEB3B;
        --warning-line: #FFEB3B;
        --error: #FF6E6E;
        --error-soft: #000000;
        --error-soft-fg: #FF6E6E;
        --error-line: #FF6E6E;
        --input-bg: #000000;
        --input-border: #FFFFFF;
        --focus-ring: rgba(255,235,59,.55);
        --shadow-card: none;
        --border-w: 2px;
    }

    /* ============================================================
       Komponenten
       ============================================================ */
    html, body { background: var(--bg); }
    body { color: var(--ink); font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
    h1, h2, h3, .serif { font-family: 'Fraunces', Georgia, serif; letter-spacing: -0.015em; font-weight: 600; color: var(--ink); }
    [data-theme="hc"] h1, [data-theme="hc"] h2, [data-theme="hc"] h3 { font-weight: 700; }

    a { color: var(--link); }
    a:hover { color: var(--link-hover); }
    [data-theme="hc"] a { text-decoration: underline; text-underline-offset: 2px; }

    .card {
        background: var(--card);
        border: var(--border-w) solid var(--line);
        border-radius: 14px;
        box-shadow: var(--shadow-card);
    }
    [data-theme="hc"] .card { border-radius: 8px; }

    .input, select.input, textarea.input {
        background: var(--input-bg);
        border: var(--border-w) solid var(--input-border);
        color: var(--ink);
        border-radius: 8px;
        transition: border-color .15s, box-shadow .15s;
    }
    .input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px var(--focus-ring); }
    [data-theme="hc"] .input:focus { box-shadow: 0 0 0 4px var(--focus-ring); }

    .btn {
        display: inline-flex; align-items: center; gap: .5rem;
        border-radius: 8px; padding: .5rem 1rem;
        font-weight: 600; font-size: .875rem;
        transition: background .15s, color .15s, transform .05s;
        cursor: pointer; border: var(--border-w) solid transparent;
    }
    .btn:active { transform: translateY(1px); }
    .btn-primary { background: var(--brand); color: #fff; border-color: var(--brand); }
    .btn-primary:hover { background: var(--brand-strong); color: #fff; }
    [data-theme="hc"] .btn-primary { color: #000; font-weight: 700; }
    [data-theme="hc"] .btn-primary:hover { color: #000; }
    .btn-ghost { background: transparent; color: var(--ink); border-color: var(--input-border); }
    .btn-ghost:hover { background: var(--bg-alt); }
    .btn-danger { background: var(--error); color: #fff; border-color: var(--error); }
    [data-theme="hc"] .btn-danger { color: #000; font-weight: 700; }

    .alert { padding: .75rem 1rem; border: var(--border-w) solid; border-radius: 10px; font-size: .875rem; }
    .alert-success { background: var(--success-soft); border-color: var(--success-line); color: var(--success-soft-fg); }
    .alert-error   { background: var(--error-soft);   border-color: var(--error-line);   color: var(--error-soft-fg); }
    .alert-warning { background: var(--warning-soft); border-color: var(--warning-line); color: var(--warning-soft-fg); }
    .alert-info    { background: var(--bg-alt);       border-color: var(--line);         color: var(--ink); }

    .chip { display: inline-flex; align-items: center; gap: .35rem; padding: .2rem .6rem; border-radius: 999px; background: var(--bg-alt); color: var(--ink-soft); font-size: .75rem; font-weight: 500; border: var(--border-w) solid transparent; }
    [data-theme="hc"] .chip { border-color: var(--line); }
    .chip-brand   { background: var(--brand-soft);   color: var(--brand-soft-fg); }
    .chip-ember   { background: var(--ember-soft);   color: var(--ember-soft-fg); }
    .chip-warning { background: var(--warning-soft); color: var(--warning-soft-fg); }
    .chip-success { background: var(--success-soft); color: var(--success-soft-fg); }

    .nav-link { padding: .45rem .85rem; border-radius: 8px; color: var(--ink-soft); font-weight: 500; font-size: .875rem; transition: background .15s, color .15s; border: var(--border-w) solid transparent; }
    .nav-link:hover { background: var(--bg-alt); color: var(--brand); }
    .nav-link.active { background: var(--brand); color: #fff; border-color: var(--brand); }
    [data-theme="hc"] .nav-link.active { color: #000; font-weight: 700; }
    .nav-link.active:hover { background: var(--brand-strong); color: #fff; }
    [data-theme="hc"] .nav-link.active:hover { color: #000; }

    .text-muted   { color: var(--ink-soft); }
    .text-brand   { color: var(--brand); }
    .text-ember   { color: var(--ember); }
    .text-success { color: var(--success); }
    .text-warning { color: var(--warning); }
    .text-error   { color: var(--error); }
    .bg-soft      { background: var(--bg-soft); }
    .border-soft  { border-color: var(--line-soft); }

    .stat-num { font-family: 'Fraunces', Georgia, serif; font-weight: 600; letter-spacing: -.02em; }
    .divider { border-color: var(--line); }
    .table-row { border-top: var(--border-w) solid var(--line-soft); }

    .progress-track { background: var(--bg-alt); border-radius: 999px; overflow: hidden; }
    [data-theme="hc"] .progress-track { background: #000; border: 1px solid #FFF; }
    .progress-bar { height: 100%; border-radius: 999px; background: var(--brand); }

    .glow-flame { filter: drop-shadow(0 0 6px rgba(234,88,12,.35)); }
    [data-theme="dark"] .glow-flame { filter: drop-shadow(0 0 8px rgba(251,146,60,.55)); }
    [data-theme="hc"]   .glow-flame { filter: none; }

    /* Heatmap-Zellen: alpha kommt per --alpha, Farbe aus --brand-rgb */
    .heat-cell { background-color: rgba(var(--brand-rgb), var(--alpha, 0.1)); border: 1px solid rgba(var(--brand-rgb), .25); color: var(--ink); }
    [data-theme="hc"] .heat-cell { border-color: var(--line); }

    /* Theme-Picker im Header */
    .theme-pick { padding: .35rem .55rem; border-radius: 8px; border: var(--border-w) solid var(--input-border); background: var(--card); color: var(--ink); font-size: .95rem; line-height: 1; cursor: pointer; transition: background .15s, color .15s, border-color .15s; }
    .theme-pick:hover { background: var(--bg-alt); }
    .theme-pick.active { background: var(--brand); color: #fff; border-color: var(--brand); }
    [data-theme="hc"] .theme-pick.active { color: #000; font-weight: 700; }

    /* Mood-Buttons (radio) */
    .mood-pill { display: inline-flex; width: 3rem; height: 3rem; align-items: center; justify-content: center; border-radius: 10px; border: var(--border-w) solid var(--line); background: var(--card); transition: all .15s; }
    .peer:checked ~ .mood-pill { border-color: var(--brand); background: var(--brand-soft); }

    button, a { -webkit-tap-highlight-color: transparent; }
</style>
</head>
<body class="min-h-screen">
<?php
}

function layout_nav(?array $user): void {
    if (!$user) return;
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $items = [
        'dashboard.php'    => ['Dashboard',    '🏠'],
        'session_log.php'  => ['Session',      '📚'],
        'cpe.php'          => ['CPE',          '⏱'],
        'progress.php'     => ['Fortschritt',  '📈'],
        'buddies.php'      => ['Lernpartner',  '🤝'],
    ];
    if (($user['role'] ?? '') === 'admin') {
        $items['admin.php'] = ['Admin', '⚙️'];
    }
    $theme = current_theme($user);
    $returnTo = e(basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
    ?>
    <header class="sticky top-0 z-30 backdrop-blur" style="background: color-mix(in oklab, var(--card) 90%, transparent); border-bottom: var(--border-w) solid var(--line);">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3 flex items-center gap-4">
            <a href="dashboard.php" class="font-serif text-2xl font-semibold tracking-tight text-brand">CertTrack</a>
            <nav class="flex-1 flex flex-wrap gap-1">
                <?php foreach ($items as $href => [$label, $icon]):
                    $active = ($current === $href);
                ?>
                    <a href="<?= e($href) ?>" class="nav-link <?= $active ? 'active' : '' ?>">
                        <span class="mr-1"><?= $icon ?></span><?= e($label) ?>
                    </a>
                <?php endforeach ?>
            </nav>
            <form method="post" action="theme.php" class="hidden md:flex items-center gap-1" aria-label="Theme">
                <?= csrf_field() ?>
                <input type="hidden" name="return" value="<?= $returnTo ?>">
                <?php foreach (valid_themes() as $t):
                    $m = theme_meta($t); $isActive = $t === $theme;
                ?>
                    <button type="submit" name="theme" value="<?= e($t) ?>"
                            class="theme-pick <?= $isActive ? 'active' : '' ?>"
                            title="<?= e($m['label']) ?>"
                            aria-label="Theme: <?= e($m['label']) ?>"
                            aria-pressed="<?= $isActive ? 'true' : 'false' ?>"><?= $m['icon'] ?></button>
                <?php endforeach ?>
            </form>
            <div class="flex items-center gap-2 text-sm">
                <a href="settings.php" title="Einstellungen" class="hidden sm:inline-flex items-center gap-1 text-muted hover:text-brand px-2 py-1 rounded">
                    <span class="text-base">⚙</span><span class="hidden lg:inline"><?= e($user['name'] ?: $user['email']) ?></span>
                </a>
                <a href="logout.php" class="btn btn-ghost px-3 py-1.5">Logout</a>
            </div>
        </div>
    </header>
    <?php
    foreach (flashes() as $f) {
        $cls = match ($f['type']) {
            'success' => 'alert alert-success',
            'error'   => 'alert alert-error',
            'warning' => 'alert alert-warning',
            default   => 'alert alert-info',
        };
        echo '<div class="max-w-6xl mx-auto mt-4 px-4 sm:px-6"><div class="' . $cls . '">' . e($f['msg']) . '</div></div>';
    }
}

function layout_foot(): void {
    ?>
    <footer class="max-w-6xl mx-auto px-4 sm:px-6 py-8 mt-12 text-xs text-muted" style="border-top: var(--border-w) solid var(--line);">
        CertTrack — Lern-Tracker mit Streak. <span class="ml-2">Bleib dran.</span>
    </footer>
</body>
</html>
    <?php
}
