const { test, expect } = require('@playwright/test');
const { sendEmail } = require('../../build-deploy/mailer');

const envelope = {
    from: 'sender@example.com',
    to: 'recipient@example.com',
    subject: 'Test',
    html: '<p>Test</p>',
    text: 'Test',
};

test.describe('Nightly report mailer', () => {
    test('sends via SMTP when primary succeeds', async () => {
        const primary  = async () => {};
        const fallback = async () => { throw new Error('fallback must not be called'); };
        const result = await sendEmail(primary, fallback, envelope);
        expect(result).toBe('primary');
    });

    test('falls back to Resend when SMTP fails', async () => {
        let fallbackCalled = false;
        const primary  = async () => { throw new Error('SMTP connection refused'); };
        const fallback = async () => { fallbackCalled = true; };
        const result = await sendEmail(primary, fallback, envelope);
        expect(result).toBe('fallback');
        expect(fallbackCalled).toBe(true);
    });

    test('throws when both transports fail', async () => {
        const primary  = async () => { throw new Error('SMTP failed'); };
        const fallback = async () => { throw new Error('Resend failed'); };
        await expect(sendEmail(primary, fallback, envelope))
            .rejects.toThrow('Both transports failed');
    });

    test('throws primary error when no fallback is configured', async () => {
        const primary = async () => { throw new Error('SMTP failed'); };
        await expect(sendEmail(primary, null, envelope))
            .rejects.toThrow('SMTP failed');
    });
});
