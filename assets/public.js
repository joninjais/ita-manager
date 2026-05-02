/* ITA Manager – Frontend JavaScript */
(function () {
    'use strict';

    function initITA() {
        // ── Tab Navigation ──────────────────────────────────────────────────
        var tabBtns = document.querySelectorAll('.ita-tab-btn');
        tabBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var container = btn.closest('.ita-container');
                if (!container) return;
                container.querySelectorAll('.ita-tab-btn').forEach(function (b) {
                    b.classList.remove('active');
                    b.setAttribute('aria-selected', 'false');
                });
                container.querySelectorAll('.ita-tab-panel').forEach(function (p) {
                    p.classList.remove('active');
                });
                btn.classList.add('active');
                btn.setAttribute('aria-selected', 'true');
                var target = document.getElementById(btn.dataset.target);
                if (target) target.classList.add('active');
            });
        });

        // ── Accordion Toggle ───────────────────────────────────────────────
        // ใช้ event delegation เพื่อรองรับ dynamic content ใน Elementor
        document.addEventListener('click', function (e) {
            var hdr = e.target.closest('.ita-indicator-hdr, .ita-moit-hdr');
            if (!hdr) return;
            var bdy = hdr.nextElementSibling;
            var arrow = hdr.querySelector('.ita-ind-arrow');
            if (!bdy) return;
            var isOpen = bdy.style.display !== 'none' && bdy.style.display !== '';
            if (isOpen) {
                bdy.style.display = 'none';
                if (arrow) arrow.style.transform = 'rotate(-90deg)';
            } else {
                bdy.style.display = '';
                if (arrow) arrow.style.transform = '';
            }
        });
    }

    // รองรับทั้ง DOMContentLoaded ปกติ และ Elementor frontend init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initITA);
    } else {
        initITA();
    }

    // Elementor: re-init เมื่อ widget ถูก render (editor preview)
    if (window.elementorFrontend) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/global', initITA);
    }
    document.addEventListener('elementor/frontend/init', function () {
        if (window.elementorFrontend) {
            window.elementorFrontend.hooks.addAction('frontend/element_ready/global', initITA);
        }
    });
})();
