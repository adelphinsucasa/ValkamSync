<?php
declare(strict_types=1);

/**
 * PDO connection helper with SQLite/MySQL driver switch.
 *
 * Config-driven via .app_config.php:
 *   DB_DRIVER: 'sqlite' (default) or 'mysql'
 *   SQLite:  DB_PATH
 *   MySQL:   DB_HOST, DB_NAME (gzcapita_valkam), DB_USER, DB_PASS
 *
 * Tables in this project are always prefixed `vsync_` so ValkamSync can
 * coexist with the existing Valkam app in `gzcapita_valkam` without collision.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $driver = defined('DB_DRIVER') ? DB_DRIVER : 'sqlite';
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($driver === 'sqlite') {
        $path = defined('DB_PATH')
            ? DB_PATH
            : dirname(__DIR__, 2) . '/data/valkamsync.db';
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_NAME
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        $pdo->exec("SET time_zone = '+00:00'");
    }

    return $pdo;
}

function db_driver(): string
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}
