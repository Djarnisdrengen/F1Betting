    </main>
    
    <!-- Mobile nav overlay -->
    <div class="nav-overlay" id="nav-overlay" onclick="toggleMobileMenu()"></div>
    
    <footer class="footer">
        <div class="container">
            <p><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?> - <?= t('points_system') ?></p>
        </div>
    </footer>
    
    <script src="assets/js/app.js"></script>
    <script>
    // Mobile menu toggle
    function toggleMobileMenu() {
        const nav = document.getElementById('main-nav');
        const overlay = document.getElementById('nav-overlay');
        const btn = document.querySelector('.mobile-menu-btn i');
        
        nav.classList.toggle('active');
        overlay.classList.toggle('active');
        
        if (nav.classList.contains('active')) {
            btn.className = 'fas fa-times';
            document.body.style.overflow = 'hidden';
        } else {
            btn.className = 'fas fa-bars';
            document.body.style.overflow = '';
        }
    }
    
    // Toggle leaderboard on mobile
    function toggleLeaderboard() {
        const header = document.querySelector('.leaderboard-collapse-header');
        const content = document.getElementById('leaderboard-content');
        
        if (header && content) {
            header.classList.toggle('expanded');
            content.classList.toggle('expanded');
        }
    }
    
    // Close mobile menu on resize
    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            const nav = document.getElementById('main-nav');
            const overlay = document.getElementById('nav-overlay');
            if (nav && nav.classList.contains('active')) {
                nav.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
    });
    
    // Countdown timer functionality
    function updateCountdowns() {
        document.querySelectorAll('.countdown-timer').forEach(timer => {
            const opens = timer.dataset.opens;
            const closes = timer.dataset.closes;
            const targetDate = opens ? new Date(opens) : (closes ? new Date(closes) : null);
            
            if (!targetDate) return;
            
            const now = new Date();
            const diff = targetDate - now;
            
            const countdownEl = timer.querySelector('.countdown-value');
            if (!countdownEl) return;
            
            if (diff <= 0) {
                countdownEl.textContent = '<?= $lang === 'da' ? 'Nu!' : 'Now!' ?>';
                return;
            }
            
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            
            let text = '';
            if (days > 0) {
                text = `${days}d ${hours}t ${minutes}m`;
            } else if (hours > 0) {
                text = `${hours}t ${minutes}m ${seconds}s`;
            } else {
                text = `${minutes}m ${seconds}s`;
            }
            
            countdownEl.textContent = text;
        });
    }
    
    // Update every second
    updateCountdowns();
    setInterval(updateCountdowns, 1000);
    </script>
</body>
</html>
