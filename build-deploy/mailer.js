'use strict';

/**
 * sendEmail(primary, fallback, envelope) → 'primary' | 'fallback'
 *
 * Calls primary first. If it throws, calls fallback (when provided).
 * Throws if both fail, or if primary fails and no fallback is given.
 *
 * primary / fallback are async fn({ from, to, subject, html, text }).
 * Pass null for fallback to run primary-only.
 */
async function sendEmail(primary, fallback, envelope) {
    let primaryErr;
    try {
        await primary(envelope);
        console.log('[mailer] Sent via primary (SMTP)');
        return 'primary';
    } catch (err) {
        primaryErr = err;
        console.warn('[mailer] Primary (SMTP) failed:', err.message);
    }

    if (!fallback) throw primaryErr;

    try {
        await fallback(envelope);
        console.log('[mailer] Sent via fallback (Resend)');
        return 'fallback';
    } catch (err) {
        throw new Error(`Both transports failed. Primary: ${primaryErr.message}. Fallback: ${err.message}`);
    }
}

function makeSmtpSender({ host, port, user, pass }) {
    return async ({ from, to, subject, html, text }) => {
        const nodemailer = require('nodemailer');
        const transporter = nodemailer.createTransport({
            host,
            port,
            secure: port === 465,
            auth: { user, pass },
        });
        await transporter.sendMail({ from, to, subject, html, text });
    };
}

function makeResendSender({ apiKey }) {
    return async ({ from, to, subject, html }) => {
        const { Resend } = require('resend');
        const resend = new Resend(apiKey);
        await resend.emails.send({ from, to, subject, html });
    };
}

module.exports = { sendEmail, makeSmtpSender, makeResendSender };
