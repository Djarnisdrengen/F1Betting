    </main>

    <?php if ($currentPage !== 'admin'): ?>
    <?php include __DIR__ . '/bottom_bar.php'; ?>
    <?php endif; ?>

    <footer class="hf-footer">
        <span class="name"><?= escape($settings['app_title']) ?></span>
        &middot; <?= escape($settings['app_year']) ?>
        &middot; <?= APP_VERSION ?>
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
            const target = timer.dataset.target;
            const targetDate = opens ? new Date(opens) : closes ? new Date(closes) : target ? new Date(target) : null;
            
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

    // hf-countdown: 4-cell DAG/TIM/MIN/SEK grid driven by data-target ISO string
    function updateHfCountdowns() {
        document.querySelectorAll('.hf-countdown[data-target]').forEach(el => {
            const target = new Date(el.dataset.target);
            const diff = target - new Date();
            const cells = el.querySelectorAll('.hf-cd-num');
            if (cells.length < 4) return;
            if (diff <= 0) {
                cells[0].textContent = '0';
                cells[1].textContent = '0';
                cells[2].textContent = '0';
                cells[3].textContent = '0';
                return;
            }
            const d = Math.floor(diff / 86400000);
            const h = Math.floor((diff % 86400000) / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            cells[0].textContent = String(d).padStart(2, '0');
            cells[1].textContent = String(h).padStart(2, '0');
            cells[2].textContent = String(m).padStart(2, '0');
            cells[3].textContent = String(s).padStart(2, '0');
        });
    }
    updateHfCountdowns();
    setInterval(updateHfCountdowns, 1000);
    </script>
</body>
</html>
