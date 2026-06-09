/* === DARK MODE === */
(function() {
    const saved = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
})();

function toggleDarkMode() {
    const html = document.documentElement;
    const current = html.getAttribute('data-theme') || 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    updateDarkModeBtn();
}

function updateDarkModeBtn() {
    const btn = document.getElementById('darkModeBtn');
    if (!btn) return;
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    btn.textContent = isDark ? '☀️' : '🌙';
    btn.title = isDark ? 'Light Mode' : 'Dark Mode';
}

document.addEventListener('DOMContentLoaded', updateDarkModeBtn);

/* === TOAST NOTIFICATIONS === */
(function() {
    let container = null;

    function getContainer() {
        if (!container) {
            container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                document.body.appendChild(container);
            }
            // Screenreader ueber neue Toasts informieren
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');
        }
        return container;
    }

    const icons = {
        success: '✓',
        error: '✕',
        info: 'ℹ',
        warning: '⚠'
    };

    window.showToast = function(type, title, message, duration) {
        duration = duration || 4000;
        const c = getContainer();
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        el.innerHTML = `
            <span class="toast-icon">${icons[type] || 'ℹ'}</span>
            <div class="toast-body">
                <div class="toast-title">${title}</div>
                ${message ? `<div class="toast-msg">${message}</div>` : ''}
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">×</button>
        `;
        c.appendChild(el);
        requestAnimationFrame(() => {
            requestAnimationFrame(() => el.classList.add('show'));
        });
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 350);
        }, duration);
        return el;
    };
})();

/* === MOBILE SIDEBAR === */
function toggleMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (!sidebar) return;
    sidebar.classList.toggle('mobile-open');
    if (overlay) overlay.classList.toggle('active');
}

function closeMobileSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.querySelector('.sidebar-overlay');
    if (overlay) overlay.addEventListener('click', closeMobileSidebar);

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMobileSidebar();
            // Offene Modals per Esc schliessen (Accessibility)
            document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
        }
    });
});

/* === LANGUAGE SWITCHER === */
function switchLanguage(lang) {
    // Direkter Navigationswechsel statt fetch+reload: vermeidet den
    // "Formular erneut senden?"-Dialog und erhaelt bestehende URL-Parameter.
    const url = new URL(window.location.href);
    url.searchParams.set('set_lang', lang);
    window.location.href = url.toString();
}

/* === CLIPBOARD === */
function copyToClipboard(text, successMsg) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('success', successMsg || 'Kopiert!', '');
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('success', successMsg || 'Kopiert!', '');
    });
}
