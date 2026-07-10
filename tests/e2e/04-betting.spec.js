const { test, expect } = require("@playwright/test");
const { waitForMessages } = require("../helpers/email");

const SEED_TOKEN = process.env.INTEGRATION_SEED_TOKEN;
let seedData;
let sharedContext;
let sharedPage;

// Last name is the stable part: seeding matches existing drivers by last name,
// so the full name in the email comes from the DB and may differ from seedData.
const lastName = (driver) => driver.name.split(" ").pop();

// e.g. "Registreret på www.hpovlsen.dk: 05 Jul 2026 - 14:32 CET"
const TIMESTAMP_RE = /\d{2} [A-Za-z]{3} \d{4} - \d{2}:\d{2} CET/;

function assertConfirmationBody(body, driversInOrder) {
    const domain = new URL(process.env.BASE_URL).hostname.replace(/^www\./, "");
    expect(body).toContain(domain);
    expect(body).toMatch(TIMESTAMP_RE);

    const positions = driversInOrder.map((d, i) => {
        const re = new RegExp(`P${i + 1}: [^\\n]*${lastName(d)}`);
        const m = body.match(re);
        expect(m, `expected ${re} in:\n${body}`).not.toBeNull();
        return m.index;
    });
    expect(positions[0]).toBeLessThan(positions[1]);
    expect(positions[1]).toBeLessThan(positions[2]);
}

test.describe.serial("Betting", { tag: "@predictions" }, () => {
    test.beforeAll(async ({ browser }) => {
        // Idempotent cleanup in case a previous run left state
        const cleanPage = await browser.newPage();
        await cleanPage.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_betting_race`
        );
        await cleanPage.close();

        const page = await browser.newPage();
        const res = await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=seed_betting_race`
        );
        expect(res.status()).toBe(200);
        seedData = JSON.parse(await page.textContent("body"));
        expect(seedData.ok).toBe(true);
        expect(seedData.drivers).toHaveLength(3);
        await page.close();

        // Log in once — all tests reuse this session
        sharedContext = await browser.newContext();
        sharedPage    = await sharedContext.newPage();
        await sharedPage.goto("/login.php");
        await sharedPage.fill('input[name="email"]',    seedData.email);
        await sharedPage.fill('input[name="password"]', seedData.password);
        await sharedPage.click('button[type="submit"]');
        await sharedPage.waitForURL(/index\.php/, { timeout: 5000 });
    });

    test.afterAll(async ({ browser }) => {
        await sharedContext?.close();
        const page = await browser.newPage();
        await page.goto(
            `${process.env.BASE_URL}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN)}&action=cleanup_betting_race`
        );
        await page.close();
    });

    test("place a bet", async () => {
        await sharedPage.goto(`/bet.php?race=${seedData.raceId}&return=index`);
        await expect(sharedPage.locator('.hf-modal-overlay')).toBeVisible();

        await sharedPage.selectOption('select[name="p1"]', seedData.drivers[0].id);
        await sharedPage.selectOption('select[name="p2"]', seedData.drivers[1].id);
        await sharedPage.selectOption('select[name="p3"]', seedData.drivers[2].id);
        await sharedPage.click('#save-btn');

        await sharedPage.waitForURL(/success=bet_placed/);
        await expect(sharedPage.locator(".alert-success")).toBeVisible();
    });

    test("bet confirmation email contains domain, race, picks in order and timestamp", async () => {
        const msgs = await waitForMessages(seedData.email, 1, null, { timeout: 20000 });
        const msg  = msgs.find(m => /Bet (bekræftet|confirmed)/.test(m.subject));
        expect(msg, `No placed-confirmation mail among: ${msgs.map(m => m.subject).join(" | ")}`).toBeTruthy();
        expect(msg.subject).toContain("E2E Open Race");
        assertConfirmationBody(msg.text || msg.html, [seedData.drivers[0], seedData.drivers[1], seedData.drivers[2]]);
    });

    test("attempting to bet again redirects with already_bet error", async () => {
        await sharedPage.goto(`/bet.php?race=${seedData.raceId}&return=index`);
        await sharedPage.waitForURL(/already_bet/);
    });

    test("edit a bet", async () => {
        await sharedPage.goto("/");

        const editLink = sharedPage.locator('a[href*="edit_bet.php"]').first();
        await expect(editLink).toBeVisible();
        await editLink.click();
        await sharedPage.waitForURL(/edit_bet\.php/);

        await sharedPage.selectOption('select[name="p1"]', seedData.drivers[2].id);
        await sharedPage.selectOption('select[name="p2"]', seedData.drivers[1].id);
        await sharedPage.selectOption('select[name="p3"]', seedData.drivers[0].id);
        await sharedPage.click('#save-btn');

        await sharedPage.waitForURL(/success=bet_updated/);
        await expect(sharedPage.locator(".alert-success")).toBeVisible();
    });

    test("update confirmation email contains domain, race, new picks in order and timestamp", async () => {
        const msgs = await waitForMessages(seedData.email, 2, null, { timeout: 20000 });
        const msg  = msgs.find(m => /Bet (opdateret|updated)/.test(m.subject));
        expect(msg, `No updated-confirmation mail among: ${msgs.map(m => m.subject).join(" | ")}`).toBeTruthy();
        expect(msg.subject).toContain("E2E Open Race");
        // Edit swapped P1 and P3
        assertConfirmationBody(msg.text || msg.html, [seedData.drivers[2], seedData.drivers[1], seedData.drivers[0]]);
    });
});
