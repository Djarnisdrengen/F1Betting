(function () {
    function qs(sel, root) { return (root || document).querySelector(sel); }

    function closeModal() {
        var overlay = qs('.hf-modal-overlay');
        var ret = overlay && overlay.dataset.return;
        if (ret) { window.location.href = ret; return; }
        if (overlay) overlay.hidden = true;
        document.body.style.overflow = '';
    }

    function checkSelects() {
        var selects = document.querySelectorAll('.hf-bet-select');
        var allFilled = Array.prototype.every.call(selects, function (s) { return s.value !== ''; });
        var btn = document.getElementById('save-btn');
        if (btn) btn.disabled = !allFilled;
    }

    // Overlay click — only on the overlay itself, not inside the card
    document.addEventListener('click', function (e) {
        var closer = e.target.closest('[data-link="closeBetModal"]');
        if (!closer) return;
        if (closer.classList.contains('hf-modal-overlay') && e.target !== closer) return;
        closeModal();
    });

    document.addEventListener('keydown', function (e) {
        var overlay = qs('.hf-modal-overlay');
        if (e.key === 'Escape' && overlay && !overlay.hidden) closeModal();
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('hf-bet-select')) checkSelects();
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.body.style.overflow = 'hidden';

        checkSelects();

        var firstSelect = qs('.hf-bet-select');
        if (firstSelect) setTimeout(function () { firstSelect.focus(); }, 0);

        // Focus trap within modal card
        var modal = qs('.hf-modal-card');
        if (modal) {
            modal.addEventListener('keydown', function (e) {
                if (e.key !== 'Tab') return;
                var focusable = Array.prototype.slice.call(
                    modal.querySelectorAll('a[href],button:not([disabled]),select,[tabindex="0"]')
                ).filter(function (el) { return el.offsetParent !== null; });
                if (!focusable.length) return;
                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (e.shiftKey) {
                    if (document.activeElement === first) { e.preventDefault(); last.focus(); }
                } else {
                    if (document.activeElement === last) { e.preventDefault(); first.focus(); }
                }
            });
        }
    });
})();
