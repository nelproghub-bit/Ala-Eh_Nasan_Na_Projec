<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf_token(): void
{
    verify_csrf_value($_POST['csrf_token'] ?? '');
}

function verify_csrf_value(mixed $submitted): void
{
    if (!csrf_value_is_valid($submitted)) {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }
}

function csrf_value_is_valid(mixed $submitted): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';

    return is_string($submitted) &&
        is_string($expected) &&
        $expected !== '' &&
        hash_equals($expected, $submitted);
}

function login_bucket_keys(string $email, string $ip): array
{
    $emailKey = hash('sha256', strtolower(trim($email)));
    $ipKey = hash('sha256', $ip);

    return [
        hash('sha256', 'ip:' . $ipKey),
        hash('sha256', 'email-ip:' . $emailKey . ':' . $ipKey),
    ];
}

function begin_login_attempt(PDO $pdo, array $keys): bool
{
    sort($keys, SORT_STRING);
    $pdo->beginTransaction();

    try {
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO login_rate_limits (bucket_key, attempts, window_started_at)
             VALUES (?, 0, UTC_TIMESTAMP())'
        );
        foreach ($keys as $key) {
            $insert->execute([$key]);
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $select = $pdo->prepare(
            "SELECT bucket_key, attempts, window_started_at, blocked_until
             FROM login_rate_limits
             WHERE bucket_key IN ($placeholders)
             ORDER BY bucket_key
             FOR UPDATE"
        );
        $select->execute($keys);
        $buckets = $select->fetchAll();
        $blocked = false;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $window = new DateInterval('PT15M');

        foreach ($buckets as $bucket) {
            $blockedUntil = $bucket['blocked_until'] === null
                ? null
                : new DateTimeImmutable($bucket['blocked_until'], new DateTimeZone('UTC'));
            if ($blockedUntil !== null && $blockedUntil > $now) {
                $blocked = true;
                continue;
            }

            $windowStarted = new DateTimeImmutable($bucket['window_started_at'], new DateTimeZone('UTC'));
            if ($windowStarted->add($window) <= $now) {
                $reset = $pdo->prepare(
                    'UPDATE login_rate_limits
                     SET attempts = 0, window_started_at = UTC_TIMESTAMP(), blocked_until = NULL
                     WHERE bucket_key = ?'
                );
                $reset->execute([$bucket['bucket_key']]);
                $bucket['attempts'] = 0;
            }

            if ((int) $bucket['attempts'] >= 5) {
                $block = $pdo->prepare(
                    'UPDATE login_rate_limits
                     SET blocked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)
                     WHERE bucket_key = ?'
                );
                $block->execute([$bucket['bucket_key']]);
                $blocked = true;
            }
        }

        if (!$blocked) {
            $increment = $pdo->prepare(
                'UPDATE login_rate_limits SET attempts = attempts + 1 WHERE bucket_key = ?'
            );
            foreach ($keys as $key) {
                $increment->execute([$key]);
            }
        }

        $pdo->commit();
        return !$blocked;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function clear_login_attempts(PDO $pdo, array $keys): void
{
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $delete = $pdo->prepare("DELETE FROM login_rate_limits WHERE bucket_key IN ($placeholders)");
    $delete->execute($keys);
}

function first_admin_registration_available(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0;
}

function create_first_admin(string $email, string $password): bool
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $guard = $pdo->query(
            'SELECT id FROM admin_registration_lock WHERE id = 1 FOR UPDATE'
        )->fetchColumn();
        if ($guard === false) {
            throw new RuntimeException('The admin registration lock has not been initialized. Apply database/schema.sql.');
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() !== 0) {
            $pdo->rollBack();
            return false;
        }

        $insert = $pdo->prepare(
            'INSERT INTO admins (email, password_hash) VALUES (:email, :password_hash)'
        );
        $insert->execute([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $role = $pdo->prepare(
            "INSERT INTO admin_roles (admin_id, role, account_status) VALUES (?, 'owner', 'active')"
        );
        $role->execute([(int) $pdo->lastInsertId()]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
