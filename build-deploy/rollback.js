const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

async function uploadDir(client, localDir, remoteDir) {
    await client.ensureDir(remoteDir);
    const entries = fs.readdirSync(localDir, { withFileTypes: true });
    for (const entry of entries) {
        const localPath = path.join(localDir, entry.name);
        const remotePath = `${remoteDir}/${entry.name}`;
        if (entry.isDirectory()) {
            await uploadDir(client, localPath, remotePath);
        } else {
            process.stdout.write(`  ↑ ${entry.name}\n`);
            await client.uploadFrom(localPath, remotePath);
        }
    }
}

async function rollback(backupDir) {
    const client = new ftp.Client();
    try {
        await client.access({
            host: process.env.FTP_HOST,
            user: process.env.FTP_USER,
            password: process.env.FTP_PASS,
        });
        console.log("  ↑ Restoring files...");
        await uploadDir(client, backupDir, `${process.env.FTP_ROOT_LIVE}/public`);
        console.log("✅ Rollback complete.");
    } catch (err) {
        console.error("❌ Rollback error:", err.message);
    } finally {
        client.close();
    }
}

module.exports = { rollback };
