const fs = require("fs");
const path = require("path");

const DROP_ORDER = [
    "invites",
    "bets",
    "password_resets",
    "leaderboard_snapshots",
    "races",
    "users",
    "drivers",
    "settings",
];

const INSERT_ORDER = [
    "settings",
    "drivers",
    "users",
    "races",
    "leaderboard_snapshots",
    "bets",
    "password_resets",
    "invites",
];

const CHUNK_SIZE = 500;

function escapeString(val) {
    return val
        .replace(/\\/g, "\\\\")
        .replace(/'/g, "\\'")
        .replace(/\n/g, "\\n")
        .replace(/\r/g, "\\r");
}

function toSqlValue(val) {
    if (val === null || val === undefined) return "NULL";
    if (typeof val === "number") return String(val);
    if (typeof val === "boolean") return val ? "1" : "0";
    return `'${escapeString(String(val))}'`;
}

function buildInserts(table, rows) {
    if (!rows || rows.length === 0) return "";
    const columns = Object.keys(rows[0]).map(c => `\`${c}\``).join(", ");
    const lines = [];
    for (let i = 0; i < rows.length; i += CHUNK_SIZE) {
        const chunk = rows.slice(i, i + CHUNK_SIZE);
        const values = chunk
            .map(row => `(${Object.values(row).map(toSqlValue).join(", ")})`)
            .join(",\n  ");
        lines.push(`INSERT INTO \`${table}\` (${columns}) VALUES\n  ${values};`);
    }
    return lines.join("\n");
}

function main() {
    const inputPath = process.argv[2];
    if (!inputPath) {
        console.error("Usage: node build-deploy/backup-to-sql.js <path-to-db-backup.json>");
        process.exit(1);
    }

    const resolvedInput = path.resolve(inputPath);
    if (!fs.existsSync(resolvedInput)) {
        console.error(`❌ File not found: ${resolvedInput}`);
        process.exit(1);
    }

    let backup;
    try {
        backup = JSON.parse(fs.readFileSync(resolvedInput, "utf8"));
    } catch (e) {
        console.error(`❌ Failed to parse JSON: ${e.message}`);
        process.exit(1);
    }

    if (!backup.ok || !backup.tables) {
        console.error("❌ Invalid backup format — expected {ok:true, schema:{}, tables:{}}");
        process.exit(1);
    }

    const lines = [];
    lines.push("-- F1Betting DB restore");
    lines.push(`-- Source: ${resolvedInput}`);
    lines.push(`-- Timestamp: ${backup.timestamp ?? "unknown"}`);
    lines.push("");
    lines.push("SET foreign_key_checks = 0;");
    lines.push("");

    // Drop in reverse FK order
    for (const table of DROP_ORDER) {
        lines.push(`DROP TABLE IF EXISTS \`${table}\`;`);
    }
    lines.push("");

    // Recreate schema
    for (const table of INSERT_ORDER) {
        const ddl = backup.schema?.[table];
        if (ddl) {
            lines.push(`${ddl};`);
            lines.push("");
        }
    }

    // Insert data in FK-safe order
    for (const table of INSERT_ORDER) {
        const rows = backup.tables?.[table];
        if (rows && rows.length > 0) {
            lines.push(`-- ${table}: ${rows.length} row(s)`);
            lines.push(buildInserts(table, rows));
            lines.push("");
        }
    }

    lines.push("SET foreign_key_checks = 1;");

    const outputPath = path.join(path.dirname(resolvedInput), "db-restore.sql");
    fs.writeFileSync(outputPath, lines.join("\n"), "utf8");
    console.log(`✅ Written: ${outputPath}`);
}

main();
