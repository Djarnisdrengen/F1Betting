'use strict';
// Renders an authenticator QR from the otpauth:// URI exposed on .hf-qr[data-otpauth].
// Progressive enhancement: if qrcode.min.js failed to load, the manual key and the
// otpauth link in the markup remain fully usable.
(function () {
    function render() {
        if (typeof QRCode === 'undefined') return;
        document.querySelectorAll('.hf-qr[data-otpauth]').forEach(function (el) {
            var uri = el.getAttribute('data-otpauth');
            if (!uri || el.dataset.rendered) return;
            el.dataset.rendered = '1';
            new QRCode(el, {
                text: uri,
                width: 180,
                height: 180,
                colorDark: '#000000',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        });
    }
    // Recovery-code reveal: copy / download / dismiss. The codes are shown once and this panel
    // persists (it is deliberately NOT an .alert, which app.js auto-hides) until the user dismisses it.
    function initRecovery() {
        var panel = document.querySelector('.hf-recovery-reveal');
        if (!panel) return;
        var pre = panel.querySelector('[data-recovery-codes]');
        var codes = pre ? pre.textContent.trim() : '';
        var copied = panel.querySelector('[data-recovery-copied]');

        var copyBtn = panel.querySelector('[data-recovery-copy]');
        if (copyBtn) copyBtn.addEventListener('click', function () {
            var flash = function () {
                if (!copied) return;
                copied.hidden = false;
                setTimeout(function () { copied.hidden = true; }, 2000);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(codes).then(flash).catch(selectCodes);
            } else {
                selectCodes();
            }
        });

        function selectCodes() {
            if (!pre) return;
            var range = document.createRange();
            range.selectNodeContents(pre);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }

        var dlBtn = panel.querySelector('[data-recovery-download]');
        if (dlBtn) dlBtn.addEventListener('click', function () {
            var blob = new Blob([codes + '\n'], { type: 'text/plain' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'paddock-picks-recovery-codes.txt';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });

        var dismissBtn = panel.querySelector('[data-recovery-dismiss]');
        if (dismissBtn) dismissBtn.addEventListener('click', function () { panel.remove(); });
    }

    // ---- MFA challenge: OTP boxes + list<->detail swap (v3.0.0) ----
    // Auto-advance / backspace-to-previous / paste (or autofill) distribution across 6 boxes,
    // synced into a hidden input[name="code"] that actually submits — the backend contract
    // ($_POST['code']) is unchanged. Any multi-digit value landing in one box (real clipboard
    // paste, or mobile one-time-code autofill, which fires a plain `input` event rather than a
    // `paste` event) distributes from that box onward, so both cases are covered.
    function initOtpGroups() {
        document.querySelectorAll('[data-otp-group]').forEach(function (group) {
            var boxes = Array.prototype.slice.call(group.querySelectorAll('.otp input'));
            var hidden = group.querySelector('[data-testid="mfa-otp-value"]');
            function sync() {
                if (hidden) hidden.value = boxes.map(function (b) { return b.value; }).join('');
            }
            boxes.forEach(function (box, i) {
                box.addEventListener('input', function () {
                    var digits = box.value.replace(/\D/g, '');
                    if (digits.length > 1) {
                        digits.split('').slice(0, boxes.length - i).forEach(function (d, k) {
                            boxes[i + k].value = d;
                        });
                        boxes[Math.min(i + digits.length, boxes.length) - 1].focus();
                    } else {
                        box.value = digits;
                        if (digits && i < boxes.length - 1) boxes[i + 1].focus();
                    }
                    sync();
                });
                box.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
                });
            });
        });
    }

    // Root list <-> method detail view. Purely a visibility swap — the server already rendered
    // every view (hidden as appropriate) so no request is needed except for the one side-effecting
    // case: selecting email before a code has been sent submits the existing resend form instead
    // (a real POST; the server re-renders straight into the email view with the code sent).
    function initMfaViewSwap() {
        var views = document.querySelectorAll('[data-mfa-view]');
        if (!views.length) return;

        function showView(name) {
            var target = null;
            views.forEach(function (el) {
                var match = el.getAttribute('data-mfa-view') === name;
                el.hidden = !match;
                if (match) target = el;
            });
            if (!target) return;
            var focusEl = target.querySelector('.otp input, input[name="code"], [data-mfa-select]');
            if (focusEl) focusEl.focus();
        }

        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-mfa-select]');
            if (!el) return;
            if (el.hasAttribute('data-mfa-autosend')) {
                var form = document.querySelector('[data-testid="mfa-resend-form"]');
                if (form) { form.requestSubmit(); return; }
            }
            e.preventDefault();
            showView(el.getAttribute('data-mfa-select'));
        });
    }

    function init() {
        render();
        initRecovery();
        initOtpGroups();
        initMfaViewSwap();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
