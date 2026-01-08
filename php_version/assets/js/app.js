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
    
    // Delete confirmation modal
    let deleteModal = null;
    let deleteUrl = null;
    
    function showDeleteModal(url, itemName) {
        deleteUrl = url;
        
        if (!deleteModal) {
            deleteModal = document.createElement('div');
            deleteModal.id = 'delete-modal';
            deleteModal.innerHTML = `
                <div class="modal-overlay" onclick="closeDeleteModal()"></div>
                <div class="modal-content">
                    <h3 id="delete-modal-title">Bekræft sletning</h3>
                    <p id="delete-modal-text">Er du sikker?</p>
                    <div class="modal-buttons">
                        <button class="btn btn-secondary" onclick="closeDeleteModal()">Annuller</button>
                        <button class="btn btn-danger" onclick="confirmDelete()">Slet</button>
                    </div>
                </div>
            `;
            deleteModal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:1000;display:none;';
            
            const style = document.createElement('style');
            style.textContent = `
                #delete-modal .modal-overlay { position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5); }
                #delete-modal .modal-content { position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--bg-card);padding:2rem;border-radius:12px;min-width:300px;border:1px solid var(--border-color); }
                #delete-modal h3 { margin:0 0 1rem 0;font-family:'Chivo',sans-serif; }
                #delete-modal p { margin:0 0 1.5rem 0;color:var(--text-secondary); }
                #delete-modal .modal-buttons { display:flex;gap:0.5rem;justify-content:flex-end; }
            `;
            document.head.appendChild(style);
            document.body.appendChild(deleteModal);
        }
        
        document.getElementById('delete-modal-text').textContent = 
            (document.documentElement.lang === 'da' ? 'Er du sikker på at du vil slette ' : 'Are you sure you want to delete ') + itemName + '?';
        deleteModal.style.display = 'block';
    }
    
    window.showDeleteModal = showDeleteModal;
    
    window.closeDeleteModal = function() {
        if (deleteModal) deleteModal.style.display = 'none';
        deleteUrl = null;
    };
    
    window.confirmDelete = function() {
        if (deleteUrl) {
            window.location.href = deleteUrl;
        }
    };
    
    // Attach to delete buttons
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.href || this.dataset.url;
            const name = this.dataset.name || 'dette element';
            showDeleteModal(url, name);
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
});
