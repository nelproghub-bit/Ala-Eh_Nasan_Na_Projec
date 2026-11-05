<?php
declare(strict_types=1);

function require_admin_role(array $roles): array
{
    $admin = require_admin();
    if (!in_array($admin['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }

    return $admin;
}

function record_admin_activity(
    PDO $pdo,
    array $admin,
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?string $details = null
): void {
    $statement = $pdo->prepare(
        'INSERT INTO activity_logs (admin_id, action, entity_type, entity_id, details)
         VALUES (:admin_id, :action, :entity_type, :entity_id, :details)'
    );
    $statement->execute([
        'admin_id' => $admin['id'],
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => $details === null ? null : mb_substr($details, 0, 1000),
    ]);
}

function admin_flash(string $message): never
{
    $_SESSION['admin_flash'] = $message;
    redirect(basename((string) ($_SERVER['PHP_SELF'] ?? 'admin-dashboard.php')));
}

function validated_positive_id(mixed $value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    return $id !== false && $id !== null && $id > 0 ? $id : null;
}

function validated_coordinate(mixed $value, float $minimum, float $maximum): ?float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return null;
    }
    if (!is_numeric((string) $value)) {
        return null;
    }

    $coordinate = (float) $value;
    return is_finite($coordinate) && $coordinate >= $minimum && $coordinate <= $maximum
        ? $coordinate
        : null;
}
