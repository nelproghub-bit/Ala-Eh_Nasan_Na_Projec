'use strict';

// Read-only HTTP checks. Never download private file contents or follow redirects.
// Usage: node tools/check-deployment-security.cjs <public-url> [checkout-url]
const assert = require('node:assert/strict');

function baseUrl(value) {
  const url = new URL(value);
  assert(!url.username && !url.password && !url.search && !url.hash, 'Use a URL without credentials, query, or fragment.');
  assert(url.protocol === 'https:' || (url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)), 'Use HTTPS for deployed sites.');
  if (!url.pathname.endsWith('/')) url.pathname += '/';
  return url;
}

async function head(base, path) {
  return fetch(new URL(path, base), { method: 'HEAD', redirect: 'manual', signal: AbortSignal.timeout(10000) });
}

async function main() {
  assert(process.argv[2], 'Usage: node tools/check-deployment-security.cjs <public-url> [checkout-url]');
  const publicUrl = baseUrl(process.argv[2]);
  const checkoutUrl = process.argv[3] ? baseUrl(process.argv[3]) : publicUrl;
  let checks = 0;
  for (const path of ['commuter/user-login.html', 'commuter/user-map.html']) {
    const response = await head(publicUrl, path);
    assert.equal(response.status, 200, `${path} must load without a redirect`);
    const headers = response.headers;
    assert.equal(headers.get('x-content-type-options'), 'nosniff', `${path}: nosniff missing`);
    assert.equal(headers.get('x-frame-options'), 'DENY', `${path}: frame protection missing`);
    assert.match(headers.get('cache-control') || '', /no-store/, `${path}: no-store missing`);
    const csp = headers.get('content-security-policy') || '';
    for (const directive of ["default-src 'self'", "form-action 'self'", "frame-ancestors 'none'", "object-src 'none'"]) {
      assert(csp.split(';').map(value => value.trim()).includes(directive), `${path}: ${directive} missing`);
    }
    console.log(`PASS ${path}: account-page security headers`);
    checks++;
  }
  for (const base of new Set([publicUrl.href, checkoutUrl.href])) {
    for (const path of ['.env', '.git/HEAD', '.vscode/settings.json', 'commuter-database.sql', 'composer.lock', 'app/bootstrap.php', 'database/schema.sql', 'admin-module/public/admin-login.php', 'commuter-module/public/user-login.html', 'storage/', 'assets/']) {
      const response = await head(base, path);
      assert([403, 404].includes(response.status), `${new URL(path, base)}: expected 403/404, received ${response.status}`);
      console.log(`PASS ${new URL(path, base).pathname}: ${response.status}`);
      checks++;
    }
  }
  const verification = await head(publicUrl, 'googlec5e94b545ac00b0e.html');
  assert.equal(verification.status, 200, 'Search Console verification must remain publicly accessible');
  console.log(`Passed ${checks + 1} deployment checks. This does not check or clear Safe Browsing listings.`);
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
