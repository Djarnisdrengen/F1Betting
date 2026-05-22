const fs = require("fs");
const path = require("path");
const readline = require("readline");
require("dotenv").config({ path: path.join(__dirname, ".env") });
const { readPhpConfig } = require("./php-config");

function ask(rl, question) {
    return new Promise(resolve => rl.question(question, resolve));
}

async function main() {
    const backupsDir = path.join(__dirname, "backups", "live");
    const available = fs.existsSync(backupsDir)
        ? fs.readdirSync(backupsDir)
            .filter(d => fs.existsSync(path.join(backupsDir, d, "db-backup.json")))
            .sort()
            .reverse()
        : [];

    if (available.length === 0) {
        console.error("❌ No db-backup.json files found in build-deploy/backups/live/");
        process.exit(1);
    }

    const args = process.argv.slice(2);
    const envFlagIdx = args.findIndex(a => a === '--env');
    const envArg = envFlagIdx !== -1 ? args[envFlagIdx + 1] : null;

    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

    console.log("\nAvailable DB backups:\n");
    available.forEach((t, i) => {
        const date = t.replace("T", " ").replace(/(-\d{3}Z)$/, "").replace(/-/g, (m, o) => o > 9 ? ":" : "-");
        console.log(`  [${i + 1}] ${date}`);
    });

    const indexInput = await ask(rl, "\nSelect backup number: ");
    const index = parseInt(indexInput, 10) - 1;
    if (isNaN(index) || index < 0 || index >= available.length) {
        console.error("❌ Invalid selection");
        rl.close();
        process.exit(1);
    }

    let env;
    if (envArg === 'test' || envArg === 'live') {
        env = envArg;
    } else {
        const envInput = await ask(rl, "Restore to [test/live] (default: test): ");
        env = envInput.trim() === "live" ? "live" : "test";
    }
    rl.close();
    const timestamp = available[index];

    let baseUrl, token;
    try {
        const cfg = readPhpConfig(env);
        baseUrl = cfg.siteUrl;
        token   = cfg.integrationSeedToken;
    } catch (e) {
        console.error("❌", e.message);
        process.exit(1);
    }

    if (!baseUrl || !token) {
        console.error(`❌ SITE_URL or INTEGRATION_SEED_TOKEN missing in config.${env}.php`);
        process.exit(1);
    }

    const envLabel = env === "live"
        ? "⚠️  LIVE database (formula-1.dk)"
        : "⚠️  TEST database (hpovlsen.dk)";
    const rl2 = readline.createInterface({ input: process.stdin, output: process.stdout });
    const confirmed = await new Promise(resolve =>
        rl2.question(`\nYou are about to overwrite the ${envLabel}. Type YES to confirm: `, answer => {
            rl2.close();
            resolve(answer === "YES");
        })
    );
    if (!confirmed) {
        console.log("Aborted.");
        process.exit(1);
    }

    const backup = JSON.parse(fs.readFileSync(path.join(backupsDir, timestamp, "db-backup.json"), "utf8"));
    const hasSchema = !!(backup.schema && Object.values(backup.schema).some(Boolean));
    console.log(`\n🔄 Restoring to ${env} (${hasSchema ? "schema + data" : "data only"})...`);

    let res;
    try {
        res = await fetch(`${baseUrl}/tools/db-restore.php?token=${token}`, {
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
