const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

async function sync() {
    const baseUrl = process.env.INTEGRATION_BASE_URL;
    const token = process.env.INTEGRATION_SEED_TOKEN;

    if (!baseUrl || !token) {
        console.error("❌ INTEGRATION_BASE_URL and INTEGRATION_SEED_TOKEN must be set in build-deploy/.env");
        process.exit(1);
    }

    const url = `${baseUrl}/sync-from-live.php?token=${token}`;
    console.log("🔄 Syncing live data to test site...");

    let res;
    try {
        res = await fetch(url);
    } catch (err) {
        console.error("❌ Request failed:", err.message);
        process.exit(1);
    }

    let body;
    try {
        body = await res.json();
    } catch {
        console.error(`❌ Invalid JSON response (HTTP ${res.status})`);
        process.exit(1);
    }

    if (!res.ok || !body.ok) {
        console.error("❌ Sync failed:", body.error ?? `HTTP ${res.status}`);
        process.exit(1);
    }

    const { dropped_old_tables: dropped, copied } = body;
    const parts = [];
    if (dropped > 0) parts.push(`${dropped} old_ tables dropped`);
    parts.push(`${copied.drivers} drivers`);
    parts.push(`${copied.users} users`);
    parts.push(`${copied.races} races`);
    parts.push(`${copied.bets} bets copied`);
    console.log("✅ Sync complete:", parts.join(", "));
}

sync();
