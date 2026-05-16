const path  = require("path");
const http  = require("http");
const https = require("https");
const { URL: URL_ } = require("url");
require("dotenv").config({ path: path.join(__dirname, "../build-deploy/.env") });

// Try to load admin credentials from PHP config (single source of truth, same as security.js)
let adminEmail, adminPassword;
try {
    const { readPhpConfig } = require("../build-deploy/php-config");
    const env = process.env.DEPLOY_ENV || "test";
    const cfg = readPhpConfig(env);
    adminEmail    = cfg.adminEmail;
    adminPassword = cfg.adminPassword;
} catch { /* config absent — authenticated smoke checks will be skipped */ }

// ─── Public checks (no session required) ─────────────────────────────────────

const CHECKS = [
    { path: "/",                contains: "<html" },
    { path: "/login.php",       contains: 'name="email"' },
    { path: "/leaderboard.php", contains: "leaderboard" },
    { path: "/races.php",       contains: "<html" },

    // Translations — verify lang files load and t() returns real strings (not raw key names)
    { path: "/login.php",       contains: "Log ind" },     // t('login') in DA
    { path: "/login.php",       contains: "Adgangskode" }, // t('password') in DA
];

// ─── Authenticated checks — verify Sprint 3 profile sections render ───────────
// Require a logged-in session; skipped when credentials are unavailable.

const AUTHED_CHECKS = [
    { path: "/profile.php", containsAny: ["Din Betting Historik", "Betting History"] },
    { path: "/profile.php", containsAny: ["Skift Adgangskode", "Change Password"] },
];

// ─── Minimal cookie-aware HTTP helpers ───────────────────────────────────────

function httpGet(url, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL_(url);
        const mod = parsed.protocol === "https:" ? https : http;
        const req = mod.request({
            hostname: parsed.hostname,
            port:     parsed.port || (parsed.protocol === "https:" ? 443 : 80),
            path:     parsed.pathname + parsed.search,
            method:   "GET",
            headers: {
                "User-Agent": "F1Betting-Smoke/1.0",
                ...(cookieStr ? { Cookie: cookieStr } : {}),
            },
        }, (res) => {
            let body = "";
            res.on("data", c => body += c);
            res.on("end", () => resolve({ status: res.statusCode, headers: res.headers, body }));
        });
        req.on("error", reject);
        req.setTimeout(10000, () => { req.destroy(); reject(new Error("timeout")); });
        req.end();
    });
}

function httpPost(url, fields, cookieStr) {
    return new Promise((resolve, reject) => {
        const parsed = new URL_(url);
        const mod = parsed.protocol === "https:" ? https : http;
        const body = Object.entries(fields)
            .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
            .join("&");
        const req = mod.request({
            hostname: parsed.hostname,
            port:     parsed.port || (parsed.protocol === "https:" ? 443 : 80),
            path:     parsed.pathname,
            method:   "POST",
            headers: {
                "User-Agent":      "F1Betting-Smoke/1.0",
                "Content-Type":    "application/x-www-form-urlencoded",
                "Content-Length":  Buffer.byteLength(body),
                ...(cookieStr ? { Cookie: cookieStr } : {}),
            },
        }, (res) => {
            let data = "";
            res.on("data", c => data += c);
            res.on("end", () => resolve({ status: res.statusCode, headers: res.headers, body: data }));
        });
        req.on("error", reject);
        req.setTimeout(10000, () => { req.destroy(); reject(new Error("timeout")); });
        req.write(body);
        req.end();
    });
}

function pickCookies(h) {
    const arr = Array.isArray(h) ? h : (h ? [h] : []);
    return arr.map(c => c.split(";")[0]).join("; ");
}

function extractCsrf(html) {
    return (
        html.match(/name=["']csrf_token["'][^>]*value=["']([^"']+)["']/i) ||
        html.match(/value=["']([^"']+)["'][^>]*name=["']csrf_token["']/i) ||
        []
    )[1] || "";
}

async function loginForSmoke(baseUrl, email, password) {
    const page    = await httpGet(`${baseUrl}/login.php`);
    const cookies = pickCookies(page.headers["set-cookie"]);
    const csrf    = extractCsrf(page.body);
    const res     = await httpPost(`${baseUrl}/login.php`, { email, password, csrf_token: csrf }, cookies);
    const newCookies = pickCookies(res.headers["set-cookie"]);
    if (!newCookies) throw new Error("Login did not return a session cookie — check credentials");
    return newCookies;
}

// ─── Smoke runner ─────────────────────────────────────────────────────────────

async function runSmoke(baseUrl) {
    console.log(`\n🧪 Running smoke tests against ${baseUrl}...`);
    let failed = 0;
    let total  = CHECKS.length;

    for (const check of CHECKS) {
        const label = `GET ${check.path} (${check.contains})`.padEnd(44);
        try {
            const res  = await fetch(`${baseUrl}${check.path}`);
            const body = await res.text();
            const ok   = res.status === 200 && body.toLowerCase().includes(check.contains.toLowerCase());
            if (ok) {
                console.log(`  ✅ ${label} → 200`);
            } else {
                console.log(`  ❌ ${label} → ${res.status} (missing "${check.contains}")`);
                failed++;
            }
        } catch (err) {
            console.log(`  ❌ ${label} → ERROR: ${err.message}`);
            failed++;
        }
    }

    // Authenticated checks
    if (adminEmail && adminPassword) {
        total += AUTHED_CHECKS.length;
        let authCookie;
        try {
            authCookie = await loginForSmoke(baseUrl, adminEmail, adminPassword);
        } catch (err) {
            for (const check of AUTHED_CHECKS) {
                const label = `GET ${check.path} [authed] (${check.contains})`.padEnd(44);
                console.log(`  ❌ ${label} → login failed: ${err.message}`);
                failed++;
            }
            authCookie = null;
        }
        if (authCookie) {
            for (const check of AUTHED_CHECKS) {
                const label = `GET ${check.path} [authed] (${check.contains})`.padEnd(44);
                try {
                    const res = await httpGet(`${baseUrl}${check.path}`, authCookie);
                    const ok  = res.status === 200 && res.body.toLowerCase().includes(check.contains.toLowerCase());
                    if (ok) {
                        console.log(`  ✅ ${label} → 200`);
                    } else {
                        console.log(`  ❌ ${label} → ${res.status} (missing "${check.contains}")`);
                        failed++;
                    }
                } catch (err) {
                    console.log(`  ❌ ${label} → ERROR: ${err.message}`);
                    failed++;
                }
            }
        }
    } else {
        console.log(`  ℹ️  Authenticated smoke checks skipped (no credentials in config)`);
    }

    if (failed > 0) {
        console.log(`❌ Smoke tests failed (${failed}/${total} failed)\n`);
        return false;
    }
    console.log(`✅ Smoke tests passed (${total}/${total})\n`);
    return true;
}

module.exports = { runSmoke };

if (require.main === module) {
    const baseUrl = process.env.BASE_URL || process.argv[2];
    if (!baseUrl) {
        console.error("Usage: BASE_URL=https://hpovlsen.dk node tests/smoke.js");
        process.exit(1);
    }
    runSmoke(baseUrl).then(ok => process.exit(ok ? 0 : 1));
}
