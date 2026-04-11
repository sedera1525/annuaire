<?php
/**
 * MU-Plugin : Palette TOPsocietes — header, footer et global (retour-6 + retour-7)
 *
 * Palette tirée du logo :
 *   Orange  #F97316  · Pink    #EC4899  · Violet #8B5CF6
 *   Teal    #06B6D4  · Blue   #3B82F6  · Navy   #1e2d5a (texte seul)
 *
 * Principe : AUCUN fond sombre — tout le contenu sur fond blanc/clair.
 * Le menu principal du thème (topsocietes.com) est masqué sur le sous-domaine.
 */

add_action('wp_head', function (): void {
    echo '<style id="topsocietes-theme-colors">
/* ================================================================
   VARIABLES PALETTE LOGO TOPSOCIETES
================================================================ */
:root {
    --ts-orange:  #F97316;
    --ts-pink:    #EC4899;
    --ts-violet:  #8B5CF6;
    --ts-teal:    #06B6D4;
    --ts-blue:    #3B82F6;
    --ts-navy:    #1e2d5a;
    --ts-grad:    linear-gradient(135deg, #F97316 0%, #EC4899 40%, #8B5CF6 70%, #06B6D4 100%);
    --ts-grad-btn:linear-gradient(135deg, #F97316 0%, #EC4899 100%);
    --ts-light:   #f8fafc;
    --ts-white:   #ffffff;
}

/* ── MASQUER le menu WordPress du thème (point 3 retour-7) ────── */
#apus-header,
.apus-header,
.header-main,
.apus-top-bar,
.top-bar-wrap,
.header-mobile,
#apus-header-mobile,
nav.navbar,
.navbar,
.navbar-default,
.main-nav,
.primary-menu-container,
#main-navigation,
.site-navigation,
.header-navigation {
    display: none !important;
}

/* ── FOND GLOBAL — blanc, pas sombre ─────────────────────────── */
body,
#page,
#wrapper-container,
.site-content,
#content,
.main-content-area,
.entry-content,
#main,
.site-main {
    background: #ffffff !important;
}

/* ── HEADER PERSONNALISÉ via plugin (sc-topbar) ───────────────── */
.sc-topbar {
    background: linear-gradient(135deg, #F97316 0%, #EC4899 40%, #8B5CF6 70%, #06B6D4 100%) !important;
    border: none !important;
    box-shadow: 0 3px 16px rgba(249,115,22,.3) !important;
    padding: 12px 24px !important;
}
.sc-topbar a {
    color: #fff !important;
    font-weight: 600 !important;
    text-shadow: 0 1px 3px rgba(0,0,0,.2) !important;
}
.sc-topbar-logo {
    display: inline-flex !important;
    align-items: center !important;
}
.sc-topbar-logo img {
    height: 36px !important;
    width: auto !important;
    display: block !important;
}
.sc-topbar a:not(.sc-topbar-logo):hover {
    color: rgba(255,255,255,.8) !important;
}

/* ── BOUTONS GLOBAUX — gradient orange→pink ────────────────────── */
.btn-primary,
.button.button-primary,
input[type="submit"],
button[type="submit"],
.elementor-button,
.wc-proceed-to-checkout .button,
.single_add_to_cart_button,
.woocommerce #respond input#submit,
.woocommerce a.button,
.woocommerce button.button {
    background: var(--ts-grad-btn) !important;
    border: none !important;
    color: #fff !important;
    font-weight: 700 !important;
    border-radius: 8px !important;
    transition: opacity .2s !important;
    box-shadow: 0 3px 12px rgba(249,115,22,.3) !important;
}
.btn-primary:hover,
input[type="submit"]:hover,
.elementor-button:hover {
    opacity: .88 !important;
    color: #fff !important;
}

/* Bouton secondaire */
.btn-secondary,
.button.button-secondary {
    background: #fff !important;
    border: 2px solid var(--ts-orange) !important;
    color: var(--ts-orange) !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
}

/* ── FOOTER THÈME — masqué (le plugin injecte le sien) ────────── */
#apus-footer,
footer.apus-footer {
    display: none !important;
}

/* ── FOOTER PERSONNALISÉ via plugin (sc-site-footer) ───────────── */
.sc-site-footer {
    background: linear-gradient(135deg, #F97316 0%, #EC4899 40%, #8B5CF6 70%, #06B6D4 100%) !important;
    color: #fff !important;
    border-top: none !important;
    box-shadow: 0 -3px 16px rgba(249,115,22,.2) !important;
}
.sc-site-footer-links a {
    color: rgba(255,255,255,.85) !important;
}
.sc-site-footer-links a:hover {
    color: #fff !important;
    text-decoration: underline !important;
}
.sc-site-footer-copy {
    color: rgba(255,255,255,.6) !important;
}

/* ── TITRES ──────────────────────────────────────────────────────── */
h1, h2, h3 {
    color: var(--ts-navy);
}

/* ── LIENS ───────────────────────────────────────────────────────── */
a {
    color: var(--ts-blue);
}
a:hover {
    color: var(--ts-orange);
}

/* ── INPUTS ──────────────────────────────────────────────────────── */
input[type="text"],
input[type="email"],
input[type="search"],
textarea,
select {
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 8px !important;
    background: #fff !important;
    color: #1f2937 !important;
}
input:focus,
textarea:focus {
    border-color: var(--ts-orange) !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(249,115,22,.12) !important;
}
</style>';
}, 5);
