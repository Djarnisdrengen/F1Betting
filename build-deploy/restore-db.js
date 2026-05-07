const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

async function main() {
    const [timestamp, env = "test"] = process.argv.slice(2);
    const backupsDir = path.join(__dirname, "backups", "live");

    // List available backups if no timestamp given
    if (!timestamp) {
        const available = fs.existsSync(backupsDir)
            ? fs.readdirSync(backupsDir).filter(d =>
                fs.existsSync(path.join(backupsDir, d, "db-backup.json"))
              ).sort().reverse()
            : [];
        if (available.length === 0) {
            console.error("❌ No db-backup.json files found in build-deploy/backups/live/");
            process.exit(1);
        }
        console.log("Available DB backups:");
        available.forEach(t => console.log(`  ${t}`));
        console.log("\nUsage: npm run restore:db -- <timestamp> [test|live]");
        process.exit(0);
    }

    const backupFile = path.join(backupsDir, timestamp, "db-backup.json");
    if (!fs.existsSync(backupFile)) {
        console.error(`❌ No db-backup.json found at build-deploy/backups/live/${timestamp}/`);
        process.exit(1);
    }

    const baseUrl = env === "live"
        ? process.env.BASE_URL_LIVE
        : process.env.INTEGRATION_BASE_URL;
    const token = process.env.INTEGRATION_SEED_TOKEN;

    if (!baseUrl || !token) {
        console.error("❌ BASE_URL and INTEGRATION_SEED_TOKEN must be set in build-deploy/.env");
        process.exit(1);
    }

    if (env === "live") {
        console.log("⚠️  You are about to overwrite the LIVE database.");
        console.log("   Press Ctrl+C within 5 seconds to abort...");
        await new Promise(r => setTimeout(r, 5000));
    }

    const backup = JSON.parse(fs.readFileSync(backupFile, "utf8"));
    console.log(`🔄 Restoring DB backup ${timestamp} → ${env} (${baseUrl})...`);

    let res;
    try {
        res = await fetch(`${baseUrl}/db-restore.php?token=${token}`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(backup),
        });
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
        console.error("❌ Restore failed:", body.error ?? `HTTP ${res.status}`);
        process.exit(1);
    }

    const counts = Object.entries(body.restored)
        .map(([t, n]) => `${n} ${t}`)
        .join(", ");
    console.log(`✅ Restore complete: ${counts}`);
}

main();
