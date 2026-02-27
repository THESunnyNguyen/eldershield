// assets/js/app.js — ElderShield client-side utilities

// ── Auto-dismiss flash messages ───────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const flash = document.querySelector('.flash');
    if (flash) {
        setTimeout(() => {
            flash.style.transition = 'opacity 0.5s';
            flash.style.opacity = '0';
            setTimeout(() => flash.remove(), 500);
        }, 4000);
    }

    // ── Confirm dangerous actions ─────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm)) e.preventDefault();
        });
    });

    // ── Risk gauge animation on detail page ──────────────────
    const gauge = document.querySelector('.gauge-fill');
    if (gauge) {
        const targetWidth = gauge.style.width;
        gauge.style.width = '0%';
        gauge.style.transition = 'width 1s ease-out';
        setTimeout(() => { gauge.style.width = targetWidth; }, 100);
    }

    // ── Table row clickable ───────────────────────────────────
    document.querySelectorAll('.data-table tbody tr[data-href]').forEach(row => {
        row.style.cursor = 'pointer';
        row.addEventListener('click', function () {
            window.location.href = this.dataset.href;
        });
    });
});
