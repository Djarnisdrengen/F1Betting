'use strict';

const path = require('path');
const { test: base, expect } = require('@playwright/test');

const ADMIN_AUTH = path.join(__dirname, '../../.auth/admin.json');

const test = base.extend({
    storageState: async ({}, use) => {
        await use(ADMIN_AUTH);
    },
});

module.exports = { test, expect };
