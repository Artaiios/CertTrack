<?php
/**
 * Vorlage. Datei kopieren nach includes/db_credentials.php und mit echten Werten fuellen.
 * Die echte Datei NIE ins Repo / ZIP packen.
 */

return [
    'db' => [
        'host'     => 'db5012345678.hosting-data.io',
        'name'     => 'dbs1234567',
        'user'     => 'dbu1234567',
        'pass'     => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // Wird fuer HMAC-SHA256 von Magic-Link-Tokens und IP-Hashes benutzt.
    // Mindestens 32 zufaellige Zeichen. Einmal setzen, nie aendern (sonst werden alle bestehenden Magic Links und IP-Hashes invalid).
    'app_secret' => 'CHANGE_ME_TO_RANDOM_64_CHARS_OR_MORE_xxxxxxxxxxxxxxxxxxxxxxxxxxxx',

    // Basis-URL ohne Trailing-Slash. Wird in Magic-Link-Mails verwendet.
    'base_url'   => 'https://dein.domain.tld',

    // PHP-Zeitzone fuer alle Datumsoperationen. Bestimmt, wann ein "Tag" beginnt — wichtig fuer Streaks.
    'timezone'   => 'Europe/Berlin',

    'mail' => [
        'host'     => 'smtp.ionos.de',
        'port'     => 587,
        'user'     => 'noreply@dein.domain.tld',
        'pass'     => 'CHANGE_ME',
        'secure'   => 'tls',          // 'tls' oder 'ssl'
        'from'     => 'noreply@dein.domain.tld',
        'fromName' => 'CertTrack',
    ],
];
