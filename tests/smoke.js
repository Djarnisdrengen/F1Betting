const path = require("path");
require("dotenv").config({ path: path.join(__dirname, "../build-deploy/.env") });

const CHECKS = [
    { path: "/",                 contains: "<html" },
    { path: "/login.php",        contains: 'name="email"' },
    { path: "/leaderboard.php",  contains: "leaderboard" },
    { path: "/races.php",        contains: "<html" },

    // Translations — verify lang files load and t() returns real strings (not raw key names)
    { path: "/login.php",        contains: "Log ind" },     // t('login') in DA
    { path: "/login.php",        contains: "Adgangskode" }, // t('password') in DA
];

async function runSmoke(baseUrl) {
    console.log(`\n🧪 Running smoke tests against ${baseUrl}...`);
    let failed = 0;
    for (const check of CHECKS) {
        const label = `GET ${check.path}`.padEnd(28);
        try {
            const res = await fetch(`${baseUrl}${check.path}`);
            const body = await res.text();
            const ok = res.status === 200 && body.toLowerCase().includes(check.contains.toLowerCase());
            if (ok) {
                console.log(`  ✅ ${label} → 200`);
            } else {
                console.log(`  ❌ ${label} → ${res.status} (expected 200 with content)`);
                failed++;
            }
        } catch (err) {
            console.log(`  ❌ ${label} → ERROR: ${err.message}`);
            failed++;
        }
    }
    const total = CHECKS.length;
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
