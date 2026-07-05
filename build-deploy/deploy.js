const ftp = require("basic-ftp");
const fs = require("fs");
const path = require("path");
const readline = require("readline");
const { execFileSync } = require("child_process");
require("dotenv").config({ path: path.join(__dirname, ".env") });

const { backup, pruneBackups } = require("./backup");
const { rollback } = require("./rollback");
const { runSmoke } = require("../tests/smoke");
const { readPhpConfig } = require("./php-config");
const { checkSchema } = require("./schema-check");

function loadIgnores(isLive) {
    const parse = file => fs.existsSync(file)
        ? fs.readFileSync(file, "utf8").split("\n").map(l => l.trim()).filter(l => l && !l.startsWith("#"))
        : [];
    const base = parse(path.join(__dirname, ".deployignore"));
    const live = isLive ? parse(path.join(__dirname, ".deployignore.live")) : [];
    return [...base, ...live];
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
    const smokeOk = await runSmoke(baseUrl, env);

    if (env === "test") {
        return smokeOk;
    }

    const cfg = readPhpConfig(env);
    const testEnv = {
        ...process.env,
        DEPLOY_ENV: env,
        BASE_URL: baseUrl,
        TEST_USER_EMAIL: cfg.adminEmail,
        TEST_USER_PASSWORD: cfg.adminPassword,
    };

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
    const phpCfg = readPhpConfig(env);
    const baseUrl = phpCfg.siteUrl;
    const publicDir = path.join(__dirname, "../public");
    const ignores = loadIgnores(isLive);

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
        await client.ensureDir(`${remoteDir}/public`);
        await uploadDir(client, publicDir, `${remoteDir}/public`, ignores);
        const configSrc = path.join(__dirname, `../config.${env}.php`);
        if (fs.existsSync(configSrc)) {
            process.stdout.write(`  ↑ config.php\n`);
            await client.uploadFrom(configSrc, `${remoteDir}/config.php`);
        } else {
            console.warn(`⚠️  config.${env}.php not found — skipping config upload`);
        }
        const sharedSrc = path.join(__dirname, `../config.shared.php`);
        if (fs.existsSync(sharedSrc)) {
            process.stdout.write(`  ↑ config.shared.php\n`);
            await client.uploadFrom(sharedSrc, `${remoteDir}/config.shared.php`);
        }
        console.log(`✅ Done! Uploaded to ${remoteDir}`);
    } catch (err) {
        console.error("❌ FTP Error:", err.message);
        process.exit(1);
    } finally {
        client.close();
    }

    const schemaOk = await checkSchema(baseUrl, phpCfg.integrationSeedToken);
    const testsOk = schemaOk && await runTests(baseUrl, env);

    if (!testsOk) {
        if (isLive && backupInfo) {
            const reason = schemaOk ? "Tests failed" : "DB schema behind code";
            console.log(`\n❌ ${reason} — rolling back to backup ${backupInfo.timestamp}...`);
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
