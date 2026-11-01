<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = database_connect_with_prefix('DB_');

    return $connection;
}

function database_connect_with_prefix(string $prefix, ?string $fallbackPrefix = null): PDO
{
    $host = database_required_setting($prefix . 'HOST', $fallbackPrefix === null ? null : $fallbackPrefix . 'HOST');
    $port = database_required_setting($prefix . 'PORT', $fallbackPrefix === null ? null : $fallbackPrefix . 'PORT');
    $name = database_required_setting($prefix . 'NAME', $fallbackPrefix === null ? null : $fallbackPrefix . 'NAME');
    $user = database_required_setting($prefix . 'USER');
    $password = database_required_setting($prefix . 'PASSWORD');
    $caPath = database_required_setting($prefix . 'SSL_CA', $fallbackPrefix === null ? null : $fallbackPrefix . 'SSL_CA');

    if (!preg_match('/\A[0-9]+\z/', $port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException($prefix . 'PORT must be a valid TCP port.');
    }
    if (!preg_match('/\A[A-Za-z0-9_]+\z/', $name)) {
        throw new RuntimeException($prefix . 'NAME must contain only letters, numbers, and underscores.');
    }
    if (strpbrk($host, ";=\0") !== false) {
        throw new RuntimeException($prefix . 'HOST contains invalid characters.');
    }

    $resolvedCaPath = realpath($caPath);
    if ($resolvedCaPath === false || !is_file($resolvedCaPath) || !is_readable($resolvedCaPath)) {
        throw new RuntimeException($prefix . 'SSL_CA must point to a readable CA certificate file.');
    }
    if (!defined('PDO::MYSQL_ATTR_SSL_CA') || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        throw new RuntimeException('The pdo_mysql driver must support verified TLS connections.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        (int) $port,
        $name
    );
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
        constant('PDO::MYSQL_ATTR_SSL_CA') => $resolvedCaPath,
        constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') => true,
    ];
    $connection = new PDO($dsn, $user, $password, $options);
    $tlsStatus = $connection->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
    if (!is_array($tlsStatus) || !isset($tlsStatus[1]) || $tlsStatus[1] === '') {
        $connection = null;
        throw new RuntimeException('The database connection did not negotiate TLS.');
    }

    return $connection;
}

function database_required_setting(string $name, ?string $fallbackName = null): string
{
    $value = app_environment_setting($name);
    if ($value === null && $fallbackName !== null) {
        $value = app_environment_setting($fallbackName);
    }

    if ($value === null) {
        throw new RuntimeException($name . ' must be configured in the environment.');
    }

    return $value;
}
