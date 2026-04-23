<?php
/**
 * Footer du thème TOPsocietes.
 * Contenu configurable via Apparence › Menus (3 emplacements footer)
 * et Apparence › Réglages du thème.
 */
?>
</div><!-- .ts-site-wrapper -->

<footer class="ts-footer" role="contentinfo">
    <div class="ts-container">
        <div class="ts-footer-bottom">
            <?php topsocietes_logo( 28 ); ?>
            <span class="ts-footer-copyright"><?php echo esc_html( ts_get_option( 'footer_copyright', '© 2006–2026 TOPsocietes.com — Tous droits réservés' ) ); ?></span>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>

<?php
/* ─── Menus footer de secours (aucun menu assigné) ─── */

function topsocietes_footer_col1_fallback(): void {
    $links = [
        __( 'Recherche avancée', 'topsocietes' )     => '/recherche/',
        __( 'Secteurs d\'activité', 'topsocietes' )  => '/recherche/?secteur=',
        __( 'Entreprises par région', 'topsocietes' ) => '/recherche/?region=',
        __( 'Top 500 entreprises', 'topsocietes' )   => '/recherche/?tri=ca',
    ];
    echo '<ul class="ts-footer-links">';
    foreach ( $links as $label => $path ) {
        printf(
            '<li><a href="%s">%s</a></li>',
            esc_url( home_url( $path ) ),
            esc_html( $label )
        );
    }
    echo '</ul>';
}

function topsocietes_footer_col2_fallback(): void {
    $links = [
        __( 'Nos packs', 'topsocietes' )           => '/pack/',
        __( 'Pack Premium', 'topsocietes' )         => '/pack/#premium',
        __( 'Introduction HSBC', 'topsocietes' )    => '/pack/#hsbc',
        __( 'API Entreprises', 'topsocietes' )      => '/pack/#api',
    ];
    echo '<ul class="ts-footer-links">';
    foreach ( $links as $label => $path ) {
        printf(
            '<li><a href="%s">%s</a></li>',
            esc_url( home_url( $path ) ),
            esc_html( $label )
        );
    }
    echo '</ul>';
}

function topsocietes_footer_col3_fallback(): void {
    $links = [
        __( 'Mentions légales', 'topsocietes' )          => '/mentions-legales/',
        __( 'CGV / CGU', 'topsocietes' )                 => '/cgv/',
        __( 'Politique de confidentialité', 'topsocietes' ) => '/confidentialite/',
        __( 'Contact', 'topsocietes' )                   => '/contact/',
    ];
    echo '<ul class="ts-footer-links">';
    foreach ( $links as $label => $path ) {
        printf(
            '<li><a href="%s">%s</a></li>',
            esc_url( home_url( $path ) ),
            esc_html( $label )
        );
    }
    echo '</ul>';
}

/* ─── Icônes SVG réseaux sociaux ─── */

function ts_icon_linkedin(): string {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>';
}
function ts_icon_twitter(): string {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
}
function ts_icon_facebook(): string {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>';
}
function ts_icon_instagram(): string {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>';
}
