<?php
declare(strict_types=1);

function db_config_value(string $envName, string $fallback, bool $allowEmpty = false): string
{
    $value = getenv($envName);
    if ($value === false) {
        return $fallback;
    }

    $value = (string) $value;
    if (!$allowEmpty && trim($value) === '') {
        return $fallback;
    }

    return $value;
}

function db_config_bool(string $envName, bool $fallback): bool
{
    $value = getenv($envName);
    if ($value === false || trim((string) $value) === '') {
        return $fallback;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbHost = db_config_value('DB_HOST', DB_HOST);
    $dbPort = db_config_value('DB_PORT', DB_PORT);
    $dbUser = db_config_value('DB_USER', DB_USER);
    $dbPass = db_config_value('DB_PASS', DB_PASS, true);
    $dbName = db_config_value('DB_NAME', DB_NAME);
    $dbCharset = db_config_value('DB_CHARSET', defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4');
    $autoCreate = db_config_bool('DB_AUTO_CREATE', defined('DB_AUTO_CREATE') && DB_AUTO_CREATE);
    if (getenv('DB_SKIP_CREATE') === '1') {
        $autoCreate = false;
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        throw new RuntimeException('Invalid database name. Use only letters, numbers, and underscores.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    } catch (PDOException $e) {
        $driverCode = (string) ($e->errorInfo[1] ?? $e->getCode());
        if (!$autoCreate || $driverCode !== '1049') {
            throw new RuntimeException('Database connection failed. Check the Hostinger database name, user, password, and host in config.php.', 0, $e);
        }

        $serverDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $dbHost, $dbPort, $dbCharset);
        $pdo = new PDO($serverDsn, $dbUser, $dbPass, $options);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
    }

    return $pdo;
}
