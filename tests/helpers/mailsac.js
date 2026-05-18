'use strict';

const API = 'https://mailsac.com/api';

function headers(apiKey) {
    return { 'Mailsac-Key': apiKey };
}

async function getMessages(inbox, apiKey) {
    const res = await fetch(`${API}/addresses/${encodeURIComponent(inbox)}/messages`, {
        headers: headers(apiKey),
    });
    if (!res.ok) throw new Error(`Mailsac list failed: ${res.status}`);
    return res.json();
}

// Deletes all messages in inbox. Requires the inbox to be owned (Mailsac Indie Plan+).
async function purgeInbox(inbox, apiKey) {
    const res = await fetch(`${API}/addresses/${encodeURIComponent(inbox)}/messages`, {
        method: 'DELETE',
        headers: headers(apiKey),
    });
    if (!res.ok) throw new Error(`Mailsac purge failed: ${res.status}`);
}

// Polls until `expected` messages are in inbox, then returns them.
// Use after purgeInbox() so there is no baseline to track.
async function waitForMessages(inbox, expected, apiKey, { timeout = 60000, interval = 3000 } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        const messages = await getMessages(inbox, apiKey);
        if (messages.length >= expected) return messages;
        await new Promise(r => setTimeout(r, interval));
    }
    throw new Error(`Mailsac: timed out waiting for ${expected} messages in ${inbox}`);
}

// Polls until `expected` new messages arrive (relative to `baselineIds`), then returns them.
// Use when purge is not available (e.g. non-owned inbox).
async function waitForNewMessages(inbox, baselineIds, expected, apiKey, { timeout = 60000, interval = 3000 } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        const messages = await getMessages(inbox, apiKey);
        const fresh = messages.filter(m => !baselineIds.has(m._id));
        if (fresh.length >= expected) return fresh;
        await new Promise(r => setTimeout(r, interval));
    }
    throw new Error(`Mailsac: timed out waiting for ${expected} new messages in ${inbox}`);
}

// Returns the plain-text body (not /body which strips external links for safety).
async function getEmailBody(inbox, messageId, apiKey) {
    const res = await fetch(`${API}/text/${encodeURIComponent(inbox)}/${encodeURIComponent(messageId)}`, {
        headers: headers(apiKey),
    });
    if (!res.ok) throw new Error(`Mailsac body fetch failed: ${res.status}`);
    return res.text();
}

// Waits for `count` messages to arrive in `inbox`, asserts each came from `fromDomain`.
// Returns [] silently if apiKey is falsy (clean skip in CI without Mailsac configured).
// Use after purgeInbox() so there is no baseline to track.
async function assertDelivered(inbox, apiKey, { count = 1, timeout = 20000, fromDomain = 'formula-1.dk' } = {}) {
    if (!apiKey) return [];
    const msgs = await waitForMessages(inbox, count, apiKey, { timeout });
    for (const msg of msgs) {
        const addresses = (msg.from || []).map(f => f.address || '').join(' ');
        if (!addresses.includes(fromDomain)) {
            throw new Error(`Mailsac: message in ${inbox} not from ${fromDomain} — got: ${addresses}`);
        }
    }
    return msgs;
}

module.exports = { getMessages, purgeInbox, waitForMessages, waitForNewMessages, getEmailBody, assertDelivered };
