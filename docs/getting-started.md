# Getting Started

## Contents

- [Prerequisites](#prerequisites)
- [1. Clone the repository](#1-clone-the-repository)
- [2. Create your config files](#2-create-your-config-files)
- [3. Set up FTP credentials](#3-set-up-ftp-credentials)
- [4. Deploy to the test server](#4-deploy-to-the-test-server)
- [5. Run the tests](#5-run-the-tests)
- [Day-to-day workflow](#day-to-day-workflow)
- [VSCode Setup](#vscode-setup)
  - [Recommended extensions](#recommended-extensions)
  - [PHP CS Fixer](#php-cs-fixer)
  - [Playwright test runner](#playwright-test-runner)
  - [Recommended settings.json additions](#recommended-settingsjson-additions)
  - [PHP path](#php-path)
- [What you can skip](#what-you-can-skip)

---

Everything you need to go from a fresh clone to a working test environment.

## Prerequisites

| Tool | Minimum version | Notes |
|---|---|---|
| Git | any | |
| Node.js | 20 LTS | Needed for deploy scripts and tests |
| npm | bundled with Node | |
| PHP | 8.0+ | Only needed if you run PHP locally |
| MySQL | 8.0+ | Only needed for a local DB; test server has its own |
| FTP access | — | Credentials from Thomas |

If you only want to edit and deploy (no local PHP server), you only need Node and Git.

---

## 1. Clone the repository

```bash
git clone https://github.com/<org>/F1Betting.git
cd F1Betting
npm install
```

---

## 2. Create your config files

The config files contain real credentials and are never committed. Create both by copying the template:

```bash
cp config.example.php config.test.php
cp config.example.php config.live.php
```

Open each file and fill in the values for the matching environment. Ask Thomas for the actual credentials.

Key sections to fill:
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — MySQL connection for that environment
- `SITE_URL` — e.g. `https://www.hpovlsen.dk` for test
- `F1_ADMIN_EMAIL`, `F1_ADMIN_PASSWORD` — the service admin account
- `JWT_SECRET`, `PASSWORD_PEPPER` — random 32-hex strings (generate with `openssl rand -hex 32`)
- `INTEGRATION_SEED_TOKEN`, `CRON_SECRET` — any random secret strings
- `SMTP_*` — email server credentials

---

## 3. Set up FTP credentials

```bash
npm run setup:deploy
```

This runs an interactive prompt that writes `build-deploy/.env` with FTP connection details. Ask Thomas for the FTP host, user, and password.

---

## 4. Deploy to the test server

```bash
npm run deploy:test
```

This uploads everything in `public/` plus `config.shared.php` and `config.test.php` (renamed to `config.php` on the server) via FTP, then runs HTTP smoke tests to confirm the site is up.

---

## 5. Run the tests

```bash
npm run test:e2e:test        # Playwright browser tests (smoke + admin)
npm run test:integration     # Seeded integration tests
npm run test:security        # OWASP security scan
```

See [Testing](testing.md) for what each suite does.

---

## Day-to-day workflow

```
edit code → git commit → npm run deploy:test → verify on hpovlsen.dk → npm run deploy:live
```

Never deploy to live without confirming test is working first.

---

## VSCode Setup

### Recommended extensions

Install these from the Extensions panel (`Ctrl+Shift+X`):

| Extension | Publisher | Why |
|---|---|---|
| PHP Intelephense | bmewburn | Go-to-definition, type hints, hover docs |
| Playwright Test for VSCode | ms-playwright | Run/debug individual Playwright tests |
| ESLint | microsoft | JS linting |
| EditorConfig for VS Code | editorconfig | Consistent indentation |
| PHP CS Fixer | junstyle | Auto-format PHP on save |
| GitLens | gitkraken | Inline blame, branch history |
| DotENV | mikestead | Syntax highlighting for .env files |

### PHP CS Fixer

The repo ships with `.php-cs-fixer.php` configured for PSR-12. To auto-format on save, add this to your VSCode `settings.json`:

```json
{
  "[php]": {
    "editor.defaultFormatter": "junstyle.php-cs-fixer",
    "editor.formatOnSave": true
  },
  "php-cs-fixer.config": "${workspaceFolder}/.php-cs-fixer.php"
}
```

### Playwright test runner

Once the Playwright extension is installed, a test beaker icon appears in the activity bar. You can run or debug individual specs without the terminal. It reads `tests/playwright.config.js` automatically.

Make sure `DEPLOY_ENV=test` is set in your terminal or in a `.env.test` before running tests from the UI.

### Recommended `settings.json` additions

```json
{
  "files.associations": {
    "*.php": "php"
  },
  "editor.tabSize": 4,
  "editor.insertSpaces": true,
  "php.validate.executablePath": "/usr/bin/php"
}
```

### PHP path

Find your PHP binary:
```bash
which php    # Linux/Mac
where php    # Windows
```

Set `php.validate.executablePath` to that path in settings.

---

## What you can skip

- **Local MySQL** — you can develop and deploy without a local DB. The test server has its own database.
- **Local PHP server** — same as above. Edit → deploy → verify on hpovlsen.dk.
- **Local Apache** — the `.htaccess` file only matters on the real server.
