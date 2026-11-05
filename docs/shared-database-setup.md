# Shared Aiven MySQL setup

This guide configures Arnel Gubi, Alex Cueto, and Ashley De Chavez to run separate XAMPP/PHP copies of the website against exactly one shared application database: `ala_eh`. The Aiven service may also provide its own default `defaultdb`; the website does not use it. Every developer and the migration tool must use `DB_NAME=ala_eh`. Only the individual runtime account credentials differ. Normal pages retrieve shared records when requested. The admin trip map polls active trips for new driver-consented GPS updates; it is not a continuous server push.

## What this repository configures

- One PDO connection factory is used by the existing PHP modules. It requires a CA file, enables MySQL server-certificate verification, checks that TLS was negotiated, uses native prepared statements, and limits connection timeouts.
- `.env.example` defines one shared endpoint/database name for all connections. Each developer privately configures their own runtime account credentials in `.env`; authorized schema maintainers can separately set migration credentials. Credentials and certificate files are not source-controlled.
- `database/schema.sql` contains the existing table definitions and constraints, without user records or database-creation privileges.
- `tools/check_database.php` is a CLI-only connectivity check. It reports no host, username, password, certificate path, or server error to the caller.
- `tools/import_schema.php` imports the initial schema only when the target database has no tables.
- `tools/migrate.php` is a CLI-only runner for reviewed, versioned SQL files and requires a separate migration account.
- The admin operations migration adds terminals/routes, vehicle/driver state, trip/location history, document review, commuter records, admin roles, settings, and activity logs without dropping existing tables or records.

## Tasks requiring an authorized service owner

The repository does not provision cloud resources or have access to cloud credentials. An authorized owner must create the Aiven MySQL service/database, approve costs and region, manage the service lifecycle/backups, set network access, create individual accounts, distribute credentials securely, and approve schema changes. Never send database passwords or private keys through GitHub, source files, chat, or this guide.

## 1. Create and secure the managed database

1. In the Aiven Console, create an Aiven for MySQL service in the approved project and region. Select a production-appropriate plan and configure backups/maintenance according to the project’s operational requirements.
2. Create or select the single application database `ala_eh` using the Aiven-supported database management workflow. The service-provided `defaultdb` is not used by the app. Do not create per-developer databases or rely on the application account to create databases.
3. In the service's network access controls, allow only the developers' current public egress IP addresses (and approved deployment addresses). XAMPP's private/LAN address is not the public address seen by Aiven. Remove temporary allowlist entries when they are no longer needed. Avoid unrestricted public access.
4. Download the service CA certificate from the Aiven service connection information. Store it outside the repository and outside any public web directory. Verify it came from the authenticated Aiven Console and use the CA file supplied for this service; do not disable certificate verification to work around TLS errors.
5. Optionally verify the endpoint and CA from a terminal with an OpenSSL client:

   ```text
   openssl s_client -connect YOUR_HOST:YOUR_PORT -servername YOUR_HOST -CAfile PATH_TO_CA -verify_return_error -verify_hostname YOUR_HOST
   ```

   Confirm certificate verification succeeds before configuring the application.

## 2. Create individual database accounts

Create separate credentials for Arnel, Alex, and Ashley through the Aiven-supported user-management interface or an authorized database administrator. Use a unique, strong password for each developer and deliver it using an approved secret manager or secure channel.

Runtime accounts only need the application DML operations:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE
ON `ala_eh`.*
TO 'arnel_app'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE
ON `ala_eh`.*
TO 'alex_app'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE
ON `ala_eh`.*
TO 'ashley_app'@'%';
```

Create each account through the Aiven-supported user-management interface or an authorized database administrator before applying its grant. Confirm the provider accepts the account host format; if it does not, use its supported host/account mechanism. Restrict network access with the service IP allowlist regardless of account host. Do not grant runtime accounts `CREATE`, `ALTER`, `DROP`, `GRANT OPTION`, or administrative privileges. Review actual grants before sharing credentials.

If a provider-created service user starts with broad/global privileges, remove them before using that account:

```sql
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'arnel_app'@'%';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'alex_app'@'%';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'ashley_app'@'%';
```

Then grant only the application DML permissions shown above, and verify each account with `SHOW GRANTS`. The Aiven application users for this project have been restricted to `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on `ala_eh` only. Keep the Aiven administrator account separate; never configure it as an application's `DB_USER`.

Schema imports and migrations require a different, authorized schema account. Set only `MIGRATION_DB_USER` and `MIGRATION_DB_PASSWORD` for authorized migration work; the importer and migration runner reuse the same `DB_HOST`, `DB_PORT`, `DB_NAME`, and `DB_SSL_CA` as the application. This ensures migrations target the one shared `ala_eh` database. Grant the schema account only the DDL and migration-history access needed for approved work. A runtime account must not be used by `tools/migrate.php`.

## 3. Import the sanitized schema

The checked-in `database/schema.sql` creates the existing application tables, indexes, foreign keys, and the migration-history table. It contains no driver/admin records, passwords, or other personal data. It is idempotent for existing tables and does not create, drop, or select a database.

The easiest option is to configure the migration account in `.env` and run `php tools/import_schema.php`. The importer requires an empty database, uses a lock to prevent concurrent imports, and stops without deleting data if the database is not empty. Alternatively, after selecting the `ala_eh` database, import the file with an authorized schema account using a MySQL client:

```text
mysql --host=YOUR_HOST --port=YOUR_PORT --user=SCHEMA_ADMIN --password --ssl-mode=VERIFY_IDENTITY --ssl-ca=PATH_TO_CA ala_eh < database/schema.sql
```

The client prompts for the password. Do not put a password on the command line. If the import is against an existing installation, take and verify a backup first; `CREATE TABLE IF NOT EXISTS` preserves current records, but this schema file is not a data migration and does not reconcile incompatible old table definitions. Compare the existing schema and resolve differences with a reviewed migration before deployment.

## 4. Configure each XAMPP installation

For each developer:

1. Clone the GitHub repository to that developer's own machine and install/use the project's supported PHP/XAMPP version with `pdo_mysql` and `mbstring` enabled.
2. Copy `.env.example` to `.env`. The shared Aiven hostname, port, and one database name (`ala_eh`) are prefilled; set that developer's own runtime username/password and set `DB_SSL_CA` to the downloaded CA file's local absolute path. Quote values that contain special INI characters and escape embedded quotes/backslashes as required by PHP's INI parser.
3. Leave `MIGRATION_DB_USER` and `MIGRATION_DB_PASSWORD` blank unless the developer is explicitly authorized to perform schema migrations. Configure these two values only for an authorized schema administrator, using a distinct account. The migration tools still use the same `DB_NAME=ala_eh`.
4. Set `SESSION_COOKIE_SECURE=false` only for local HTTP development. Use HTTPS and `SESSION_COOKIE_SECURE=true` in deployed environments.
5. From the project root, run `php tools/check_database.php`. A success means a verified-TLS connection was established. On failure, check the configured PHP error log and verify the hostname/port, CA path, IP allowlist, database name, account grants, and network connectivity. The command intentionally does not print internal connection details.
6. Serve the app with XAMPP/Apache using `public/` as the document root. Do not publish the repository root or `tools/`, `app/`, and `database/` as web content.

`DB_SSL_CA` is used by both application and migration connections. In `.env`, use a quoted Windows path such as `C:\xampp\mysql\certs\aiven-ca.pem`. Keep the certificate in a local non-public folder; `.gitignore` excludes common certificate and private-key extensions.

## 5. Apply future schema changes

1. Back up the shared database and verify the backup before any schema change.
2. Review and test an additive migration locally or in a non-production database. Create a new SQL file in `database/migrations/` named with the next four-digit sequence, for example `0001_add_route_metadata.sql`. Never edit a migration that has already been applied.
3. Keep migrations narrowly scoped and safe to retry after partial failure. MySQL DDL can implicitly commit, so the migration runner cannot make arbitrary DDL transactional. Do not include destructive operations (such as dropping tables/columns or truncating data) without explicit owner approval, a tested recovery plan, and a verified backup.
4. A schema-authorized maintainer sets `MIGRATION_DB_USER` and `MIGRATION_DB_PASSWORD` locally and runs `php tools/migrate.php`. The tool reuses the shared `DB_*` endpoint and CA settings, checks the existing baseline schema, takes a database advisory lock, applies each unapplied migration in order, and records successful versions. It does not run automatically during website requests.
5. Review the resulting schema and application behavior, then merge the migration and code changes through the repository's normal pull-request process. Keep the migration account credentials separate from runtime accounts.

The runner records `0000_initial_schema` as a baseline after it verifies all existing application tables. It does not replay the schema over the database. If tables are missing or an existing schema differs, stop and resolve that with an authorized, reviewed change rather than attempting an automatic reset.

## Admin operations and trip tracking

The admin portal now includes driver and document review, jeepney records, terminal and route configuration, active-trip monitoring, reports/CSV exports, owner-managed administrator roles, commuter contact records, settings, and an audit log. Profile photos are stored in the shared database, served only to their signed-in owner, and limited to verified JPEG, PNG, or WebP uploads (5 MB). Back up `ala_eh` first, merge the reviewed migration, then have an authorized schema administrator apply it from the project root:

```text
php tools/migrate.php
```

The migration account needs permission to create the new tables and indexes. Runtime app accounts continue to need only `SELECT`, `INSERT`, `UPDATE`, and `DELETE`; do not give them schema privileges. The migration is additive and idempotent on retry, but MySQL DDL is not transactional, so review logs and inspect the resulting schema if a run fails.

Administrators should enter verified real terminal/stop coordinates. The application does not seed guessed locations. Only a verified driver with an approved, active jeepney and route can start a trip. Starting a trip explicitly requests browser geolocation; the driver can deny permission, and completing/cancelling the trip stops further location updates. Mobile browsers generally require HTTPS for geolocation. Authorized admins see the latest reported position on a map that polls every configured 10–120 seconds. OpenStreetMap tiles are fetched from the public tile service; avoid exposing individual trip details outside the admin portal.

Terminal management records an **Area**, **Location**, and **Landmark**. Existing terminal coordinates are preserved when those details are edited; coordinate entry is intentionally not part of this form. Newly added terminals have no coordinates, and an authorized administrator must arrange verified coordinates separately before they can be used for GPS-based terminal mapping.

Driver-uploaded PDF/JPEG/PNG files (8 MB maximum) are stored as private database blobs so authorized admins on another developer's computer can review the same document. This increases database size and backup requirements; for production-scale document volume, migrate to a properly secured shared object-storage service. Commuter records in this release are admin-managed contact records only: commuter registration, login, and permissions are not implemented. Admin roles are `owner` (user/settings management), `admin` (transport operations), and `viewer` (read-only portal access). The initial admin is seeded as owner; only an owner can create further admin accounts.

## GitHub collaboration

The repository owner must invite the three developers through GitHub repository settings and choose the least repository permission that supports their work. Use feature branches and pull requests; protect the default branch and require review for database migrations. Do not add `.env`, CA certificates, passwords, private keys, or database exports containing live data to a commit. GitHub repository access does not grant MySQL access; each developer's database account and Aiven network allowlist entry are managed separately.
