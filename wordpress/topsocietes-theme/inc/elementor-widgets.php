<?php
namespace TOPsocietes\Widgets;

/**
 * Widgets Elementor personnalisés — TOPsocietes
 *
 * Nécessite le plugin Elementor (>= 3.0).
 * Chaque widget est enregistré dans functions.php via
 * topsocietes_register_elementor_widgets().
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;
use Elementor\Group_Control_Typography;

/* ═══════════════════════════════════════════════════
   1. WIDGET : Hero Section
═══════════════════════════════════════════════════ */
class Hero_Widget extends Widget_Base {

    public function get_name(): string    { return 'ts_hero'; }
    public function get_title(): string   { return __( 'TOPsocietes — Hero', 'topsocietes' ); }
    public function get_icon(): string    { return 'eicon-banner'; }
    public function get_categories(): array { return [ 'topsocietes' ]; }
    public function get_keywords(): array { return [ 'hero', 'topsocietes', 'bannière' ]; }

    protected function register_controls(): void {
        // CONTENT TAB
        $this->start_controls_section( 'section_hero_content', [
            'label' => __( 'Contenu', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'badge_text', [
            'label'   => __( 'Texte du badge', 'topsocietes' ),
            'type'    => Controls_Manager::TEXT,
            'default' => __( 'Depuis 2006 — La référence des entreprises françaises', 'topsocietes' ),
        ] );

        $this->add_control( 'heading', [
            'label'   => __( 'Titre principal', 'topsocietes' ),
            'type'    => Controls_Manager::TEXTAREA,
            'default' => __( 'L\'annuaire <span class="ts-gradient">premium</span> des sociétés françaises', 'topsocietes' ),
        ] );

        $this->add_control( 'description', [
            'label'   => __( 'Description', 'topsocietes' ),
            'type'    => Controls_Manager::TEXTAREA,
            'default' => __( 'Accédez aux données financières, dirigeants et informations légales de plus de 4 millions d\'entreprises.', 'topsocietes' ),
        ] );

        $this->add_control( 'primary_btn_text', [
            'label'   => __( 'Bouton principal — texte', 'topsocietes' ),
            'type'    => Controls_Manager::TEXT,
            'default' => '🔍 Rechercher une entreprise',
        ] );

        $this->add_control( 'primary_btn_url', [
            'label'   => __( 'Bouton principal — lien', 'topsocietes' ),
            'type'    => Controls_Manager::URL,
            'default' => [ 'url' => '/recherche/' ],
        ] );

        $this->add_control( 'secondary_btn_text', [
            'label'   => __( 'Bouton secondaire — texte', 'topsocietes' ),
            'type'    => Controls_Manager::TEXT,
            'default' => __( 'Voir nos offres', 'topsocietes' ),
        ] );

        $this->add_control( 'secondary_btn_url', [
            'label'   => __( 'Bouton secondaire — lien', 'topsocietes' ),
            'type'    => Controls_Manager::URL,
            'default' => [ 'url' => '/tarifs/' ],
        ] );

        $this->add_control( 'show_globe', [
            'label'   => __( 'Afficher le globe décoratif', 'topsocietes' ),
            'type'    => Controls_Manager::SWITCHER,
            'default' => 'yes',
        ] );

        $this->end_controls_section();

        // Stats flottantes
        $this->start_controls_section( 'section_stats', [
            'label'     => __( 'Stats flottantes', 'topsocietes' ),
            'tab'       => Controls_Manager::TAB_CONTENT,
            'condition' => [ 'show_globe' => 'yes' ],
        ] );

        $repeater = new Repeater();
        $repeater->add_control( 'stat_num', [ 'label' => __( 'Valeur', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => '4,2M+' ] );
        $repeater->add_control( 'stat_lbl', [ 'label' => __( 'Label', 'topsocietes' ),  'type' => Controls_Manager::TEXT, 'default' => 'Entreprises' ] );
        $repeater->add_control( 'stat_top',  [ 'label' => __( 'Position top (%)', 'topsocietes' ),  'type' => Controls_Manager::TEXT, 'default' => '20%' ] );
        $repeater->add_control( 'stat_left', [ 'label' => __( 'Position left (%)', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => '5%' ] );

        $this->add_control( 'stats', [
            'label'   => __( 'Stats', 'topsocietes' ),
            'type'    => Controls_Manager::REPEATER,
            'fields'  => $repeater->get_controls(),
            'default' => [
                [ 'stat_num' => '4,2M+', 'stat_lbl' => 'Entreprises',   'stat_top' => '20%',  'stat_left' => '5%' ],
                [ 'stat_num' => '18 ans', 'stat_lbl' => "d'expertise",   'stat_top' => '',     'stat_left' => '' ],
            ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $s = $this->get_settings_for_display();
        ?>
        <section class="ts-hero">
            <div class="ts-hero-content ts-fade-up">
                <?php if ( $s['badge_text'] ) : ?>
                <div class="ts-hero-badge">
                    <div class="ts-hero-badge-dot"></div>
                    <?php echo esc_html( $s['badge_text'] ); ?>
                </div>
                <?php endif; ?>

                <h1><?php echo wp_kses_post( $s['heading'] ); ?></h1>

                <?php if ( $s['description'] ) : ?>
                <p><?php echo esc_html( $s['description'] ); ?></p>
                <?php endif; ?>

                <div class="ts-hero-actions">
                    <?php if ( $s['primary_btn_text'] ) : ?>
                    <a href="<?php echo esc_url( $s['primary_btn_url']['url'] ); ?>" class="ts-btn-primary"><?php echo esc_html( $s['primary_btn_text'] ); ?></a>
                    <?php endif; ?>
                    <?php if ( $s['secondary_btn_text'] ) : ?>
                    <a href="<?php echo esc_url( $s['secondary_btn_url']['url'] ); ?>" class="ts-btn-secondary"><?php echo esc_html( $s['secondary_btn_text'] ); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ( 'yes' === $s['show_globe'] ) : ?>
            <div class="ts-hero-visual" aria-hidden="true">
                <div class="ts-globe-ring"></div>
                <div class="ts-globe-ring"></div>
                <div class="ts-globe-ring"></div>
                <div class="ts-globe-core"></div>
                <?php foreach ( $s['stats'] as $stat ) : ?>
                <div class="ts-hero-stat-float" style="top:<?php echo esc_attr( $stat['stat_top'] ); ?>;left:<?php echo esc_attr( $stat['stat_left'] ); ?>">
                    <div class="ts-num"><?php echo esc_html( $stat['stat_num'] ); ?></div>
                    <div class="ts-lbl"><?php echo esc_html( $stat['stat_lbl'] ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php
    }
}

/* ═══════════════════════════════════════════════════
   2. WIDGET : Stats Strip
═══════════════════════════════════════════════════ */
class Stats_Strip_Widget extends Widget_Base {

    public function get_name(): string    { return 'ts_stats_strip'; }
    public function get_title(): string   { return __( 'TOPsocietes — Bande de stats', 'topsocietes' ); }
    public function get_icon(): string    { return 'eicon-counter'; }
    public function get_categories(): array { return [ 'topsocietes' ]; }

    protected function register_controls(): void {
        $this->start_controls_section( 'section_stats', [
            'label' => __( 'Statistiques', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );

        $repeater = new Repeater();
        $repeater->add_control( 'num',   [ 'label' => __( 'Valeur', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => '4 200 000+' ] );
        $repeater->add_control( 'label', [ 'label' => __( 'Label', 'topsocietes' ),  'type' => Controls_Manager::TEXT, 'default' => __( 'Entreprises répertoriées', 'topsocietes' ) ] );

        $this->add_control( 'items', [
            'label'   => __( 'Items', 'topsocietes' ),
            'type'    => Controls_Manager::REPEATER,
            'fields'  => $repeater->get_controls(),
            'default' => [
                [ 'num' => '4 200 000+', 'label' => __( 'Entreprises répertoriées', 'topsocietes' ) ],
                [ 'num' => '12 M',       'label' => __( 'Données financières', 'topsocietes' ) ],
                [ 'num' => '850 000',    'label' => __( 'Recherches / mois', 'topsocietes' ) ],
                [ 'num' => '98%',        'label' => __( 'Données à jour', 'topsocietes' ) ],
            ],
        ] );

        $this->end_controls_section();
    }

    protected function render(): void {
        $items = $this->get_settings_for_display( 'items' );
        echo '<div class="ts-container"><div class="ts-stats-strip">';
        foreach ( $items as $item ) {
            echo '<div class="ts-stat-item"><div class="ts-stat-num">' . esc_html( $item['num'] ) . '</div>';
            echo '<div class="ts-stat-label">' . esc_html( $item['label'] ) . '</div></div>';
        }
        echo '</div></div>';
    }
}

/* ═══════════════════════════════════════════════════
   3. WIDGET : Sector Card Grid
═══════════════════════════════════════════════════ */
class Sector_Card_Widget extends Widget_Base {

    public function get_name(): string    { return 'ts_sector_grid'; }
    public function get_title(): string   { return __( 'TOPsocietes — Grille secteurs', 'topsocietes' ); }
    public function get_icon(): string    { return 'eicon-gallery-grid'; }
    public function get_categories(): array { return [ 'topsocietes' ]; }

    protected function register_controls(): void {
        $this->start_controls_section( 'section_header', [
            'label' => __( 'En-tête de section', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'eyebrow', [ 'label' => __( 'Eyebrow', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Secteurs d\'activité', 'topsocietes' ) ] );
        $this->add_control( 'title',   [ 'label' => __( 'Titre', 'topsocietes' ),   'type' => Controls_Manager::TEXT, 'default' => 'Explorez par <span class="ts-accent">secteur</span>' ] );
        $this->end_controls_section();

        $this->start_controls_section( 'section_source', [
            'label' => __( 'Source de données', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'source', [
            'label'   => __( 'Source', 'topsocietes' ),
            'type'    => Controls_Manager::SELECT,
            'default' => 'taxonomy',
            'options' => [
                'taxonomy' => __( 'Taxonomie WordPress', 'topsocietes' ),
                'manual'   => __( 'Saisie manuelle', 'topsocietes' ),
            ],
        ] );
        $this->add_control( 'limit', [ 'label' => __( 'Nombre', 'topsocietes' ), 'type' => Controls_Manager::NUMBER, 'default' => 8, 'min' => 1, 'max' => 24, 'condition' => [ 'source' => 'taxonomy' ] ] );

        $repeater = new Repeater();
        $repeater->add_control( 'icon',  [ 'label' => __( 'Emoji/Icône', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => '🏗️' ] );
        $repeater->add_control( 'name',  [ 'label' => __( 'Nom', 'topsocietes' ),         'type' => Controls_Manager::TEXT, 'default' => 'BTP & Construction' ] );
        $repeater->add_control( 'count', [ 'label' => __( 'Nombre', 'topsocietes' ),      'type' => Controls_Manager::TEXT, 'default' => '42 800 sociétés' ] );
        $repeater->add_control( 'url',   [ 'label' => __( 'Lien', 'topsocietes' ),        'type' => Controls_Manager::URL ] );

        $this->add_control( 'items', [
            'label'     => __( 'Secteurs', 'topsocietes' ),
            'type'      => Controls_Manager::REPEATER,
            'fields'    => $repeater->get_controls(),
            'condition' => [ 'source' => 'manual' ],
        ] );
        $this->end_controls_section();
    }

    protected function render(): void {
        $s = $this->get_settings_for_display();
        ?>
        <section class="ts-section">
            <?php if ( $s['eyebrow'] || $s['title'] ) : ?>
            <div class="ts-section-header">
                <?php if ( $s['eyebrow'] ) : ?><div class="ts-eyebrow"><?php echo esc_html( $s['eyebrow'] ); ?></div><?php endif; ?>
                <?php if ( $s['title'] ) : ?><h2 class="ts-section-title"><?php echo wp_kses_post( $s['title'] ); ?></h2><?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="ts-sectors-grid">
                <?php if ( 'taxonomy' === $s['source'] ) :
                    $terms = get_terms( [ 'taxonomy' => 'secteur', 'hide_empty' => false, 'number' => $s['limit'] ] );
                    if ( ! is_wp_error( $terms ) && $terms ) :
                        foreach ( $terms as $term ) :
                            $icon = get_term_meta( $term->term_id, 'icon', true ) ?: '🏢';
                            $link = get_term_link( $term );
                        ?>
                        <a href="<?php echo esc_url( is_wp_error( $link ) ? '#' : $link ); ?>" class="ts-sector-card">
                            <div class="ts-sector-icon"><?php echo esc_html( $icon ); ?></div>
                            <div class="ts-sector-name"><?php echo esc_html( $term->name ); ?></div>
                            <div class="ts-sector-count"><?php echo esc_html( number_format( $term->count, 0, ',', ' ' ) . ' ' . __( 'sociétés', 'topsocietes' ) ); ?></div>
                        </a>
                        <?php endforeach;
                    endif;
                else :
                    foreach ( $s['items'] as $item ) : ?>
                    <a href="<?php echo esc_url( $item['url']['url'] ?? '#' ); ?>" class="ts-sector-card">
                        <div class="ts-sector-icon"><?php echo esc_html( $item['icon'] ); ?></div>
                        <div class="ts-sector-name"><?php echo esc_html( $item['name'] ); ?></div>
                        <div class="ts-sector-count"><?php echo esc_html( $item['count'] ); ?></div>
                    </a>
                    <?php endforeach;
                endif; ?>
            </div>
        </section>
        <?php
    }
}

/* ═══════════════════════════════════════════════════
   4. WIDGET : Company Card Grid
═══════════════════════════════════════════════════ */
class Company_Card_Widget extends Widget_Base {

    public function get_name(): string    { return 'ts_company_grid'; }
    public function get_title(): string   { return __( 'TOPsocietes — Grille entreprises', 'topsocietes' ); }
    public function get_icon(): string    { return 'eicon-posts-grid'; }
    public function get_categories(): array { return [ 'topsocietes' ]; }

    protected function register_controls(): void {
        $this->start_controls_section( 'section_header', [
            'label' => __( 'En-tête', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'eyebrow', [ 'label' => __( 'Eyebrow', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Profils premium', 'topsocietes' ) ] );
        $this->add_control( 'title',   [ 'label' => __( 'Titre', 'topsocietes' ),   'type' => Controls_Manager::TEXT, 'default' => 'Entreprises <span class="ts-accent">en vedette</span>' ] );
        $this->end_controls_section();

        $this->start_controls_section( 'section_query', [
            'label' => __( 'Requête', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'limit',   [ 'label' => __( 'Nombre', 'topsocietes' ),         'type' => Controls_Manager::NUMBER, 'default' => 6 ] );
        $this->add_control( 'premium', [ 'label' => __( 'Premium uniquement', 'topsocietes' ), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ] );
        $this->add_control( 'orderby', [
            'label'   => __( 'Trier par', 'topsocietes' ),
            'type'    => Controls_Manager::SELECT,
            'default' => 'date',
            'options' => [
                'date'  => __( 'Date', 'topsocietes' ),
                'ca'    => __( 'Chiffre d\'affaires', 'topsocietes' ),
                'title' => __( 'Alphabétique', 'topsocietes' ),
            ],
        ] );
        $this->end_controls_section();
    }

    protected function render(): void {
        $s = $this->get_settings_for_display();

        $args = [
            'post_type'      => 'entreprise',
            'posts_per_page' => $s['limit'],
        ];
        if ( 'yes' === $s['premium'] ) {
            $args['meta_key']   = '_ts_premium';
            $args['meta_value'] = '1';
        }
        if ( 'ca' === $s['orderby'] ) {
            $args['orderby'] = 'meta_value_num';
            $args['meta_key'] = '_ts_ca';
            $args['order'] = 'DESC';
        } else {
            $args['orderby'] = $s['orderby'];
        }

        $loop = new \WP_Query( $args );
        ?>
        <section class="ts-section" style="padding-top:0">
            <?php if ( $s['eyebrow'] || $s['title'] ) : ?>
            <div class="ts-section-header">
                <?php if ( $s['eyebrow'] ) : ?><div class="ts-eyebrow"><?php echo esc_html( $s['eyebrow'] ); ?></div><?php endif; ?>
                <?php if ( $s['title'] ) : ?><h2 class="ts-section-title"><?php echo wp_kses_post( $s['title'] ); ?></h2><?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="ts-companies-grid">
                <?php while ( $loop->have_posts() ) :
                    $loop->the_post();
                    $pid      = get_the_ID();
                    $color    = get_post_meta( $pid, '_ts_color', true )   ?: '#3b82f6';
                    $initials = get_post_meta( $pid, '_ts_initiales', true ) ?: topsocietes_initiales( get_the_title() );
                    $city     = get_post_meta( $pid, '_ts_ville', true );
                    $ca       = get_post_meta( $pid, '_ts_ca_display', true );
                    $eff      = get_post_meta( $pid, '_ts_effectif_display', true );
                    $sector   = wp_get_post_terms( $pid, 'secteur', [ 'fields' => 'names' ] );
                ?>
                <a href="<?php the_permalink(); ?>" class="ts-company-card">
                    <div class="ts-company-card-header">
                        <div class="ts-company-avatar" style="background:<?php echo esc_attr( $color ); ?>22;color:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $initials ); ?></div>
                        <div>
                            <div class="ts-company-name"><?php the_title(); ?></div>
                            <?php if ( $city ) : ?><div class="ts-company-city">📍 <?php echo esc_html( $city ); ?></div><?php endif; ?>
                        </div>
                    </div>
                    <?php if ( $sector && ! is_wp_error( $sector ) ) : ?>
                    <div class="ts-company-tags"><span class="ts-tag"><?php echo esc_html( $sector[0] ); ?></span></div>
                    <?php endif; ?>
                    <div class="ts-company-ca">
                        <div>
                            <div class="ts-company-ca-label"><?php esc_html_e( 'Chiffre d\'affaires', 'topsocietes' ); ?></div>
                            <div class="ts-company-ca-val"><?php echo esc_html( $ca ?: '–' ); ?></div>
                        </div>
                        <?php if ( $eff ) : ?>
                        <div style="text-align:right">
                            <div class="ts-company-ca-label"><?php esc_html_e( 'Effectif', 'topsocietes' ); ?></div>
                            <div class="ts-company-eff"><?php echo esc_html( $eff ); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endwhile; wp_reset_postdata(); ?>
            </div>
        </section>
        <?php
    }
}

/* ═══════════════════════════════════════════════════
   5. WIDGET : Pricing Card
═══════════════════════════════════════════════════ */
class Pricing_Card_Widget extends Widget_Base {

    public function get_name(): string    { return 'ts_pricing_card'; }
    public function get_title(): string   { return __( 'TOPsocietes — Carte tarif', 'topsocietes' ); }
    public function get_icon(): string    { return 'eicon-price-table'; }
    public function get_categories(): array { return [ 'topsocietes' ]; }

    protected function register_controls(): void {
        $this->start_controls_section( 'section_plan', [
            'label' => __( 'Plan', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'plan_name',    [ 'label' => __( 'Nom du plan', 'topsocietes' ),    'type' => Controls_Manager::TEXT, 'default' => 'Pro' ] );
        $this->add_control( 'plan_desc',    [ 'label' => __( 'Description', 'topsocietes' ),    'type' => Controls_Manager::TEXTAREA, 'default' => __( 'Idéal pour les professionnels de la prospection.', 'topsocietes' ) ] );
        $this->add_control( 'plan_price',   [ 'label' => __( 'Prix (€/mois)', 'topsocietes' ), 'type' => Controls_Manager::NUMBER, 'default' => 49 ] );
        $this->add_control( 'is_featured',  [ 'label' => __( 'Plan mis en avant', 'topsocietes' ), 'type' => Controls_Manager::SWITCHER ] );
        $this->add_control( 'badge',        [ 'label' => __( 'Badge', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Le plus populaire', 'topsocietes' ), 'condition' => [ 'is_featured' => 'yes' ] ] );
        $this->add_control( 'btn_text',     [ 'label' => __( 'Bouton', 'topsocietes' ),        'type' => Controls_Manager::TEXT, 'default' => __( 'Commencer l\'essai', 'topsocietes' ) ] );
        $this->add_control( 'btn_url',      [ 'label' => __( 'Lien bouton', 'topsocietes' ),   'type' => Controls_Manager::URL ] );

        $repeater = new Repeater();
        $repeater->add_control( 'feature',  [ 'label' => __( 'Fonctionnalité', 'topsocietes' ), 'type' => Controls_Manager::TEXT ] );
        $repeater->add_control( 'included', [ 'label' => __( 'Incluse', 'topsocietes' ), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes' ] );
        $this->add_control( 'features',    [ 'label' => __( 'Fonctionnalités', 'topsocietes' ), 'type' => Controls_Manager::REPEATER, 'fields' => $repeater->get_controls() ] );
        $this->end_controls_section();
    }

    protected function render(): void {
        $s = $this->get_settings_for_display();
        $featured = 'yes' === $s['is_featured'];
        ?>
        <div class="ts-pricing-card<?php echo $featured ? ' featured' : ''; ?>">
            <?php if ( $featured && $s['badge'] ) : ?>
                <div class="ts-plan-badge"><?php echo esc_html( $s['badge'] ); ?></div>
            <?php endif; ?>
            <div class="ts-plan-name"><?php echo esc_html( $s['plan_name'] ); ?></div>
            <div class="ts-plan-desc"><?php echo esc_html( $s['plan_desc'] ); ?></div>
            <div class="ts-plan-price">
                <span class="ts-plan-currency">€</span>
                <span class="ts-plan-amount"><?php echo esc_html( $s['plan_price'] ); ?></span>
                <span class="ts-plan-period">/mois</span>
            </div>
            <div class="ts-plan-divider"></div>
            <ul class="ts-plan-features">
                <?php foreach ( $s['features'] as $f ) : $inc = 'yes' === $f['included']; ?>
                <li class="ts-plan-feature<?php echo $inc ? ' included' : ''; ?>">
                    <span class="ts-feature-check <?php echo $inc ? 'ts-check-yes' : 'ts-check-no'; ?>"><?php echo $inc ? '✓' : '✕'; ?></span>
                    <?php echo esc_html( $f['feature'] ); ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <a href="<?php echo esc_url( $s['btn_url']['url'] ?? '#' ); ?>" class="<?php echo $featured ? 'ts-btn-primary' : 'ts-btn-outline'; ?>" style="width:100%;justify-content:center">
                <?php echo esc_html( $s['btn_text'] ); ?>
            </a>
        </div>
        <?php
    }
}

/* ═══════════════════════════════════════════════════
   6. WIDGET : Search Bar
═══════════════════════════════════════════════════ */
class Search_Bar_Widget extends Widget_Base {

    public function get_name(): string    { return 'ts_search_bar'; }
    public function get_title(): string   { return __( 'TOPsocietes — Barre de recherche', 'topsocietes' ); }
    public function get_icon(): string    { return 'eicon-search'; }
    public function get_categories(): array { return [ 'topsocietes' ]; }

    protected function register_controls(): void {
        $this->start_controls_section( 'section_content', [
            'label' => __( 'Contenu', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'placeholder', [ 'label' => __( 'Placeholder', 'topsocietes' ), 'type' => Controls_Manager::TEXT, 'default' => __( 'Nom, SIREN, ville, secteur...', 'topsocietes' ) ] );
        $this->add_control( 'btn_text',    [ 'label' => __( 'Bouton', 'topsocietes' ),      'type' => Controls_Manager::TEXT, 'default' => __( 'Rechercher', 'topsocietes' ) ] );
        $this->add_control( 'target_url',  [ 'label' => __( 'Page de résultats', 'topsocietes' ), 'type' => Controls_Manager::URL, 'default' => [ 'url' => '/recherche/' ] ] );
        $this->end_controls_section();
    }

    protected function render(): void {
        $s      = $this->get_settings_for_display();
        $action = $s['target_url']['url'] ?: home_url( '/recherche/' );
        ?>
        <form class="ts-search-bar-wrapper" action="<?php echo esc_url( $action ); ?>" method="get" role="search">
            <div class="ts-search-input-group">
                <span class="ts-search-icon" aria-hidden="true">🔍</span>
                <input type="search" name="q" class="ts-search-input" placeholder="<?php echo esc_attr( $s['placeholder'] ); ?>" aria-label="<?php echo esc_attr( $s['placeholder'] ); ?>">
            </div>
            <button type="submit" class="ts-search-btn"><?php echo esc_html( $s['btn_text'] ); ?></button>
        </form>
        <?php
    }
}

/* ═══════════════════════════════════════════════════
   7. WIDGET : CTA Banner
═══════════════════════════════════════════════════ */
class CTA_Banner_Widget extends Widget_Base {

    public function get_name(): string    { return 'ts_cta_banner'; }
    public function get_title(): string   { return __( 'TOPsocietes — Bannière CTA', 'topsocietes' ); }
    public function get_icon(): string    { return 'eicon-call-to-action'; }
    public function get_categories(): array { return [ 'topsocietes' ]; }

    protected function register_controls(): void {
        $this->start_controls_section( 'section_cta', [
            'label' => __( 'Contenu', 'topsocietes' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'style', [
            'label'   => __( 'Style', 'topsocietes' ),
            'type'    => Controls_Manager::SELECT,
            'default' => 'dark',
            'options' => [
                'dark'   => __( 'Sombre (bleu)', 'topsocietes' ),
                'orange' => __( 'Orange', 'topsocietes' ),
            ],
        ] );
        $this->add_control( 'title',    [ 'label' => __( 'Titre', 'topsocietes' ),       'type' => Controls_Manager::TEXT,     'default' => __( 'Mettez votre entreprise en avant', 'topsocietes' ) ] );
        $this->add_control( 'desc',     [ 'label' => __( 'Description', 'topsocietes' ), 'type' => Controls_Manager::TEXTAREA, 'default' => __( 'Accédez à des milliers de décideurs qualifiés.', 'topsocietes' ) ] );
        $this->add_control( 'btn_text', [ 'label' => __( 'Bouton', 'topsocietes' ),      'type' => Controls_Manager::TEXT,     'default' => __( 'Découvrir nos offres →', 'topsocietes' ) ] );
        $this->add_control( 'btn_url',  [ 'label' => __( 'Lien', 'topsocietes' ),        'type' => Controls_Manager::URL,      'default' => [ 'url' => '/tarifs/' ] ] );
        $this->end_controls_section();
    }

    protected function render(): void {
        $s = $this->get_settings_for_display();
        $modifier = 'orange' === $s['style'] ? 'ts-cta-banner--orange' : 'ts-cta-banner--dark';
        ?>
        <div class="ts-cta-banner <?php echo esc_attr( $modifier ); ?>">
            <div>
                <div class="ts-cta-banner-title"><?php echo esc_html( $s['title'] ); ?></div>
                <?php if ( $s['desc'] ) : ?>
                    <p class="ts-cta-banner-desc"><?php echo esc_html( $s['desc'] ); ?></p>
                <?php endif; ?>
            </div>
            <a href="<?php echo esc_url( $s['btn_url']['url'] ?? '#' ); ?>" class="ts-btn-primary" style="flex-shrink:0">
                <?php echo esc_html( $s['btn_text'] ); ?>
            </a>
        </div>
        <?php
    }
}
