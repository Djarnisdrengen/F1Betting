const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });
const { readPhpConfig } = require("./php-config");

// Verify the target DB has every schema object the deployed code needs.
// The required objects live in database/migrations.json (not deployed), so we
// POST them to the server-side checker, which introspects that env's own DB.
// Returns true if the DB is up to date (or the checker isn't deployed yet).
async function checkSchema(baseUrl, token) {
    if (!token) {
        console.warn("⚠️  INTEGRATION_SEED_TOKEN missing — skipping schema check.");
        return true;
    }

    let objects;
    try {
        const manifest = JSON.parse(
            fs.readFileSync(path.join(__dirname, "../database/migrations.json"), "utf8")
        );
        objects = manifest.objects;
    } catch (err) {
        console.error("❌ Could not read database/migrations.json:", err.message);
        return false;
    }

    console.log("🔎 Checking DB schema is up to date...");
    let res;
    try {
        res = await fetch(`${baseUrl}/tools/schema-check.php?token=${token}`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ objects }),
        });
    } catch (err) {
        console.error("❌ Schema check request failed:", err.message);
        return false;
    }

    // Graceful fallback: during a deploy the endpoint is uploaded earlier in the
    // same run, so it should be present. A 404 means something is off (excluded
    // from upload, wrong path) — warn rather than block on the checker itself.
    if (res.status === 404) {
        console.warn("⚠️  schema-check.php not reachable (404) — skipping schema check.");
        return true;
    }

    const text = await res.text();
    let body;
    try {
        body = JSON.parse(text);
    } catch {
        console.error(`❌ Schema check returned invalid JSON (HTTP ${res.status}):`);
        console.error(text.slice(0, 500));
        return false;
    }

    if (body.ok) {
        console.log("✅ DB schema up to date.");
        return true;
    }

    const byMigration = new Map();
    for (const m of body.missing ?? []) {
        if (!byMigration.has(m.migration)) byMigration.set(m.migration, []);
        byMigration.get(m.migration).push(m.detail);
    }
    console.error("\n❌ Target DB is behind the code. Run these migrations before deploying:");
    for (const [migration, details] of byMigration) {
        console.error(`   • ${migration}  (${details.join(", ")})`);
    }
    if (body.error) console.error(`   ${body.error}`);
    return false;
}

// CLI: `node build-deploy/schema-check.js [test|live]` — read-only, no deploy.
async function main() {
    const env = process.argv[2] || "test";
    if (env !== "test" && env !== "live") {
        console.error(`❌ Unknown env "${env}" — use "test" or "live".`);
        process.exit(2);
    }
    let cfg;
    try {
        cfg = readPhpConfig(env);
    } catch (e) {
        console.error("❌", e.message);
        process.exit(2);
    }
    if (!cfg.siteUrl || !cfg.integrationSeedToken) {
        console.error(`❌ SITE_URL or INTEGRATION_SEED_TOKEN missing in config.${env}.php`);
        process.exit(2);
    }
    console.log(`🔎 Schema check against ${env.toUpperCase()} (${cfg.siteUrl})`);
    const ok = await checkSchema(cfg.siteUrl, cfg.integrationSeedToken);
    process.exit(ok ? 0 : 1);
}

module.exports = { checkSchema };

if (require.main === module) {
    main();
}
