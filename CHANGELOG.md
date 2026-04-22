# CertTrack — Changelog

## 2026-04-22 — Hotfix: Dashboard 500 + CPE-Druckbild aufgeraeumt

**Dashboard 500 behoben.** Ursache war eine Query in `study_recommendations()`: `ORDER BY (correct / total)` referenzierte zwei Aggregat-Aliase (`COALESCE(SUM(...))`), was MariaDB mit "Reference 'correct' not supported (reference to group function)" ablehnt. MySQL erlaubt das, MariaDB nicht. Loesung: beide Queries (schwaechste Domain, vernachlaessigte Domain) jetzt in einer einzigen ohne Sortier-Tricks fetchen und in PHP filtern/sortieren. Robuster und besser lesbar.

**CPE-Druckbild komplett neu.** Vorher haben Raender gefehlt und der Inhalt klebte am Seitenrand — jetzt:
- `@page` mit klaren Margins (18mm seitlich, 22mm oben/unten, 22mm Top auf Seite 1)
- Einzeln gerahmte `.page` mit Innenpadding 28/22mm — wirkt auf Bildschirm wie Briefpapier, im Druck als sauberer Satzspiegel
- Kopf in serifer Schrift (`Fraunces`), darunter Trennlinie in Brand-Blau
- Meta-Grid (Erstellt / Zeitraum / Zertifizierung / Eintraege)
- Tabelle mit fixierten Spaltenbreiten, ×2-Badge bei Doppelzaehlung
- Hervorgehobene Summen-Box, Hinweis-Footnote, Unterschriftenzeilen "Ort, Datum" + "Unterschrift"
- `page-break-inside: avoid` fuer Zeilen, Summen, Footnote
- Sticky Drucken-Toolbar nur auf dem Bildschirm

**"CISM — CISM —"-Doppelung im Header behoben.** Neue Helper-Funktion `cert_display()` zeigt nur den Namen, wenn der bereits mit dem Code beginnt — sonst Code + Name. Sauber.

**PDO::MYSQL_ATTR_INIT_COMMAND ersetzt.** Auf PHP 8.5+ wirft die Konstante eine Deprecation-Warnung, die vor `header(Location: ...)` ausgegeben wuerde und damit Redirects (z.B. nach Login) bricht. Loesung: Init-Command jetzt per `$pdo->exec("SET ...")` direkt nach dem Connect. Funktioniert auf PHP 8.0–8.5+.

## 2026-04-22 — Foto-Upload-Bug, CPE-Export, Pruefungs-Countdown, Lernempfehlungen

**Foto-Upload auf Mobile reparariert.** Vorher gab's zwei `<input>`s mit gleichem Namen `evidence[]` — der eine hat den anderen serverseitig ueberschrieben, sodass das Kamera-Bild zwar im Anhang stand, aber beim Submit verschluckt wurde. Jetzt nur noch ein einziger Input mit `multiple accept="image/*,application/pdf"`. Auf Android und iOS oeffnet das System-Picker mit Kamera, Galerie und Dateien als Optionen — funktioniert verlaesslich auf beiden Plattformen.

**CPE-Export.** Drei Formate fuer die Einreichung bei ISACA, ISC2, CompTIA:
- **Druckansicht** (`?format=print`): saubere Tabelle, "Drucken / Als PDF speichern" via Browser. Kein PDF-Library noetig.
- **CSV** (`?format=csv`): Semikolon-separiert mit BOM, Excel oeffnet Umlaute korrekt. Alle Spalten inkl. Doppelzaehl-Faktor und Belegnamen.
- **ZIP komplett** (`?format=zip`): Originaldateien plus CSV in einem Archiv. Belege werden auditfreundlich nummeriert (`0001_2025-03-15_webinar_zero-trust.pdf`), die CSV listet die Beleg-Nummer-Range pro Eintrag. README.txt erklaert die Struktur. Braucht `ZipArchive` (Standard auf IONOS).

Filter: Jahr und Zertifizierung. Trigger ueber das neue Panel oben in `cpe.php`.

**Pruefungs-Countdown auf dem Dashboard.** Wenn fuers Hauptthema ein `target_exam_date` hinterlegt ist, zeigt das Dashboard prominent "X Tage bis zur Pruefung" plus einen Fortschrittsbalken (Verlauf von Einschreibung bis Pruefungstermin). Ohne Datum kommt ein dezenter Hinweis mit Link in die Einstellungen.

**Lernempfehlungen.** Neuer Abschnitt "Heute empfohlen" auf dem Dashboard, max. 4 Karten, generiert aus den eigenen Daten:
- **Schwaechste Domain** (>= 10 Fragen versucht, schlechtester Schnitt)
- **Vernachlaessigt** (laenger als 7 Tage nicht beruehrt) oder **Noch nie geuebt**
- **Pace** im Verhaeltnis zum Pruefungsdatum (Empfehlung: 3/5/8 Fragen pro Tag bei >90/30-90/<30 Tagen)
- **Streak retten** (1-3 Tage Pause)

## 2026-04-22 — Drei Themes: Hell, Dunkel, High Contrast

Theme ist jetzt waehlbar. Drei Modi:

- **Hell** — warmes Off-White, Standard.
- **Dunkel** — fuer abends und im Dunkeln, mit lichtstaerkeren Brand-Toenen (`#60A5FA` statt Deep-Navy), Karten in `#111827`.
- **High Contrast** — pures Schwarz auf Weiss, gelbe Akzente (`#FFEB3B`), 2px-Borders, dickere Focus-Rings. Orientiert an WCAG AAA.

Umgesetzt mit CSS Custom Properties (`--bg`, `--card`, `--ink`, `--brand` etc.), die per `[data-theme="..."]`-Selector ueberschrieben werden. Eine einzige Codebase, drei Looks.

Der Picker liegt oben rechts im Header (drei Icon-Buttons, ab `md:` sichtbar). Auf Mobile geht's via Cog → Einstellungen → Theme. Auswahl wird in `users.theme` gespeichert plus als `ct_theme`-Cookie (1 Jahr) — auch nicht-eingeloggte Seiten respektieren das.

Heatmap und XP-Chart in der Fortschrittsseite passen sich automatisch an. Die Heatmap nutzt `rgba(var(--brand-rgb), var(--alpha))`, der Chart liest `document.documentElement.dataset.theme` und waehlt eine passende Palette.

Schema-Migration `users.theme` wird beim naechsten `install.php`-Aufruf nachgezogen.

## 2026-04-22 — Helles Design, Evidence-Upload, Cert-Katalog

Drei groessere Aenderungen:

**Helles Design.** Das dunkle Theme war ermuedend und sah aus wie jede zweite KI-generierte App. Jetzt warmes Off-White (`#FAF7F0`) mit weissen Karten, deep-navy als Brand-Farbe (`#1E3A8A`) und Fraunces-Serif fuer Headlines (Inter fuer Body). Streak-Flame bleibt orange-warm, Erfolgs-Akzente in Emerald. Wirkt eher wie ein gutes Notizbuch als ein Dashboard.

**Evidence-Upload bei CPE.** Pro CPE-Eintrag koennen jetzt PDFs und Bilder (JPG, PNG, WebP, HEIC) hochgeladen werden, max 8 MB pro Datei, beliebig viele. Auf dem Smartphone gibt's einen extra "Foto aufnehmen"-Button mit `capture="environment"` — direkter Zugriff auf die Rueckkamera. Belege liegen unter `uploads/cpe/<user_id>/<random>.<ext>`, das Verzeichnis ist per `.htaccess` komplett vor direktem HTTP-Zugriff gesperrt. Anzeige & Download laufen ueber das auth-pruefende `evidence.php`. Erstmaliges Hochladen schaltet das neue Badge "Beleg-Sammler" frei (+10 XP Bonus pro Eintrag mit Anhang).

**Cert-Templates und persoenlicher Katalog.** Zusaetzlich zu CISM jetzt direkt verfuegbar: CISSP, CISA, CRISC, CCSP, CompTIA Security+ (SY0-701), CompTIA CySA+, OSCP, AWS Security Specialty (SCS-C02), ISO/IEC 27001 Lead Auditor — jeweils mit den offiziellen Domains. Jeder User kann sich in `settings.php` selbst einschreiben, ein "Hauptthema" (★) festlegen, und Zertifizierungen wieder aus dem persoenlichen Katalog entfernen. Dashboard, Session-Maske und Fortschrittsseite bevorzugen ab sofort das Hauptthema.

Schema-Migration `users.primary_certification_id` wird beim naechsten `install.php`-Aufruf automatisch nachgezogen, ebenso die neue Tabelle `cpe_evidence`. Beim Setup wird `uploads/cpe/` automatisch angelegt und auf Schreibrechte geprueft — falls IONOS sich quer stellt, kommt ein klarer Fehlertext.

CSP wurde fuer Google Fonts erweitert (fonts.googleapis.com / fonts.gstatic.com), sonst blieben Fraunces und Inter blockiert.

## 2026-04-22 — Initial Release

CertTrack ist da. Erste lauffähige Version mit allem, was zum CISM-Lernen gebraucht wird:

- Magic-Link-Login (kein Passwort, weil ein vergessenes Passwort der schnellste Weg ist, das Lernen einzustellen).
- Lernsessions mit Domain-Zuordnung, Stimmungs-Skala und sofortigem XP-Feedback.
- CPE-Erfassung getrennt nach allgemein und zertifizierungsspezifisch, mit Doppelzählung für Vorträge und Artikel.
- Dashboard mit Streak, Level-Fortschritt und Schnellerfassung — damit "schnell 20 Fragen geloggt" auch wirklich schnell geht.
- Fortschrittsseite mit Domain-Aufschlüsselung, XP-Verlauf und Aktivitäts-Heatmap pro Wochentag.
- Lernpartner-System: Einladung per E-Mail, Streak-Vergleich, kurze Motivations-Nachrichten.
- 20 Level mit Security-Themen-Namen (Rookie Analyst → CISM Legend) und 7 Start-Badges, die automatisch unlocked werden.
- Admin-Bereich für User, Zertifizierungen, Domains und Ankündigungen.
- DSGVO: IP wird HMAC-SHA256-gehasht, Consent wird beim ersten Login eingeholt und gespeichert.

CISM kommt mit fertigen Domains: Governance, Risk Management, Programme Development, Incident Management. Andere Zertifizierungen kann der Admin im Adminbereich anlegen.

`install.php` ist idempotent, also sicher mehrfach ausführbar. Trotzdem nach dem Setup löschen oder per `.htaccess` blocken.
