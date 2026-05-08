class CustomReporter {
    constructor() {
        this._passed = 0;
        this._failed = 0;
        this._prevPath = [];
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

    onTestBegin(test) {
        const path = this._parsePath(test);
        const groups = path.slice(0, -1);
        const title = path[path.length - 1];
        this._printGroupHeaders(groups);
        process.stdout.write(`${"  ".repeat(groups.length)}⏳ ${title}`);
    }

    onTestEnd(test, result) {
        const path = this._parsePath(test);
        const indent = "  ".repeat(path.length - 1);
        const title = path[path.length - 1];

        if (result.status === "passed") {
            this._passed++;
            process.stdout.write(`\r${indent}✅ ${title}\n`);
        } else {
            this._failed++;
            const msg = result.error?.message?.split("\n")[0] || "failed";
            process.stdout.write(`\r${indent}❌ ${title} → ${msg}\n`);
        }
    }

    onEnd() {
        const total = this._passed + this._failed;
        process.stdout.write("\n");
        if (this._failed > 0) {
            process.stdout.write(`❌ E2E tests failed (${this._failed}/${total} failed) — screenshots saved to build-deploy/screenshots/\n\n`);
        } else {
            process.stdout.write(`✅ E2E tests passed (${total}/${total})\n\n`);
        }
    }
}

module.exports = CustomReporter;
