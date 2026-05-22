# F1 Betting

A Formula 1 prediction game where players pick the top-3 podium finishers for each Grand Prix and earn points for correct predictions. Supports two environments (test / live), bilingual UI (Danish / English), dark/light themes, and a full automated test + deploy pipeline.

## Documentation

All documentation lives in [`docs/`](docs/).

| Document | What it covers |
|---|---|
| [Architecture](docs/architecture.md) | Repo layout, database schema, config system, request lifecycle |
| [Getting Started](docs/getting-started.md) | Clone → first deploy in one go, prerequisites, VSCode setup |
| [Deploy from Scratch](docs/deploy-from-scratch.md) | Fresh server setup: database, config files, first upload |
| [Deployment](docs/deployment.md) | Day-to-day deploy workflow, backup, rollback, DB sync |
| [Testing](docs/testing.md) | Smoke, E2E, integration, and security tests |
| [GitHub Actions](docs/github-actions.md) | Nightly CI setup, required variables and secrets |
| [Cron Jobs](docs/cron-jobs.md) | Qualifying import, email notifications, server setup |
| [Patterns & Best Practices](docs/patterns.md) | Code conventions used throughout the project |
| [Common Gotchas](docs/gotchas.md) | Known traps that catch new developers |
| [Command Reference](docs/commands.md) | Every terminal command in one printable table |
| [Test Strategy](docs/test-strategy.md) | Testing philosophy, stack architecture, how to add new tests |
| [Security Review Log](docs/security-review-log.md) | Monthly security review findings and actions |

## Quick links

```
npm run deploy:test       # upload to test server + run smoke tests
npm run deploy:live       # upload to live (requires YES confirmation)
npm run test:e2e:test     # Playwright E2E against test
npm run test:security     # OWASP security scan against test
```

Start with [Getting Started](docs/getting-started.md) if you are new to this project.
