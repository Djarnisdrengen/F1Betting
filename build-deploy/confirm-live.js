const readline = require("readline");

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
rl.question("⚠️  You are about to run against LIVE (formula-1.dk). Type YES to confirm: ", answer => {
    rl.close();
    if (answer !== "YES") {
        console.log("Aborted.");
        process.exit(1);
    }
});
