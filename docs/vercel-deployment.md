# Deploy to Vercel

This project runs as a PHP 8.4 FrankenPHP container on Vercel's beta Container Runtime. The container serves only `public/`; application code, SQL files, and configuration remain outside the web root.

## Before deploying

1. Use a Vercel account with access to the GitHub repository and enable the Container Runtime for the project.
2. Create a production MySQL database reachable from Vercel. Do not use the local XAMPP database or a database account with schema-administrator privileges for web requests.
3. Configure the production schema using the project setup instructions in the root [README](../README.md). Run `php tools/migrate-admin.php` from a trusted environment after the existing schema and required migrations are installed. This applies `0008_vercel_persistent_storage.sql`, which adds shared sessions and allows documents to be stored in MySQL.
4. Create the first administrator through the secured first-admin registration page after deployment, or use the existing production administrator account.

## Import and configure

Import `teasear2816-ux/Ala-Eh-Nasaan-Na-` from GitHub. Set the project root to the repository root and select the **Container** framework if Vercel does not select it automatically. The root `Dockerfile` builds PHP 8.4 with the required PDO, SQLite, image, and PDF extensions; `Caddyfile` points the web root at `public/`.

Add these environment variables to Vercel's **Production** environment. Add them to Preview only if Preview should use a separate test database.

| Variable | Value |
| --- | --- |
| `DB_HOST` | Hostname of the production MySQL server |
| `DB_PORT` | MySQL port, commonly `3306` |
| `DB_NAME` | Application database name |
| `DB_USER` | Dedicated least-privilege application user |
| `DB_PASSWORD` | Application user's password |
| `SESSION_COOKIE_SECURE` | `true` |
| `SESSION_DRIVER` | `mysql` |
| `PROFILE_PHOTO_STORAGE` | `database` |
| `DRIVER_DOCUMENT_STORAGE` | `database` |
| `ALA_EH_TERMINALS_DB` | `/tmp/ala-eh-terminals.sqlite` |

For MySQL providers requiring a custom CA certificate, set `DB_SSL_CA_BASE64` to the base64-encoded PEM certificate. The application writes it to temporary storage and enables certificate verification. Do not commit credentials or certificate files.

Deploy with Vercel's Git integration or the Vercel CLI:

```powershell
npx vercel login
npx vercel link
npx vercel deploy --prod
```

On the first deployment, follow Vercel's prompts to link the imported project. After changing environment variables, create a new deployment for those values to take effect.

## Persistent data notes

- Login sessions are stored in the production MySQL database. The `app_sessions` table must exist before setting `SESSION_DRIVER=mysql`; migration `0008_vercel_persistent_storage.sql` creates it.
- Driver profile photos and uploaded driver documents use the existing MySQL profile-photo table and `driver_documents.file_contents`, respectively. Keep the database backups protected because these tables contain private data.
- The commuter terminal reference database is initialized from committed schema and seed files in each container's `/tmp`. It is temporary and is not a place to store runtime edits; update the source data and redeploy to publish changes.
- Report caches and PHP temporary files are also temporary. Do not store user data in the container filesystem.
- Use a separate production database; never point the deployment at a local or development database.

## Verify the deployment

After deployment, check the home page and the public terminal API. Then verify passenger, driver, and administrator sign-in, session persistence across requests, photo/document upload, and protected downloads. Confirm the production migration is applied and that the Vercel logs contain no PHP extension or database-connection errors.

## Browser deceptive-site warning

If Safari, Chrome, Brave, or another browser displays a deceptive-site warning before the sign-in page loads, the warning is a browser security classification of the URL/site or its content. It is not a response produced by the PHP login endpoint. Follow [the investigation and deployment checks](browser-security-warning.md) to inspect the affected deployment and request review after remediation.

Before requesting a review, deploy the current code and inspect the production deployment for unexpected redirects, injected scripts, downloads, or pages outside the intended public routes. Keep the Vercel project and Git account protected with MFA and rotate any credential that may have been exposed. A custom domain can provide a stable public address, but changing domains does not establish safety or resolve a flagged deployment's underlying problem.

Then submit the exact affected production URL for a Google Safe Browsing review and open a Vercel support ticket if the hostname remains flagged. Include the deployment URL, confirmation that the site is the official Ala-Eh Nasaan Na? Jeepney Locator, and the remediation summary. Do not ask users to continue through the browser warning; wait for the review to clear it.
