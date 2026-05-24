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

<!-- In-system confirmation dialog (replaces native window.confirm) -->
<div class="app-confirm-backdrop" id="appConfirm" role="dialog" aria-modal="true" aria-labelledby="appConfirmTitle" hidden>
    <div class="app-confirm-card">
        <div class="app-confirm-body">
            <div class="app-confirm-icon is-danger" id="appConfirmIcon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div>
                <div class="app-confirm-title" id="appConfirmTitle">Please confirm</div>
                <p class="app-confirm-message" id="appConfirmMessage"></p>
            </div>
        </div>
        <div class="app-confirm-actions">
            <button type="button" class="btn btn-light btn-sm" id="appConfirmCancel">Cancel</button>
            <button type="button" class="btn btn-danger btn-sm" id="appConfirmOk">Confirm</button>
        </div>
    </div>
</div>
<script>
(function () {
    const backdrop  = document.getElementById('appConfirm');
    const iconWrap  = document.getElementById('appConfirmIcon');
    const titleEl   = document.getElementById('appConfirmTitle');
    const msgEl     = document.getElementById('appConfirmMessage');
    const okBtn     = document.getElementById('appConfirmOk');
    const cancelBtn = document.getElementById('appConfirmCancel');
    let resolver = null;

    const variants = {
        danger:  { icon: 'bi-exclamation-triangle-fill', btn: 'btn-danger'  },
        warning: { icon: 'bi-exclamation-circle-fill',   btn: 'btn-warning' },
        primary: { icon: 'bi-question-circle-fill',      btn: 'btn-primary' }
    };

    function onKey(e) {
        if (e.key === 'Escape') closeConfirm(false);
        else if (e.key === 'Enter') closeConfirm(true);
    }

    function closeConfirm(result) {
        backdrop.classList.remove('show');
        setTimeout(function () { backdrop.hidden = true; }, 180);
        document.removeEventListener('keydown', onKey);
        const r = resolver; resolver = null;
        if (r) r(result);
    }

    function appConfirm(message, variant) {
        const key = variants[variant] ? variant : 'danger';
        const v = variants[key];
        iconWrap.className = 'app-confirm-icon is-' + key;
        iconWrap.innerHTML = '<i class="bi ' + v.icon + '"></i>';
        msgEl.textContent = message;
        okBtn.className = 'btn btn-sm ' + v.btn;
        backdrop.hidden = false;
        void backdrop.offsetWidth; // reflow so the transition runs
        backdrop.classList.add('show');
        okBtn.focus();
        document.addEventListener('keydown', onKey);
        return new Promise(function (resolve) { resolver = resolve; });
    }

    okBtn.addEventListener('click', function () { closeConfirm(true); });
    cancelBtn.addEventListener('click', function () { closeConfirm(false); });
    backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closeConfirm(false); });

    // Any form whose <form> or clicked submit button carries data-confirm.
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        const submitter = e.submitter;
        const message = (submitter && submitter.getAttribute('data-confirm'))
                      || form.getAttribute('data-confirm');
        if (!message) return;
        if (form.dataset.appConfirmed === '1') { form.dataset.appConfirmed = ''; return; }
        e.preventDefault();
        const variant = (submitter && submitter.getAttribute('data-confirm-variant'))
                      || form.getAttribute('data-confirm-variant') || 'danger';
        appConfirm(message, variant).then(function (ok) {
            if (!ok) return;
            form.dataset.appConfirmed = '1';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter && submitter.type === 'submit' ? submitter : undefined);
            } else {
                form.submit();
            }
        });
    }, true);

    // Standalone links with data-confirm (not form submit buttons).
    document.addEventListener('click', function (e) {
        const el = e.target.closest('[data-confirm]');
        if (!el || el.tagName !== 'A' || !el.href) return;
        if (el.closest('form')) return;
        e.preventDefault();
        appConfirm(el.getAttribute('data-confirm'), el.getAttribute('data-confirm-variant') || 'danger')
            .then(function (ok) { if (ok) window.location.href = el.href; });
    }, true);

    window.appConfirm = appConfirm;
})();
</script>

<script>
// Phone/contact fields: keep only digits and cap at 11 characters.
(function () {
    function normalizePhoneInput(input) {
        let cleaned = input.value.replace(/\D+/g, '');
        if (cleaned.length === 12 && cleaned.startsWith('63')) {
            cleaned = '0' + cleaned.slice(2);
        }
        cleaned = cleaned.slice(0, 11);
        if (input.value !== cleaned) input.value = cleaned;
    }

    document.addEventListener('input', function (e) {
        const input = e.target;
        if (input instanceof HTMLInputElement && input.matches('[data-phone-field]')) {
            normalizePhoneInput(input);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[data-phone-field]').forEach(normalizePhoneInput);
    });
})();
</script>

<script>
// Flash toast: slide in, auto-dismiss, and manual close.
(function () {
    function dismiss(toast) {
        if (toast.dataset.dismissed === '1') return;
        toast.dataset.dismissed = '1';
        toast.classList.remove('app-toast--in');
        toast.classList.add('app-toast--out');
        setTimeout(function () {
            const zone = toast.closest('.app-toast-zone');
            toast.remove();
            if (zone && !zone.querySelector('.app-toast')) zone.remove();
        }, 280);
    }

    const AUTO_MS = 5000;

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.app-toast').forEach(function (toast) {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { toast.classList.add('app-toast--in'); });
            });
            const closeBtn = toast.querySelector('.app-toast-close');
            if (closeBtn) closeBtn.addEventListener('click', function () { dismiss(toast); });

            let timer = setTimeout(function () { dismiss(toast); }, AUTO_MS);
            // Pause the countdown while the pointer is over the toast so the
            // user has time to read longer messages.
            toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
            toast.addEventListener('mouseleave', function () {
                timer = setTimeout(function () { dismiss(toast); }, AUTO_MS);
            });
        });
    });
})();
</script>

<script>
// Prevent accidental double submission. Runs in the bubble phase so the
// confirm dialog (capture phase) decides first; we skip any submit it
// blocked, and only lock in the real submission that actually proceeds.
(function () {
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) return;            // confirm dialog handled it
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if ((form.method || '').toLowerCase() === 'get') return; // filters/search

        if (form.dataset.submitting === '1') {     // a submit is already underway
            e.preventDefault();
            return;
        }
        form.dataset.submitting = '1';

        const submitter = e.submitter;
        const targets = submitter && submitter.type === 'submit'
            ? [submitter]
            : Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));

        // Defer so the button is still enabled when this submission is
        // serialized (a disabled submitter would be dropped from POST).
        setTimeout(function () {
            targets.forEach(function (btn) {
                btn.disabled = true;
                btn.classList.add('is-submitting');
                if (btn.tagName === 'BUTTON' && !btn.querySelector('.spinner-border')) {
                    btn.dataset.originalHtml = btn.innerHTML;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" '
                        + 'role="status" aria-hidden="true"></span>Working…';
                }
            });
        }, 0);
    });
})();
</script>
</body>
</html>
