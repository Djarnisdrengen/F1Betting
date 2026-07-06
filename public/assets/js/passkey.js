// Passkey (WebAuthn) client flows for webauthn.php.
// Wired via data attributes: [data-passkey-add] (Security tab registration),
// [data-passkey-challenge] (second-factor challenge), [data-passkey-login]
// (passwordless login). Codecs are exported for the node --test unit suite.
(function () {
    'use strict';

    // base64url (server options) -> Uint8Array
    function b64uToBuf(s) {
        var b64 = String(s).replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) b64 += '=';
        var bin = (typeof atob === 'function') ? atob(b64) : Buffer.from(b64, 'base64').toString('binary');
        var buf = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
        return buf;
    }

    // ArrayBuffer/TypedArray -> standard base64 (endpoint uses strict base64_decode)
    function bufToB64(buf) {
        var bytes = buf instanceof Uint8Array ? buf : new Uint8Array(buf);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return (typeof btoa === 'function') ? btoa(bin) : Buffer.from(bin, 'binary').toString('base64');
    }

    function csrfToken() {
        var el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function post(action, fields) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', csrfToken());
        Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
        return fetch('/webauthn.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; });
    }

    // Reveal the nearest error note ([data-passkey-error] within the same
    // [data-passkey-scope], falling back to the first on the page).
    function fail(el) {
        var scope = (el && el.closest('[data-passkey-scope]')) || document;
        var err = scope.querySelector('[data-passkey-error]') || document.querySelector('[data-passkey-error]');
        if (err) err.hidden = false;
    }

    function register(btn) {
        post('register_options', {}).then(function (res) {
            if (!res || !res.options || !res.options.publicKey) return fail(btn);
            var pk = res.options.publicKey;
            pk.challenge = b64uToBuf(pk.challenge);
            pk.user.id = b64uToBuf(pk.user.id);
            (pk.excludeCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
            navigator.credentials.create({ publicKey: pk }).then(function (cred) {
                if (!cred) return fail(btn);
                var transports = '';
                if (cred.response.getTransports) {
                    try { transports = cred.response.getTransports().join(','); } catch (e) {}
                }
                post('register_verify', {
                    clientDataJSON: bufToB64(cred.response.clientDataJSON),
                    attestationObject: bufToB64(cred.response.attestationObject),
                    transports: transports
                }).then(function (v) {
                    // Reload so the new row — and, for a first factor, the
                    // show-once recovery-codes flash — render server-side.
                    if (v && v.ok) window.location.reload(); else fail(btn);
                });
            }).catch(function () { fail(btn); });
        });
    }

    function assertFlow(btn, optionsAction, verifyAction, extra) {
        post(optionsAction, {}).then(function (res) {
            if (!res || !res.options || !res.options.publicKey) return fail(btn);
            var pk = res.options.publicKey;
            pk.challenge = b64uToBuf(pk.challenge);
            (pk.allowCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
            navigator.credentials.get({ publicKey: pk }).then(function (cred) {
                if (!cred) return fail(btn);
                var fields = {
                    rawId: bufToB64(cred.rawId),
                    clientDataJSON: bufToB64(cred.response.clientDataJSON),
                    authenticatorData: bufToB64(cred.response.authenticatorData),
                    signature: bufToB64(cred.response.signature),
                    userHandle: cred.response.userHandle ? bufToB64(cred.response.userHandle) : ''
                };
                Object.keys(extra || {}).forEach(function (k) { fields[k] = extra[k]; });
                post(verifyAction, fields).then(function (v) {
                    if (v && v.ok && v.redirect) window.location.href = v.redirect;
                    else if (v && v.ok) window.location.reload();
                    else fail(btn);
                });
            }).catch(function () { fail(btn); });
        });
    }

    function init() {
        var supported = !!(window.PublicKeyCredential && navigator.credentials);
        if (!supported) {
            document.querySelectorAll('[data-passkey-supported]').forEach(function (el) { el.hidden = true; });
            document.querySelectorAll('[data-passkey-unsupported]').forEach(function (el) { el.hidden = false; });
            return;
        }
        document.addEventListener('click', function (e) {
            var add = e.target.closest('[data-passkey-add]');
            if (add) { e.preventDefault(); register(add); return; }
            var chal = e.target.closest('[data-passkey-challenge]');
            if (chal) { e.preventDefault(); assertFlow(chal, 'challenge_options', 'challenge_verify', {}); return; }
            var login = e.target.closest('[data-passkey-login]');
            if (login) {
                e.preventDefault();
                assertFlow(login, 'login_options', 'login_verify', { redirect: login.getAttribute('data-redirect') || '' });
            }
        });
    }

    var api = { b64uToBuf: b64uToBuf, bufToB64: bufToB64 };
    if (typeof window !== 'undefined' && typeof document !== 'undefined') {
        window.f1Passkey = api;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
