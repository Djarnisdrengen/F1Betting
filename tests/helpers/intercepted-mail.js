'use strict';

const BASE_URL   = () => process.env.BASE_URL;
const SEED_TOKEN = () => process.env.INTEGRATION_SEED_TOKEN;

async function fetchAll() {
    const url = `${BASE_URL()}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN())}&action=get_test_emails`;
    const res = await fetch(url);
    if (!res.ok) throw new Error(`get_test_emails failed: ${res.status}`);
    const all = await res.json();
    // _id = global array index; stable within a run, used as baseline by waitForNewMessages
    return all.map((m, i) => ({ ...m, _id: String(i) }));
}

async function getMessages(inbox) {
    return (await fetchAll()).filter(m => m.to === inbox);
}

async function purgeInbox() {
    const url = `${BASE_URL()}/tools/test-seed.php?token=${encodeURIComponent(SEED_TOKEN())}&action=clear_test_emails`;
    const res = await fetch(url);
    if (!res.ok) throw new Error(`clear_test_emails failed: ${res.status}`);
}

async function waitForMessages(inbox, expected, _apiKey, { timeout = 30000, interval = 2000 } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        const msgs = await getMessages(inbox);
        if (msgs.length >= expected) return msgs;
        await new Promise(r => setTimeout(r, interval));
    }
    throw new Error(`Timed out waiting for ${expected} message(s) in ${inbox}`);
}

async function waitForNewMessages(inbox, baselineIds, expected, _apiKey, { timeout = 30000, interval = 2000 } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        const msgs = await getMessages(inbox);
        const fresh = msgs.filter(m => !baselineIds.has(m._id));
        if (fresh.length >= expected) return fresh;
        await new Promise(r => setTimeout(r, interval));
    }
    throw new Error(`Timed out waiting for ${expected} new message(s) in ${inbox}`);
}

async function getEmailBody(inbox, messageId) {
    const all = await fetchAll();
    const msg = all[parseInt(messageId, 10)];
    return msg ? (msg.text || msg.html || '') : '';
}

async function assertDelivered(inbox, _apiKey, { count = 1, timeout = 20000 } = {}) {
    return waitForMessages(inbox, count, null, { timeout });
}

module.exports = { getMessages, purgeInbox, waitForMessages, waitForNewMessages, getEmailBody, assertDelivered };
