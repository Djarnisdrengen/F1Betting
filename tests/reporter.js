class CustomReporter {
    constructor() {
        this._passed = 0;
        this._failed = 0;
    }

    onTestEnd(test, result) {
        if (result.status === "passed") {
            this._passed++;
            console.log(`  ✅ ${test.title}`);
        } else {
            this._failed++;
            const msg = result.error?.message?.split("\n")[0] || "failed";
            console.log(`  ❌ ${test.title} → ${msg}`);
        }
    }

    onEnd() {
        const total = this._passed + this._failed;
        if (this._failed > 0) {
            console.log(`❌ E2E tests failed (${this._failed}/${total} failed) — screenshots saved to build-deploy/screenshots/\n`);
        } else {
            console.log(`✅ E2E tests passed (${total}/${total})\n`);
        }
    }
}

module.exports = CustomReporter;
