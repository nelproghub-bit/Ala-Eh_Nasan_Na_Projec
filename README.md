# Ala-Eh Nasaan Na? Jeepney Locator

A professional jeepney locator and driver management application built with PHP 8.1+ and MySQL. Features PDO prepared statements, password hashing, CSRF protection, hardened session cookies, and database-backed login throttling.

## Design & Typography

This application features a professional design system with:

- **Modern Typography**: Inter and Plus Jakarta Sans fonts for exceptional readability
- **Professional UI**: Refined components with smooth animations and hover states
- **Design Tokens**: Consistent spacing, colors, and border radius throughout
- **Responsive Design**: Mobile-first approach with fluid typography scaling
- **Accessibility**: WCAG AA compliant with proper focus states and color contrast

### Typography System
- **Display Font**: Plus Jakarta Sans (headings, hero sections, important UI elements)
- **Body Font**: Inter (body text, navigation, forms, general UI)
- **Font Weights**: 400-800 for various text hierarchies
- **Refined Letter Spacing**: Optimized for screen reading at all sizes

### Color Palette
- Modern blue palette (#0a1929 to #eff6ff)
- High-contrast text colors for readability
- Professional neutral tones (#0f172a to #f8fafc)
- Semantic colors for status indicators

## Setup

Follow [the shared MySQL setup guide](docs/shared-database-setup.md) to configure the one shared managed database, each developer's private TLS-enabled connection, and safe schema changes. All collaborators use the same `DB_NAME`; only their individual least-privilege usernames/passwords differ. Application credentials are never committed.

1. Copy `.env.example` to `.env` and configure the connection and verified CA certificate for your environment. The application loads `.env` for local development; process environment variables take precedence.
2. Keep `SESSION_COOKIE_SECURE=true` and serve the app over HTTPS in production. For local HTTP-only development, set it to `false` locally, then restore `true` before deployment.
3. Configure the web server document root to `public/`; do not expose the project root, `app/`, or `database/` as web content. The root `.htaccess` blocks direct HTTP access to `.env` when using Apache.
4. Ensure PHP has `pdo_mysql` and `mbstring` enabled.
5. On a new, empty database, import `database/schema.sql` with `php tools/import_schema.php` using an authorized schema account, then apply approved migrations with `php tools/migrate.php`. The admin operations migration must be applied before opening the expanded admin portal.
6. Open `public/admin-login.php` and choose **Create the first owner account**. First-owner registration closes automatically after the first account is created. The owner can create admins and read-only viewers from the portal. Admin credentials are hashed and separate from driver accounts.
7. Use `php tools/check_database.php` to check local connectivity without exposing connection details.

GitHub collaborators should be granted repository access through the repository's settings. Keep `.env`, database credentials, private keys, and downloaded CA files out of commits; review pull requests before merging schema or application changes. Normal pages retrieve shared records on request; the admin trip map polls active driver-consented locations.

The admin portal includes operations overview, driver/document review, jeepney management, terminal and route configuration, trip monitoring, reports, user management, and owner-only system settings/activity logs. Driver GPS sharing is opt-in per trip and requires browser geolocation permission. Terminal and route coordinates must be entered from verified local information; the application does not seed guessed locations. Commuter management currently stores contact records only and does not provide commuter sign-in.

Drivers can manage one jeepney profile at `public/jeepney-profile.php`. The signed-in driver's session determines ownership; the API never accepts a driver ID to select whose vehicle is edited. `GET /api/vehicle.php` returns the signed-in driver's profile, and `PUT /api/vehicle.php` accepts JSON containing `plate_number`, `capacity`, and `route_assignment`, with the page's CSRF token in the `X-CSRF-Token` header. Philippine plate formats such as `ABC 1234` and `123 ABC` are accepted. Each submission is stored as pending changes; verified details remain unchanged until an administrator approves the update in the vehicle review queue.

Verified drivers can view their assigned route at `public/driver-route.php` and update their own email and phone at `public/driver-profile.php`. Contact updates are validated, CSRF-protected, and scoped to the signed-in driver's database ID. Route names in `routes.route_name` must exactly match the approved `jeepney_profiles.route_assignment`; ordered location details are stored in `RouteStops`. Each `RouteStops` row includes a unique sequence number, location name, area, landmark, location type (`terminal`, `boarding_point`, `stop`, or `destination`), and optional implementation note. Apply `database/schema.sql` to create the tables. Seed the route using the exact approved route-assignment name, followed by one `RouteStops` row for each location in travel order. Mark only confirmed formal terminals and the destination with their corresponding types; the driver view reads these labels from the table.

The client supplied these six initial location candidates; they are documented here without assigning a route direction, sequence, terminal role, or destination that has not been confirmed:

| Area / perspective | Named location | Client-provided landmark | Implementation note |
|---|---|---|---|
| Batangas City | Total Gas Station | DJPMM Access Rd. | Exact entrance/boarding point to verify |
| Batangas City | Diversion Road | In front of Kwatog’s, Brgy. Bolbok | Roadside point; verify safe/legal boarding |
| Batangas City | Grand Terminal | Batangas City | Verify exact map pin and loading bay |
| Mabini — POV from Mabini | Mabini Tourism Triangle | Left side, F. Castillo Blvd | Preserve Mabini-side orientation |
| Mabini — POV from Mabini | In front of 7/11 Mabini | Left side, F. Castillo Blvd | Preserve Mabini-side orientation |
| Mabini — POV from Mabini | In front of Iconails Beauty Lounge | Mabini Circumferential Rd | Verify road side and exact map pin |

Before inserting these candidates into `RouteStops`, confirm the exact route name used by the approved vehicle assignment, each travel direction and stop sequence, and which locations are formal terminals or destinations. This avoids presenting unverified roadside points as authoritative route guidance.

Seed template (replace the placeholders only after confirming the route details):

```sql
INSERT INTO routes (route_name) VALUES ('<EXACT_APPROVED_ROUTE_ASSIGNMENT>');
SET @route_id = LAST_INSERT_ID();

INSERT INTO RouteStops (
    route_id, stop_sequence, location_name, area_label, landmark, stop_type, implementation_note
) VALUES
    (@route_id, 1, '<ORIGIN TERMINAL>', '<AREA>', '<LANDMARK>', 'terminal', '<NOTE>'),
    (@route_id, 2, '<INTERMEDIATE STOP>', '<AREA>', '<LANDMARK>', 'stop', '<NOTE>'),
    (@route_id, 3, '<DESTINATION>', '<AREA>', '<LANDMARK>', 'destination', '<NOTE>');
```

New driver records always start with `verification_status = 'pending_verification'`. Login lets a driver see that status, but the dashboard explicitly treats pending accounts as not yet approved. Any future driver-only operation must independently require `verified`; hiding a link is not authorization.

## Driver verification options

1. **Manual document review:** Keep new accounts pending until an authorized reviewer checks the submitted license/identity documents against the driver's details, then updates the status to `verified` or `rejected`. Restrict status changes to an authenticated staff role and keep an audit trail.
2. **Third-party identity/license verification:** Integrate a licensed verification provider, obtain explicit consent, and validate the driver's license/identity through its API. Change the status only after a successful, auditable provider result; retain the pending state for failures or manual-review cases.

Choose the operational model before enabling access to dispatch, payments, or other driver services. Do not store raw identity-document images in this starter app.

## Security notes

- Login failures are limited to five attempts per 15-minute window by source IP and by IP/email pair. Successful authentication clears those buckets. The rate-limit table should be periodically cleaned of expired rows.
- Cookies are `HttpOnly`, `SameSite=Strict`, and `Secure` by default. HTTPS is required in production.
- Registration validates unique email and license values through database constraints. Passwords are never stored in plaintext.
- Configure web-server HTTPS, request-size limits, backups, database least-privilege access, and monitoring before production use.
