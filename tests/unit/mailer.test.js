'use strict';
const { test } = require('node:test');
const assert   = require('node:assert/strict');
const { sendEmail } = require('../../build-deploy/mailer');

const envelope = {
    from:    'sender@example.com',
    to:      'recipient@example.com',
    subject: 'Test',
    html:    '<p>Test</p>',
    text:    'Test',
};

test('sends via SMTP when primary succeeds', async () => {
    const primary  = async () => {};
    const fallback = async () => { throw new Error('fallback must not be called'); };
    assert.strictEqual(await sendEmail(primary, fallback, envelope), 'primary');
});

test('falls back to Resend when SMTP fails', async () => {
    let fallbackCalled = false;
    const primary  = async () => { throw new Error('SMTP connection refused'); };
    const fallback = async () => { fallbackCalled = true; };
    assert.strictEqual(await sendEmail(primary, fallback, envelope), 'fallback');
    assert.strictEqual(fallbackCalled, true);
});

test('throws when both transports fail', async () => {
    const primary  = async () => { throw new Error('SMTP failed'); };
    const fallback = async () => { throw new Error('Resend failed'); };
    await assert.rejects(sendEmail(primary, fallback, envelope), /Both transports failed/);
});

test('throws primary error when no fallback is configured', async () => {
    const primary = async () => { throw new Error('SMTP failed'); };
    await assert.rejects(sendEmail(primary, null, envelope), /SMTP failed/);
});
