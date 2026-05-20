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

    function showDeleteModal(target, itemName, options) {
        const da = document.documentElement.lang === 'da';
        const title       = (options && options.title)       || (da ? 'Bekræft sletning'  : 'Confirm deletion');
        const confirmText = (options && options.confirmText) || (da ? 'Slet'               : 'Delete');
        const bodyText    = (options && options.bodyText)
            || (da ? 'Er du sikker på at du vil slette ' : 'Are you sure you want to delete ') + itemName + '?';

        deleteTarget = target;

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
                '#delete-modal h3 { margin:0 0 1rem 0;font-family:\'Chivo\',sans-serif; }',
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
    };

    window.confirmDelete = function() {
        if (!deleteTarget) return;
        if (deleteTarget instanceof HTMLFormElement) {
            const btn = deleteTarget.querySelector('button[type="submit"]');
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
    };

    // Attach to delete buttons
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('form');
            const name = this.dataset.name || 'dette element';
            showDeleteModal(form || this.href || this.dataset.url, name);
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
    
    // Auto-hide alerts
    document.querySelectorAll('.alert').forEach(alert => {
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

