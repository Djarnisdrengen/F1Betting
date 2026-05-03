const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

function loadIgnores() {
    const ignorePath = path.join(__dirname, ".deployignore");
    if (!fs.existsSync(ignorePath)) return [];
    return fs.readFileSync(ignorePath, "utf8")
        .split("\n")
        .map(line => line.trim())
        .filter(line => line && !line.startsWith("#"));
}

function isIgnored(relPath, ignores) {
    return ignores.some(pattern => {
        const normalized = relPath.replace(/\\/g, "/");
        return normalized === pattern || normalized.startsWith(pattern + "/");
    });
}

async function uploadDir(client, localDir, remoteDir, ignores) {
    await client.ensureDir(remoteDir);
    const entries = fs.readdirSync(localDir, { withFileTypes: true });
    for (const entry of entries) {
        const localPath = path.join(localDir, entry.name);
        const remotePath = `${remoteDir}/${entry.name}`;
        const relPath = path.relative(path.join(__dirname, "../public"), localPath).replace(/\\/g, "/");
        if (isIgnored(relPath, ignores)) continue;
        if (entry.isDirectory()) {
            await uploadDir(client, localPath, remotePath, ignores);
        } else {
            process.stdout.write(`  ↑ ${relPath}\n`);
            await client.uploadFrom(localPath, remotePath);
        }
    }
}

async function deploy() {
    const env = process.argv[2] || "test";
    const isLive = env === "live";
    const remoteDir = isLive ? process.env.FTP_ROOT_LIVE : process.env.FTP_ROOT_TEST;
    const publicDir = path.join(__dirname, "../public");
    const ignores = loadIgnores();

    console.log(`🚀 Deploying to ${env.toUpperCase()}...`);

    if (process.env.DRY_RUN === "true") {
        console.log("⚠️ DRY_RUN: Skipping upload.");
        return;
    }

    const client = new ftp.Client();
    try {
        await client.access({
            host: process.env.FTP_HOST,
            user: process.env.FTP_USER,
            password: process.env.FTP_PASS
        });
        await uploadDir(client, publicDir, `${remoteDir}/public`, ignores);
        console.log(`✅ Done! Uploaded to ${remoteDir}`);
    } catch (err) {
        console.error("❌ FTP Error:", err);
    } finally {
        client.close();
    }
}
deploy();
