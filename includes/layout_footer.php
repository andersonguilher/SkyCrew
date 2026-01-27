<?php
// includes/layout_footer.php
?>
        </div>
    </div>
    <script>
        function updateTopClock() {
            const clock = document.getElementById('top-clock');
            if (clock) clock.textContent = new Date().toISOString().split('T')[1].split('.')[0] + ' UTC';
        }
        setInterval(updateTopClock, 1000);
        updateTopClock();
    </script>
</body>
</html>
