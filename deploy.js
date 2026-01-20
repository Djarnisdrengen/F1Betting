#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const archiver = require('archiver');
const FTP = require('ftp');
require('dotenv').config();

// Configuration
const environment = process.argv[2] || 'test';
const buildOnly = process.argv.includes('--build-only');

const FTP_HOST = process.env.FTP_HOST;
const FTP_USER = process.env.FTP_USER;
const FTP_PASS = process.env.FTP_PASS;
const FTP_ROOT_TEST = process.env.FTP_ROOT_TEST;
const FTP_ROOT_LIVE = process.env.FTP_ROOT_LIVE;
const DRY_RUN = process.env.DRY_RUN === 'true';

const FTP_ROOT = environment === 'live' ? FTP_ROOT_LIVE : FTP_ROOT_TEST;
const ZIP_FILE = path.join(__dirname, 'public-build.zip');
const PUBLIC_DIR = path.join(__dirname, 'public');
const DEPLOY_IGNORE_FILE = path.join(__dirname, '.deployignore');

// Validate environment
if (!['test', 'live'].includes(environment)) {
  console.error('❌ Invalid environment. Use: deploy test | deploy live');
  process.exit(1);
}

if (!fs.existsSync(DEPLOY_IGNORE_FILE)) {
  console.error('❌ .deployignore file not found');
  process.exit(1);
}

// Read .deployignore patterns
function getIgnorePatterns() {
  return fs
    .readFileSync(DEPLOY_IGNORE_FILE, 'utf8')
    .split('\n')
    .map(line => line.trim())
    .filter(line => line && !line.startsWith('#'));
}

// Check if file should be ignored
function shouldIgnore(filePath, relativeToPublic) {
  const patterns = getIgnorePatterns();
  
  for (const pattern of patterns) {
    if (relativeToPublic.includes(pattern.replace(/\//g, '\\'))) {
      return true;
    }
  }
  return false;
}

// Create ZIP file
async function createZip() {
  return new Promise((resolve, reject) => {
    console.log(`📦 Creating ZIP file for ${environment} environment...`);

    const output = fs.createWriteStream(ZIP_FILE);
    const archive = archiver('zip', { zlib: { level: 9 } });

    output.on('close', () => {
      console.log(`✅ ZIP created: ${ZIP_FILE} (${(archive.pointer() / 1024 / 1024).toFixed(2)} MB)`);
      resolve();
    });

    archive.on('error', (err) => {
      console.error('❌ Archive error:', err);
      reject(err);
    });

    archive.pipe(output);

    // Add all files from public folder except ignored ones
    const addFilesRecursive = (dir, arcPath) => {
      const files = fs.readdirSync(dir);

      files.forEach(file => {
        const fullPath = path.join(dir, file);
        const arcFullPath = path.join(arcPath, file);
        const relativeToPublic = path.relative(PUBLIC_DIR, fullPath);

        if (shouldIgnore(fullPath, relativeToPublic)) {
          console.log(`  ⊘ Skipped: ${relativeToPublic}`);
          return;
        }

        const stat = fs.statSync(fullPath);

        if (stat.isDirectory()) {
          addFilesRecursive(fullPath, arcFullPath);
        } else {
          archive.file(fullPath, { name: arcFullPath });
        }
      });
    };

    addFilesRecursive(PUBLIC_DIR, 'public');
    archive.finalize();
  });
}

// Upload to FTP
async function uploadToFTP() {
  return new Promise((resolve, reject) => {
    if (!FTP_HOST || !FTP_USER || !FTP_PASS) {
      reject(new Error('❌ Missing FTP credentials in .env file'));
      return;
    }

    const ftp = new FTP();

    console.log(`\n🚀 Connecting to FTP: ${FTP_HOST}`);
    console.log(`📍 Deployment path: ${FTP_ROOT}`);
    console.log(`🌐 Environment: ${environment.toUpperCase()}`);

    if (DRY_RUN) {
      console.log(`\n⚠️  DRY_RUN mode enabled - no files will be uploaded`);
    }

    ftp.on('ready', () => {
      console.log('✅ Connected to FTP server');

      if (DRY_RUN) {
        console.log('✅ Dry run completed successfully');
        ftp.end();
        resolve();
        return;
      }

      // Change to deployment root
      ftp.cwd(FTP_ROOT, (err) => {
        if (err) {
          console.error(`❌ Failed to change directory to ${FTP_ROOT}:`, err.message);
          ftp.end();
          reject(err);
          return;
        }

        console.log(`📁 Changed directory to ${FTP_ROOT}`);

        // Upload ZIP file
        const zipStream = fs.createReadStream(ZIP_FILE);
        const fileName = `public-${environment}-${new Date().toISOString().split('T')[0]}.zip`;

        ftp.put(zipStream, fileName, (err) => {
          if (err) {
            console.error('❌ Upload failed:', err);
            ftp.end();
            reject(err);
            return;
          }

          console.log(`✅ Uploaded: ${fileName}`);
          console.log('\n📋 Manual next steps:');
          console.log(`  1. SSH into ${FTP_HOST}`);
          console.log(`  2. Navigate to ${FTP_ROOT}`);
          console.log(`  3. Extract: unzip ${fileName}`);
          console.log(`  4. Move files from public/ to root: mv public/* ./`);
          console.log(`  5. Clean up: rm -rf public ${fileName}`);

          ftp.end();
          resolve();
        });
      });
    });

    ftp.on('error', (err) => {
      console.error('❌ FTP error:', err);
      reject(err);
    });

    ftp.on('close', () => {
      console.log('🔌 FTP connection closed');
    });

    ftp.connect({
      host: FTP_HOST,
      user: FTP_USER,
      password: FTP_PASS,
      connTimeout: 10000,
      pasvTimeout: 10000,
    });
  });
}

// Main execution
async function main() {
  try {
    // Check for .env file
    if (!fs.existsSync(path.join(__dirname, '.env'))) {
      console.error('❌ .env file not found. Please create it from .env.example');
      process.exit(1);
    }

    // Create ZIP
    await createZip();

    if (buildOnly) {
      console.log('\n✅ Build complete! ZIP ready for manual deployment.');
      process.exit(0);
    }

    // Upload to FTP
    await uploadToFTP();

    console.log('\n🎉 Deployment completed successfully!');

    // Cleanup
    if (fs.existsSync(ZIP_FILE)) {
      fs.unlinkSync(ZIP_FILE);
      console.log('🗑️  ZIP file cleaned up');
    }
  } catch (error) {
    console.error('\n❌ Deployment failed:', error.message);
    process.exit(1);
  }
}

main();
