const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
const readline = require("readline");
const { execFileSync } = require("child_process");
require("dotenv").config({ path: path.join(__dirname, ".env") });

const { backup, pruneBackups } = require("./backup");
const { rollback } = require("./rollback");
const { runSmoke } = require("../tests/smoke");

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

async function runTests(baseUrl, env) {
    const testEnv = {
        ...process.env,
        BASE_URL: baseUrl,
        TEST_USER_EMAIL: process.env[`TEST_USER_EMAIL_${env.toUpperCase()}`],
        TEST_USER_PASSWORD: process.env[`TEST_USER_PASSWORD_${env.toUpperCase()}`],
    };

    const smokeOk = await runSmoke(baseUrl);

    console.log(`🎭 Running Playwright E2E tests against ${baseUrl}...`);
    let e2eOk = true;
    try {
        execFileSync("npx", ["playwright", "test", "--config", "tests/playwright.config.js"], {
            stdio: "inherit",
            cwd: path.join(__dirname, ".."),
            env: testEnv,
        });
    } catch {
        e2eOk = false;
    }

    return smokeOk && e2eOk;
}

async function deploy() {
    const env = process.argv[2] || "test";
    const isLive = env === "live";
    const remoteDir = isLive ? process.env.FTP_ROOT_LIVE : process.env.FTP_ROOT_TEST;
    const baseUrl = isLive ? process.env.BASE_URL_LIVE : process.env.BASE_URL_TEST;
    const publicDir = path.join(__dirname, "../public");
    const ignores = loadIgnores();

    console.log(`🚀 Deploying to ${env.toUpperCase()}...`);

    if (process.env.DRY_RUN === "true") {
        console.log("⚠️ DRY_RUN: Skipping upload.");
        return;
    }

    let backupInfo = null;
    if (isLive) {
        backupInfo = await backup();
    }

    const client = new ftp.Client();
    try {
        await client.access({
            host: process.env.FTP_HOST,
            user: process.env.FTP_USER,
            password: process.env.FTP_PASS,
        });
        await uploadDir(client, publicDir, `${remoteDir}/public`, ignores);
        console.log(`✅ Done! Uploaded to ${remoteDir}`);
    } catch (err) {
        console.error("❌ FTP Error:", err.message);
        process.exit(1);
    } finally {
        client.close();
    }

    const testsOk = await runTests(baseUrl, env);

    if (!testsOk) {
        if (isLive && backupInfo) {
            console.log(`\n❌ Tests failed — rolling back to backup ${backupInfo.timestamp}...`);
            await rollback(backupInfo.backupDir);
        }
        console.log(`\n❌ Deploy to ${env.toUpperCase()} failed — fix and redeploy.`);
        process.exit(1);
    }

    if (isLive) {
        pruneBackups();
    }

    console.log(`\n✅ Deploy to ${env.toUpperCase()} complete. All tests passed.`);
}

async function main() {
    const env = process.argv[2] || "test";
    if (env === "live") {
        const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
        await new Promise(resolve =>
            rl.question("⚠️  Deploy to LIVE (formula-1.dk)? Type YES to confirm: ", answer => {
                rl.close();
                if (answer !== "YES") {
                    console.log("Aborted.");
                    process.exit(0);
                }
                resolve();
            })
        );
    }
    deploy();
}
main();
