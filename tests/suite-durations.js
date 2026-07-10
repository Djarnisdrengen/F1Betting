'use strict';
// Measured baseline wall-clock time per suite, standalone `npm run test:e2e:<suite>` (seconds).
// Used only to print a rough "expect ~Xs" estimate at the start of a run — not a budget or an
// assertion. Update after materially adding/removing tests in a suite; see the Suites table in
// docs/testing.md for how these were measured.
module.exports = {
    smoke: 9,
    auth: 99,
    registration: 4,
    predictions: 3,
    scoring: 6,
    'race-page': 3,
    admin: 8,
    profile: 9,
    appearance: 9,
    'preferences-editor': 10,
    cron: 5,
    mobile: 2,
};
