#!/usr/bin/env node

/**
 * F1 Betting Deployment System Setup
 * This script helps initialize the deployment system
 */

const fs = require('fs');
const path = require('path');
const readline = require('readline');

const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout,
});

function question(prompt) {
  return new Promise(resolve => {
    rl.question(prompt, resolve);
  });
}

async function setup() {
  console.log('\n🎯 F1 Betting Deployment Setup\n');
  console.log('This will help you configure the deployment system.\n');

  const envPath = path.join(__dirname, '.env');
  
  if (fs.existsSync(envPath)) {
    const overwrite = await question('⚠️  .env file already exists. Overwrite? (y/n): ');
    if (overwrite.toLowerCase() !== 'y') {
      console.log('\nSetup cancelled.');
      rl.close();
      return;
    }
  }

  console.log('\nEnter your FTP configuration:\n');

  const ftpHost = await question('FTP Host (e.g., ftp.example.com): ');
  const ftpUser = await question('FTP Username: ');
  const ftpPass = await question('FTP Password: ');
  const ftpRootTest = await question('FTP Root Path - TEST (e.g., /public_html_test): ');
  const ftpRootLive = await question('FTP Root Path - LIVE (e.g., /public_html): ');

  const envContent = `# FTP Server Credentials
FTP_HOST=${ftpHost}
FTP_USER=${ftpUser}
FTP_PASS=${ftpPass}

# Deployment paths
FTP_ROOT_TEST=${ftpRootTest}
FTP_ROOT_LIVE=${ftpRootLive}

# Set to true to skip actual FTP upload (testing)
DRY_RUN=false
`;

  fs.writeFileSync(envPath, envContent);

  console.log('\n✅ Configuration saved to .env\n');
  console.log('📋 Next steps:\n');
  console.log('   npm run deploy:test   - Deploy to test environment');
  console.log('   npm run deploy:live   - Deploy to live environment');
  console.log('   npm run build         - Build ZIP only\n');
  console.log('For more info: cat DEPLOYMENT.md\n');

  rl.close();
}

setup();
