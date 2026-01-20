# F1 Betting Deployment Guide

This repository includes automated deployment scripts to zip the `public` folder and upload it to your FTP servers.

All deployment files are organized in the `build/deploy/` folder.

## Folder structure
build/deploy/
├── deploy.js              # Node.js deployment script
├── deploy.ps1             # PowerShell deployment script  
├── setup-deployment.js    # Interactive setup wizard
├── .env.example           # FTP credentials template
├── .deployignore          # Files to exclude
└── README.md              # Documentation

## Setup

### 1. Install Dependencies (Node.js version)

```bash
npm install
```

### 2. Configure Environment Variables

Copy `build/deploy/.env.example` to `build/deploy/.env` and update with your credentials:

```bash
cp build/deploy/.env.example build/deploy/.env
```

Edit `build/deploy/.env` with your FTP server details:

```env
FTP_HOST=your-ftp-server.com
FTP_USER=your-ftp-username
FTP_PASS=your-ftp-password

# Different root folders for each environment
FTP_ROOT_TEST=/public_html_test
FTP_ROOT_LIVE=/public_html

# Optional: Set to true to test without uploading
DRY_RUN=false
```

## Usage

### Keyboard Shortcuts (VS Code)

The fastest way to deploy:

| Shortcut | Action |
|----------|--------|
| **Ctrl+Shift+B** | Deploy to TEST (hpovlsen.dk) |
| **Ctrl+Shift+L** | Deploy to LIVE (formula-1.dk) - requires "yes" confirmation |
| **Ctrl+Alt+P** | Preview files (lists what would be deployed) |

### Node.js Version (Cross-platform)

Deploy to test environment:
```bash
npm run deploy:test
```

Deploy to live environment:
```bash
npm run deploy:live
```

Build ZIP only (without uploading):
```bash
npm run build
```

Setup/configure deployment:
```bash
npm run setup:deploy
```

### PowerShell Version (Windows)

From the `build/deploy/` folder:

Deploy to test environment:
```powershell
.\deploy.ps1 -Environment test
```

Deploy to live environment:
```powershell
.\deploy.ps1 -Environment live
```

Build ZIP only:
```powershell
.\deploy.ps1 -Environment test -BuildOnbuild/deploy/ly
```

## What Gets Deployed

✅ **Deployed:** Everything in the `public` folder (HTML, CSS, JS, assets, includes, etc.)

❌ **Never Deployed:** Files listed in `.deployignore`
- `config.php` (keep server-specific config)
- `cron_import_log.txt` (keep local logs)
- `node_modules`, `.git`, and other development files

## Deployment Flow

1. **ZIP Creation**: The script reads all files in `public/`, excluding patterns in `.deployignore`
2. **FTP Upload**: Uploads the ZIP with timestamp (e.g., `public-test-2026-01-20.zip`)
3. **Manual Extraction**: SSH into server and manually extract/deploy

## Manual Server Steps

After the ZIP is uploaded, SSH into your server and:

```bash
cd /path/to/root
unzip public-test-2026-01-20.zip
mv public/* ./
rm -rf public public-test-2026-01-20.zip
```

## Two Environment Setup

- **Test**: `hpovlsen.dk` → `FTP_ROOT_TEST`
- **Live**: `formula-1.dk` → `FTP_ROOT_LIVE`

Both use the same FTP server and credentials, but different root paths.

## Security Notes

- **Never commit `.env`** to git (it's already in `.gitignore`)
- **Keep `config.php` on server** - don't deploy it
- Use `.deployignore` to exclude sensitive/local files

## Troubleshooting

### FTP Connection Issues
- Verify `FTP_HOST`, `FTP_USER`, `FTP_PASS` in `.env`
- Check firewall/FTP port access
- Try `DRY_RUN=true` to test configuration

### ZIP File Size
- Too large? Check what's being included with verbose output
- Consider compressing assets or using CDN

### PowerShell Script Won't Run
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

## Development

To modify deployment patterns, edit `build/deploy/.deployignore`:
```
config.php
cron_import_log.txt
.git
node_modules
```

One pattern per line. Lines starting with `#` are comments.
