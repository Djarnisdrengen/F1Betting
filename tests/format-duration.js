'use strict';
// Shared by reporter.js and run-e2e-suites.js so per-suite and cross-suite timing reads the
// same way everywhere.
function formatDuration(seconds) {
    if (seconds < 60) return `${seconds.toFixed(1)}s`;
    const m = Math.floor(seconds / 60);
    const s = Math.round(seconds % 60);
    return `${m}m ${s}s`;
}

module.exports = { formatDuration };
