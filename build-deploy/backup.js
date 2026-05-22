const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });
const { readPhpConfig } = require("./php-config");

async function backupDb(backupDir) {
    let baseUrl, token;
    try {
        const cfg = readPhpConfig("live");
        baseUrl = cfg.siteUrl;
        token   = cfg.integrationSeedToken;
    } catch (e) {
        console.log("⚠️  Skipping DB backup —", e.message);
        return;
    }

    if (!baseUrl || !token) {
        console.log("⚠️  Skipping DB backup — SITE_URL or INTEGRATION_SEED_TOKEN missing in config.live.php");
        return;
    }

    console.log("🗄️  Backing up live database...");
    let res;
    try {
        res = await fetch(`${baseUrl}/tools/db-backup.php?token=${token}`);
    } catch (err) {
        console.log("⚠️  DB backup request failed:", err.message);
        return;
    }

    let body;
    try {
        body = await res.json();
    } catch {
        console.log(`⚠️  DB backup returned non-JSON (HTTP ${res.status}) — skipping`);
        return;
    }

    if (!res.ok || !body.ok) {
        console.log("⚠️  DB backup failed:", body.error ?? `HTTP ${res.status}`);
        return;
    }

    fs.writeFileSync(path.join(backupDir, "db-backup.json"), JSON.stringify(body, null, 2));
    const rowCounts = Object.entries(body.tables)
        .filter(([, rows]) => rows !== null)
        .map(([t, rows]) => `${rows.length} ${t}`)
        .join(", ");
    console.log(`✅ DB backup complete (${rowCounts})\n`);
}

async function backup() {
    const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
    const backupDir = path.join(__dirname, "backups", "live", timestamp);
    fs.mkdirSync(backupDir, { recursive: true });

    const client = new ftp.Client();
    try {
        await client.access({
            host: process.env.FTP_HOST,
            user: process.env.FTP_USER,
            password: process.env.FTP_PASS,
        });
        console.log(`\n📦 Backing up live site → build-deploy/backups/live/${timestamp}/`);
        await client.ensureDir(`${process.env.FTP_ROOT_LIVE}/public`);
        await client.downloadToDir(backupDir, `${process.env.FTP_ROOT_LIVE}/public`);
        console.log(`✅ File backup complete`);
    } finally {
        client.close();
    }

    await backupDb(backupDir);

    return { timestamp, backupDir };
}

function pruneBackups() {
    const dir = path.join(__dirname, "backups", "live");
    if (!fs.existsSync(dir)) return;
    const entries = fs.readdirSync(dir)
        .filter(f => fs.statSync(path.join(dir, f)).isDirectory())
        .sort()
        .reverse();
    entries.slice(5).forEach(e => {
        fs.rmSync(path.join(dir, e), { recursive: true, force: true });
    });
}

module.exports = { backup, pruneBackups };

if (require.main === module) {
    backup()
        .then(({ timestamp }) => { pruneBackups(); console.log(`✅ Backup complete: ${timestamp}`); })
        .catch(err => { console.error("❌ Backup failed:", err.message); process.exit(1); });
}
