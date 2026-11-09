<?php
declare(strict_types=1);

/**
 * Returns an opaque version for data that is shared between the portals.
 *
 * The client only receives the hash, never the underlying counts or dates.
 * Missing optional tables are ignored so the feature also works on existing
 * installations that have not yet applied every migration.
 */
function live_update_token(): string
{
    $sources = [
        ['drivers', 'created_at'],
        ['jeepney_profiles', 'updated_at'],
        ['routes', 'created_at'],
        ['admins', 'created_at'],
        ['commuter_accounts', 'updated_at'],
        ['driver_seat_availability', 'updated_at'],
        ['driver_trip_status', 'updated_at'],
        ['driver_pickup_assignments', 'updated_at'],
        ['map_locations', 'updated_at'],
        ['profile_photos', 'updated_at'],
        ['admin_roles', 'updated_at'],
        ['driver_admin_status', 'updated_at'],
    ];

    $state = [];
    $connection = db();
    foreach ($sources as [$table, $timestamp]) {
        try {
            $statement = $connection->query(
                "SELECT COUNT(*) AS total, COALESCE(UNIX_TIMESTAMP(MAX(`{$timestamp}`)), 0) AS changed_at FROM `{$table}`"
            );
            $result = $statement->fetch(PDO::FETCH_ASSOC);
            $state[$table] = [(int) ($result['total'] ?? 0), (int) ($result['changed_at'] ?? 0)];
        } catch (PDOException) {
            // Optional migration not installed yet.
        }
    }

    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}
