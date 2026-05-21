(function () {
    var activeSlot = null;
    var bet = { 1: null, 2: null, 3: null };

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    // Navigate away — both close paths (✕, Annuller, overlay, Esc) use this
    function closeModal() {
        var overlay = qs('.hf-modal-overlay');
        var ret = overlay && overlay.dataset.return;
        if (ret) { window.location.href = ret; return; }
        if (overlay) overlay.hidden = true;
        document.body.style.overflow = '';
        activeSlot = null;
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

    // Activate a slot
    document.addEventListener('click', function (e) {
        var slot = e.target.closest('[data-link="activateSlot"]');
        if (!slot) return;
        activeSlot = parseInt(slot.dataset.pos, 10);
        render();
    });

    // Pick a driver — move semantics: if already in another slot, clear it
    document.addEventListener('click', function (e) {
        var row = e.target.closest('[data-link="pickDriver"]');
        if (!row || row.disabled || activeSlot === null) return;
        var driverId = row.dataset.driverId;
        [1, 2, 3].forEach(function (p) {
            if (bet[p] === driverId && p !== activeSlot) bet[p] = null;
        });
        bet[activeSlot] = driverId;
        // Auto-advance to next empty slot
        var next = [1, 2, 3].find(function (p) { return p !== activeSlot && !bet[p]; });
        activeSlot = next !== undefined ? next : activeSlot;
        render();
    });

    // Save — populate hidden form and submit
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-link="saveBet"]');
        if (!btn) return;
        if (btn.classList.contains('is-disabled') || btn.getAttribute('aria-disabled') === 'true') return;
        var form = document.getElementById('bet-form');
        if (!form) return;
        document.getElementById('form-p1').value = bet[1] || '';
        document.getElementById('form-p2').value = bet[2] || '';
        document.getElementById('form-p3').value = bet[3] || '';
        form.submit();
    });

    function render() {
        var driversById = window.driversById || {};
        var pickLabel = (window.betL10n && window.betL10n.pickDriver) || '—';

        qsa('.hf-slot').forEach(function (s) {
            var p = parseInt(s.dataset.pos, 10);
            s.classList.toggle('is-active', p === activeSlot);
            s.classList.toggle('is-filled', !!bet[p]);
            s.classList.toggle('is-empty', !bet[p]);
            var nameEl = qs('.hf-slot-name', s);
            if (nameEl) {
                nameEl.textContent = (bet[p] && driversById[bet[p]])
                    ? driversById[bet[p]].surname
                    : pickLabel;
            }
        });

        qsa('.hf-driver-row').forEach(function (r) {
            var id = r.dataset.driverId;
            var assignedTo = [1, 2, 3].find(function (p) { return bet[p] === id; });
            r.classList.toggle('is-selected', assignedTo !== undefined && assignedTo === activeSlot);

            var pill = qs('.hf-driver-pill', r);
            if (assignedTo !== undefined) {
                if (!pill) {
                    pill = document.createElement('span');
                    r.appendChild(pill);
                }
                pill.className = 'hf-driver-pill pos-' + assignedTo;
                pill.textContent = 'P' + assignedTo;
            } else if (pill) {
                pill.remove();
            }
            r.disabled = (activeSlot === null);
        });

        var allFilled = [1, 2, 3].every(function (p) { return !!bet[p]; });
        var gem = qs('.hf-btn-primary[data-link="saveBet"]');
        if (gem) {
            gem.classList.toggle('is-disabled', !allFilled);
            gem.setAttribute('aria-disabled', String(!allFilled));
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Scroll-lock — modal is always open on this page
        document.body.style.overflow = 'hidden';

        // Restore POST-back state (error re-render after failed submission)
        var pb = window.betPostBack || {};
        if (pb.p1) bet[1] = pb.p1;
        if (pb.p2) bet[2] = pb.p2;
        if (pb.p3) bet[3] = pb.p3;

        // AC-BET-07: first empty slot auto-active; if all filled, none active
        var first = [1, 2, 3].find(function (p) { return !bet[p]; });
        activeSlot = first !== undefined ? first : null;

        render();

        // AC-BET-17: focus ✕ on open
        var closeBtn = qs('.hf-bet-close');
        if (closeBtn) setTimeout(function () { closeBtn.focus(); }, 0);

        // AC-BET-17: focus trap within modal card
        var modal = qs('.hf-modal-card');
        if (modal) {
            modal.addEventListener('keydown', function (e) {
                if (e.key !== 'Tab') return;
                var focusable = qsa(
                    'a[href],button:not([disabled]),[tabindex="0"]', modal
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
