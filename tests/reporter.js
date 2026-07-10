const SUITE_DURATIONS = require("./suite-durations");
const { formatDuration } = require("./format-duration");

class CustomReporter {
    constructor() {
        this._passed = 0;
        this._failed = 0;
        this._prevPath = [];
        this._total = 0;
        this._index = 0;
        this._env = process.env.DEPLOY_ENV || "test";
        this._startTime = 0;
    }

    _parsePath(test) {
        return test.titlePath().filter(s => s && !s.endsWith(".js"));
    }

    _printGroupHeaders(groups) {
        for (let i = 0; i < groups.length; i++) {
            if (groups[i] !== this._prevPath[i]) {
                if (i === 0) {
                    process.stdout.write(`${this._prevPath.length > 0 ? "\n" : ""}${groups[i]}\n`);
                } else {
                    process.stdout.write(`${"  ".repeat(i)}${groups[i]}\n`);
                }
                this._prevPath = groups.slice(0, i + 1);
            }
        }
        this._prevPath = groups;
    }

    onBegin(config, suite) {
        this._startTime = Date.now();
        const tests = suite.allTests();
        this._total = tests.length;
        // config.projects is the full static project list regardless of --project filtering —
        // the actually-selected project name lives on the tests themselves. titlePath() is
        // ["", projectName, fileName, ...describes, testTitle]; _parsePath drops the empty
        // root and the file segment, so its first element is the project name.
        const name = tests[0] ? this._parsePath(tests[0])[0] : "E2E";
        const estimate = SUITE_DURATIONS[name];
        const estimateNote = estimate ? ` — expect ~${formatDuration(estimate)}` : "";
        process.stdout.write(`🏎️  ${name} — env: ${this._env} — ${this._total} tests${estimateNote}\n`);
    }

    _progress() {
        const pct = this._total > 0 ? Math.round((this._index / this._total) * 100) : 0;
        return `[${this._index}/${this._total} · ${pct}%]`;
    }

    onTestBegin(test) {
        this._index++;
        const path = this._parsePath(test);
        const groups = path.slice(0, -1);
        const title = path[path.length - 1];
        this._printGroupHeaders(groups);
        process.stdout.write(`${"  ".repeat(groups.length)}${this._progress()} ⏳ ${title}`);
    }

    onTestEnd(test, result) {
        const path = this._parsePath(test);
        const indent = "  ".repeat(path.length - 1);
        const title = path[path.length - 1];
        const prefix = this._progress();

        const secs = (result.duration / 1000).toFixed(1) + "s";
        if (result.status === "passed") {
            this._passed++;
            process.stdout.write(`\r${indent}${prefix} ✅ ${title} (${secs})\n`);
        } else if (result.status === "skipped") {
            process.stdout.write(`\r${indent}${prefix} ⏭  ${title} (skipped)\n`);
        } else {
            this._failed++;
            const msg = result.error?.message?.split("\n")[0] || "failed";
            process.stdout.write(`\r${indent}${prefix} ❌ ${title} (${secs}) → ${msg}\n`);
        }
    }

    onEnd(result) {
        const total = this._passed + this._failed;
        const elapsed = formatDuration((Date.now() - this._startTime) / 1000);
        process.stdout.write("\n");
        if (result.status === 'timedout') {
            process.stdout.write(`🏁 ❌ E2E suite timed out — env: ${this._env} — ${elapsed} elapsed\n\n`);
        } else if (result.status === 'failed' && total === 0) {
            process.stdout.write(`🏁 ❌ E2E setup failed — no tests ran (check global-setup logs above) — env: ${this._env} — ${elapsed} elapsed\n\n`);
        } else if (total === 0) {
            // A project/tag filter that matched nothing still reports result.status "passed" —
            // never let that read as a clean pass (MUST-3: a mutating suite silently matching
            // zero tests, e.g. via a live-safety gate, must never look like "0/0 passed ✅").
            process.stdout.write(`🏁 ⚠️  0 tests matched — env: ${this._env} — check the --project/tag filter and DEPLOY_ENV\n\n`);
            process.exitCode = 1;
        } else if (this._failed > 0) {
            process.stdout.write(`🏁 ❌ E2E tests failed (${this._failed}/${total} failed) — env: ${this._env} — took ${elapsed} — screenshots saved to build-deploy/screenshots/\n\n`);
        } else {
            process.stdout.write(`🏁 ✅ E2E tests passed (${total}/${total}) — env: ${this._env} — took ${elapsed}\n\n`);
        }
    }
}

module.exports = CustomReporter;
