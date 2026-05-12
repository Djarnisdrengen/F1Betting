const https = require('https');
const http = require('http');
const tls = require('tls');
const fs = require('fs');
const path = require('path');
const { URL } = require('url');

require('dotenv').config({ path: path.join(__dirname, '../../build-deploy/.env') });

const env = process.env.DEPLOY_ENV || 'test';
const BASE_URL = process.env[`BASE_URL_${env.toUpperCase()}`] || process.env.BASE_URL;
const USE_SSLLABS = process.argv.includes('--ssllabs');

const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

if (!BASE_URL) {
    console.error('No BASE_URL found. Set DEPLOY_ENV and BASE_URL_TEST / BASE_URL_LIVE in .env');
    process.exit(1);
}

const parsedBase = new URL(BASE_URL);
const hostname = parsedBase.hostname;

// ─── HTTP helpers ─────────────────────────────────────────────────────────────

function request(targetUrl, options = {}) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(targetUrl);
        const mod = parsed.protocol === 'https:' ? https : http;
        const opts = {
            hostname: parsed.hostname,
            port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
            path: parsed.pathname + parsed.search,
            method: options.method || 'GET',
            headers: {
                'User-Agent': UA,
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language': 'en-US,en;q=0.5',
                ...(options.headers || {}),
            },
        };
        const req = mod.request(opts, (res) => {
            let body = '';
            res.on('data', (chunk) => { body += chunk; });
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body }));
        });
        req.on('error', reject);
        req.setTimeout(10000, () => { req.destroy(); reject(new Error('Request timeout')); });
        req.end();
    });
}

function getTlsCert(host, port = 443) {
    return new Promise((resolve, reject) => {
        const socket = tls.connect({ host, port, servername: host }, () => {
            const cert = socket.getPeerCertificate(true);
            socket.end();
            resolve(cert);
        });
        socket.on('error', reject);
        socket.setTimeout(10000, () => { socket.destroy(); reject(new Error('TLS timeout')); });
    });
}

function postForm(targetUrl, fields, options = {}) {
    return new Promise((resolve, reject) => {
        const parsed = new URL(targetUrl);
        const mod = parsed.protocol === 'https:' ? https : http;
        const body = Object.entries(fields)
            .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v || '')}`)
            .join('&');
        const opts = {
            hostname: parsed.hostname,
            port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
            path: parsed.pathname + parsed.search,
            method: 'POST',
            headers: {
                'User-Agent': UA,
                'Content-Type': 'application/x-www-form-urlencoded',
                'Content-Length': Buffer.byteLength(body),
                'Accept': 'text/html,application/xhtml+xml',
                'Accept-Language': 'en-US,en;q=0.5',
                ...(options.headers || {}),
            },
        };
        const req = mod.request(opts, (res) => {
            let resBody = '';
            res.on('data', chunk => { resBody += chunk; });
            res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: resBody }));
        });
        req.on('error', reject);
        req.setTimeout(10000, () => { req.destroy(); reject(new Error('Request timeout')); });
        req.write(body);
        req.end();
    });
}

function extractCsrfToken(html) {
    let m = html.match(/<input[^>]+name=["']csrf_token["'][^>]*value=["']([^"']+)["']/i);
    if (m) return m[1];
    m = html.match(/<input[^>]+value=["']([^"']+)["'][^>]*name=["']csrf_token["']/i);
    if (m) return m[1];
    return '';
}

function parseCookies(setCookieHeaders) {
    if (!setCookieHeaders) return '';
    const arr = Array.isArray(setCookieHeaders) ? setCookieHeaders : [setCookieHeaders];
    return arr.map(c => c.split(';')[0]).join('; ');
}

// ─── Result accumulator ───────────────────────────────────────────────────────

const results = [];

function pass(section, check, detail = '') {
    results.push({ status: 'PASS', section, check, detail, cwe: null, remediation: '' });
}
function fail(section, check, detail = '', cwe = null, remediation = '') {
    results.push({ status: 'FAIL', section, check, detail, cwe, remediation });
}
function warn(section, check, detail = '', cwe = null, remediation = '') {
    results.push({ status: 'WARN', section, check, detail, cwe, remediation });
}
function info(section, check, detail = '') {
    results.push({ status: 'INFO', section, check, detail, cwe: null, remediation: '' });
}

const SECTION_NAMES = {
    A: 'Transport Security (OWASP A02)',
    B: 'Security Headers (OWASP A05)',
    C: 'Cookie Security (OWASP A07)',
    D: 'Access Control (OWASP A01)',
    E: 'CSRF (OWASP A01)',
    F: 'Information Disclosure (OWASP A05)',
    H: 'Outdated Components (OWASP A06)',
    I: 'Account Enumeration (OWASP A07)',
    J: 'DNS Security',
    K: 'Application Hardening',
    G: 'SSL Labs',
};

const SECTION_ORDER = ['A', 'B', 'C', 'D', 'E', 'F', 'H', 'I', 'J', 'K', 'G'];

const SUGGESTIONS = [];

// ─── Section A: Transport Security ────────────────────────────────────────────

async function checkTransport() {
    // A1: HTTP → HTTPS redirect
    try {
        const res = await request(`http://${hostname}/`);
        if (res.status >= 301 && res.status <= 308 && (res.headers.location || '').startsWith('https://')) {
            pass('A', 'HTTP→HTTPS redirect', `HTTP ${res.status} → ${res.headers.location}`);
        } else {
            fail('A', 'HTTP→HTTPS redirect',
                `Got ${res.status}, location: ${res.headers.location || 'none'}`,
                'CWE-319',
                'Redirect all HTTP to HTTPS in .htaccess: RewriteEngine On / RewriteCond %{HTTPS} off / RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]');
        }
    } catch (e) {
        fail('A', 'HTTP→HTTPS redirect', `Request failed: ${e.message}`, 'CWE-319',
            'Ensure HTTP port 80 is open and redirecting to HTTPS.');
    }

    // A2: TLS certificate expiry + hostname
    try {
        const cert = await getTlsCert(hostname);
        if (!cert || !cert.valid_to) {
            fail('A', 'TLS certificate', 'Could not retrieve certificate', 'CWE-297'); return;
        }
        const expiresAt = new Date(cert.valid_to);
        const daysLeft = Math.floor((expiresAt - Date.now()) / 86400000);

        if (daysLeft < 0) {
            fail('A', 'TLS certificate expiry', `EXPIRED ${Math.abs(daysLeft)} days ago`, 'CWE-298',
                "Renew the TLS certificate immediately. Enable auto-renewal (e.g. Let's Encrypt certbot).");
        } else if (daysLeft < 30) {
            warn('A', 'TLS certificate expiry', `Expires in ${daysLeft} days (${cert.valid_to})`, 'CWE-298',
                'Certificate expires soon — renew now.');
        } else {
            pass('A', 'TLS certificate expiry', `Valid for ${daysLeft} more days (expires ${cert.valid_to})`);
        }

        // A3: Hostname match
        const sans = cert.subjectaltname || '';
        const cn = (cert.subject && cert.subject.CN) || '';
        const wildcard = `*.${hostname.split('.').slice(1).join('.')}`;
        const matchesSan = sans.toLowerCase().includes(`dns:${hostname.toLowerCase()}`) ||
                           sans.toLowerCase().includes(`dns:${wildcard.toLowerCase()}`);
        const matchesCn = cn.toLowerCase() === hostname.toLowerCase() ||
                          (cn.startsWith('*.') && hostname.toLowerCase().endsWith(cn.slice(1).toLowerCase()));

        if (matchesSan || matchesCn) {
            pass('A', 'TLS cert hostname match', `CN=${cn}`);
        } else {
            fail('A', 'TLS cert hostname match', `CN=${cn}, SAN=${sans}`, 'CWE-297',
                'Obtain a certificate that covers this exact hostname.');
        }

        info('A', 'TLS cert issuer', cert.issuer ? (cert.issuer.O || cert.issuer.CN || JSON.stringify(cert.issuer)) : 'unknown');

    } catch (e) {
        fail('A', 'TLS certificate', `Error: ${e.message}`, 'CWE-297',
            'Verify TLS is configured correctly on the server.');
    }

    // A4: HSTS
    try {
        const res = await request(BASE_URL);
        const hsts = res.headers['strict-transport-security'];
        if (!hsts) {
            fail('A', 'HSTS header present', 'Strict-Transport-Security header missing', 'CWE-319',
                'Add to .htaccess: Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" ' +
                '— see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Strict-Transport-Security');
        } else {
            const maxAge = parseInt((hsts.match(/max-age=(\d+)/) || [])[1] || '0', 10);
            if (maxAge < 31536000) {
                warn('A', 'HSTS max-age', `max-age=${maxAge} (should be ≥31536000)`, 'CWE-319',
                    'Increase HSTS max-age to at least 31536000 (1 year).');
            } else {
                pass('A', 'HSTS max-age', `max-age=${maxAge}`);
            }
            if (hsts.includes('includeSubDomains')) {
                pass('A', 'HSTS includeSubDomains', hsts);
            } else {
                warn('A', 'HSTS includeSubDomains', 'Missing includeSubDomains directive', 'CWE-319',
                    'Add includeSubDomains to HSTS header.');
            }
        }
    } catch (e) {
        fail('A', 'HSTS header', `Request failed: ${e.message}`, 'CWE-319');
    }
}

// ─── Section B: Security Headers ──────────────────────────────────────────────

async function checkSecurityHeaders() {
    let headers;
    try {
        const res = await request(BASE_URL);
        headers = res.headers;
    } catch (e) {
        fail('B', 'Security headers', `Request failed: ${e.message}`); return;
    }

    const headerChecks = [
        {
            key: 'content-security-policy',
            name: 'Content-Security-Policy',
            cwe: 'CWE-79',
            remediation: "Add CSP header. Start conservative: \"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:\". See https://content-security-policy.com/",
        },
        {
            key: 'x-content-type-options',
            name: 'X-Content-Type-Options',
            expected: 'nosniff',
            cwe: 'CWE-430',
            remediation: 'Add to .htaccess: Header always set X-Content-Type-Options "nosniff"',
        },
        {
            key: 'x-frame-options',
            name: 'X-Frame-Options',
            cwe: 'CWE-1021',
            remediation: 'Add to .htaccess: Header always set X-Frame-Options "DENY" — prevents clickjacking.',
        },
        {
            key: 'referrer-policy',
            name: 'Referrer-Policy',
            cwe: 'CWE-200',
            remediation: 'Add to .htaccess: Header always set Referrer-Policy "strict-origin-when-cross-origin"',
        },
        {
            key: 'permissions-policy',
            name: 'Permissions-Policy',
            cwe: 'CWE-276',
            remediation: 'Add to .htaccess: Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"',
        },
    ];

    for (const c of headerChecks) {
        const val = headers[c.key];
        if (!val) {
            fail('B', c.name, 'Header missing', c.cwe, c.remediation);
        } else if (c.expected && !val.includes(c.expected)) {
            warn('B', c.name, `Found: "${val}", expected to contain "${c.expected}"`, c.cwe, c.remediation);
        } else {
            pass('B', c.name, val.length > 80 ? val.slice(0, 80) + '…' : val);
        }
    }

    // CSP unsafe-eval check
    const csp = headers['content-security-policy'];
    if (csp && csp.includes("'unsafe-eval'")) {
        warn('B', "CSP: no unsafe-eval", "CSP contains 'unsafe-eval' — permits arbitrary code execution", 'CWE-79',
            "Remove 'unsafe-eval' from CSP. Audit scripts for dynamic code evaluation patterns and refactor them.");
    } else if (csp) {
        pass('B', "CSP: no unsafe-eval");
    }

    // CSP report-uri / report-to
    if (csp) {
        if (csp.includes('report-uri') || csp.includes('report-to')) {
            pass('B', 'CSP report-uri', 'Violation reporting directive present');
        } else {
            warn('B', 'CSP report-uri', 'CSP has no report-uri or report-to directive', null,
                'Add report-uri or report-to to CSP so browsers send violation reports to a collection endpoint.');
        }
    }

    // X-Powered-By absent
    if (headers['x-powered-by']) {
        warn('B', 'X-Powered-By absent', `Found: "${headers['x-powered-by']}" — reveals tech stack`, 'CWE-200',
            'Remove via php.ini: expose_php = Off, plus Header unset X-Powered-By in .htaccess');
    } else {
        pass('B', 'X-Powered-By absent');
    }

    // Server header version
    const server = headers['server'];
    if (!server) {
        pass('B', 'Server header minimal', 'Not present');
    } else if (/\d/.test(server)) {
        warn('B', 'Server header minimal', `"${server}" contains version info`, 'CWE-200',
            'Remove version from Server header. In Apache: ServerTokens Prod in httpd.conf');
    } else {
        pass('B', 'Server header minimal', `"${server}" (no version)`);
    }
}

// ─── Section C: Cookie Flags ───────────────────────────────────────────────────

async function checkCookies() {
    let res;
    try {
        res = await request(`${BASE_URL}/login.php`);
    } catch (e) {
        fail('C', 'Cookie flags', `Request to /login.php failed: ${e.message}`); return;
    }

    const rawCookies = res.headers['set-cookie'];
    if (!rawCookies || !rawCookies.length) {
        info('C', 'Cookies', 'No Set-Cookie headers on GET /login.php — checks skipped'); return;
    }

    const cookies = Array.isArray(rawCookies) ? rawCookies : [rawCookies];
    for (const cookie of cookies) {
        const name = cookie.split('=')[0].trim();
        const lc = cookie.toLowerCase();

        if (lc.includes('; secure') || lc.endsWith(';secure') || lc.endsWith(' secure')) {
            pass('C', `${name}: Secure flag`);
        } else {
            fail('C', `${name}: Secure flag`, 'Cookie missing Secure flag', 'CWE-614',
                "In PHP: session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Lax'])");
        }

        if (lc.includes('; httponly') || lc.includes(';httponly')) {
            pass('C', `${name}: HttpOnly flag`);
        } else {
            fail('C', `${name}: HttpOnly flag`, 'Cookie missing HttpOnly flag', 'CWE-1004',
                'HttpOnly prevents client-side scripts from reading the session cookie — critical for XSS mitigation.');
        }

        const ss = lc.match(/samesite=(\w+)/);
        if (!ss) {
            fail('C', `${name}: SameSite flag`, 'Cookie missing SameSite flag', 'CWE-352',
                "Add SameSite=Lax to prevent CSRF attacks on session cookies.");
        } else if (ss[1] === 'none') {
            warn('C', `${name}: SameSite flag`, 'SameSite=None allows cross-site sending', 'CWE-352',
                'Use SameSite=Lax unless cross-site POSTs are intentionally required.');
        } else {
            pass('C', `${name}: SameSite flag`, `SameSite=${ss[1]}`);
        }
    }
}

// ─── Section D: Access Control ────────────────────────────────────────────────

async function checkAccessControl() {
    const authRequired = ['/admin.php', '/profile.php', '/bet.php'];

    for (const p of authRequired) {
        try {
            const res = await request(`${BASE_URL}${p}`);
            const loc = res.headers.location || '';
            if (res.status >= 301 && res.status <= 308 && loc.includes('login')) {
                pass('D', `Auth guard: ${p}`, `Redirects to login (${res.status})`);
            } else if (res.status >= 301 && res.status <= 308 && !loc.includes('login')) {
                warn('D', `Auth guard: ${p}`, `Redirects but not to login: ${loc}`, 'CWE-284',
                    `Ensure ${p} redirects unauthenticated users to login.php`);
            } else if (res.status === 200) {
                if (res.body.includes('login.php') || res.body.includes('name="email"')) {
                    pass('D', `Auth guard: ${p}`, 'Contains login redirect in body');
                } else {
                    fail('D', `Auth guard: ${p}`, `HTTP 200 with no login redirect`, 'CWE-284',
                        `${p} is accessible without authentication. Add session_start() + auth check at the top of the file.`);
                }
            } else {
                info('D', `Auth guard: ${p}`, `HTTP ${res.status}`);
            }
        } catch (e) {
            fail('D', `Auth guard: ${p}`, `Request failed: ${e.message}`, 'CWE-284');
        }
    }

    const sensitiveFiles = [
        '/config.php',
        '/.env',
        '/.git/HEAD',
        '/.gitignore',
        '/.htaccess',
        '/.htpasswd',
        '/composer.json',
        '/package.json',
        '/phpinfo.php',
        '/adminer.php',
        '/phpmyadmin/',
        '/logs/app.log',
        '/tools/test-seed.php',
        '/tools/sync-from-live.php',
    ];
    for (const f of sensitiveFiles) {
        try {
            const res = await request(`${BASE_URL}${f}`);
            if (res.status === 403) {
                pass('D', `Sensitive file: ${f}`, 'HTTP 403 Forbidden');
            } else if (res.status === 404) {
                pass('D', `Sensitive file: ${f}`, 'HTTP 404 Not Found');
            } else if (res.status === 200) {
                const looksLike = res.body.includes('DB_') || res.body.includes('password') ||
                                  res.body.includes('ref:') || res.body.includes('<?php') ||
                                  res.body.includes('"scripts"');
                if (looksLike) {
                    fail('D', `Sensitive file: ${f}`, `HTTP 200, ${res.body.length} bytes — exposes sensitive data`, 'CWE-538',
                        `Block direct access in .htaccess: <Files "${path.basename(f)}"> Require all denied </Files>`);
                } else {
                    info('D', `Sensitive file: ${f}`, `HTTP 200 (${res.body.length} bytes, content appears non-sensitive)`);
                }
            } else {
                info('D', `Sensitive file: ${f}`, `HTTP ${res.status}`);
            }
        } catch (e) {
            info('D', `Sensitive file: ${f}`, `Request failed: ${e.message}`);
        }
    }
}

// ─── Section E: CSRF ──────────────────────────────────────────────────────────

async function checkCsrf() {
    try {
        const res = await request(`${BASE_URL}/login.php`);
        const hasToken =
            /<input[^>]+type=["']hidden["'][^>]*name=["'](csrf_token|_token|token)["']/i.test(res.body) ||
            /<input[^>]+name=["'](csrf_token|_token|token)["'][^>]*type=["']hidden["']/i.test(res.body);
        if (hasToken) {
            pass('E', 'CSRF token in login form');
        } else {
            warn('E', 'CSRF token in login form', 'No hidden CSRF token field found in /login.php', 'CWE-352',
                'All state-changing forms should include a hidden CSRF token validated server-side on POST.');
        }
    } catch (e) {
        fail('E', 'CSRF token', `Request failed: ${e.message}`, 'CWE-352');
    }
}

// ─── Section F: Information Disclosure ────────────────────────────────────────

async function checkInfoDisclosure() {
    try {
        const res = await request(`${BASE_URL}/nonexistent-page-xyz-404-check.php`);
        if (res.body.includes('Fatal error') || res.body.includes('Warning:') || res.body.includes('Stack trace')) {
            fail('F', 'No PHP errors on 404', 'PHP error/stack trace exposed on 404 page', 'CWE-209',
                'Set display_errors = Off and log_errors = On in php.ini for production.');
        } else {
            pass('F', 'No PHP errors on 404', `HTTP ${res.status}, no error traces`);
        }
    } catch (e) {
        info('F', 'No PHP errors on 404', `Request failed: ${e.message}`);
    }

    try {
        const res = await request(`${BASE_URL}/index.php?trigger_error_for_security_scan=1`);
        if (res.body.includes('Fatal error') || res.body.includes('Call Stack') || res.body.includes('Stack trace')) {
            fail('F', 'No PHP error disclosure', 'PHP error details visible in response body', 'CWE-209',
                'Set display_errors = Off in php.ini. Never expose raw PHP errors to end users.');
        } else {
            pass('F', 'No PHP error disclosure');
        }
    } catch (e) {
        // Ignore — page may not handle this param
    }
}

// ─── Section H: Outdated Components ──────────────────────────────────────────

async function checkOutdatedComponents() {
    let headers;
    try {
        const res = await request(BASE_URL);
        headers = res.headers;
    } catch (e) {
        fail('H', 'Component version check', `Request failed: ${e.message}`); return;
    }

    const combined = (headers['x-powered-by'] || '') + ' ' + (headers['server'] || '');
    const phpMatch = combined.match(/PHP\/([\d.]+)/i);

    if (!phpMatch) {
        pass('H', 'PHP version detectable', 'PHP version not exposed in response headers');
        return;
    }

    const version = phpMatch[1];
    const [major, minor] = version.split('.').map(Number);
    // EOL dates as of 2026: PHP <8.0 EOL, 8.0 EOL Nov 2023, 8.1 EOL Dec 2025
    const isEol = major < 8 || (major === 8 && minor <= 1);

    if (isEol) {
        fail('H', 'PHP version EOL', `PHP ${version} — End of Life, no security updates`, 'CWE-1104',
            `Upgrade to PHP 8.2 or later. Also set expose_php = Off in php.ini to hide the version.`);
    } else {
        warn('H', 'PHP version visible', `PHP ${version} is current but version is exposed in headers`, 'CWE-200',
            'Set expose_php = Off in php.ini to suppress PHP version from X-Powered-By.');
    }
}

// ─── Section I: Account Enumeration ──────────────────────────────────────────

async function checkAccountEnumeration() {
    const realEmail = process.env[`TEST_USER_EMAIL_${env.toUpperCase()}`] || process.env.TEST_USER_EMAIL;
    if (!realEmail) {
        info('I', 'Account enumeration', 'TEST_USER_EMAIL not set in .env — skipping'); return;
    }

    const fakeEmail = 'scanner-nonexistent-xyz-12345@example-scan-test.invalid';
    const wrongPassword = 'WrongPwd_SecurityScan_XYZ_2025!';

    try {
        const page1 = await request(`${BASE_URL}/login.php`);
        const fakeRes = await postForm(`${BASE_URL}/login.php`, {
            email: fakeEmail,
            password: wrongPassword,
            csrf_token: extractCsrfToken(page1.body),
        });

        // CSRF tokens are single-use — fetch a fresh one
        const page2 = await request(`${BASE_URL}/login.php`);
        const realRes = await postForm(`${BASE_URL}/login.php`, {
            email: realEmail,
            password: wrongPassword,
            csrf_token: extractCsrfToken(page2.body),
        });

        // Different HTTP status codes reveal account existence
        if (fakeRes.status !== realRes.status) {
            fail('I', 'Account enumeration: status codes',
                `Nonexistent email → HTTP ${fakeRes.status}, valid email → HTTP ${realRes.status}`,
                'CWE-203',
                'Return identical HTTP status codes for all invalid login attempts regardless of whether the account exists.');
            return;
        }

        // Look for distinguishing error message phrases
        const ENUM_PHRASES = [
            /no account/i, /not found/i, /not registered/i, /does not exist/i,
            /unknown email/i, /wrong password/i, /incorrect password/i, /invalid password/i,
        ];
        const fakeMatches = ENUM_PHRASES.filter(re => re.test(fakeRes.body)).map(r => r.source);
        const realMatches = ENUM_PHRASES.filter(re => re.test(realRes.body)).map(r => r.source);

        const fakeDiff = fakeMatches.filter(r => !realMatches.includes(r));
        const realDiff = realMatches.filter(r => !fakeMatches.includes(r));

        if (fakeDiff.length || realDiff.length) {
            fail('I', 'Account enumeration: error messages',
                `Responses differ — fake: [${fakeDiff.join(', ') || 'none'}], real: [${realDiff.join(', ') || 'none'}]`,
                'CWE-203',
                'Use a single generic message for all invalid logins, e.g. "Invalid email or password". Never indicate which field was wrong.');
            return;
        }

        // Significant body length difference may indicate differing responses
        const lenDiff = Math.abs(fakeRes.body.length - realRes.body.length);
        if (lenDiff > 100) {
            warn('I', 'Account enumeration: response length',
                `Body length differs by ${lenDiff} chars between nonexistent and valid email`,
                'CWE-203',
                'Ensure login error responses are byte-for-byte identical for all invalid credential combinations.');
        } else {
            pass('I', 'Account enumeration', 'Login returns identical status and indistinguishable error for valid vs nonexistent email');
        }
    } catch (e) {
        fail('I', 'Account enumeration', `Test failed: ${e.message}`, 'CWE-203');
    }
}

// ─── Section J: DNS Security ──────────────────────────────────────────────────

async function checkDnsSecurity() {
    // SPF / DKIM / DMARC / CAA records live on the apex domain, not www
    const apex = hostname.replace(/^www\./, '');

    async function dnsQuery(name, type) {
        const url = `https://cloudflare-dns.com/dns-query?name=${encodeURIComponent(name)}&type=${encodeURIComponent(type)}`;
        const res = await request(url, { headers: { 'Accept': 'application/dns-json' } });
        return JSON.parse(res.body);
    }

    // DNSSEC — AD flag set means Cloudflare validated the full chain of trust
    try {
        const data = await dnsQuery(apex, 'DNSKEY');
        if (data.AD === true) {
            pass('J', 'DNSSEC', 'AD flag set — chain of trust validated by Cloudflare resolver');
        } else {
            const hasKeys = Array.isArray(data.Answer) && data.Answer.some(r => r.type === 48);
            if (hasKeys) {
                warn('J', 'DNSSEC', 'DNSKEY records present but AD flag not set — chain of trust incomplete', null,
                    'Verify DS record is registered at the parent zone (.dk registry). Check: https://dnssec-analyzer.verisignlabs.com/');
            } else {
                warn('J', 'DNSSEC', 'No DNSKEY records found — DNSSEC not enabled', null,
                    'Enable DNSSEC in your DNS provider dashboard. Check: https://dnssec-analyzer.verisignlabs.com/');
            }
        }
    } catch (e) {
        info('J', 'DNSSEC', `DNS query failed: ${e.message}`);
    }

    // CAA
    try {
        const data = await dnsQuery(apex, 'CAA');
        const answers = (data.Answer || []).filter(r => r.type === 257);
        if (!answers.length) {
            warn('J', 'CAA record', 'No CAA records found', null,
                `Add CAA record: 0 issue "letsencrypt.org" — restricts which CAs may issue certs for ${apex}`);
        } else {
            const hasLE = answers.some(r => (r.data || '').includes('letsencrypt.org'));
            const summary = answers.map(r => r.data).join(' | ');
            if (hasLE) {
                pass('J', 'CAA record', summary.length > 80 ? summary.slice(0, 80) + '…' : summary);
            } else {
                warn('J', 'CAA record', `letsencrypt.org not listed — found: ${summary}`, null,
                    "Add CAA record: 0 issue \"letsencrypt.org\" since your cert is from Let's Encrypt.");
            }
        }
    } catch (e) {
        info('J', 'CAA record', `DNS query failed: ${e.message}`);
    }

    // SPF
    try {
        const data = await dnsQuery(apex, 'TXT');
        const txts = (data.Answer || []).filter(r => r.type === 16).map(r => (r.data || '').replace(/^"|"$/g, ''));
        const spf = txts.find(t => t.startsWith('v=spf1'));
        const spfInclude = hostname.includes('hpovlsen') ? 'simplelogin.co' : '_spf.protonmail.ch';
        if (spf) {
            pass('J', 'SPF record', spf.length > 80 ? spf.slice(0, 80) + '…' : spf);
        } else {
            warn('J', 'SPF record', 'No SPF TXT record found', null,
                `Add SPF TXT record to ${apex}, e.g. "v=spf1 include:${spfInclude} ~all"`);
        }
    } catch (e) {
        info('J', 'SPF record', `DNS query failed: ${e.message}`);
    }

    // DMARC
    try {
        const data = await dnsQuery(`_dmarc.${apex}`, 'TXT');
        const txts = (data.Answer || []).filter(r => r.type === 16).map(r => (r.data || '').replace(/^"|"$/g, ''));
        const dmarc = txts.find(t => t.startsWith('v=DMARC1'));
        if (dmarc) {
            const policy = (dmarc.match(/p=(\w+)/) || [])[1] || 'none';
            if (policy === 'reject' || policy === 'quarantine') {
                pass('J', 'DMARC record', dmarc.length > 80 ? dmarc.slice(0, 80) + '…' : dmarc);
            } else {
                warn('J', 'DMARC record', `Policy p=${policy} — consider p=quarantine or p=reject`, null,
                    'Tighten DMARC policy once legitimate mail flows are verified.');
            }
        } else {
            warn('J', 'DMARC record', `No DMARC record at _dmarc.${apex}`, null,
                `Add TXT record at _dmarc.${apex}: "v=DMARC1; p=quarantine; rua=mailto:dmarc@${apex}"`);
        }
    } catch (e) {
        info('J', 'DMARC record', `DNS query failed: ${e.message}`);
    }

    // DKIM — probe common selectors; warn if none found (selector may just be non-standard)
    const dkimSelectors = hostname.includes('hpovlsen')
        ? ['dkim', 'dkim2', 'dkim3', 'default', 'mail', 'k1', 's1', 's2']
        : ['protonmail', 'protonmail2', 'protonmail3', 'default', 'mail', 'k1', 's1', 's2'];
    let dkimFound = false;
    for (const sel of dkimSelectors) {
        try {
            const data = await dnsQuery(`${sel}._domainkey.${apex}`, 'TXT');
            const txts = (data.Answer || []).filter(r => r.type === 16).map(r => r.data || '');
            if (txts.some(t => t.includes('p='))) {
                pass('J', 'DKIM record', `Selector "${sel}._domainkey.${apex}" found`);
                dkimFound = true;
                break;
            }
        } catch (_) { /* try next */ }
    }
    if (!dkimFound) {
        warn('J', 'DKIM record', `No DKIM record found (tried: ${dkimSelectors.join(', ')})`, null,
            `Configure DKIM with your mail provider and publish the public key at <selector>._domainkey.${apex}. ` +
            'If your selector is non-standard, verify with: dig TXT <selector>._domainkey.' + apex);
    }
}

// ─── Section K: Application Hardening ────────────────────────────────────────

async function checkApplicationHardening() {
    // Rate limiting — 6 rapid login attempts; look for 429 or rate-limit headers
    try {
        const fakeEmail = 'ratelimit-scan@example-scan.invalid';
        const wrongPwd  = 'WrongPwd_RateLimitTest_XYZ!';
        let rateLimited = false;
        let rateLimitStatus = 0;
        for (let i = 0; i < 6; i++) {
            const pageRes = await request(`${BASE_URL}/login.php`);
            const csrf    = extractCsrfToken(pageRes.body);
            const cookies = parseCookies(pageRes.headers['set-cookie']);
            const res = await postForm(
                `${BASE_URL}/login.php`,
                { email: fakeEmail, password: wrongPwd, csrf_token: csrf },
                { headers: { Cookie: cookies } }
            );
            // 429 = PHP-level block; 400/503 = web-server-level block (LiteSpeed/Apache)
            if (res.status === 429 || res.status === 400 || res.status === 503 ||
                res.headers['retry-after'] || res.headers['x-ratelimit-limit']) {
                rateLimited = true;
                rateLimitStatus = res.status;
                break;
            }
        }
        if (rateLimited) {
            pass('K', 'Rate limiting: login', `HTTP ${rateLimitStatus} after rapid attempts — server is rate limiting`);
        } else {
            warn('K', 'Rate limiting: login', '6 rapid login attempts — no rate-limiting response observed', 'CWE-307',
                'Add rate limiting to login.php: DB-level counter per IP/email, fail2ban, or mod_evasive in .htaccess.');
        }
    } catch (e) {
        info('K', 'Rate limiting: login', `Test failed: ${e.message}`);
    }

    // Session invalidation — login, logout, verify old session is rejected
    const email    = process.env[`TEST_USER_EMAIL_${env.toUpperCase()}`]    || process.env.TEST_USER_EMAIL;
    const password = process.env[`TEST_USER_PASSWORD_${env.toUpperCase()}`] || process.env.TEST_USER_PASSWORD;
    if (!email || !password) {
        info('K', 'Session invalidation', 'TEST_USER credentials not set — skipping');
    } else {
        try {
            const loginPageRes = await request(`${BASE_URL}/login.php`);
            const preCookies   = parseCookies(loginPageRes.headers['set-cookie']);
            const csrf         = extractCsrfToken(loginPageRes.body);

            const loginRes = await postForm(
                `${BASE_URL}/login.php`,
                { email, password, csrf_token: csrf },
                { headers: { Cookie: preCookies } }
            );

            if (loginRes.status < 300 || loginRes.status >= 400) {
                info('K', 'Session invalidation', `Login returned HTTP ${loginRes.status} — skipping`);
            } else {
                const authedCookies = parseCookies(loginRes.headers['set-cookie']) || preCookies;

                await request(`${BASE_URL}/logout.php`, { headers: { Cookie: authedCookies } });

                const afterLogoutRes = await request(`${BASE_URL}/admin.php`, { headers: { Cookie: authedCookies } });
                const loc = afterLogoutRes.headers.location || '';
                if (afterLogoutRes.status >= 301 && afterLogoutRes.status <= 308 && loc.includes('login')) {
                    pass('K', 'Session invalidation', 'Session correctly rejected after logout');
                } else if (afterLogoutRes.status === 200) {
                    fail('K', 'Session invalidation', 'Session still valid after logout — admin page accessible', 'CWE-613',
                        'Call session_destroy() on logout, unset $_SESSION, and delete the session cookie.');
                } else {
                    info('K', 'Session invalidation', `After logout: HTTP ${afterLogoutRes.status}`);
                }
            }
        } catch (e) {
            fail('K', 'Session invalidation', `Test failed: ${e.message}`, 'CWE-613');
        }
    }

    // Subresource Integrity — flag external scripts/styles missing integrity=
    try {
        const res  = await request(BASE_URL);
        const html = res.body;
        const externals = [];
        for (const m of html.matchAll(/<(script|link)([^>]*?)(?:\/>|>)/gi)) {
            const attrs    = m[2];
            const srcMatch = attrs.match(/(?:src|href)=["']([^"']+)["']/i);
            if (!srcMatch) continue;
            const url = srcMatch[1];
            if (!url.startsWith('http') || url.includes(hostname)) continue;
            externals.push({ url, hasIntegrity: /integrity=["'][^"']+["']/.test(attrs) });
        }
        if (!externals.length) {
            pass('K', 'Subresource Integrity', 'No external scripts or stylesheets on home page');
        } else {
            const missing = externals.filter(e => !e.hasIntegrity);
            if (missing.length) {
                warn('K', 'Subresource Integrity',
                    `${missing.length}/${externals.length} external resource(s) missing integrity= attribute`, 'CWE-829',
                    'Add integrity= and crossorigin= to CDN-hosted scripts/styles. Generate hashes at https://www.srihash.org/');
            } else {
                pass('K', 'Subresource Integrity', `All ${externals.length} external resource(s) have integrity= attribute`);
            }
        }
    } catch (e) {
        info('K', 'Subresource Integrity', `Check failed: ${e.message}`);
    }
}

// ─── Section G: SSL Labs (optional) ───────────────────────────────────────────

async function checkSslLabs() {
    if (!USE_SSLLABS) {
        info('G', 'SSL Labs', 'Skipped (run with --ssllabs to enable)'); return;
    }

    console.log('  Querying SSL Labs API (may take 60-90 seconds)...');

    let elapsed = 0;
    const timer = setInterval(() => {
        elapsed++;
        process.stdout.write(`\r  Analysing... ${elapsed}s `);
    }, 1000);

    try {
        const startRes = await request(
            `https://api.ssllabs.com/api/v3/analyze?host=${hostname}&startNew=on&all=done`,
            { headers: { 'Accept': 'application/json' } }
        );
        let data = JSON.parse(startRes.body);

        let attempts = 0;
        while (data.status !== 'READY' && data.status !== 'ERROR' && attempts < 18) {
            await new Promise(r => setTimeout(r, 10000));
            const pollRes = await request(
                `https://api.ssllabs.com/api/v3/analyze?host=${hostname}&all=done`,
                { headers: { 'Accept': 'application/json' } }
            );
            data = JSON.parse(pollRes.body);
            attempts++;
        }

        clearInterval(timer);
        process.stdout.write(`\r  Done (${elapsed}s)          \n`);

        if (data.status === 'ERROR') {
            fail('G', 'SSL Labs grade', `Analysis failed: ${data.statusMessage || 'unknown'}`, null,
                'Check the domain is publicly accessible and try again.');
            return;
        }

        if (!data.endpoints || !data.endpoints.length) {
            info('G', 'SSL Labs grade', 'No endpoints returned'); return;
        }

        for (const ep of data.endpoints) {
            const grade = ep.grade || ep.statusMessage || 'unknown';
            if (grade === 'A+' || grade === 'A') {
                pass('G', `SSL Labs grade (${ep.ipAddress})`, grade);
            } else if (grade.startsWith('B')) {
                warn('G', `SSL Labs grade (${ep.ipAddress})`, grade, null,
                    `See full report: https://www.ssllabs.com/ssltest/analyze.html?d=${hostname}`);
            } else {
                fail('G', `SSL Labs grade (${ep.ipAddress})`, grade, 'CWE-326',
                    `Grade ${grade} indicates TLS misconfiguration. See https://www.ssllabs.com/ssltest/analyze.html?d=${hostname}`);
            }
        }
    } catch (e) {
        clearInterval(timer);
        process.stdout.write(`\r  Failed (${elapsed}s)         \n`);
        fail('G', 'SSL Labs', `Error: ${e.message}`, null, 'SSL Labs API may be unavailable. Try again later.');
    }
}

// ─── Report ───────────────────────────────────────────────────────────────────

function printReport() {
    const R = '\x1b[0m', G = '\x1b[32m', RED = '\x1b[31m', Y = '\x1b[33m', C = '\x1b[36m', B = '\x1b[1m', D = '\x1b[2m';

    console.log(`\n${B}═══════════════════════════════════════════════════════════${R}`);
    console.log(`${B}  F1Betting Security Report${R}`);
    console.log(`${B}  Env: ${env.toUpperCase()}   Target: ${BASE_URL}${R}`);
    console.log(`${B}  ${new Date().toISOString().slice(0, 19).replace('T', ' ')} UTC${R}`);
    console.log(`${B}═══════════════════════════════════════════════════════════${R}\n`);

    let currentSection = null;
    const totals = { PASS: 0, FAIL: 0, WARN: 0, INFO: 0 };

    for (const r of results) {
        if (r.section !== currentSection) {
            currentSection = r.section;
            if (results.indexOf(r) > 0) console.log('');
            console.log(`${B}── ${SECTION_NAMES[r.section] || r.section} ──${R}`);
        }
        const icon = r.status === 'PASS' ? `${G}✔${R}` : r.status === 'FAIL' ? `${RED}✘${R}` : r.status === 'WARN' ? `${Y}⚠${R}` : `${C}ℹ${R}`;
        const col  = r.status === 'PASS' ? G : r.status === 'FAIL' ? RED : r.status === 'WARN' ? Y : C;
        const detail = r.detail ? ` ${D}— ${r.detail}${R}` : '';
        console.log(`  ${icon} ${col}${r.check}${R}${detail}`);
        if (r.cwe) {
            console.log(`    ${D}CWE: https://cwe.mitre.org/data/definitions/${r.cwe.replace('CWE-', '')}.html${R}`);
        }
        if (r.remediation) {
            console.log(`    ${D}↳ ${r.remediation}${R}`);
        }
        totals[r.status] = (totals[r.status] || 0) + 1;
    }

    console.log(`\n${B}───────────────────────────────────────────────────────────${R}`);
    console.log(`  ${G}✔ ${totals.PASS} passed${R}  ${RED}✘ ${totals.FAIL} failed${R}  ${Y}⚠ ${totals.WARN} warnings${R}  ${C}ℹ ${totals.INFO} info${R}`);
    console.log(`${B}───────────────────────────────────────────────────────────${R}\n`);

    if (SUGGESTIONS.length) {
        console.log(`${B}Suggestions for further hardening:${R}`);
        for (const s of SUGGESTIONS) {
            console.log(`  ${C}›${R} ${B}${s.item}:${R} ${D}${s.detail}${R}`);
        }
        console.log('');
    }
}

function generateMarkdown() {
    const icon = { PASS: '✅', FAIL: '❌', WARN: '⚠️', INFO: 'ℹ️' };
    const summary = {
        pass: results.filter(r => r.status === 'PASS').length,
        fail: results.filter(r => r.status === 'FAIL').length,
        warn: results.filter(r => r.status === 'WARN').length,
        info: results.filter(r => r.status === 'INFO').length,
    };

    const ts = new Date().toISOString().slice(0, 19).replace('T', ' ');
    let md = `# F1Betting Security Report\n\n`;
    md += `| | |\n|---|---|\n`;
    md += `| **Environment** | ${env.toUpperCase()} |\n`;
    md += `| **Target** | ${BASE_URL} |\n`;
    md += `| **Generated** | ${ts} UTC |\n\n`;

    md += `## Summary\n\n`;
    md += `| ✅ Pass | ❌ Fail | ⚠️ Warn | ℹ️ Info |\n`;
    md += `|:------:|:------:|:------:|:------:|\n`;
    md += `| **${summary.pass}** | **${summary.fail}** | **${summary.warn}** | **${summary.info}** |\n\n`;

    // Results grouped by section
    const bySection = {};
    for (const r of results) {
        (bySection[r.section] = bySection[r.section] || []).push(r);
    }

    for (const sec of SECTION_ORDER) {
        if (!bySection[sec]) continue;
        md += `---\n\n## ${sec} — ${SECTION_NAMES[sec] || sec}\n\n`;
        md += `| | Check | Detail |\n|---|---|---|\n`;
        for (const r of bySection[sec]) {
            const detail = (r.detail || '').replace(/\|/g, '\\|');
            md += `| ${icon[r.status]} | ${r.check} | ${detail} |\n`;
        }
        md += '\n';
    }

    // Findings: FAIL and WARN with full details
    const findings = results.filter(r => r.status === 'FAIL' || r.status === 'WARN');
    if (findings.length) {
        md += `---\n\n## Findings Requiring Attention\n\n`;
        for (const r of findings) {
            md += `### ${icon[r.status]} ${r.check}\n\n`;
            md += `**Section:** ${r.section} — ${SECTION_NAMES[r.section] || r.section}  \n`;
            if (r.detail)      md += `**Detail:** ${r.detail}  \n`;
            if (r.cwe)         md += `**CWE:** [${r.cwe}](https://cwe.mitre.org/data/definitions/${r.cwe.replace('CWE-', '')}.html)  \n`;
            if (r.remediation) md += `**Remediation:** ${r.remediation}  \n`;
            md += '\n';
        }
    }

    if (SUGGESTIONS.length) {
        md += `---\n\n## Suggestions for Further Hardening\n\n`;
        for (const s of SUGGESTIONS) {
            md += `- **${s.item}:** ${s.detail}\n`;
        }
    }

    return md;
}

function saveReport() {
    const reportDir = path.join(__dirname, '../../build-deploy/security-reports');
    if (!fs.existsSync(reportDir)) fs.mkdirSync(reportDir, { recursive: true });

    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    const base = `${timestamp}-${env}-security`;

    fs.writeFileSync(path.join(reportDir, `${base}.json`), JSON.stringify({
        generated: new Date().toISOString(),
        env,
        target: BASE_URL,
        summary: {
            pass: results.filter(r => r.status === 'PASS').length,
            fail: results.filter(r => r.status === 'FAIL').length,
            warn: results.filter(r => r.status === 'WARN').length,
            info: results.filter(r => r.status === 'INFO').length,
        },
        checks: results,
    }, null, 2));

    fs.writeFileSync(path.join(reportDir, `${base}.md`), generateMarkdown());

    // Roll: keep only the 5 most recent reports per env (same pattern as log rolling)
    for (const ext of ['md', 'json']) {
        const files = fs.readdirSync(reportDir)
            .filter(f => f.endsWith(`-${env}-security.${ext}`))
            .sort()
            .reverse();
        for (const old of files.slice(2)) {
            fs.unlinkSync(path.join(reportDir, old));
        }
    }

    console.log(`Reports saved → build-deploy/security-reports/${base}.{md,json}\n`);
}

// ─── Main ─────────────────────────────────────────────────────────────────────

async function main() {
    console.log(`\nRunning security checks against ${BASE_URL}...`);
    await checkTransport();
    await checkSecurityHeaders();
    await checkCookies();
    await checkAccessControl();
    await checkCsrf();
    await checkInfoDisclosure();
    await checkOutdatedComponents();
    await checkAccountEnumeration();
    await checkDnsSecurity();
    await checkApplicationHardening();
    await checkSslLabs();
    printReport();
    saveReport();
    const failures = results.filter(r => r.status === 'FAIL').length;
    process.exit(failures > 0 ? 1 : 0);
}

main().catch(err => { console.error('Fatal error:', err); process.exit(1); });
