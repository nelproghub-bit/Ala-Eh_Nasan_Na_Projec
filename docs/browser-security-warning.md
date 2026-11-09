# Browser "Dangerous site" warning

The Brave screenshot is a browser security interstitial. It does not identify an application exception or prove which script, URL, or deployment caused the classification. Login forms alone are not evidence of phishing. Fixing application code does not automatically remove an existing Safe Browsing warning.

## Live inspection: 8 November 2026

Affected site: <https://ala-eh-nasan-na.great-site.net/>.

- [Google's live Safe Browsing report](https://transparencyreport.google.com/safe-browsing/search?url=https%3A%2F%2Fala-eh-nasan-na.great-site.net%2F&hl=en) returned **This site is unsafe**, with a deceptive-content warning concerning requests for personal information or software downloads. This confirms the listing behind the reported warning, but does not identify the offending URL or establish whether the listing is a false positive.
- The home page and passenger login loaded in the inspection browser. Both lacked `Content-Security-Policy`, `X-Frame-Options`, and `X-Content-Type-Options` response headers. The passenger HTML was served with `Cache-Control: max-age=2592000, public, proxy-revalidate`. The local header fixes were therefore not effective on this deployment at inspection time.
- The passenger login loaded only its two local external scripts and the expected inline introduction initializer. SHA-256 hashes of both deployed scripts matched the local files exactly:

  | Script | SHA-256 |
  | --- | --- |
  | `commuter/assets/user.js?v=12-presence` | `560faefe50e8edf125d01d184808db77291c4b6c52b99c5f399787ded005039d` |
  | `commuter/assets/login-intro.js?v=3` | `4a5cdf863d80a599a88e87f37ad2910584d8f927dc4f0cb30fa7264bb3ee1eed` |

- The unauthenticated session endpoint returned 401, as expected. No credentials were submitted and no accounts were created. The Google verification file returned 200 and its expected contents in the browser; Search Console ownership was not checked.
- The initial navigation added `?i=1`. InfinityFree [documents this as its browser verification mechanism](https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353), which can interfere with command-line clients and webmaster verification tools. That mechanism is not itself proof of phishing and was not established as the reason Google flagged this site.
- Private-path HEAD probes returned connection failures or redirects toward the host's error pages; they did not establish that private files were exposed. No private response bodies were downloaded.

After the live inspection, the passenger HTML was found to contain an unused hidden driver credential form described as a preview. It never authenticated the driver and discarded the entered password in JavaScript. Although no evidence shows that it transmitted credentials, a dormant password form with no real authentication purpose can resemble deceptive credential collection to scanners. It has been removed from both passenger source copies. The real passenger login and registration forms now declare explicit same-origin POST destinations and field names; JavaScript continues sending the existing JSON requests to those same endpoints.

The authenticated Search Console report was later provided. It identifies one **Deceptive pages** issue and shows **Sample URLs: N/A**. Google therefore provides no single example route to remove; the entire deployed site and every credential page must be treated as in scope. Deployment access and the host's full file inventory were not available. Local changes have not been uploaded or submitted for review.

## Source review and fixes

- The reviewed integrated login code posts credentials to local PHP endpoints. Passenger navigation uses fixed local destinations; admin return navigation checks the destination filename and rejects external hosts and schemes.
- The reviewed external JavaScript is Leaflet 1.9.4 from unpkg, with integrity hashes on both static tags and the dynamic passenger map loader. No obfuscated executable payload was found in the inspected application source. This is not proof that the deployed files or hosting account are clean.
- The container already provided security headers, but Apache/shared hosting had no equivalent public policy. [public/.htaccess](../public/.htaccess) now supplies CSP, anti-framing, MIME protection, and no-store headers for passenger account pages. Maps and Google Fonts retain their required resource permissions.
- The root Apache rules did not block Git metadata or the root SQL dump. [The root rules](../.htaccess) now block hidden paths, database/configuration/backup files, source modules, and directory listings. Public rules also protect installations whose document root is already `public/`.
- Both [Caddy](../Caddyfile) and Apache now restrict unpkg script/style permissions to the exact Leaflet files used by the app, rather than the entire CDN host. Existing inline scripts still require `unsafe-inline`; this is not a complete XSS audit.

These are verified source/configuration gaps, not a confirmed explanation for the browser classification. Live observations above cover the inspected public routes only; the host's remaining files still need inspection.

## Deploy and verify

1. Prefer setting the document root to `public/`. On Apache/shared hosting that preserves the repository layout, upload **both** changed `.htaccess` files, including hidden files, to their respective directories. If the contents of `public/` were copied into the host's `htdocs/`, its `.htaccess` belongs beside the deployed `index.php`; merge with existing host-specific routing rules rather than replacing those blindly. Keep application code and configuration outside the public directory where supported. The host must support Apache 2.4 authorization, `mod_headers`, `mod_rewrite`, and the relevant override permissions. An HTTP 500 after upload means the host must inspect its Apache error log and enable the required directives; do not remove protections just to hide the error.
2. On the container deployment, rebuild with the changed `Caddyfile`. Apache files are not used by Caddy. Keep production HTTPS enforced by the hosting provider; the Apache policy intentionally supports local HTTP XAMPP development.
3. Run the read-only response checks against the canonical HTTPS URL. If the checkout itself is accessible at another path, provide that URL as the second argument:

   ```text
   node tools/check-deployment-security.cjs https://your-domain.example/
   node tools/check-deployment-security.cjs https://your-domain.example/public/ https://your-domain.example/
   ```

   Checks use HEAD requests, do not follow redirects, and never retrieve private file bodies. A passing result checks these routes and headers only; it is not a malware scan or a Safe Browsing verdict. On this InfinityFree deployment, automated clients may receive the hosting verification response instead of the application. In that case, inspect actual responses in a browser after its normal verification; do not treat a challenge response as proof that the application passed or failed.
4. Inspect the live deployment and hosting logs for unexpected files, external redirects, changed scripts, or exposed database files. Compare deployed code with the intended revision. If private files were accessible, investigate access logs and rotate exposed credentials through the host.
5. Open the affected property's **Security issues** report in [Google Search Console](https://search.google.com/search-console). Review its example URLs, fix any identified issue, then request a security review with the changes actually deployed. The existing Google verification file remains public, but the file alone does not establish verified ownership.
6. Check the exact URL in [Google's Safe Browsing site status](https://transparencyreport.google.com/safe-browsing/search). If no issue is found after inspection, use [Report an incorrect phishing warning](https://safebrowsing.google.com/safebrowsing/report_error/). Contact the hosting provider if it injects unexpected content or the reported problem persists.

Suggested Search Console review text after the clean deployment is live and verified:

> We reviewed the complete deployment because Search Console supplied no sample URLs. We removed an unused hidden driver credential preview from the passenger login page. The remaining login and registration forms now declare explicit same-origin POST endpoints, and credentials are sent only to the site's own authentication API. We redeployed from the reviewed source, blocked hidden/configuration/database/source paths and directory listings, added CSP, anti-framing, MIME-sniffing, and private no-store response headers, and checked the live public routes for unexpected scripts and redirects. Please review the site again for the Deceptive pages classification.

Do not bypass or disable the browser protection, or assume changing the domain will resolve the cause. Verify the affected URL again after the review completes.
