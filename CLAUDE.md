# CertTrack — Projektregeln für Claude Code

## Was ist das?
CertTrack ist ein universeller Lern- und Zertifizierungs-Tracker für mehrere Benutzer. Primär gebaut für die CISM-Vorbereitung (ISACA), aber bewusst generisch — beliebige Zertifizierungen oder Lernziele lassen sich konfigurieren.

Der Hauptanwender hat ADHS. Das System lebt davon, **sichtbaren Fortschritt** zu zeigen und mit **Gamification** (XP, Level, Badges, Streaks) Motivation zu stützen. Wenn du an etwas arbeitest, das diese Schleife schwächt (z. B. Feedback verzögert, Streak-Brüche unsichtbar macht, XP-Vergabe wegrationalisiert), halt kurz inne und frag nach.

## Stack
- **PHP 8.0+** (reines PHP, kein Framework)
- **MariaDB / MySQL** über PDO
- **Tailwind CSS** via CDN
- **Chart.js** via CDN
- **PHPMailer** (manuell unter `includes/PHPMailer/` einzubinden, kein Composer)

Hosting: **IONOS Shared Hosting**. Kein Shell-Zugriff. Keine Composer-Installation auf dem Server. Keine Cron-Jobs ohne IONOS-UI.

## Coding-Konventionen
- Alle DB-Zugriffe über die PDO-Singleton aus `includes/db.php` mit **Prepared Statements** — niemals String-Konkatenation in SQL.
- CSRF-Token auf jedem POST-Formular. `csrf_token()` zum Erzeugen, `csrf_check()` zum Prüfen.
- Session-Auth läuft komplett über `includes/auth.php`. Auf jeder geschützten Seite zuerst `require_login()` aufrufen.
- HTML-Output immer mit `e()` (htmlspecialchars-Wrapper) escapen.
- Konstanten (App-Name, URLs, HMAC-Keys) leben in `includes/db.php`. Secrets selbst stehen in `includes/db_credentials.php`.
- Indentierung: 4 Spaces. Keine Tabs. PHP-Closing-Tag `?>` weglassen in Pure-PHP-Dateien.
- Keine Inline-Kommentare zur Erklärung von Offensichtlichem. Kommentare nur, wenn das *Warum* nicht aus dem Code lesbar ist.
- Bei DB-Schema-Änderungen: `install.php` anpassen UND einen Eintrag in `CHANGELOG.md` machen, damit klar ist, ob ein Re-Run nötig ist.

## Dateien, die NICHT überschrieben werden dürfen
- `includes/db_credentials.php` — DB-Zugang und Mail-Settings. Liegt nur auf dem Server.
- Bestehende Daten in der DB. `install.php` ist idempotent (nur `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE` für Seed-Daten). Keine `DROP`-Statements ohne explizite Aufforderung.

## install.php
Ist **idempotent**. Darf beliebig oft aufgerufen werden. Erstellt fehlende Tabellen, fügt Seed-Badges/Levels/CPE-Kategorien per `INSERT IGNORE` ein. Beim ersten Lauf ohne Admin: legt einen Admin-User aus dem im Formular eingegebenen E-Mail an. Sollte nach erfolgreichem Setup gelöscht oder per `.htaccess` geschützt werden — Hinweis erscheint im Output.

## Deployment IONOS
1. Lokal: ZIP der `certtrack/` ohne `db_credentials.php` bauen.
2. Per FTP / IONOS-Webspace-Explorer in das Web-Root hochladen.
3. `includes/db_credentials.php` aus der Vorlage `db_credentials.example.php` erzeugen, mit echten DB- und SMTP-Daten füllen, hochladen.
4. `https://deine-domain.tld/install.php` einmal aufrufen.
5. Admin-User anlegen, dann `install.php` löschen oder per `.htaccess` `Deny from all` schützen.
6. PHPMailer-Quellen unter `includes/PHPMailer/` ablegen (Files: `PHPMailer.php`, `SMTP.php`, `Exception.php` aus dem GitHub-Release).

PHP-Version in IONOS auf 8.0 oder höher stellen (Customer-Dashboard → Hosting → PHP-Version).

## Sicherheit
- Magic Links: 256-Bit-Token, nur HMAC-SHA256-Hash in DB, 15 Min gültig, single-use, an Klick-IP gebunden (gehasht).
- Session-ID nach erfolgreichem Login regenerieren.
- Headers: HSTS, X-Frame-Options DENY, X-Content-Type-Options nosniff, CSP mit Tailwind- und Chart.js-CDN auf der Allowlist.
- IPs werden nie im Klartext geloggt — `hash_ip()` aus `functions.php`.
- DSGVO: Beim ersten Login Consent zum Audit-Logging einholen, Entscheidung im User-Record speichern.

## CHANGELOG.md
Wird in **menschlichem Ton** geführt. Kein "v1.2.3 - Added X, Fixed Y, Removed Z"-Boilerplate. Lieber: "Streak-Anzeige zeigt jetzt auch gestrige Sessions korrekt an, war ein Off-by-One mit der Zeitzone." Datum + kurzer Absatz reicht.

## Was diese Codebase NICHT will
- Kein Build-Step. Keine npm-Builds. Tailwind kommt vom CDN.
- Keine API-Endpunkte für externe Clients. Alles serverseitig gerendert.
- Keine Frameworks, keine ORMs, kein DI-Container.
- Keine "AI-Schickeria"-UI: kein Glassmorphism, keine Gradient-Soup, kein dunkles Standard-Theme. Helles, warmes Off-White (`#FAF7F0`) mit weißen Karten, Fraunces-Serif für Headlines, Inter für Body. Akzent: Deep-Navy (`#1E3A8A`), Streak-Flammen-Orange (`#EA580C`), Erfolg in Emerald (`#047857`). Wirkt wie ein gutes Notizbuch, nicht wie ein Cockpit.

## Uploads / Evidence
- Verzeichnis `uploads/cpe/<user_id>/<random>.<ext>`, per `.htaccess` mit `Require all denied` gegen direkten HTTP-Zugriff gesperrt.
- Ausgeliefert nur über `evidence.php` (auth + Ownership-Check, sendet `Content-Type` + `X-Content-Type-Options: nosniff`).
- Erlaubte Typen: PDF, JPG, PNG, WebP, HEIC. Hart per `finfo` validiert (nicht nur Endung). Max 8 MB/Datei (`CT_UPLOAD_MAX_BYTES`).
- Mobile: Form bietet zusätzlich `<input capture="environment">` für Direkt-Foto.
- Beim Löschen eines CPE-Eintrags werden alle zugehörigen Dateien per `delete_evidence_file()` mitentfernt — bitte nie Eintrag und Dateien getrennt manipulieren.

## CPE-Export (Audit-Tauglichkeit)
- `cpe_export.php?format=print|csv|zip` mit optionalen Filtern `year=YYYY` und `cert=ID`.
- **ZIP-Format ist die Audit-Lieferform**: Belege werden in `belege/NNNN_YYYY-MM-DD_<cat>_<desc>.<ext>` umbenannt, die CSV im selben ZIP listet die laufende Nummer-Range. README.txt liegt bei. Braucht `ZipArchive` (Standard auf IONOS).
- **CSV** ist UTF-8 mit BOM und `;` als Trennzeichen — öffnet in Excel mit Umlauten korrekt.
- **Druckansicht** ist eine eigenständige HTML-Seite ohne Layout-Includes; "Drucken/PDF" über den Browser. Kein PDF-Library im Spiel.
- Beim Erweitern der Felder: alle drei Formate parallel anpassen — sonst entstehen Diskrepanzen zwischen CSV und ZIP-Manifest.

## Datei-Uploads / Mobile
- **Single `<input type="file" name="evidence[]" multiple accept="image/*,application/pdf">`** ist die Standardlösung. Niemals zwei `<input>`s mit demselben Namen — PHP überschreibt dann eines mit dem anderen, und die Kamera-Aufnahme verschwindet.
- `capture="environment"` als zusätzlicher Button geht nur, wenn das Input einen **anderen** Namen hat. Lieber bleiben lassen — der native Picker bietet auf iOS/Android Kamera, Galerie und Dateien sowieso an.

## Dashboard-Widgets (Pruefung & Empfehlungen)
- `exam_countdown(userId, primaryCertId)` liefert Restdauer und Fortschritt seit Einschreibung. Wenn kein `target_exam_date` gesetzt ist → null, Dashboard zeigt dann einen Settings-Hinweis.
- `study_recommendations(userId, primaryCertId)` liefert max. 4 Empfehlungen aus eigenen Daten. Reine Read-only-Heuristik, keine externen LLMs/Daten. Wenn neue Empfehlungstypen dazukommen: in dieser Funktion ergänzen, am Ende `array_slice(...,4)` lassen — ADHD-friendly: nicht überfrachten.

## Themes (light / dark / hc)
- Drei Themes über CSS Custom Properties in `includes/layout.php`. Definitionen liegen in `:root` (light) und werden per `[data-theme="dark"]` bzw. `[data-theme="hc"]` überschrieben.
- Komponenten (`.card`, `.input`, `.btn-*`, `.alert-*`, `.chip*`, `.text-*`, `.bg-soft`, `.heat-cell`, `.progress-track`) **nur** über diese CSS-Vars stylen — niemals hardcodierte Hex-Farben in Pages.
- Aktives Theme: `current_theme($user)` aus `functions.php`. Reihenfolge: `users.theme` → Cookie `ct_theme` → `light`.
- Gesetzt wird per `set_theme()` (DB + Cookie). POST-Endpoint: `theme.php` mit CSRF und `return`-Parameter.
- Picker: drei Icon-Buttons im Header (ab `md`), zusätzlich vollständige Sektion auf `settings.php`.
- Charts in `progress.php` lesen `document.documentElement.dataset.theme` und wählen eine Palette. Heatmap nutzt `rgba(var(--brand-rgb), var(--alpha))` — `--brand-rgb` muss in jedem neuen Theme definiert sein.
- HC-Spezialitäten: `--border-w: 2px`, dickere Focus-Rings, Buttons in HC bekommen `color:#000` + `font-weight:700`.

## Cert-Templates und persönlicher Katalog
- `install.php` seedet 10 Zertifizierungen (CISM, CISSP, CISA, CRISC, CCSP, Security+, CySA+, OSCP, AWS-SCS, ISO27001-LA) samt offiziellen Domains via `INSERT IGNORE` — Re-Run sicher.
- Jeder User kann sich in `settings.php` selbst einschreiben (`user_certifications`) und ein **Hauptthema** (`users.primary_certification_id`) wählen. Dashboard, Session-Log und Fortschrittsseite nutzen `default_certification_id()` aus `functions.php` für die Vorauswahl.
- Beim Anlegen weiterer Templates: in `install.php`-Array `$certTemplates` ergänzen, nicht über UI seeden.
