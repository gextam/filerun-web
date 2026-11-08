<?php
// Copy to config.php (the installer does this for you) and adjust.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'filerun',
        'user' => 'filerun',
        'pass' => 'secret',
        'prefix' => 'fr_',
        'socket' => '',            // e.g. /var/run/mysqld/mysqld.sock (overrides host/port)
    ],
    'data_dir' => '/var/filerun-data',   // user files, versions, trash, thumbnails – keep it outside the web root
    'secret' => 'change-me-to-a-long-random-string',
    'public_url' => 'https://files.example.com',  // used in e-mails and links; null = detect from the request
    'debug' => false,
    'trusted_proxies' => [],     // e.g. ['173.245.48.0/20'] when behind Cloudflare / a reverse proxy
    'asset_url' => null,         // URL of this folder when it cannot be detected (app outside the web root)
    'binaries' => [],            // override tool paths: ['ffmpeg' => '/usr/local/bin/ffmpeg']
    'webauthn_rp_id' => null,    // passkey relying party id, defaults to the host name
];
