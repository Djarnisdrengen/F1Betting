    </main>
    
    <footer class="footer">
        <div class="container">
            <p><?= escape($settings['app_title']) ?> <?= escape($settings['app_year']) ?> - <?= t('points_system') ?></p>
        </div>
    </footer>
    
    <script src="assets/js/app.js"></script>
    <script>
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
