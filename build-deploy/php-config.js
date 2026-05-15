const fs = require("fs");
const path = require("path");

// Reads string define() values from config.test.php or config.live.php.
// Throws if the file does not exist (e.g. not yet set up on this machine).
function readPhpConfig(env) {
    const file = path.join(__dirname, `../config.${env}.php`);
    if (!fs.existsSync(file)) {
        throw new Error(`config.${env}.php not found — run setup or create it from config.example.php`);
    }
    const src = fs.readFileSync(file, "utf8");
    const str = (key) => {
        const m = src.match(new RegExp(`define\\(\\s*'${key}'\\s*,\\s*'([^']*)'`));
        return m ? m[1] : null;
    };
    return {
        siteUrl:              str("SITE_URL"),
        adminEmail:           str("F1_ADMIN_EMAIL"),
        adminPassword:        str("F1_ADMIN_PASSWORD"),
        integrationSeedToken: str("INTEGRATION_SEED_TOKEN"),
        cronSecret:           str("CRON_SECRET"),
    };
}

module.exports = { readPhpConfig };
