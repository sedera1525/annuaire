<?php
/**
 * TOPsocietes Theme — functions.php
 * Gestion du thème, Elementor, CPT, scripts/styles.
 */

defined( 'ABSPATH' ) || exit;

define( 'TS_VERSION', '1.2.0' );
define( 'TS_DIR', get_template_directory() );
define( 'TS_URI', get_template_directory_uri() );

/* ─────────────────────────────────────────────
   1. Setup du thème
───────────────────────────────────────────── */
add_action( 'after_setup_theme', 'topsocietes_setup' );
function topsocietes_setup() {
    load_theme_textdomain( 'topsocietes', TS_DIR . '/languages' );

    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] );
    add_theme_support( 'custom-logo', [
        'height'      => 38,
        'width'       => 160,
        'flex-height' => true,
        'flex-width'  => true,
    ] );
    add_theme_support( 'align-wide' );
    add_theme_support( 'responsive-embeds' );
    add_theme_support( 'custom-background', [ 'default-color' => 'fafefc' ] );

    // Menus de navigation
    register_nav_menus( [
        'primary'  => __( 'Menu principal', 'topsocietes' ),
        'footer-1' => __( 'Footer — Annuaire', 'topsocietes' ),
        'footer-2' => __( 'Footer — Services', 'topsocietes' ),
        'footer-3' => __( 'Footer — Légal', 'topsocietes' ),
    ] );

    // Compatibilité Elementor
    add_theme_support( 'elementor' );
}

/* ─────────────────────────────────────────────
   2. Enqueue Scripts & Styles
───────────────────────────────────────────── */
add_action( 'wp_enqueue_scripts', 'topsocietes_assets' );
function topsocietes_assets() {
    // Google Fonts
    wp_enqueue_style(
        'topsocietes-fonts',
        'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500&display=swap',
        [],
        null
    );

    // Thème principal
    wp_enqueue_style(
        'topsocietes-style',
        get_stylesheet_uri(),
        [ 'topsocietes-fonts' ],
        TS_VERSION
    );

    // JS principal
    wp_enqueue_script(
        'topsocietes-main',
        TS_URI . '/assets/js/topsocietes.js',
        [],
        TS_VERSION,
        true
    );

    // Variables JS pour AJAX (recherche)
    wp_localize_script( 'topsocietes-main', 'tsAjax', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'ts_search_nonce' ),
        'i18n'    => [
            'noResults' => __( 'Aucun résultat trouvé.', 'topsocietes' ),
            'loading'   => __( 'Chargement…', 'topsocietes' ),
        ],
    ] );
}

/* ─────────────────────────────────────────────
   3. Compatibilité Elementor
───────────────────────────────────────────── */
add_action( 'elementor/init', 'topsocietes_elementor_init' );
function topsocietes_elementor_init() {
    // Enregistrement des catégories de widgets
    add_action( 'elementor/elements/categories_registered', 'topsocietes_elementor_categories' );
}

function topsocietes_elementor_categories( $elements_manager ) {
    $elements_manager->add_category( 'topsocietes', [
        'title' => __( 'TOPsocietes', 'topsocietes' ),
        'icon'  => 'fa fa-building',
    ] );
}

// Charger les widgets Elementor
add_action( 'elementor/widgets/register', 'topsocietes_register_elementor_widgets' );
function topsocietes_register_elementor_widgets( $widgets_manager ) {
    require_once TS_DIR . '/inc/elementor-widgets.php';

    $widgets_manager->register( new \TOPsocietes\Widgets\Hero_Widget() );
    $widgets_manager->register( new \TOPsocietes\Widgets\Stats_Strip_Widget() );
    $widgets_manager->register( new \TOPsocietes\Widgets\Sector_Card_Widget() );
    $widgets_manager->register( new \TOPsocietes\Widgets\Company_Card_Widget() );
    $widgets_manager->register( new \TOPsocietes\Widgets\Pricing_Card_Widget() );
    $widgets_manager->register( new \TOPsocietes\Widgets\Search_Bar_Widget() );
    $widgets_manager->register( new \TOPsocietes\Widgets\CTA_Banner_Widget() );
}

// Ajouter les styles du thème dans l'éditeur Elementor
add_action( 'elementor/editor/before_enqueue_styles', function () {
    wp_enqueue_style(
        'topsocietes-fonts',
        'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500&display=swap',
        [],
        null
    );
} );


/* ─────────────────────────────────────────────
   4. Réglages du thème
───────────────────────────────────────────── */
require_once TS_DIR . '/inc/theme-settings.php';

/* ─────────────────────────────────────────────
   5. Custom Post Types
───────────────────────────────────────────── */
require_once TS_DIR . '/inc/custom-post-types.php';

/* ─────────────────────────────────────────────
   5. AJAX — Recherche d'entreprises
───────────────────────────────────────────── */
add_action( 'wp_ajax_ts_search_companies',        'topsocietes_ajax_search' );
add_action( 'wp_ajax_nopriv_ts_search_companies', 'topsocietes_ajax_search' );

function topsocietes_ajax_search() {
    check_ajax_referer( 'ts_search_nonce', 'nonce' );

    $query   = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
    $sector  = sanitize_text_field( wp_unslash( $_POST['sector'] ?? '' ) );
    $region  = sanitize_text_field( wp_unslash( $_POST['region'] ?? '' ) );
    $forme   = sanitize_text_field( wp_unslash( $_POST['forme'] ?? '' ) );
    $ca_min  = sanitize_text_field( wp_unslash( $_POST['ca_min'] ?? '' ) );
    $ca_max  = sanitize_text_field( wp_unslash( $_POST['ca_max'] ?? '' ) );
    $paged   = max( 1, intval( $_POST['page'] ?? 1 ) );
    $orderby = sanitize_text_field( wp_unslash( $_POST['orderby'] ?? 'relevance' ) );

    $args = [
        'post_type'      => 'entreprise',
        'posts_per_page' => 20,
        'paged'          => $paged,
    ];

    // Recherche textuelle
    if ( $query ) {
        $args['s'] = $query;
    }

    // Meta query
    $meta_query = [];
    if ( $ca_min ) {
        $meta_query[] = [ 'key' => '_ts_ca', 'value' => floatval( $ca_min ), 'compare' => '>=', 'type' => 'NUMERIC' ];
    }
    if ( $ca_max ) {
        $meta_query[] = [ 'key' => '_ts_ca', 'value' => floatval( $ca_max ), 'compare' => '<=', 'type' => 'NUMERIC' ];
    }
    if ( $forme ) {
        $meta_query[] = [ 'key' => '_ts_forme_juridique', 'value' => $forme, 'compare' => '=' ];
    }
    if ( $meta_query ) {
        $args['meta_query'] = $meta_query;
    }

    // Taxonomy
    $tax_query = [];
    if ( $sector ) {
        $tax_query[] = [ 'taxonomy' => 'secteur', 'field' => 'slug', 'terms' => $sector ];
    }
    if ( $region ) {
        $tax_query[] = [ 'taxonomy' => 'region', 'field' => 'slug', 'terms' => $region ];
    }
    if ( $tax_query ) {
        $args['tax_query'] = $tax_query;
    }

    // Tri
    switch ( $orderby ) {
        case 'ca_desc': $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_ts_ca'; $args['order'] = 'DESC'; break;
        case 'ca_asc':  $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_ts_ca'; $args['order'] = 'ASC';  break;
        case 'eff':     $args['orderby'] = 'meta_value_num'; $args['meta_key'] = '_ts_effectif'; $args['order'] = 'DESC'; break;
        case 'alpha':   $args['orderby'] = 'title'; $args['order'] = 'ASC'; break;
        default:        $args['orderby'] = 'relevance'; break;
    }

    $loop = new WP_Query( $args );
    $results = [];

    if ( $loop->have_posts() ) {
        $rank = ( $paged - 1 ) * 20 + 1;
        while ( $loop->have_posts() ) {
            $loop->the_post();
            $id = get_the_ID();
            $results[] = [
                'id'      => $id,
                'rank'    => $rank++,
                'name'    => get_the_title(),
                'url'     => get_permalink(),
                'city'    => get_post_meta( $id, '_ts_ville', true ),
                'sector'  => wp_get_post_terms( $id, 'secteur', [ 'fields' => 'names' ] )[0] ?? '',
                'siren'   => get_post_meta( $id, '_ts_siren', true ),
                'ca'      => get_post_meta( $id, '_ts_ca_display', true ),
                'eff'     => get_post_meta( $id, '_ts_effectif_display', true ),
                'premium' => (bool) get_post_meta( $id, '_ts_premium', true ),
                'color'   => get_post_meta( $id, '_ts_color', true ) ?: '#3b82f6',
                'letter'  => get_post_meta( $id, '_ts_initiales', true ) ?: strtoupper( substr( get_the_title(), 0, 2 ) ),
                'forme'   => get_post_meta( $id, '_ts_forme_juridique', true ),
                'naf'     => get_post_meta( $id, '_ts_code_naf', true ),
            ];
        }
        wp_reset_postdata();
    }

    wp_send_json_success( [
        'results'    => $results,
        'total'      => $loop->found_posts,
        'pages'      => $loop->max_num_pages,
        'current'    => $paged,
    ] );
}

/* ─────────────────────────────────────────────
   6. Fonctions utilitaires du thème
───────────────────────────────────────────── */

/**
 * Affiche le logo du site (SVG inline ou logo WordPress)
 */
function topsocietes_logo( int $size = 36 ): void {
    if ( has_custom_logo() ) {
        the_custom_logo();
        return;
    }
    ?>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ts-nav-logo" rel="home">
        <?php echo topsocietes_svg_logo( $size ); ?>
        <div class="ts-nav-logo-text">TOP<span>societes</span>.com</div>
    </a>
    <?php
}

/**
 * Retourne le SVG du logo
 */
function topsocietes_svg_logo( int $size = 36 ): string {
    ob_start();
    ?>
    <svg width="<?php echo esc_attr( $size ); ?>" height="<?php echo esc_attr( $size ); ?>" viewBox="0 0 40 40" fill="none" aria-hidden="true">
        <rect x="3"  y="18" width="8" height="18" rx="2" fill="url(#ts-g1)"/>
        <rect x="14" y="10" width="8" height="26" rx="2" fill="url(#ts-g2)"/>
        <rect x="25" y="4"  width="8" height="32" rx="2" fill="url(#ts-g3)"/>
        <path d="M1 38 Q20 28 39 38" stroke="url(#ts-g4)" stroke-width="2.5" fill="none" stroke-linecap="round"/>
        <defs>
            <linearGradient id="ts-g1" x1="0" y1="0" x2="0" y2="1">
                <stop stop-color="#f97316"/><stop offset="1" stop-color="#ef4444"/>
            </linearGradient>
            <linearGradient id="ts-g2" x1="0" y1="0" x2="0" y2="1">
                <stop stop-color="#f59e0b"/><stop offset="1" stop-color="#f97316"/>
            </linearGradient>
            <linearGradient id="ts-g3" x1="0" y1="0" x2="0" y2="1">
                <stop stop-color="#3b82f6"/><stop offset="1" stop-color="#7c3aed"/>
            </linearGradient>
            <linearGradient id="ts-g4" x1="0" y1="0" x2="1" y2="0">
                <stop stop-color="#ef4444"/>
                <stop offset="0.5" stop-color="#f59e0b"/>
                <stop offset="1" stop-color="#3b82f6"/>
            </linearGradient>
        </defs>
    </svg>
    <?php
    return ob_get_clean();
}

/**
 * Retourne les initiales (2 lettres) d'un nom d'entreprise
 */
function topsocietes_initiales( string $name ): string {
    $words = array_filter( explode( ' ', strtoupper( trim( $name ) ) ) );
    if ( count( $words ) >= 2 ) {
        return substr( array_values( $words )[0], 0, 1 ) . substr( array_values( $words )[1], 0, 1 );
    }
    return substr( $name, 0, 2 );
}

/**
 * Formate un chiffre d'affaires
 */
function topsocietes_format_ca( float $ca ): string {
    if ( $ca >= 1_000_000_000 ) {
        return number_format( $ca / 1_000_000_000, 1, ',', ' ' ) . ' Md€';
    }
    if ( $ca >= 1_000_000 ) {
        return number_format( $ca / 1_000_000, 1, ',', ' ' ) . ' M€';
    }
    return number_format( $ca, 0, ',', ' ' ) . ' €';
}

/* ─────────────────────────────────────────────
   7. Breadcrumbs
───────────────────────────────────────────── */
function topsocietes_breadcrumb(): void {
    echo '<nav class="ts-breadcrumb" aria-label="' . esc_attr__( 'Fil d\'Ariane', 'topsocietes' ) . '">';
    echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Accueil', 'topsocietes' ) . '</a>';
    echo '<span class="ts-breadcrumb-sep" aria-hidden="true">›</span>';

    if ( is_singular( 'entreprise' ) ) {
        $sector_terms = get_the_terms( get_the_ID(), 'secteur' );
        if ( $sector_terms && ! is_wp_error( $sector_terms ) ) {
            $term = $sector_terms[0];
            echo '<a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>';
            echo '<span class="ts-breadcrumb-sep" aria-hidden="true">›</span>';
        }
        echo '<span aria-current="page">' . esc_html( get_the_title() ) . '</span>';
    } elseif ( is_archive() ) {
        echo '<span aria-current="page">' . esc_html( post_type_archive_title( '', false ) ) . '</span>';
    } elseif ( is_page() ) {
        echo '<span aria-current="page">' . esc_html( get_the_title() ) . '</span>';
    }

    echo '</nav>';
}

/* ─────────────────────────────────────────────
   8. Widgets / Sidebars
───────────────────────────────────────────── */
add_action( 'widgets_init', 'topsocietes_widgets_init' );
function topsocietes_widgets_init() {
    register_sidebar( [
        'name'          => __( 'Sidebar Fiche Entreprise', 'topsocietes' ),
        'id'            => 'fiche-sidebar',
        'description'   => __( 'Widgets affichés dans la colonne latérale des fiches entreprise.', 'topsocietes' ),
        'before_widget' => '<div class="ts-fiche-card" id="%1$s" class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3>',
        'after_title'   => '</h3>',
    ] );
}

/* ─────────────────────────────────────────────
   9. Sécurité & performances
───────────────────────────────────────────── */

// Désactiver l'API REST pour les utilisateurs non connectés (optionnel)
// add_filter( 'rest_authentication_errors', 'topsocietes_rest_auth' );

// Supprimer les liens inutiles dans le <head>
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );

// Taille des images
add_image_size( 'ts-company-logo',   200, 200, true );
add_image_size( 'ts-company-banner', 1200, 400, true );
add_image_size( 'ts-card-thumb',     600, 400, true );
