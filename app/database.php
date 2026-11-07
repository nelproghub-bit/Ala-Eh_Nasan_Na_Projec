<?php
declare(strict_types=1);

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    // Some shared hosts disable putenv(), so an .env value cannot reliably be
    // promoted to getenv(). Read the local file as a fallback for every DB
    // setting while still letting actual environment variables take priority.
    $environmentFile = dirname(__DIR__) . '/.env';
    $values = is_file($environmentFile) ? parse_ini_file($environmentFile, false, INI_SCANNER_RAW) : [];
    if (!is_array($values)) {
        throw new RuntimeException('Unable to read the database configuration.');
    }
    $setting = static function (string $name, string $default = '') use ($values): string {
        $environmentValue = getenv($name);
        if ($environmentValue !== false && $environmentValue !== '') {
            return $environmentValue;
        }
        return isset($values[$name]) && is_string($values[$name]) ? $values[$name] : $default;
    };

    $host = $setting('DB_HOST', '127.0.0.1');
    $port = $setting('DB_PORT', '3306');
    $name = $setting('DB_NAME', 'ala_eh');
    $user = $setting('DB_USER', 'driver_app');
    $password = $setting('DB_PASSWORD');
    if ($password === '') {
        throw new RuntimeException('DB_PASSWORD must be configured in the environment.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $caPath = trim((string) (getenv('DB_SSL_CA') ?: ''));
    $caContents = getenv('DB_SSL_CA_BASE64');
    if ($caContents !== false && $caContents !== '') {
        $decodedCa = base64_decode($caContents, true);
        if (!is_string($decodedCa) || $decodedCa === '') {
            throw new RuntimeException('DB_SSL_CA_BASE64 must contain a valid base64-encoded CA certificate.');
        }
        $caPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ala-eh-mysql-ca.pem';
        if (file_put_contents($caPath, $decodedCa, LOCK_EX) !== strlen($decodedCa)) {
            throw new RuntimeException('Unable to prepare the database CA certificate.');
        }
        @chmod($caPath, 0600);
    }
    if ($caPath !== '') {
        if (!is_file($caPath) || !is_readable($caPath) || !defined('PDO::MYSQL_ATTR_SSL_CA')) {
            throw new RuntimeException('The configured database CA certificate is unavailable.');
        }
        $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $caPath;
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
        }
    }

    $connection = new PDO($dsn, $user, $password, $options);

    return $connection;
}
