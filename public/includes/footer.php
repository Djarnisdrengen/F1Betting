    </main>

    <?php if ($currentPage !== 'admin'): ?>
    <?php include __DIR__ . '/bottom_bar.php'; ?>
    <?php endif; ?>

    <footer class="footer">
        <div class="container">
            <p>&copy; 1996-<?= date("Y") ?> - <?= t('contact') ?> info@<?= preg_replace('/^www\./', '', SITE_DOMAIN) ?></p>
        </div>

    </footer>
    
    <!--  APP.JS INCLUDE -->
    <script nonce="<?php echo $nonce; ?>" src="assets/js/app.js"></script>

    <!--  INLINE SCRIPT TO ENSURE EARLY LOAD -->
    <script nonce="<?php echo $nonce; ?>">

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.leaderboard-section').forEach(div => {
            div.addEventListener('click', toggleLeaderboard);
        });
    });

    // Toggle leaderboard on mobile
    function toggleLeaderboard() {
        const header = document.querySelector('.leaderboard-collapse-header');
        const content = document.getElementById('leaderboard-content');

        if (header && content) {
            header.classList.toggle('expanded');
            content.classList.toggle('expanded');
        }
    }
    
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
                countdownEl.textContent = '<?= t('countdown_now') ?>';
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
