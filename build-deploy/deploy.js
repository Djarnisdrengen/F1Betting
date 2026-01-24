const ftp = require("basic-ftp");
const archiver = require("archiver");
const fs = require("fs");
const path = require("path");
require("dotenv").config({ path: path.join(__dirname, ".env") });

async function deploy() {
    const env = process.argv[2] || "test";
    const isLive = env === "live";
    const remoteDir = isLive ? process.env.FTP_ROOT_LIVE : process.env.FTP_ROOT_TEST;
    const timestamp = new Date().toISOString().replace(/[:.]/g, "-").slice(0, 10);
    const zipName = `public-${env}-${timestamp}.zip`;
    const zipPath = path.join(__dirname, zipName);

    console.log(`🚀 Deploying to ${env.toUpperCase()}...`);

    const output = fs.createWriteStream(zipPath);
    const archive = archiver("zip", { zlib: { level: 9 } });

    archive.pipe(output);

    // Load ignores from .deployignore
    const ignorePath = path.join(__dirname, ".deployignore");
    let ignores = [];
    if (fs.existsSync(ignorePath)) {
        ignores = fs.readFileSync(ignorePath, "utf8")
            .split("\n")
            .map(line => line.trim())
            .filter(line => line && !line.startsWith("#"));
    }

    // Reference root/public
    archive.glob("**/*", {
        cwd: path.join(__dirname, "../public"),
        ignore: ignores
    });

    await archive.finalize();
    console.log(`📦 Created: ${zipName}`);

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
        await client.ensureDir(remoteDir);
        await client.uploadFrom(zipPath, zipName);
        console.log(`✅ Success! Uploaded to ${remoteDir}`);
    } catch (err) {
        console.error("❌ FTP Error:", err);
    } finally {
        client.close();
    }
}
deploy();