# Database migrations

Add reviewed, forward-only SQL files here as `NNNN_description.sql` and apply them only with `php tools/migrate.php` and an authorized migration account. Never edit a migration already applied to a shared database. Back up first; MySQL DDL is not generally transactional. See [the shared database setup guide](../../docs/shared-database-setup.md) for the migration and approval process.
