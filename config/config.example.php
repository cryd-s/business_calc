<?php
return [
    'db' => [
        // Option A (empfohlen für All-Inkl): klassische MySQL-Konfiguration
        'host' => 'localhost',
        'port' => 3306,
        'dbname' => 'deine_datenbank',
        'user' => 'db_user',
        'pass' => 'db_passwort',
        'charset' => 'utf8mb4',

        // Option B (lokal): eigene DSN verwenden, z. B. sqlite
        // 'dsn' => 'sqlite:' . __DIR__ . '/../var/app.sqlite',
    ],
];
