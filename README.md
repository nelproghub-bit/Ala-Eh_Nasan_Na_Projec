# Ala-Eh Nasaan Na? Jeepney Locator

A professional jeepney locator and driver management application built with PHP 8.1+ and MySQL. Features PDO prepared statements, password hashing, CSRF protection, hardened session cookies, and database-backed login throttling.

## Integrated admin portal

The supplied `admin-module` interface is integrated at [public/admin-login.php](public/admin-login.php), using the project's existing database and administrator credentials. It includes operations overview, driver/document review, jeepney management, terminals/routes, trip monitoring/history, CSV/PDF reports, users/roles, profile photos, dark mode, and owner settings/activity logs. Admin styles are isolated in [public/assets/admin](public/assets/admin) to preserve driver and commuter layouts.

Registered administrators can also sign in through the passenger or driver login form; successful admin authentication opens the admin dashboard with the stored owner/admin/viewer role. All three entry points share login throttling and reject deactivated admin accounts. Normal passenger and driver credentials retain their own portal access.

- Apply additive changes with `php tools/migrate-admin.php` after the existing project schema and commuter/pickup migrations. The earliest existing administrator becomes the owner; later accounts keep admin access. Existing account IDs and passwords are preserved. Migration history prevents repeat changes.
- Commuter management uses `commuter_accounts`, including actual sign-in and account suspension. Driver suspension and jeepney operational status are enforced by the driver portal and public assignment feeds.
- The **Ongoing trips** card refreshes every five seconds. It counts each operating jeepney once when its saved seats are full (using the approved capacity), its driver reports Full/In transit, or it has an active tracked trip. Off-duty drivers, suspended accounts, and inactive/maintenance vehicles are excluded. Reopening seats removes a seat-only full entry; an active trip or explicit Full/In transit status remains counted until changed. Failed refreshes retain the last count and show a retry notice.
- **Commuter map pins** edits the shared driver/commuter locations. **Terminals & routes** manages approved vehicle route names, terminal endpoints, and planned stops; editing these does not redraw the detailed commuter road geometry automatically.
- Drivers can use **My documents** to supply documents for review and **Trip tracking** to start an authorized trip and opt into GPS sharing with admins. The existing driver availability status is also visible in trip monitoring. Local next-stop navigation remains separate from GPS sharing.
- PDF reports use Composer dependencies: run `composer install` when deploying. The bundled `admin-module/vendor` is used as a local fallback. Writable report caches and test previews live under `storage`, which is excluded from Git and blocked from HTTP by the root Apache rules.
- Validation: `php tools/admin-integration.test.php` uses a disposable MySQL database for real handler tests (requires database create/drop privileges); `php tools/admin-smoke.php admin-dashboard.php` performs a read-only render using the existing owner. Give CLI PHP a writable `session.save_path` when needed.

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

1. Create a MySQL user/database, then apply `database/schema.sql` (it creates `ala_eh`) followed by `database/map-locations.sql`, `database/seat-availability.sql`, `database/trip-status.sql`, `database/driver-pickup-areas.sql`, and `database/commuter-accounts.sql` in that database. Apply `database/commuter-presence.sql` once to add presence tracking to existing commuter accounts. New installations include the presence column in the commuter account schema. The admin commuter table refreshes presence every 15 seconds; recent interaction is considered active for five minutes.
2. Copy `.env.example` to `.env`, then set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. The application loads `.env` for local development; process environment variables take precedence. For a default local XAMPP installation with an empty root password, use `DB_USER=root` and leave `DB_PASSWORD` blank. Only use the root account for local development; production must use a dedicated least-privilege MySQL account.
3. Keep `SESSION_COOKIE_SECURE=true` and serve the app over HTTPS in production. For local HTTP-only development, set it to `false` locally, then restore `true` before deployment.
4. Configure the web server document root to `public/`; do not expose the project root, `app/`, or `database/` as web content. The root `.htaccess` blocks direct HTTP access to `.env` when using Apache.
5. Ensure PHP has `pdo_mysql` and `mbstring` enabled.
6. Serve using your PHP web server, for example `php -S 127.0.0.1:8000 -t public` after configuring the environment.
7. Apply `database/schema.sql`, then open `public/admin-login.php` and choose **Create the first admin account**. First-admin registration is closed automatically after one account has been created. Admin credentials are stored as password hashes and are separate from driver accounts. Alternatively, create the first admin from the project root with `php tools/create_admin.php admin@example.com`.

## Deploy to Vercel

For a browser **Dangerous site** warning or Apache/shared-host deployment checks, see [the security warning investigation and deployment checks](docs/browser-security-warning.md).

The full PHP application can run on Vercel's beta Container Runtime using the root `Dockerfile`. Deployment requires an external production MySQL database; sessions and uploaded photos/documents are configured to persist in that database. Follow [docs/vercel-deployment.md](docs/vercel-deployment.md) for database migrations, Vercel environment variables, deployment, and verification.

Open `public/admin-login.php` to sign in, then review pending applications in the admin dashboard. Approval and rejection actions require an authenticated admin session and CSRF token. The optional CLI setup command asks for the password twice; input may be visible in the terminal.

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

## Interactive route map

## Passenger portal integration

The passenger module is available from the main public document root at
`public/commuter/index.html`. Its terminal and jeepney-route APIs are served by
`public/commuter/api/` and use `app/commuter-integration.php` to read the same
MySQL-backed `map_locations` and jeepney route snapshot used by the driver map.
This keeps passenger terminal details, route tracing, and driver route
assignments connected while leaving the original `commuter-module/` folder as
the standalone source module and test suite.

Drivers and commuters share the same terminal and boarding-point map. Open **Jeepney map** in the public navigation (`public/map.php`, no account required), or **My route** as a verified driver. Purple building icons identify terminals; green bus icons identify boarding points. Click a pin for its landmark, directional services, boarding/alighting restrictions, and **Start here / Go here** actions. **Explore a jeepney route** shows its full path and stops in travel order; entering two locations clips the path and stop list to that journey. Filters hide terminals or boarding points, and **Show all stops** restores the full network.

The six supplied locations are stored in `map_locations`. Grand Terminal starts at the community-mapped [Bauan departure platform](https://www.openstreetmap.org/node/7246626950). The other five keep null coordinates and appear as **Exact map pin needed**; they are not guessed or silently placed at a town centre. Mabini route geometry is not yet available. OSM platform members supply the additional BW-02 stops, with separate arrival/departure pins and the relation's original stop sequence retained even where a loop repeats the same road.

Administrators can open **Map locations** (`public/admin-locations.php`), choose **Edit**, click or drag the exact pin, set the location type, and select confirmed services. **Save location** publishes active configured pins to both maps on their next load. Blank coordinates leave a location listed but unpinned. All edits require admin authentication and a CSRF token; place names, coordinate pairs, Batangas bounds, and service IDs are validated. Source geometry is community mapping, not a live trip schedule or administrative route approval.

Verified drivers can enter place names in **My route**, for example **Bauan** and **Batangas City**. Both fields resolve automatically after typing pauses; ambiguous searches offer keyboard-accessible place choices. The route draws automatically once both places are resolved, and redraws after dragging a pin. **Use my location** requires browser permission and HTTPS or localhost. **Fit route** restores the route view; **Clear** removes the selections. **Try again** appears after a failed lookup.

Coverage currently includes community-mapped **BW-02 Bauan–Batangas** routes: the [city-proper/BATRASCO loop](https://www.openstreetmap.org/relation/5391157), [Bauan to Grand Terminal](https://www.openstreetmap.org/relation/10755762), and [Grand Terminal to Bauan](https://www.openstreetmap.org/relation/10755763). Geometry follows ordered OSM road members, preserves travel direction, and clips the journey to nearby route points. Locations more than 1.2 km from the mapped route are rejected; dashed lines show off-route distances and are not road directions. No car-routing fallback is used. Distance measures the mapped segment; live travel time and service availability are not inferred. The public route snapshot is not administrative approval and does not change the driver's approved assignment.

The planner uses [Leaflet 1.9.4](https://leafletjs.com/reference.html), [OpenStreetMap tiles](https://operations.osmfoundation.org/policies/tiles/), and [Photon place search](https://github.com/komoot/photon). External searches are restricted to the Batangas area, debounced, serialized to at most one per second, and cached in memory. The three common place aliases resolve locally. Search text is sent to Photon; the app does not save location history. Pin matching happens in the browser using the bundled [route snapshot](public/assets/jeepney-routes.json), attributed to OpenStreetMap contributors under ODbL. Internet access is required for library loading, map tiles, and external place search. Configure a suitable hosted or self-hosted Photon instance for production volume.

To refresh the snapshot, download the three source relations with `/api/0.6/relation/{id}/full.json` as `jeepney-{id}.json` into a temporary directory, then run `node tools/build-jeepney-routes.cjs <directory>`. The builder rejects disconnected geometry and records source versions and dates. Run `node --test tools/jeepney-routing.test.cjs` to check direction, coverage, and route continuity. Apply `database/schema.sql` for the separate approved-route tables.

## Current trip status

Apply `database/trip-status.sql` after the main schema. Verified drivers with an approved jeepney can open **Current trip status**, select **Available**, **In transit**, **Full**, or **Out** (off duty), and save. The full-width panel and navigation badge show the saved value; a new driver starts at **Not set**. Selections stay as drafts until saved and survive switching dashboard panels. Trip status is a manual operating status, independent of the occupied-seat count. It does not automatically update seats or broadcast live vehicle positions. Updates require CSRF, current driver/vehicle eligibility, allowed status values, and a matching revision to prevent stale-tab overwrites. The session determines which driver's status is saved.

## Seat availability

Apply `database/seat-availability.sql` after the main schema. Verified drivers with an approved plate and passenger capacity can expand **Available seats** on the dashboard, adjust the occupied count with passenger boarding/alighting buttons or direct input, then **Save seat availability**. Quick actions set zero occupied or full. Changes preview immediately but are only persisted after saving; the collapsed summary shows the saved count. A new vehicle starts unreported rather than claiming live availability. Previously approved capacity remains usable while proposed vehicle edits are under review.

Colors use the **occupied** percentage: below 50% green, 50–69% yellow, 70–89% orange, and 90–100% red. The prominent percentage shows **available** seats, so zero occupied out of 20 displays 100% available in green. Percentages use the actual approved capacity. Status labels accompany colors. Updates are session-scoped, CSRF-protected, range-checked, and use row locking plus a revision check to reject stale updates from other tabs. Capacity changes require the driver to confirm a new count. No live commuter broadcast or automatic passenger sensing is implied. Run `php tools/seats.test.php` for calculation and eligibility checks.

## Driver verification options

1. **Manual document review:** Keep new accounts pending until an authorized reviewer checks the submitted license/identity documents against the driver's details, then updates the status to `verified` or `rejected`. Restrict status changes to an authenticated staff role and keep an audit trail.
2. **Third-party identity/license verification:** Integrate a licensed verification provider, obtain explicit consent, and validate the driver's license/identity through its API. Change the status only after a successful, auditable provider result; retain the pending state for failures or manual-review cases.

Choose the operational model before enabling access to dispatch, payments, or other driver services. Do not store raw identity-document images in this starter app.

## Security notes

- Login failures are limited to five attempts per 15-minute window by source IP and by IP/email pair. Successful authentication clears those buckets. The rate-limit table should be periodically cleaned of expired rows.
- Cookies are `HttpOnly`, `SameSite=Strict`, and `Secure` by default. HTTPS is required in production.
- Registration validates unique email and license values through database constraints. Passwords are never stored in plaintext.
- Configure web-server HTTPS, request-size limits, backups, database least-privilege access, and monitoring before production use.
