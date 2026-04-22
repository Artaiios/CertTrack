# CertTrack

A universal study & certification tracker for multiple users, built for shared hosting (PHP + MariaDB, no framework, no build step).

Primarily designed to prepare for **CISM** (ISACA), but generic enough for any certification path. The default catalogue ships with templates for **CISM, CISSP, CISA, CRISC, CCSP, CompTIA Security+, CySA+, OSCP, AWS Security Specialty, and ISO/IEC 27001 Lead Auditor** — each with its official domain breakdown.

Built with one opinionated constraint: the primary user has ADHD. The system leans hard on **visible progress, streaks, XP, and immediate feedback** — not because it's cute, but because that's what keeps the study routine alive.

## Features

**Study & progress**
- Quick-log sessions from the dashboard in seconds (certification, questions, correct, duration)
- Full session form with domain, mood (5-step emoji), notes
- Per-domain accuracy bars (green ≥80%, blue ≥60%, amber ≥40%, red below)
- XP-over-time line chart (90 days)
- Weekday activity heatmap (last 12 weeks)
- Exam countdown on the dashboard when a target date is set
- Study recommendations: weakest domain, neglected domain, pace vs. exam date, streak-at-risk

**CPE tracking (audit-grade)**
- Per-activity categories (webinar, conference, reading, talk, article, volunteer, mentoring, lab, other)
- Automatic doubled credit for talks / articles (configurable per certification)
- Yearly + 3-year progress bars
- **Evidence upload** per entry: PDF, JPG, PNG, WebP, HEIC — up to 8 MB per file, unlimited files per entry. On mobile the native picker offers camera, gallery, and files.
- **Export for submission**: print-friendly PDF (via browser), Excel-compatible CSV with BOM, or a **ZIP package** with the spreadsheet plus all original evidence files renamed to a numbered audit-friendly convention (`0001_YYYY-MM-DD_<category>_<slug>.<ext>`)

**Gamification**
- 20 levels with security-themed names (Rookie Analyst → CISM Legend)
- XP rules: +10 per correct answer, +2 per wrong (still counts), +50 per CPE hour, +5 per streak day bonus (capped at 30), +100 for talks/articles, +10 bonus for attaching evidence
- Streak tracking with "🔥" flame animation when ≥ 7 days
- 8 automatic badges (First Step, On Track 7d, Unstoppable 30d, Domain Master ≥80%, CPE Champion, Night Owl, Evidence Keeper, …)

**Learning partners**
- Invite unlimited partners by email
- Streak duel comparison
- 280-char motivation messages
- Opt-in progress sharing

**Admin**
- User management (create, role, assign certification, delete)
- Certification catalogue with per-cert CPE targets and active toggle
- Domain editor per certification
- Announcements with expiry date

**Theming**
- Three themes — **light**, **dark**, **high contrast** — switchable from the navigation bar
- Persisted per user (`users.theme`) plus a long-lived cookie for pre-login pages
- Charts and the weekday heatmap automatically adopt the active palette
- Typography: Fraunces (display serif) and Inter (body), via Google Fonts

**Security**
- Magic-link login (no passwords). 256-bit tokens, only HMAC-SHA256 hashes stored, 15-minute TTL, single-use
- Session ID regenerated on login
- CSRF protection on every POST form
- Prepared statements exclusively (PDO) — no string concatenation in SQL
- HSTS, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, CSP with allowlisted CDNs
- GDPR-friendly audit log: IPs are only stored as HMAC-SHA256 hashes, consent captured on first login
- Uploaded evidence is served only through an authenticated PHP endpoint; the `uploads/` directory is fully blocked from direct HTTP access via `.htaccess`

## Tech Stack

- **PHP 8.0+** (tested through 8.5), no framework
- **MariaDB / MySQL** via PDO with prepared statements
- **Tailwind CSS** via CDN — no build step
- **Chart.js** via CDN
- **PHPMailer** (vendored manually, see below)
- Fonts: **Fraunces** + **Inter** from Google Fonts

No Composer, no npm, no front-end bundler. Everything is a single PHP request that renders HTML.

## File Layout

```
certtrack/
├── install.php              # Idempotent installer (schema + seed data + admin user)
├── index.php                # Magic-link request form
├── auth.php                 # Magic-link consumer
├── logout.php
├── theme.php                # Theme switcher POST handler
├── dashboard.php            # Streak, level, exam countdown, recommendations, quick log
├── session_log.php          # Full session capture
├── cpe.php                  # CPE entries + evidence upload + export panel
├── cpe_export.php           # CSV / ZIP / print-friendly export formats
├── evidence.php             # Authenticated evidence file server
├── progress.php             # Domain accuracy, XP chart, heatmap, buddy comparison
├── buddies.php              # Learning partners
├── settings.php             # Profile, primary certification, catalogue, theme
├── admin.php                # User / certification / domain / announcement admin
├── includes/
│   ├── db_credentials.example.php   # Template — copy, rename, fill in, never commit
│   ├── db.php                        # PDO singleton + app constants
│   ├── auth.php                      # Sessions, magic links, PHPMailer wiring, CSP
│   ├── functions.php                 # XP, streak, badges, evidence, theme, recommendations
│   └── layout.php                    # Shared head / nav / footer with theme system
├── uploads/
│   ├── .htaccess                    # Deny all direct HTTP access
│   └── cpe/<user_id>/<random>.<ext>
├── .htaccess                        # Apache hardening
├── CHANGELOG.md                     # Human-written release notes
└── CLAUDE.md                        # Project conventions for future maintenance
```

## Installation

1. Set the PHP version to **8.0 or higher** in the control panel (Hosting → PHP version).
2. Upload the project via FTP to your web root — **without** `includes/db_credentials.php`.
3. Copy `includes/db_credentials.example.php` to `includes/db_credentials.php` and fill in real DB and SMTP credentials. Generate a long random `app_secret` (64+ random chars) — once set, do not change it, or all existing magic links and IP hashes become invalid.
4. Download PHPMailer (the three files `PHPMailer.php`, `SMTP.php`, `Exception.php` from [PHPMailer releases](https://github.com/PHPMailer/PHPMailer/releases)) and place them under `includes/PHPMailer/`. Without this, CertTrack falls back to PHP's `mail()` — which usually still works on IONOS but is less reliable.
5. Visit `https://yourdomain.tld/install.php` in the browser. Create the first admin user when prompted.
6. **Delete `install.php`** or block it with `.htaccess` (`Deny from all`).
7. Ensure `uploads/cpe/` exists and is writable (755). The installer tries to create it; if that fails the setup will print a clear message.

### Local development

```bash
# MariaDB (or MySQL)
brew install mariadb
brew services start mariadb

# Create DB + user
mariadb -e "CREATE DATABASE certtrack; CREATE USER 'ct'@'127.0.0.1' IDENTIFIED BY 'ct'; GRANT ALL ON certtrack.* TO 'ct'@'127.0.0.1';"

# Copy credentials
cp includes/db_credentials.example.php includes/db_credentials.php
# Edit: host=127.0.0.1, name=certtrack, user=ct, pass=ct

# Run installer
php -S 127.0.0.1:8000
# Open http://127.0.0.1:8000/install.php
```

PHPMailer is optional for local work unless you need to test the magic-link email flow.

## Configuration

All runtime secrets live in `includes/db_credentials.php`:

```php
return [
    'db' => [
        'host'    => 'db5012345678.hosting-data.io',
        'name'    => 'dbs1234567',
        'user'    => 'dbu1234567',
        'pass'    => '…',
        'charset' => 'utf8mb4',
    ],
    'app_secret' => '…at least 64 random chars, set once, never change…',
    'base_url'   => 'https://yourdomain.tld',
    'timezone'   => 'Europe/Berlin',
    'mail' => [
        'host'     => 'smtp.ionos.de',
        'port'     => 587,
        'user'     => 'noreply@yourdomain.tld',
        'pass'     => '…',
        'secure'   => 'tls',
        'from'     => 'noreply@yourdomain.tld',
        'fromName' => 'CertTrack',
    ],
];
```

Application constants (XP rates, upload limits, magic-link TTL) live in `includes/db.php` and are deliberately unambiguous — tweak directly if you want different values.

## Security Notes

- The `install.php` script is **idempotent** — running it multiple times only adds missing tables, columns, and seed data via `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN` with existence check, and `INSERT IGNORE`. No data is ever dropped.
- Never commit your real `includes/db_credentials.php`. The `.gitignore` excludes it.
- The `uploads/` directory ships with two `.htaccess` files that disable PHP execution and block direct HTTP reads. All evidence is served through `evidence.php` with an ownership check.
- Magic-link tokens are 256 bits of entropy, HMAC-SHA256-hashed before storage, one-time use, 15-minute lifetime. The raw token never touches the database.

## Contributing

This is a personal project, but PRs are welcome if you're also preparing for a certification and want to scratch an itch. Please:

- Keep the **no-framework, no-build-step** constraint.

## License

MIT — see [LICENSE](LICENSE).
