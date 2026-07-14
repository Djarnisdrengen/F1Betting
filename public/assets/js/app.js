// F1 Betting App JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Toggle bets visibility
    document.querySelectorAll('.toggle-bets').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const target = document.getElementById(targetId);
            const icon = this.querySelector('i');

            if (target.classList.contains('hidden')) {
                target.classList.remove('hidden');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                target.classList.add('hidden');
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        });
    });

    // Whole-card navigation (index.php / races.php race boxes).
    // Ignores clicks on inner interactive elements (links, buttons, form fields,
    // the bets toggle) and on text selections, so existing controls keep working.
    document.querySelectorAll('.clickable-card[data-href]').forEach(card => {
        card.addEventListener('click', function(e) {
            if (e.target.closest('a, button, input, select, label, .toggle-bets, .bets-section')) return;
            if (window.getSelection && String(window.getSelection())) return;
            window.location.href = this.dataset.href;
        });
    });
    
    // Tab navigation
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const tabGroup = this.closest('.tabs-container');
            const targetId = this.dataset.tab;
            
            // Update active tab
            tabGroup.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Show target content
            tabGroup.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById(targetId).classList.remove('hidden');
        });
    });
    
    // Collapsible forms toggle
    window.toggleFormOLD = function(formId) {
        const form = document.getElementById(formId);
        const header = form.previousElementSibling;
        
        if (form.classList.contains('expanded')) {
            form.classList.remove('expanded');
            header.classList.remove('expanded');
        } else {
            form.classList.add('expanded');
            header.classList.add('expanded');
        }
    };
    
    // Ensure collapsible forms start collapsed (not expanded)
    document.querySelectorAll('.collapsible-form').forEach(form => {
        form.classList.remove('expanded');
        const header = form.previousElementSibling;
        if (header) header.classList.remove('expanded');
    });
    
    // Scroll to edit form if present (from URL hash)
    if (window.location.hash) {
        const target = document.querySelector(window.location.hash);
        if (target) {
            setTimeout(() => {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
    }
    
    // Auto-scroll to edit form when URL has edit parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('edit')) {
        const editId = urlParams.get('edit');
        const editForm = document.querySelector('.edit-form-active');
        if (editForm) {
            setTimeout(() => {
                // Scroll to show the item name at top of viewport
                const rect = editForm.getBoundingClientRect();
                const scrollTop = window.pageYOffset + rect.top - 120;
                window.scrollTo({ top: scrollTop, behavior: 'smooth' });
            }, 100);
        }
    }
    
    // Delete confirmation modal
    let deleteModal = null;
    let deleteTarget = null; // HTMLFormElement (POST) or URL string (GET)
    let deleteButton = null; // the actual submit button clicked, when deleteTarget is a form —
                              // forms with several submit buttons (save/publish/delete) need the
                              // one that was clicked, not just the first submit button in the form.

    function showDeleteModal(target, itemName, options, button) {
        const da = document.documentElement.lang === 'da';
        const title       = (options && options.title)       || (da ? 'Bekræft sletning'  : 'Confirm deletion');
        const confirmText = (options && options.confirmText) || (da ? 'Slet'               : 'Delete');
        const bodyText    = (options && options.bodyText)
            || (da ? 'Er du sikker på at du vil slette ' : 'Are you sure you want to delete ') + itemName + '?';

        deleteTarget = target;
        deleteButton = button || null;

        if (!deleteModal) {
            deleteModal = document.createElement('div');
            deleteModal.id = 'delete-modal';
            deleteModal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:1000;display:none;';

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';

            const content = document.createElement('div');
            content.className = 'modal-content';

            const h3 = document.createElement('h3');
            h3.id = 'delete-modal-title';

            const p = document.createElement('p');
            p.id = 'delete-modal-text';

            const btns = document.createElement('div');
            btns.className = 'modal-buttons';

            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn btn-secondary btn-user-delete-cancel';
            cancelBtn.textContent = da ? 'Annuller' : 'Cancel';

            const confirmBtn = document.createElement('button');
            confirmBtn.className = 'btn btn-danger btn-user-delete-confirm';

            btns.appendChild(cancelBtn);
            btns.appendChild(confirmBtn);
            content.appendChild(h3);
            content.appendChild(p);
            content.appendChild(btns);
            deleteModal.appendChild(overlay);
            deleteModal.appendChild(content);

            const style = document.createElement('style');
            style.textContent = [
                '#delete-modal .modal-overlay { position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5); }',
                '#delete-modal .modal-content { position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--bg-card);padding:2rem;border-radius:12px;min-width:300px;border:1px solid var(--border-color); }',
                '#delete-modal h3 { margin:0 0 1rem 0;font-family:var(--font-display); }',
                '#delete-modal p { margin:0 0 1.5rem 0;color:var(--text-secondary); }',
                '#delete-modal .modal-buttons { display:flex;gap:0.5rem;justify-content:flex-end; }',
            ].join('');
            document.head.appendChild(style);
            document.body.appendChild(deleteModal);
        }

        document.getElementById('delete-modal-title').textContent = title;
        document.getElementById('delete-modal-text').textContent  = bodyText;
        deleteModal.querySelector('.btn-user-delete-confirm').textContent = confirmText;
        deleteModal.style.display = 'block';
        
        /// Handle modal button clicks
        deleteModal.addEventListener('click', e => {
            if (e.target.closest('.btn-user-delete-cancel')) {
                closeDeleteModal();
            }
            if (e.target.closest('.btn-user-delete-confirm')) {
                confirmDelete();
            }
        });

    }
    
    window.showDeleteModal = showDeleteModal;
    
    window.closeDeleteModal = function() {
        if (deleteModal) deleteModal.style.display = 'none';
        deleteTarget = null;
        deleteButton = null;
    };

    window.confirmDelete = function() {
        if (!deleteTarget) return;
        if (deleteTarget instanceof HTMLFormElement) {
            const btn = deleteButton || deleteTarget.querySelector('button[type="submit"]');
            if (btn && btn.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = btn.name;
                hidden.value = btn.value || '';
                deleteTarget.appendChild(hidden);
            }
            deleteTarget.submit();
        } else {
            window.location.href = deleteTarget;
        }
        deleteTarget = null;
        deleteButton = null;
    };

    // Attach to delete buttons
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('form');
            const name = this.dataset.name || 'dette element';
            showDeleteModal(form || this.href || this.dataset.url, name, null, this);
        });
    });

    // Attach to reset-result buttons
    document.querySelectorAll('.btn-reset-result').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const da = document.documentElement.lang === 'da';
            showDeleteModal(this.closest('form'), this.dataset.name, {
                title:       da ? 'Nulstil resultat'                                              : 'Reset result',
                bodyText:    da ? 'Dette fjerner point og stjerner optjent i dette løb.'          : 'This will remove all points and stars earned from this race.',
                confirmText: da ? 'Nulstil'                                                       : 'Reset',
            });
        });
    });
    
    // Auto-hide alerts (opt out via data-persist — e.g. login/MFA lockout errors, which
    // should stay visible until the next attempt or a reload, not vanish after 5s)
    document.querySelectorAll('.alert:not([data-persist])').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });

    // Drawer toggle
    const hamburger = document.getElementById('hf-hamburger');
    const drawer    = document.getElementById('hf-drawer');
    if (hamburger && drawer) {
        hamburger.addEventListener('click', function (e) {
            e.stopPropagation();
            const open = drawer.classList.toggle('open');
            this.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('click', function (e) {
            if (!drawer.classList.contains('open')) return;
            if (!drawer.contains(e.target) && !hamburger.contains(e.target)) {
                drawer.classList.remove('open');
                hamburger.setAttribute('aria-expanded', 'false');
            }
        });
    }
});

// ── Paddock Challenges toast (CP gains, duel lock) ───────────────────────────
(function () {
    let toastEl = null;
    let toastTimer = null;

    window.hfToast = function (message) {
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.id = 'hf-toast';
            toastEl.innerHTML = '<i class="fa-solid fa-bolt"></i><span class="hf-toast-msg"></span>';
            document.body.appendChild(toastEl);

            const style = document.createElement('style');
            style.textContent = [
                '#hf-toast { position:fixed; left:50%; bottom:84px; z-index:1000;',
                'background:var(--bg-card); border:1px solid var(--gold, #fbbf24); color:var(--text-primary);',
                'padding:11px 18px; border-radius:9999px; font-family:var(--display); font-weight:700; font-size:14px;',
                'box-shadow:0 8px 24px rgba(0,0,0,.45); white-space:nowrap; display:flex; align-items:center; gap:8px;',
                'opacity:0; pointer-events:none; transform:translateX(-50%) translateY(8px);',
                'transition:opacity .2s ease, transform .2s ease; }',
                '#hf-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }',
                '#hf-toast i { color:var(--gold, #fbbf24); }',
            ].join('');
            document.head.appendChild(style);
        }

        toastEl.querySelector('.hf-toast-msg').textContent = message;
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 1600);
    };
}());

// ── Profile page tabs, counters & toggles ────────────────────────────────────
(function () {
    const tabs = document.querySelector('[data-testid="profile-tabs"]');
    if (!tabs) return;

    const buttons = Array.from(tabs.querySelectorAll('.hf-tab-btn'));
    const panels  = Array.from(tabs.querySelectorAll('.hf-tab-panel'));

    function activateTab(target) {
        buttons.forEach(b => b.classList.toggle('active', b.dataset.target === target));
        panels.forEach(p => {
            if (p.id === target) {
                p.removeAttribute('hidden');
            } else {
                p.setAttribute('hidden', '');
            }
        });
        const url = new URL(window.location.href);
        url.searchParams.set('tab', target);
        history.replaceState(null, '', url);
    }

    // Init from URL or default to first tab
    const initialTab = new URL(window.location.href).searchParams.get('tab') || buttons[0]?.dataset.target;
    if (initialTab) activateTab(initialTab);

    buttons.forEach(btn => {
        btn.addEventListener('click', () => activateTab(btn.dataset.target));
    });

    // Character counter for display name
    const nameInput   = document.querySelector('[data-testid="display-name-input"]');
    const charCounter = document.querySelector('[data-testid="char-counter"]');
    if (nameInput && charCounter) {
        function updateCounter() {
            const len = nameInput.value.length;
            const max = parseInt(nameInput.getAttribute('maxlength') || '100', 10);
            charCounter.textContent = len + '/' + max;
            charCounter.classList.toggle('warn', len >= max - 10);
        }
        updateCounter();
        nameInput.addEventListener('input', updateCounter);
    }

    // Password match indicator
    const newPw     = document.querySelector('[data-testid="new-password-input"]');
    const confirmPw = document.querySelector('[data-testid="confirm-password-input"]');
    const matchSpan = document.querySelector('[data-testid="pw-match-indicator"]');
    if (newPw && confirmPw && matchSpan) {
        function updateMatch() {
            if (!confirmPw.value) {
                matchSpan.textContent = '';
                matchSpan.className = 'hf-pw-match';
                return;
            }
            const match = newPw.value === confirmPw.value;
            const lang  = document.documentElement.lang === 'da';
            matchSpan.textContent = match
                ? '✓ ' + (lang ? 'Adgangskoderne matcher'       : 'Passwords match')
                : '✗ ' + (lang ? 'Adgangskoderne matcher ikke'  : 'Passwords do not match');
            matchSpan.className = 'hf-pw-match ' + (match ? 'ok' : 'err');
        }
        newPw.addEventListener('input', updateMatch);
        confirmPw.addEventListener('input', updateMatch);
    }

    // Preference segmented toggles
    tabs.querySelectorAll('.hf-pref-toggle').forEach(function (group) {
        const hiddenInput = group.nextElementSibling;
        group.querySelectorAll('.hf-pref-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                group.querySelectorAll('.hf-pref-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                if (hiddenInput) hiddenInput.value = btn.dataset.value;
            });
        });
    });
}());

