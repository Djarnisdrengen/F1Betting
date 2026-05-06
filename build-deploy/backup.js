const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

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
        await client.downloadToDir(backupDir, `${process.env.FTP_ROOT_LIVE}/public`);
        console.log(`✅ Backup complete\n`);
    } finally {
        client.close();
    }

    return { timestamp, backupDir };
}

function pruneBackups() {
    const dir = path.join(__dirname, "backups", "live");
    if (!fs.existsSync(dir)) return;
    const entries = fs.readdirSync(dir)
        .filter(f => fs.statSync(path.join(dir, f)).isDirectory())
        .sort()
        .reverse();
    entries.slice(2).forEach(e => {
        fs.rmSync(path.join(dir, e), { recursive: true, force: true });
    });
}

module.exports = { backup, pruneBackups };
