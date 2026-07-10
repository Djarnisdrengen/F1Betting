'use strict';
// Full-run orchestrator: runs each UX-oriented suite (see
// epics/Optimize test suite structure/epic-e2e-test-restructure.md for the taxonomy) as its
// own `npx playwright test --project=<slug>` child process, awaited strictly sequentially —
// a hard non-interleave guarantee that a single multi-project Playwright invocation can't give
// alongside independent per-suite pass/fail and cross-suite progress reporting.
const { spawn, execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const os = require('os');
const SUITE_DURATIONS = require('./suite-durations');
const { formatDuration } = require('./format-duration');

const REPO_ROOT = path.join(__dirname, '..');
const CONFIG = path.join(__dirname, 'playwright.config.js');

// Fixed literal order — fail-fast: smoke first, auth second (docs/test-strategy.md principle
// 8). Not alphabetical, not directory-discovery order, so it can't silently drift.
const SUITE_ORDER = [
    ['smoke', 'Smoke & Platform Health'],
    ['auth', 'Authentication'],
    ['registration', 'Invites & Registration'],
    ['predictions', 'Podium Predictions'],
    ['scoring', 'Auto-Scoring & Leaderboard'],
    ['race-page', 'Race Page & Results Display'],
    ['admin', 'Race & Content Admin Management'],
    ['profile', 'Profile & Stats'],
    ['appearance', 'Theme & Appearance Persistence'],
    ['preferences-editor', 'Preferences Editor'],
    ['cron', 'Notifications & Cron Jobs'],
];

const env = process.env.DEPLOY_ENV || 'test';
const isLive = env === 'live';

// Optional, comma-separated suite slugs to leave out of this run (e.g. `E2E_SKIP_SUITES=auth`
// for a faster local loop that skips Authentication's ~99s of serial tests). Purely a run-scope
// choice — never affects the orphan/drift check, which always validates the full taxonomy.
const skipSlugs = (process.env.E2E_SKIP_SUITES || '').split(',').map(s => s.trim()).filter(Boolean);
for (const slug of skipSlugs) {
    if (!SUITE_ORDER.some(([s]) => s === slug)) {
        console.warn(`⚠️  E2E_SKIP_SUITES: "${slug}" isn't a known suite slug — ignoring it.`);
    }
}

function countTests(suites) {
    let n = 0;
    for (const suite of suites || []) {
        for (const spec of suite.specs || []) n += (spec.tests || []).length;
        n += countTests(suite.suites);
    }
    return n;
}

// Fast, no globalSetup/globalTeardown involved — `--list` is pure static discovery.
function listCount(projectName) {
    const out = execFileSync('npx', [
        'playwright', 'test', '--list', '--reporter=json',
        `--project=${projectName}`, '--config', CONFIG,
    ], { cwd: REPO_ROOT, env: { ...process.env, DEPLOY_ENV: env }, encoding: 'utf8' });
    return countTests(JSON.parse(out).suites);
}

// MUST-1: an untagged spec or a typo'd tag (`@predicitons`) must never silently vanish, and a
// double-tagged test must never silently inflate the count. Diff "raw testDir glob" (the `all`
// project, no grep) against "sum across the 11 primary project greps" and fail loudly on any
// mismatch, before running a single leg.
function orphanCheck() {
    const raw = listCount('all');
    const bySuite = SUITE_ORDER.map(([slug]) => [slug, listCount(slug)]);
    const sum = bySuite.reduce((acc, [, n]) => acc + n, 0);
    if (sum !== raw) {
        console.error(`❌ Orphan/drift check failed: raw testDir glob has ${raw} tests, but the`);
        console.error(`   11 primary suite tags sum to ${sum}. Per-suite counts:`);
        for (const [slug, n] of bySuite) console.error(`     ${slug}: ${n}`);
        console.error('   An untagged spec, a typo\'d tag, or a double-tagged test is likely.');
        return null;
    }
    console.log(`✅ Orphan/drift check passed — ${raw} tests, tags and testDir glob agree.\n`);
    return { raw, bySuite };
}

function runLeg(slug, index, total, jsonOutput) {
    return new Promise(resolve => {
        const child = spawn('npx', [
            'playwright', 'test', `--project=${slug}`, '--config', CONFIG,
        ], {
            cwd: REPO_ROOT,
            stdio: 'inherit',
            env: {
                ...process.env,
                DEPLOY_ENV: env,
                E2E_SUITE_LEG: String(index + 1),
                E2E_SUITE_TOTAL: String(total),
                E2E_LEG_JSON_OUTPUT: jsonOutput,
            },
        });
        child.on('close', (code, signal) => resolve({ code, signal }));
        child.on('error', () => resolve({ code: null, signal: 'ENOENT' }));
    });
}

// Distinguishes "child crashed" (no/invalid JSON — the leg never got far enough to report, or
// was killed) from "tests failed" (valid JSON, real failures) — never exit code alone (MUST-2).
function classifyLeg(jsonOutput, closeResult) {
    let parsed;
    try {
        parsed = JSON.parse(fs.readFileSync(jsonOutput, 'utf8'));
    } catch {
        return { crashed: true, reason: closeResult.signal ? `killed (${closeResult.signal})` : `exit ${closeResult.code}, no report` };
    }
    const stats = parsed.stats || {};
    const failed = (stats.unexpected || 0) > 0 || (parsed.errors || []).length > 0;
    return { crashed: false, failed, stats };
}

async function forceInterceptOff() {
    if (isLive) return;
    try {
        const { readPhpConfig } = require('../build-deploy/php-config');
        const cfg = readPhpConfig(env);
        const baseUrl = process.env.BASE_URL || cfg.siteUrl;
        const token = process.env.INTEGRATION_SEED_TOKEN || cfg.integrationSeedToken;
        await fetch(`${baseUrl}/tools/test-seed.php?token=${encodeURIComponent(token)}&action=smtp_intercept_off`);
        console.log('[orchestrator] Interception disabled — real delivery restored');
    } catch {
        // best-effort — mirrors global-teardown.js's own tolerance
    }
}

async function main() {
    const runStart = Date.now();
    const legs = isLive ? [SUITE_ORDER[0]] : SUITE_ORDER.filter(([slug]) => !skipSlugs.includes(slug));
    const liveSkipped = isLive ? SUITE_ORDER.slice(1) : [];
    const explicitlySkipped = isLive ? [] : SUITE_ORDER.filter(([slug]) => skipSlugs.includes(slug));

    const estimatedTotal = legs.reduce((acc, [slug]) => acc + (SUITE_DURATIONS[slug] || 0), 0);
    if (estimatedTotal > 0) {
        console.log(`⏱  Estimated total: ~${formatDuration(estimatedTotal)} (measured baseline, not a budget)\n`);
    }

    if (liveSkipped.length) {
        console.log('🔒 DEPLOY_ENV=live — only the smoke suite is live-safe (see epic\'s Resolved');
        console.log('   Contradiction #3). Skipping the rest rather than letting them silently');
        console.log('   resolve to "0 tests matched":');
        for (const [slug, name] of liveSkipped) console.log(`     ⏭  ${slug} — ${name}`);
        console.log('');
    }
    if (explicitlySkipped.length) {
        console.log('⏭  Skipping (E2E_SKIP_SUITES):');
        for (const [slug, name] of explicitlySkipped) console.log(`     ⏭  ${slug} — ${name}`);
        console.log('');
    }

    let baseline = null;
    if (!isLive) {
        baseline = orphanCheck();
        if (!baseline) {
            process.exitCode = 1;
            return;
        }
    }
    const countBySlug = new Map(baseline?.bySuite || []);
    // Scoped to the legs actually running this time (not the full 175) — so cumulative % means
    // "progress through this run," not "progress through the full taxonomy minus what we skipped."
    const grandTotal = baseline ? legs.reduce((acc, [slug]) => acc + (countBySlug.get(slug) || 0), 0) : null;

    const results = [];
    try {
        let cumulative = 0;
        for (let i = 0; i < legs.length; i++) {
            const [slug, name] = legs[i];
            const leftLabel = grandTotal !== null
                ? `cumulative ${cumulative}/${grandTotal} (${Math.round((cumulative / grandTotal) * 100)}%)`
                : '';
            const estimate = SUITE_DURATIONS[slug];
            const estimateLabel = estimate ? ` — expect ~${formatDuration(estimate)}` : '';
            console.log(`🏎️  Suite ${i + 1} of ${legs.length} — ${name}${leftLabel ? ` — ${leftLabel}` : ''}${estimateLabel}`);

            const jsonOutput = path.join(os.tmpdir(), `e2e-leg-${slug}-${process.pid}-${Date.now()}.json`);
            const legStart = Date.now();
            const closeResult = await runLeg(slug, i, legs.length, jsonOutput);
            const outcome = classifyLeg(jsonOutput, closeResult);
            fs.rmSync(jsonOutput, { force: true });
            // Prefer Playwright's own stats.duration (ms, test-execution time only) when the leg
            // reported one; fall back to wall-clock (covers a crash, where stats never got written).
            const legSeconds = (outcome.stats?.duration ?? (Date.now() - legStart)) / 1000;

            results.push({ slug, name, ...outcome, seconds: legSeconds });
            cumulative += countBySlug.get(slug) ?? outcome.stats?.expected ?? 0;

            if (outcome.crashed) {
                console.log(`🏁 💥 ${name} (${slug}) CRASHED — ${outcome.reason} — took ${formatDuration(legSeconds)}\n`);
            } else if (outcome.failed) {
                console.log(`🏁 ❌ ${name} (${slug}) had failing tests — took ${formatDuration(legSeconds)}\n`);
            } else {
                console.log(`🏁 ✅ ${name} (${slug}) passed — took ${formatDuration(legSeconds)}\n`);
            }
        }
    } finally {
        await forceInterceptOff();
    }

    const crashedLegs = results.filter(r => r.crashed);
    const failedLegs = results.filter(r => !r.crashed && r.failed);
    const totalElapsed = (Date.now() - runStart) / 1000;

    const skipNote = liveSkipped.length ? `, ${liveSkipped.length} skipped (live-safety)`
        : explicitlySkipped.length ? `, ${explicitlySkipped.length} skipped (E2E_SKIP_SUITES)`
        : '';
    console.log('─'.repeat(60));
    console.log(`E2E full run — env: ${env} — ${results.length} suite(s) run${skipNote}`);
    for (const r of results) {
        const mark = r.crashed ? '💥 CRASHED' : r.failed ? '❌ FAILED' : '✅ passed';
        console.log(`  ${mark} — ${r.name} (${r.slug}) — ${formatDuration(r.seconds)}`);
    }

    if (crashedLegs.length || failedLegs.length) {
        console.log(`\n❌ ${crashedLegs.length} crashed, ${failedLegs.length} failed out of ${results.length} suites — total time ${formatDuration(totalElapsed)}.`);
        process.exitCode = 1;
    } else {
        console.log(`\n✅ All ${results.length} suites passed — total time ${formatDuration(totalElapsed)}.`);
    }
}

main();
