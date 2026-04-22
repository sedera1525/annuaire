/**
 * TOPsocietes — JavaScript principal
 * Navigation mobile, FAQ accordion, filtres recherche, tarifs toggle.
 */

(function () {
    'use strict';

    /* ─────────────────────────────────────────────
       Navigation mobile
    ───────────────────────────────────────────── */
    const navToggle = document.getElementById('ts-nav-toggle');
    const navMenu   = document.querySelector('.ts-nav-menu');

    if (navToggle && navMenu) {
        navToggle.addEventListener('click', function () {
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', String(!expanded));
            navMenu.classList.toggle('open');
            if (expanded) { closeAllSubmenus(); }
        });

        // Fermer sur clic extérieur
        document.addEventListener('click', function (e) {
            if (!navToggle.contains(e.target) && !navMenu.contains(e.target)) {
                navToggle.setAttribute('aria-expanded', 'false');
                navMenu.classList.remove('open');
                closeAllSubmenus();
            }
        });
    }

    /* ─────────────────────────────────────────────
       Sous-menus dropdown
    ───────────────────────────────────────────── */
    function closeAllSubmenus() {
        document.querySelectorAll('.ts-nav-menu .menu-item-has-children.ts-open').forEach(function (li) {
            li.classList.remove('ts-open');
            const btn = li.querySelector(':scope > .ts-submenu-toggle');
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    document.querySelectorAll('.ts-nav-menu .menu-item-has-children').forEach(function (li) {
        const btn = document.createElement('button');
        btn.className = 'ts-submenu-toggle';
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', 'Ouvrir le sous-menu');
        btn.innerHTML = '▾';

        const link = li.querySelector(':scope > a');
        if (link) { link.after(btn); }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = li.classList.toggle('ts-open');
            btn.setAttribute('aria-expanded', String(isOpen));
            if (isOpen) {
                // Fermer les autres au même niveau
                li.parentElement.querySelectorAll(':scope > .menu-item-has-children.ts-open').forEach(function (other) {
                    if (other !== li) {
                        other.classList.remove('ts-open');
                        const otherBtn = other.querySelector(':scope > .ts-submenu-toggle');
                        if (otherBtn) { otherBtn.setAttribute('aria-expanded', 'false'); }
                    }
                });
            }
        });
    });

    /* ─────────────────────────────────────────────
       Scroll animé — fade-up
    ───────────────────────────────────────────── */
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('ts-fade-up');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });

        document.querySelectorAll('.ts-sector-card, .ts-company-card, .ts-pricing-card, .ts-kpi-item').forEach(function (el) {
            if (!el.classList.contains('ts-fade-up')) {
                el.style.opacity = '0';
                observer.observe(el);
            }
        });
    }

    /* ─────────────────────────────────────────────
       FAQ Accordion
    ───────────────────────────────────────────── */
    document.querySelectorAll('.ts-faq-question').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const item     = this.closest('.ts-faq-item');
            const expanded = this.getAttribute('aria-expanded') === 'true';

            // Fermer tous les autres
            document.querySelectorAll('.ts-faq-item.open').forEach(function (openItem) {
                if (openItem !== item) {
                    openItem.classList.remove('open');
                    openItem.querySelector('.ts-faq-question').setAttribute('aria-expanded', 'false');
                }
            });

            item.classList.toggle('open', !expanded);
            this.setAttribute('aria-expanded', String(!expanded));
        });
    });

    /* ─────────────────────────────────────────────
       Toggle tarifs mensuel / annuel
    ───────────────────────────────────────────── */
    const billingBtns = document.querySelectorAll('.ts-billing-btn');

    billingBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const billing = this.dataset.billing;

            billingBtns.forEach(function (b) {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-pressed', String(b === btn));
            });

            document.querySelectorAll('.ts-plan-amount[data-monthly]').forEach(function (el) {
                el.textContent = 'annual' === billing ? el.dataset.annual : el.dataset.monthly;
            });

            document.querySelectorAll('.ts-plan-orig[data-annual-orig]').forEach(function (el) {
                el.textContent = 'annual' === billing ? el.dataset.annualOrig : '';
            });
        });
    });

    /* ─────────────────────────────────────────────
       Page Recherche — interactions filtres + AJAX
    ───────────────────────────────────────────── */
    const searchInput   = document.getElementById('ts-search-input');
    const searchBtn     = document.getElementById('ts-search-btn');
    const searchClear   = document.getElementById('ts-search-clear');
    const filterBadges  = document.querySelectorAll('.ts-filter-badge');
    const resultsList   = document.getElementById('ts-results-list');
    const loadingEl     = document.getElementById('ts-loading');
    const countEl       = document.getElementById('ts-count-num');

    // Toggle filtre "forme juridique"
    filterBadges.forEach(function (badge) {
        badge.addEventListener('click', function () {
            filterBadges.forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            if (resultsList) { triggerSearch(1); }
        });
    });

    // Bouton effacer
    if (searchInput && searchClear) {
        searchInput.addEventListener('input', function () {
            searchClear.style.display = this.value ? 'inline' : 'none';
        });
        searchClear.addEventListener('click', function () {
            searchInput.value = '';
            this.style.display = 'none';
            searchInput.focus();
            triggerSearch(1);
        });
    }

    if (searchBtn) {
        searchBtn.addEventListener('click', function () { triggerSearch(1); });
    }
    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { triggerSearch(1); }
        });
    }

    // Tri
    const sortEl = document.getElementById('ts-sort');
    if (sortEl) { sortEl.addEventListener('change', function () { triggerSearch(1); }); }

    // Filtres selects
    ['ts-filter-sector', 'ts-filter-region', 'ts-filter-ca'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) { el.addEventListener('change', function () { triggerSearch(1); }); }
    });

    // Pagination AJAX
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-page]');
        if (btn && btn.closest('#ts-pagination')) {
            e.preventDefault();
            triggerSearch(parseInt(btn.dataset.page, 10));
        }
    });

    function triggerSearch(page) {
        if (!resultsList || typeof tsAjax === 'undefined') { return; }

        const query   = searchInput ? searchInput.value.trim() : '';
        const sector  = document.getElementById('ts-filter-sector')?.value || '';
        const region  = document.getElementById('ts-filter-region')?.value || '';
        const ca      = (document.getElementById('ts-filter-ca')?.value || '').split(',');
        const forme   = document.querySelector('.ts-filter-badge.active')?.dataset?.forme || '';
        const orderby = document.getElementById('ts-sort')?.value || 'relevance';

        const data = new FormData();
        data.append('action',  'ts_search_companies');
        data.append('nonce',   tsAjax.nonce);
        data.append('query',   query);
        data.append('sector',  sector);
        data.append('region',  region);
        data.append('ca_min',  ca[0] || '');
        data.append('ca_max',  ca[1] || '');
        data.append('forme',   forme);
        data.append('orderby', orderby);
        data.append('page',    page);

        if (loadingEl) { loadingEl.style.display = 'block'; }
        resultsList.style.opacity = '0.4';

        fetch(tsAjax.ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (loadingEl) { loadingEl.style.display = 'none'; }
                resultsList.style.opacity = '1';

                if (res.success) {
                    renderResults(res.data.results);
                    if (countEl) { countEl.textContent = res.data.total.toLocaleString('fr-FR'); }
                    renderPagination(res.data.current, res.data.pages);
                }
            })
            .catch(function () {
                if (loadingEl) { loadingEl.style.display = 'none'; }
                resultsList.style.opacity = '1';
            });
    }

    function renderResults(results) {
        if (!resultsList) { return; }
        if (!results || results.length === 0) {
            resultsList.innerHTML = '<p style="padding:40px;text-align:center;color:var(--ts-text-muted)">' + (tsAjax.i18n.noResults || 'Aucun résultat.') + '</p>';
            return;
        }
        resultsList.innerHTML = results.map(function (r) {
            return [
                '<a href="' + escHtml(r.url) + '" class="ts-result-row" role="listitem">',
                '  <div class="ts-result-rank">#' + escHtml(r.rank) + '</div>',
                '  <div class="ts-result-logo" style="background:' + escHtml(r.color) + '22;color:' + escHtml(r.color) + '">' + escHtml(r.letter) + '</div>',
                '  <div class="ts-result-info">',
                '    <div class="ts-result-name">' + escHtml(r.name) + (r.premium ? ' <span class="ts-premium-badge">⭐ PREMIUM</span>' : '') + '</div>',
                '    <div class="ts-result-meta"><span>' + escHtml(r.city) + '</span><span>' + escHtml(r.sector) + '</span><span>SIREN ' + escHtml(r.siren) + '</span></div>',
                '  </div>',
                '  <div class="ts-result-figures">',
                '    <div class="ts-result-ca">' + escHtml(r.ca) + '</div>',
                '    <div class="ts-result-eff">' + escHtml(r.eff) + '</div>',
                '  </div>',
                '</a>',
            ].join('');
        }).join('');
    }

    function renderPagination(current, total) {
        const paginationEl = document.getElementById('ts-pagination');
        if (!paginationEl || total <= 1) { return; }

        let html = '';
        const pages = [];
        if (total <= 7) {
            for (let i = 1; i <= total; i++) { pages.push(i); }
        } else {
            pages.push(1);
            if (current > 3) { pages.push('…'); }
            for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) { pages.push(i); }
            if (current < total - 2) { pages.push('…'); }
            pages.push(total);
        }

        if (current > 1) { html += '<a href="#" class="ts-page-btn" data-page="' + (current - 1) + '">‹</a>'; }
        pages.forEach(function (p) {
            if (p === '…') { html += '<span style="display:flex;align-items:center;color:var(--ts-text-muted);padding:0 4px">…</span>'; }
            else if (p === current) { html += '<span class="ts-page-btn current" aria-current="page">' + p + '</span>'; }
            else { html += '<a href="#" class="ts-page-btn" data-page="' + p + '">' + p + '</a>'; }
        });
        if (current < total) { html += '<a href="#" class="ts-page-btn" data-page="' + (current + 1) + '">›</a>'; }

        paginationEl.innerHTML = html;
    }

    function escHtml(str) {
        if (!str && str !== 0) { return ''; }
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* ─────────────────────────────────────────────
       Indicateur de chargement initial page recherche
    ───────────────────────────────────────────── */
    const urlParams = new URLSearchParams(window.location.search);
    if (searchInput && urlParams.has('q')) {
        searchInput.value = urlParams.get('q');
        if (searchClear) { searchClear.style.display = 'inline'; }
        setTimeout(function () { triggerSearch(1); }, 100);
    }

    /* ─────────────────────────────────────────────
       Hero carousel (slides de contenu)
    ───────────────────────────────────────────── */
    (function () {
        var carousel = document.getElementById('ts-hero-carousel');
        if (!carousel) { return; }

        var slides  = carousel.querySelectorAll('.ts-hero-slide');
        var dots    = document.querySelectorAll('.ts-hero-dot');
        if (slides.length <= 1) { return; }

        var current = 0;
        var total   = slides.length;
        var timer;

        function goTo(n) {
            slides[current].classList.remove('active');
            slides[current].setAttribute('aria-hidden', 'true');
            if (dots[current]) { dots[current].classList.remove('active'); dots[current].setAttribute('aria-selected', 'false'); }
            current = (n + total) % total;
            slides[current].classList.add('active');
            slides[current].setAttribute('aria-hidden', 'false');
            if (dots[current]) { dots[current].classList.add('active'); dots[current].setAttribute('aria-selected', 'true'); }
        }

        function startAuto() {
            clearInterval(timer);
            timer = setInterval(function () { goTo(current + 1); }, 6000);
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () { goTo(parseInt(this.dataset.index, 10)); startAuto(); });
        });

        var heroEl = document.querySelector('.ts-hero');
        if (heroEl) {
            heroEl.addEventListener('mouseenter', function () { clearInterval(timer); });
            heroEl.addEventListener('mouseleave', startAuto);
        }

        startAuto();
    }());

})();
