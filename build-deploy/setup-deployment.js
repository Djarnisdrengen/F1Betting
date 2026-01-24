const fs = require('fs');
const path = require('path');
const readline = require('readline').createInterface({ input: process.stdin, output: process.stdout });

const prompt = (q) => new Promise((res) => readline.question(q, res));

(async () => {
    console.log("🛠️ Deployment Setup\n");
    const host = await prompt('FTP Host: ');
    const user = await prompt('FTP User: ');
    const pass = await prompt('FTP Pass: ');
    const test = await prompt('Test Path (/public_html_test): ');
    const live = await prompt('Live Path (/public_html): ');

    const content = `FTP_HOST=${host}\nFTP_USER=${user}\nFTP_PASS=${pass}\nFTP_ROOT_TEST=${test}\nFTP_ROOT_LIVE=${live}\nDRY_RUN=false`;
    fs.writeFileSync(path.join(__dirname, '.env'), content);
    console.log("\n✅ .env created!");
    readline.close();
})();