<?php
/**
 * MU-Plugin : Palette TOPsocietes — header, footer et global
 *
 * Applique la palette recommandée (retour-6) directement sur le thème
 * sans passer par le plugin ou le backend :
 *   - Header/nav         → gradient #1a2744→#1e3a6e (structure sombre)
 *   - Boutons CTA        → gradient bleu→violet (logo)
 *   - Contenu/cartes     → fond blanc / gris clair
 *   - Footer             → fond sombre + liens clairs
 *
 * Chargé automatiquement comme mu-plugin (aucune activation requise).
 */

add_action('wp_head', function (): void {
    echo '<style id="topsocietes-theme-colors">
/* ================================================================
   PALETTE TOPSOCIETES — retour-6
   Gradient logo  : #2563eb → #7c3aed
   Structure      : #1a2744 → #1e3a6e
   Fond contenu   : #ffffff / #f5f7fa
   Accent positif : #10b981
   Accent alerte  : #e63946
================================================================ */

/* ── HEADER / NAVIGATION ─────────────────────────────────────── */
#apus-header,
.apus-header,
.header-main,
.apus-top-bar,
.top-bar-wrap,
header.site-header,
.site-header,
nav.navbar,
.navbar,
.main-nav,
.primary-menu-container {
    background: linear-gradient(135deg, #1a2744 0%, #1e3a6e 100%) !important;
    border-bottom: none !important;
    box-shadow: 0 2px 12px rgba(0,0,0,.18) !important;
}

/* Logo dans le header */
#apus-header .logo,
.apus-header .logo,
.site-logo,
.navbar-brand {
    filter: brightness(0) invert(1);
}

/* Liens de navigation */
#apus-header .navbar-nav > li > a,
.apus-header .navbar-nav > li > a,
nav.navbar .nav-link,
.header-main a,
.main-nav a {
    color: #e2e8f0 !important;
    font-weight: 600;
}

#apus-header .navbar-nav > li > a:hover,
.apus-header .navbar-nav > li > a:hover,
nav.navbar .nav-link:hover,
.header-main a:hover {
    color: #93c5fd !important;
}

/* Bouton CTA dans le header */
#apus-header .btn,
.apus-header .btn,
.header-main .btn,
.navbar .btn-primary,
.header-btn,
.header-cta {
    background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%) !important;
    border: none !important;
    color: #fff !important;
    border-radius: 8px !important;
    font-weight: 700 !important;
    transition: opacity .2s !important;
}
#apus-header .btn:hover,
.header-cta:hover {
    opacity: .88 !important;
    color: #fff !important;
}

/* ── TOP BAR (barre au-dessus du header) ────────────────────── */
.apus-top-bar,
.top-bar-wrap {
    background: #111d35 !important;
    color: #94a3b8 !important;
}
.apus-top-bar a,
.top-bar-wrap a {
    color: #93c5fd !important;
}

/* ── CONTENU GÉNÉRAL — fond clair ────────────────────────────── */
body,
#page,
#wrapper-container,
.site-content,
#content,
.main-content-area,
.entry-content {
    background: #ffffff !important;
}

/* Sections / blocs Elementor à fond sombre → fond clair */
.elementor-section.has-background:not([class*="sc"]),
.elementor-section[style*="background-color: #1a2744"],
.elementor-section[style*="background-color:#1a2744"],
.elementor-section[style*="background: #1a2744"],
.elementor-section[style*="background:#1a2744"] {
    background: #f5f7fa !important;
}

/* Titres H1-H3 */
h1, h2, h3 {
    color: #1a2744;
}

/* ── BOUTONS GLOBAUX ─────────────────────────────────────────── */
.btn-primary,
.button.button-primary,
input[type="submit"],
button[type="submit"],
a.btn-default,
.elementor-button,
.wc-proceed-to-checkout .button,
.single_add_to_cart_button {
    background: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%) !important;
    border: none !important;
    color: #fff !important;
    font-weight: 700 !important;
    border-radius: 8px !important;
    transition: opacity .2s !important;
    box-shadow: 0 2px 10px rgba(37,99,235,.25) !important;
}
.btn-primary:hover,
.button.button-primary:hover,
input[type="submit"]:hover,
.elementor-button:hover,
a.btn-default:hover {
    opacity: .88 !important;
    color: #fff !important;
}

/* Bouton secondaire */
.btn-secondary,
.button.button-secondary {
    background: #fff !important;
    border: 1.5px solid #2563eb !important;
    color: #2563eb !important;
    font-weight: 600 !important;
    border-radius: 8px !important;
}
.btn-secondary:hover {
    background: #eff6ff !important;
    color: #1d4ed8 !important;
}

/* ── FOOTER ─────────────────────────────────────────────────── */
#apus-footer,
footer.apus-footer,
footer.site-footer,
.site-footer,
#footer,
.footer {
    background: linear-gradient(135deg, #0f1c33 0%, #1a2744 100%) !important;
    color: #94a3b8 !important;
    border-top: 1px solid rgba(255,255,255,.07) !important;
}

/* Titres du footer */
#apus-footer h1,
#apus-footer h2,
#apus-footer h3,
#apus-footer h4,
.site-footer h3,
.site-footer h4,
.footer-widget-title,
.widget-title {
    color: #e2e8f0 !important;
    font-size: 13px !important;
    text-transform: uppercase !important;
    letter-spacing: 1px !important;
}

/* Liens du footer */
#apus-footer a,
footer.site-footer a,
.site-footer a,
.footer a {
    color: #93c5fd !important;
    text-decoration: none !important;
}
#apus-footer a:hover,
footer.site-footer a:hover,
.site-footer a:hover {
    color: #bfdbfe !important;
    text-decoration: underline !important;
}

/* Copyright footer */
.footer-copyright,
.copyright,
#apus-footer .copyright {
    background: #0a1220 !important;
    color: #64748b !important;
    border-top: 1px solid rgba(255,255,255,.05) !important;
    font-size: 12px !important;
}

/* ── CARTES / WIDGETS ────────────────────────────────────────── */
.card,
.widget,
.elementor-widget-container > .elementor-widget-wrap,
.woocommerce ul.products li.product {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 1px 6px rgba(0,0,0,.06);
}

/* Liens globaux */
a {
    color: #2563eb;
}
a:hover {
    color: #1d4ed8;
}

/* Inputs */
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
    border-color: #2563eb !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(37,99,235,.12) !important;
}
</style>';
}, 5);
