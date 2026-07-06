const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });
const { readPhpConfig } = require("./php-config");

async function sync() {
    let baseUrl, token;
    try {
        const cfg = readPhpConfig("test");
        baseUrl = cfg.siteUrl;
        token   = cfg.integrationSeedToken;
    } catch (e) {
        console.error("❌", e.message);
        process.exit(1);
    }

    if (!baseUrl || !token) {
        console.error("❌ SITE_URL or INTEGRATION_SEED_TOKEN missing in config.test.php");
        process.exit(1);
    }

    const url = `${baseUrl}/tools/sync-from-live.php?token=${token}`;
    console.log("🔄 Syncing live data to test site...");

    let res;
    try {
        res = await fetch(url);
    } catch (err) {
        console.error("❌ Request failed:", err.message);
        process.exit(1);
    }

    const text = await res.text();
    let body;
    try {
        body = JSON.parse(text);
    } catch {
        console.error(`❌ Invalid JSON response (HTTP ${res.status}):`);
        console.error(text.slice(0, 500));
        process.exit(1);
    }

    if (!res.ok || !body.ok) {
        console.error("❌ Sync failed:", body.error ?? `HTTP ${res.status}`);
        process.exit(1);
    }

    if (!body.copied || typeof body.dropped_old_tables !== "number") {
        console.error("❌ Unexpected response shape:", JSON.stringify(body));
        process.exit(1);
    }

    // Fail loud if the server ran without the user_passkeys guard — stale live
    // passkey rows on test gate those members' logins behind an unusable factor.
    if (typeof body.passkeys_cleared !== "number") {
        console.error("❌ Sync response missing passkeys_cleared — sync-from-live.php on test is outdated (deploy first)");
        process.exit(1);
    }

    const { dropped_old_tables: dropped, copied, passwords_reset } = body;
    const parts = [];
    if (dropped > 0) parts.push(`${dropped} old_ tables dropped`);
    parts.push(`${copied.drivers} drivers`);
    parts.push(`${copied.users} users`);
    parts.push(`${copied.races} races`);
    parts.push(`${copied.bets} bets copied`);
    parts.push(`user_passkeys cleared (${body.passkeys_cleared} stale)`);
    if (passwords_reset) parts.push("passwords reset to SYNC_TEST_PASSWORD");
    else                 parts.push("⚠️  SYNC_TEST_PASSWORD not set — passwords unverifiable on test");
    console.log("✅ Sync complete:", parts.join(", "));
}

sync();
