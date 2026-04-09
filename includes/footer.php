    <div class="main-footer">
        <small>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</small>
    </div>
</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
}

// Close sidebar on window resize to desktop
window.addEventListener('resize', function() {
    if (window.innerWidth >= 992) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar) sidebar.classList.remove('show');
        if (overlay) overlay.classList.remove('show');
    }
});

// Animate elements on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.kpi-card, .card').forEach(function(el, i) {
        el.style.opacity = '0';
        el.style.transform = 'translateY(10px)';
        setTimeout(function() {
            el.style.transition = 'opacity 0.35s ease, transform 0.35s ease';
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
        }, 50 + (i * 40));
    });
});
</script>
</body>
</html>
