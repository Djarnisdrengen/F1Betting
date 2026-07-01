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

    function init() {
        render();
        initRecovery();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
