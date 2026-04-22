<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$pdo  = db();

$format = (string) ($_GET['format'] ?? 'print');
$yearIn = (string) ($_GET['year'] ?? '');
$certIn = (int)    ($_GET['cert'] ?? 0);
$validFormats = ['csv', 'zip', 'print'];
if (!in_array($format, $validFormats, true)) $format = 'print';

$where = ['e.user_id = ?'];
$params = [(int) $user['id']];

if ($yearIn !== '' && ctype_digit($yearIn)) {
    $year = (int) $yearIn;
    if ($year >= 2000 && $year <= 2100) {
        $where[] = 'YEAR(e.entry_date) = ?';
        $params[] = $year;
    }
}
if ($certIn > 0) {
    $where[] = 'e.certification_id = ?';
    $params[] = $certIn;
}

$sql = "SELECT e.*, cat.name AS cat_name, cat.code AS cat_code, cat.doubles, c.code AS cert_code, c.name AS cert_name
        FROM cpe_entries e
        JOIN cpe_categories cat ON cat.id = e.category_id
        LEFT JOIN certifications c ON c.id = e.certification_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY e.entry_date ASC, e.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
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

function slugify(string $s, int $maxLen = 40): string {
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    if ($s === '') $s = 'eintrag';
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return strtolower($s);
}

function ext_for_mime(string $mime): string {
    $map = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
        'image/heif'      => 'heif',
    ];
    return $map[$mime] ?? 'bin';
}

$totalHours = 0.0;
$totalCredited = 0.0;
foreach ($entries as $e) {
    $totalHours    += (float) $e['hours'];
    $totalCredited += (float) $e['credited_hours'];
}

audit((int) $user['id'], 'cpe_export', 'cpe', null);

$ts = date('Ymd_Hi');
$baseFilename = 'CertTrack_CPE_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string) $user['name']) . '_' . $ts;

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseFilename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM fuer Excel-Umlaute
    fputcsv($out, ['Datum','Kategorie','Beschreibung','Stunden','Stunden anrechenbar','Faktor','Zertifizierung','Belege Anzahl','Belege Dateien'], ';');
    foreach ($entries as $e) {
        $files = $evidenceByEntry[(int) $e['id']] ?? [];
        $names = implode(' | ', array_map(static fn($f) => $f['original_name'], $files));
        fputcsv($out, [
            $e['entry_date'],
            $e['cat_name'],
            $e['description'],
            number_format((float) $e['hours'], 2, ',', ''),
            number_format((float) $e['credited_hours'], 2, ',', ''),
            ((int) $e['doubles'] === 1 ? '×2' : '×1'),
            $e['cert_code'] ?: 'allgemein',
            count($files),
            $names,
        ], ';');
    }
    fputcsv($out, [], ';');
    fputcsv($out, ['SUMME', '', '', number_format($totalHours, 2, ',', ''), number_format($totalCredited, 2, ',', ''), '', '', '', ''], ';');
    fclose($out);
    exit;
}

if ($format === 'zip') {
    if (!class_exists('ZipArchive')) {
        flash('ZIP-Export ist auf diesem Server nicht verfuegbar (ZipArchive fehlt). Nimm CSV oder Druckansicht.', 'error');
        header('Location: cpe.php');
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ct_zip_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        flash('ZIP-Datei konnte nicht angelegt werden.', 'error');
        header('Location: cpe.php');
        exit;
    }

    $folder = $baseFilename;
    $readme = "CertTrack — CPE-Export\n"
            . "Erstellt: " . date('Y-m-d H:i') . "\n"
            . "User: " . $user['name'] . " <" . $user['email'] . ">\n"
            . ($yearIn !== '' ? "Jahr: $yearIn\n" : "Zeitraum: alle Jahre\n")
            . ($certIn > 0 ? "Zertifizierung: gefiltert auf cert_id=$certIn\n" : "Zertifizierung: alle\n")
            . "\n"
            . "Inhalt:\n"
            . "  - cpe_uebersicht.csv  : Tabellarische Liste aller Eintraege\n"
            . "  - belege/             : Originaldateien, durchnummeriert nach Eintragsreihenfolge\n"
            . "                          Format: NNNN_YYYY-MM-DD_<kategorie>_<beschreibung>.<ext>\n"
            . "                          Die laufende Nummer NNNN entspricht der Spalte 'Beleg-Nr' in der CSV.\n"
            . "\n"
            . "Anrechnung:\n"
            . "  - 'Stunden'             = tatsaechlich aufgewendete Zeit\n"
            . "  - 'Stunden anrechenbar' = nach Multiplikator (z.B. ×2 fuer Vortraege/Artikel)\n"
            . "  - 'Faktor'              = Multiplikator der Kategorie\n"
            . "\n";
    $zip->addFromString($folder . '/README.txt', $readme);

    // CSV-Erweitert: enthaelt zusaetzlich die Beleg-Nummer-Range pro Eintrag
    $csv = "\xEF\xBB\xBF"; // BOM
    $csv .= '"Datum";"Kategorie";"Beschreibung";"Stunden";"Stunden anrechenbar";"Faktor";"Zertifizierung";"Belege Anzahl";"Beleg-Nr"';
    $csv .= "\r\n";

    $evNum = 0;
    $manifestRows = [];
    foreach ($entries as $e) {
        $files = $evidenceByEntry[(int) $e['id']] ?? [];
        $startN = $evNum + 1;
        $rangeText = '';
        if ($files) {
            $endN = $evNum + count($files);
            $rangeText = $startN === $endN ? sprintf('%04d', $startN) : sprintf('%04d–%04d', $startN, $endN);
            foreach ($files as $f) {
                $evNum++;
                $abs = realpath(__DIR__ . '/' . ltrim((string) $f['stored_path'], '/'));
                $base = realpath(__DIR__ . '/' . CT_UPLOAD_DIR_REL);
                if (!$abs || !$base || !str_starts_with($abs, $base) || !is_file($abs)) continue;

                $ext = ext_for_mime((string) $f['mime']);
                $catSlug = slugify((string) $e['cat_code'], 20);
                $descSlug = slugify((string) $e['description'], 30);
                $zipName = sprintf('%s/belege/%04d_%s_%s_%s.%s', $folder, $evNum, $e['entry_date'], $catSlug, $descSlug, $ext);
                $zip->addFile($abs, $zipName);
            }
        }
        $manifestRows[] = [
            (string) $e['entry_date'],
            (string) $e['cat_name'],
            (string) $e['description'],
            number_format((float) $e['hours'], 2, ',', ''),
            number_format((float) $e['credited_hours'], 2, ',', ''),
            ((int) $e['doubles'] === 1 ? '×2' : '×1'),
            (string) ($e['cert_code'] ?: 'allgemein'),
            (string) count($files),
            $rangeText,
        ];
    }

    foreach ($manifestRows as $r) {
        $csv .= '"' . implode('";"', array_map(static fn($v) => str_replace('"', '""', $v), $r)) . '"' . "\r\n";
    }
    $csv .= "\r\n";
    $csv .= sprintf('"SUMME";"";"";"%s";"%s";"";"";"";""',
        number_format($totalHours, 2, ',', ''),
        number_format($totalCredited, 2, ',', '')
    ) . "\r\n";

    $zip->addFromString($folder . '/cpe_uebersicht.csv', $csv);
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $baseFilename . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// format === 'print' → druckfreundliche HTML-Seite

$certInfo = null;
if ($certIn > 0) {
    $cn = $pdo->prepare("SELECT code, name FROM certifications WHERE id=?");
    $cn->execute([$certIn]);
    $certInfo = $cn->fetch() ?: null;
}

/**
 * Zeigt nur den Cert-Namen an. Wenn der Name mit "<code> —" beginnt, ist das Code-Praefix bereits drin.
 * Sonst Code + Name. Vermeidet Doppelungen wie "CISM — CISM — Certified ...".
 */
function cert_display(?array $info): string {
    if (!$info) return '';
    $code = (string) ($info['code'] ?? '');
    $name = (string) ($info['name'] ?? '');
    if ($code !== '' && stripos($name, $code) === 0) {
        return $name;
    }
    return ($code !== '' ? $code . ' — ' : '') . $name;
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CPE-Nachweis · <?= e($user['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
    /* ------- Bildschirm ------- */
    html, body { background: #F4F1EA; }
    body {
        font-family: 'Inter', system-ui, sans-serif;
        color: #111;
        margin: 0;
        padding: 24px 16px;
        font-size: 13px;
    }
    .page {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        padding: 28mm 22mm 24mm;
        border-radius: 4px;
        box-shadow: 0 4px 24px rgba(0,0,0,.08);
    }
    h1, h2 { font-family: 'Fraunces', Georgia, serif; letter-spacing: -.01em; font-weight: 600; }
    h1 { margin: 0 0 6px; font-size: 26px; color: #1E3A8A; }
    .subtitle { font-size: 13px; color: #555; margin: 0 0 22px; padding-bottom: 14px; border-bottom: 2px solid #1E3A8A; }
    .meta-grid { display: grid; grid-template-columns: 110px 1fr; gap: 4px 12px; font-size: 12px; margin: 0 0 22px; }
    .meta-grid dt { color: #777; font-weight: 500; }
    .meta-grid dd { margin: 0; color: #111; }
    .meta-grid dd strong { font-weight: 600; }

    table { border-collapse: collapse; width: 100%; font-size: 11.5px; }
    th, td { padding: 6px 8px; text-align: left; vertical-align: top; }
    th {
        background: #F2EBDC;
        border-bottom: 1.5px solid #1E3A8A;
        font-weight: 600; font-size: 10px;
        text-transform: uppercase; letter-spacing: .04em;
        color: #1E3A8A;
    }
    td { border-bottom: 1px solid #E8E2D2; }
    tr:nth-child(even) td { background: #FCFAF5; }
    .right { text-align: right; white-space: nowrap; }
    .badge { display: inline-block; padding: 1px 6px; border-radius: 999px; background: #FEF3C7; color: #92400E; font-size: 9px; font-weight: 600; margin-left: 4px; }
    .ev-list { margin: 3px 0 0; padding-left: 14px; font-size: 10px; color: #555; }

    .summary {
        margin-top: 14px;
        padding: 10px 14px;
        background: #DBEAFE;
        border: 1px solid #1E3A8A;
        border-radius: 4px;
        font-size: 12px;
    }
    .summary strong { font-family: 'Fraunces', Georgia, serif; font-size: 16px; color: #1E3A8A; }

    .footnote {
        margin-top: 22px;
        font-size: 10px;
        color: #666;
        line-height: 1.4;
        border-top: 1px solid #E8E2D2;
        padding-top: 10px;
    }
    .signature {
        margin-top: 36px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 36px;
        font-size: 11px;
        color: #555;
    }
    .signature .line { border-top: 1px solid #999; margin-bottom: 4px; padding-top: 6px; }

    /* ------- Toolbar (nur Bildschirm) ------- */
    .toolbar {
        position: sticky; top: 0; z-index: 10;
        background: #F4F1EA;
        margin: 0 auto 16px;
        max-width: 800px;
        padding: 12px 16px;
        display: flex; gap: 8px; flex-wrap: wrap;
        border-bottom: 1px solid #E8E2D2;
    }
    .toolbar button, .toolbar a {
        padding: 6px 12px;
        border: 1px solid #1E3A8A;
        background: #1E3A8A;
        color: #fff;
        border-radius: 6px;
        font: inherit; font-size: 12px; font-weight: 600;
        cursor: pointer; text-decoration: none;
    }
    .toolbar a.ghost { background: #fff; color: #1E3A8A; }

    /* ------- Druck ------- */
    @page {
        size: A4 portrait;
        margin: 18mm 16mm 22mm 16mm;
    }
    @page :first { margin-top: 22mm; }

    @media print {
        html, body { background: #fff; }
        body { padding: 0; margin: 0; font-size: 10pt; }
        .page {
            max-width: none;
            margin: 0;
            padding: 0;
            border-radius: 0;
            box-shadow: none;
            background: transparent;
        }
        .toolbar { display: none !important; }
        h1 { font-size: 20pt; }
        h2 { font-size: 13pt; }
        .subtitle { font-size: 10pt; }
        table { font-size: 9.5pt; }
        th { font-size: 8pt; }
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tr { page-break-inside: avoid; }
        .summary, .signature, .footnote { page-break-inside: avoid; }
    }
</style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">🖨 Drucken / Als PDF speichern</button>
        <a class="ghost" href="cpe.php">Zurueck</a>
    </div>

    <article class="page">
        <h1>CPE-Nachweis</h1>
        <p class="subtitle"><?= e($user['name']) ?> · <?= e($user['email']) ?></p>

        <dl class="meta-grid">
            <dt>Erstellt</dt>
            <dd><?= e(date('d.m.Y H:i')) ?></dd>

            <dt>Zeitraum</dt>
            <dd><?php if ($yearIn !== ''): ?><strong><?= e($yearIn) ?></strong><?php else: ?>alle Jahre<?php endif ?></dd>

            <?php if ($certInfo): ?>
                <dt>Zertifizierung</dt>
                <dd><strong><?= e(cert_display($certInfo)) ?></strong></dd>
            <?php else: ?>
                <dt>Zertifizierung</dt>
                <dd>alle</dd>
            <?php endif ?>

            <dt>Eintraege</dt>
            <dd><?= count($entries) ?></dd>
        </dl>

        <?php if (!$entries): ?>
            <p>Keine Eintraege im gewaehlten Filter.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="width:64px">Datum</th>
                        <th style="width:120px">Kategorie</th>
                        <th>Beschreibung</th>
                        <th class="right" style="width:54px">Stunden</th>
                        <th class="right" style="width:64px">Anrechenbar</th>
                        <th style="width:50px">Zert.</th>
                        <th style="width:140px">Belege</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $en):
                        $files = $evidenceByEntry[(int) $en['id']] ?? [];
                    ?>
                        <tr>
                            <td><?= e(date('d.m.Y', strtotime((string) $en['entry_date']))) ?></td>
                            <td><?= e($en['cat_name']) ?><?php if ((int) $en['doubles'] === 1): ?><span class="badge">×2</span><?php endif ?></td>
                            <td><?= e($en['description'] ?: '–') ?></td>
                            <td class="right"><?= number_format((float) $en['hours'], 2, ',', '.') ?></td>
                            <td class="right"><strong><?= number_format((float) $en['credited_hours'], 2, ',', '.') ?></strong></td>
                            <td><?= e($en['cert_code'] ?: 'allg.') ?></td>
                            <td>
                                <?php if (!$files): ?>—<?php else: ?>
                                    <?= count($files) ?>
                                    <ul class="ev-list">
                                        <?php foreach ($files as $f): ?>
                                            <li><?= e($f['original_name']) ?></li>
                                        <?php endforeach ?>
                                    </ul>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>

            <div class="summary">
                Summe: <strong><?= number_format($totalHours, 2, ',', '.') ?> h</strong> aufgewendet ·
                <strong><?= number_format($totalCredited, 2, ',', '.') ?> h</strong> anrechenbar
            </div>

            <p class="footnote">
                Hinweis: Die hier aufgefuehrten Belege koennen mit der ZIP-Funktion (Originaldateien)
                zusammen mit dieser Liste an die zertifizierende Stelle eingereicht werden.
                Die Spalte "Anrechenbar" beruecksichtigt bereits den Faktor (z.B. ×2 fuer Vortraege/Artikel).
            </p>

            <div class="signature">
                <div>
                    <div class="line">Ort, Datum</div>
                </div>
                <div>
                    <div class="line">Unterschrift</div>
                </div>
            </div>
        <?php endif ?>
    </article>
</body>
</html>
