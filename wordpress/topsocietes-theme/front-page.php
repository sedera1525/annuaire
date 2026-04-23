<?php
/**
 * Template: Page d'accueil (front-page)
 */
get_header();

/* ─── Hero slides (contenu configurable) ─── */
$defaults = [
    [
        'badge'    => __( 'Europe · Asie · USA — 0 % impôt société', 'topsocietes' ),
        'title'    => __( 'Des solutions', 'topsocietes' ),
        'accent'   => __( 'efficaces, transparentes et abordables', 'topsocietes' ),
        'desc'     => __( 'Pour développer vos activités à l\'international. Création de sociétés, introductions bancaires et structures optimisées dans les principales juridictions mondiales.', 'topsocietes' ),
        'btn_text' => __( 'Créer ma société →', 'topsocietes' ),
        'btn_url'  => '/pack/',
    ],
    [
        'badge'    => '',
        'title'    => __( 'TOPsocietes.com', 'topsocietes' ),
        'accent'   => __( 'accompagne les entrepreneurs', 'topsocietes' ),
        'desc'     => __( 'dans la création ou l\'acquisition de sociétés étrangères, avec une approche claire : rendre l\'international accessible grâce à une tarification volontairement basse, sans compromis sur la conformité ni la qualité.', 'topsocietes' ),
        'btn_text' => __( 'Découvrir nos offres →', 'topsocietes' ),
        'btn_url'  => '/pack/',
    ],
    [
        'badge'    => __( 'Europe · Asie · USA — 0 % impôt société', 'topsocietes' ),
        'title'    => __( 'Création de', 'topsocietes' ),
        'accent'   => __( 'sociétés', 'topsocietes' ),
        'desc'     => __( 'en Europe, Asie et USA, simplement, légalement et à coût maîtrisé.', 'topsocietes' ),
        'btn_text' => __( 'Contactez-nous', 'topsocietes' ),
        'btn_url'  => '/contact/',
    ],
];

$hero_slides = [];
for ( $i = 1; $i <= 4; $i++ ) {
    $def = $defaults[ $i - 1 ];
    if ( '1' !== ts_get_option( "slide_{$i}_active", $i <= 3 ? '1' : '0' ) ) {
        continue;
    }
    $slide = [
        'badge'    => ts_get_option( "slide_{$i}_img",      $def['badge'] ),
        'title'    => ts_get_option( "slide_{$i}_title",    $def['title'] ),
        'accent'   => ts_get_option( "slide_{$i}_accent",   $def['accent'] ),
        'desc'     => ts_get_option( "slide_{$i}_subtitle", $def['desc'] ),
        'btn_text' => ts_get_option( "slide_{$i}_btn_text", $def['btn_text'] ),
        'btn_url'  => ts_get_option( "slide_{$i}_btn_url",  $def['btn_url'] ),
    ];
    if ( $slide['title'] || $slide['accent'] || $slide['desc'] ) {
        $hero_slides[] = $slide;
    }
}
?>

<div class="ts-page">
    <div class="ts-container">

        <!-- ═══ HERO ═══ -->
        <section class="ts-hero">
            <div class="ts-hero-content">

                <?php if ( count( $hero_slides ) <= 1 ) :
                    /* ── Slide unique ou aucune → affichage statique ── */
                    $s = $hero_slides[0] ?? $defaults[0]; ?>
                    <?php if ( $s['badge'] ) : ?>
                    <div class="ts-hero-badge ts-fade-up">
                        <div class="ts-hero-badge-dot"></div>
                        <?php echo esc_html( $s['badge'] ); ?>
                    </div>
                    <?php endif; ?>
                    <h1 class="ts-fade-up">
                        <?php echo esc_html( $s['title'] ); ?>
                        <?php if ( $s['accent'] ) : ?>
                        <span class="ts-gradient"><?php echo esc_html( $s['accent'] ); ?></span>
                        <?php endif; ?>
                    </h1>
                    <?php if ( $s['desc'] ) : ?>
                    <p class="ts-fade-up"><?php echo esc_html( $s['desc'] ); ?></p>
                    <?php endif; ?>
                    <?php if ( $s['btn_text'] && $s['btn_url'] ) : ?>
                    <div class="ts-hero-actions ts-fade-up">
                        <a href="<?php echo esc_url( $s['btn_url'] ); ?>" class="ts-btn-primary">
                            <?php echo esc_html( $s['btn_text'] ); ?>
                        </a>
                        <a href="<?php echo esc_url( home_url( '/recherche/' ) ); ?>" class="ts-btn-secondary">
                            🔍 <?php esc_html_e( 'Rechercher une entreprise', 'topsocietes' ); ?>
                        </a>
                    </div>
                    <?php endif; ?>

                <?php else : ?>
                    <!-- ── Plusieurs slides → carousel ── -->
                    <div class="ts-hero-carousel" id="ts-hero-carousel">
                        <?php foreach ( $hero_slides as $idx => $s ) : ?>
                        <div class="ts-hero-slide <?php echo 0 === $idx ? 'active' : ''; ?>"
                            aria-hidden="<?php echo 0 === $idx ? 'false' : 'true'; ?>">
                            <?php if ( $s['badge'] ) : ?>
                            <div class="ts-hero-badge">
                                <div class="ts-hero-badge-dot"></div>
                                <?php echo esc_html( $s['badge'] ); ?>
                            </div>
                            <?php endif; ?>
                            <h1>
                                <?php echo esc_html( $s['title'] ); ?>
                                <?php if ( $s['accent'] ) : ?>
                                <span class="ts-gradient"><?php echo esc_html( $s['accent'] ); ?></span>
                                <?php endif; ?>
                            </h1>
                            <?php if ( $s['desc'] ) : ?>
                            <p><?php echo esc_html( $s['desc'] ); ?></p>
                            <?php endif; ?>
                            <?php if ( $s['btn_text'] && $s['btn_url'] ) : ?>
                            <div class="ts-hero-actions">
                                <a href="<?php echo esc_url( $s['btn_url'] ); ?>" class="ts-btn-primary">
                                    <?php echo esc_html( $s['btn_text'] ); ?>
                                </a>
                                <a href="<?php echo esc_url( home_url( '/recherche/' ) ); ?>" class="ts-btn-secondary">
                                    🔍 <?php esc_html_e( 'Rechercher une entreprise', 'topsocietes' ); ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Dots navigation -->
                    <div class="ts-hero-dots" role="tablist">
                        <?php foreach ( $hero_slides as $idx => $_ ) : ?>
                        <button class="ts-hero-dot <?php echo 0 === $idx ? 'active' : ''; ?>"
                            role="tab"
                            aria-selected="<?php echo 0 === $idx ? 'true' : 'false'; ?>"
                            aria-label="<?php printf( esc_attr__( 'Slide %d', 'topsocietes' ), $idx + 1 ); ?>"
                            data-index="<?php echo $idx; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div><!-- .ts-hero-content -->

            <!-- Globe décoratif -->
            <div class="ts-hero-visual" aria-hidden="true">
                <div class="ts-globe-ring"></div>
                <div class="ts-globe-ring"></div>
                <div class="ts-globe-ring"></div>
                <div class="ts-globe-core"></div>
                <div class="ts-hero-stat-float" style="top:20%;left:5%">
                    <div class="ts-num">0%</div>
                    <div class="ts-lbl"><?php esc_html_e( 'Impôt sociétés', 'topsocietes' ); ?></div>
                </div>
                <div class="ts-hero-stat-float" style="bottom:22%;right:2%">
                    <div class="ts-num">500 €</div>
                    <div class="ts-lbl"><?php esc_html_e( 'À partir de', 'topsocietes' ); ?></div>
                </div>
            </div>
        </section>
    </div>

    <!-- ═══ STATS STRIP ═══ -->
    <div class="ts-container">
        <div class="ts-stats-strip">
            <?php
            $stats = [
                [ '500 €', __( 'Création dès 500 €', 'topsocietes' ) ],
                [ '0 %',   __( 'Impôt sur les sociétés', 'topsocietes' ) ],
                [ '3',     __( 'Continents couverts', 'topsocietes' ) ],
                [ '48h',   __( 'Délai de création', 'topsocietes' ) ],
            ];
            foreach ( $stats as [ $num, $label ] ) : ?>
                <div class="ts-stat-item">
                    <div class="ts-stat-num"><?php echo esc_html( $num ); ?></div>
                    <div class="ts-stat-label"><?php echo esc_html( $label ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="ts-container">

        <!-- ═══ NOS SOLUTIONS DE CRÉATION ═══ -->
        <section class="ts-section">
            <div class="ts-section-header ts-section-header--center">
                <div class="ts-eyebrow"><?php esc_html_e( 'Nos services', 'topsocietes' ); ?></div>
                <h2 class="ts-section-title">
                    <?php esc_html_e( 'Nos solutions de ', 'topsocietes' ); ?>
                    <span class="ts-accent"><?php esc_html_e( 'création de sociétés', 'topsocietes' ); ?></span>
                </h2>
            </div>

            <div class="ts-services-grid">
                <?php
                $services = [
                    [ '🌍', __( 'Création internationale', 'topsocietes' ),
                      __( 'Création de sociétés dans les principales juridictions à partir de 500 €. Europe, Asie, USA et offshore.', 'topsocietes' ) ],
                    [ '🏛️', __( 'TVA flexible', 'topsocietes' ),
                      __( 'Sociétés avec ou sans numéro de TVA selon votre activité et vos besoins de facturation internationaux.', 'topsocietes' ) ],
                    [ '📉', __( '0 % d\'impôt société', 'topsocietes' ),
                      __( 'Structures légales à 0 % d\'impôt sur les sociétés selon la juridiction et l\'activité exercée.', 'topsocietes' ) ],
                ];
                foreach ( $services as [ $icon, $title, $desc ] ) : ?>
                <div class="ts-sector-card ts-service-card">
                    <div class="ts-service-icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></div>
                    <div class="ts-service-title"><?php echo esc_html( $title ); ?></div>
                    <div class="ts-service-desc"><?php echo esc_html( $desc ); ?></div>
                    <div class="ts-service-check">✓ <?php esc_html_e( 'Inclus dans tous nos packs', 'topsocietes' ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ═══ BANQUE EN LIGNE ═══ -->
        <section style="margin-bottom:80px">
            <div class="ts-cta-banner ts-cta-banner--dark">
                <div class="ts-banque-content">
                    <div class="ts-banque-eyebrow"><?php esc_html_e( 'Banque en ligne', 'topsocietes' ); ?></div>
                    <div class="ts-banque-title">
                        <?php esc_html_e( 'Nos créations de société incluent une ', 'topsocietes' ); ?>
                        <span style="color:#BFD8FF"><?php esc_html_e( 'introduction bancaire', 'topsocietes' ); ?></span>
                        <?php esc_html_e( ' (banque en ligne)', 'topsocietes' ); ?>
                    </div>
                    <div class="ts-banque-desc">
                        <?php esc_html_e( 'Possibilité d\'introduction bancaire dans une banque internationale, en option. Compte professionnel opérationnel dès la création de votre structure.', 'topsocietes' ); ?>
                    </div>
                </div>
                <div class="ts-banque-card" aria-hidden="true">
                    <div style="font-size:48px;margin-bottom:8px">🏦</div>
                    <div class="ts-banque-card-title"><?php esc_html_e( 'Banque', 'topsocietes' ); ?></div>
                    <div class="ts-banque-card-sub"><?php esc_html_e( 'Banque internationale partenaire', 'topsocietes' ); ?></div>
                </div>
            </div>
        </section>

        <!-- ═══ SOCIÉTÉS PRÊTES À L'EMPLOI ═══ -->
        <section class="ts-section" style="padding-top:0">
            <div class="ts-readymade-grid">
                <div>
                    <div class="ts-eyebrow"><?php esc_html_e( 'Disponibilité immédiate', 'topsocietes' ); ?></div>
                    <h2 class="ts-section-title" style="margin-bottom:20px">
                        <?php esc_html_e( 'Sociétés prêtes ', 'topsocietes' ); ?>
                        <span class="ts-accent"><?php esc_html_e( 'à l\'emploi', 'topsocietes' ); ?></span>
                    </h2>
                    <p style="font-size:15px;color:var(--ts-text-muted);line-height:1.8;margin-bottom:28px">
                        <?php esc_html_e( 'Sociétés offshore déjà créées · Antériorité disponible : 2023 à 2025.', 'topsocietes' ); ?><br>
                        <?php esc_html_e( 'Démarrage immédiat de l\'activité. Gagnez du temps avec une structure immédiatement opérationnelle.', 'topsocietes' ); ?>
                    </p>
                    <a href="<?php echo esc_url( home_url( '/recherche/' ) ); ?>" class="ts-btn-primary">
                        <?php esc_html_e( 'Voir les sociétés disponibles →', 'topsocietes' ); ?>
                    </a>
                </div>
                <div class="ts-readymade-features">
                    <?php
                    $features = [
                        [ '📅', __( 'Antériorité 2023–2025', 'topsocietes' ),  __( 'Sociétés avec historique existant', 'topsocietes' ) ],
                        [ '⚡', __( 'Démarrage immédiat', 'topsocietes' ),     __( 'Structure opérationnelle dès J+1', 'topsocietes' ) ],
                        [ '🌐', __( 'Juridictions multiples', 'topsocietes' ), __( 'Europe, Asie, offshore', 'topsocietes' ) ],
                        [ '📄', __( 'Tous documents fournis', 'topsocietes' ), __( 'Statuts, Kbis, certificats', 'topsocietes' ) ],
                    ];
                    foreach ( $features as [ $icon, $title, $desc ] ) : ?>
                    <div class="ts-feature-item">
                        <span class="ts-feature-icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
                        <div>
                            <div class="ts-feature-title"><?php echo esc_html( $title ); ?></div>
                            <div class="ts-feature-desc"><?php echo esc_html( $desc ); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ═══ POURQUOI CHOISIR ═══ -->
        <section style="margin-bottom:80px">
            <div class="ts-cta-banner ts-cta-banner--orange">
                <div style="text-align:center;margin-bottom:40px;width:100%">
                    <div class="ts-why-eyebrow"><?php esc_html_e( 'Nos engagements', 'topsocietes' ); ?></div>
                    <div class="ts-why-title"><?php esc_html_e( 'Pourquoi choisir TopsOcietes.com ?', 'topsocietes' ); ?></div>
                </div>
                <div class="ts-why-grid" style="width:100%">
                    <?php
                    $why = [
                        [ '🌍', __( 'Expertise internationale', 'topsocietes' ) ],
                        [ '🎯', __( 'Solutions sur mesure', 'topsocietes' ) ],
                        [ '⚡', __( 'Rapidité et efficacité', 'topsocietes' ) ],
                        [ '💶', __( 'Tarifs bas', 'topsocietes' ) ],
                        [ '🤝', __( 'Approche transparente', 'topsocietes' ) ],
                    ];
                    foreach ( $why as [ $icon, $label ] ) : ?>
                    <div class="ts-why-item">
                        <div class="ts-why-icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></div>
                        <div class="ts-why-label"><?php echo esc_html( $label ); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ═══ TÉMOIGNAGES ═══ -->
        <section class="ts-section" style="padding-top:0">
            <div class="ts-section-header ts-section-header--center">
                <div class="ts-eyebrow"><?php esc_html_e( 'Avis clients', 'topsocietes' ); ?></div>
                <h2 class="ts-section-title">
                    <?php esc_html_e( 'What Say Our ', 'topsocietes' ); ?>
                    <span class="ts-accent"><?php esc_html_e( 'Customers', 'topsocietes' ); ?></span>
                </h2>
            </div>
            <div class="ts-companies-grid">
                <?php
                $testimonials = [
                    [ 'Pierre M.',  'France → Estonie', 5, __( 'Service rapide et professionnel. Ma société estonienne a été créée en 48h avec introduction bancaire incluse.', 'topsocietes' ) ],
                    [ 'Amira K.',   'France → Dubai',   5, __( 'Excellente équipe, très réactive. Structure 0 % impôt parfaitement adaptée à mon activité de consulting.', 'topsocietes' ) ],
                    [ 'Thomas R.',  'France → Malte',   5, __( 'Prix imbattables et accompagnement de qualité. Je recommande vivement pour toute création internationale.', 'topsocietes' ) ],
                ];
                foreach ( $testimonials as [ $name, $origin, $note, $text ] ) : ?>
                <div class="ts-company-card">
                    <div class="ts-testimonial-stars" aria-label="<?php echo esc_attr( $note . '/5' ); ?>">
                        <?php echo str_repeat( '<span aria-hidden="true">★</span>', (int) $note ); ?>
                    </div>
                    <p class="ts-testimonial-text">"<?php echo esc_html( $text ); ?>"</p>
                    <div class="ts-testimonial-author"><?php echo esc_html( $name ); ?></div>
                    <div class="ts-testimonial-origin"><?php echo esc_html( $origin ); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- ═══ CTA BAS DE PAGE ═══ -->
        <section style="margin-bottom:80px">
            <div class="ts-cta-banner ts-cta-banner--dark">
                <div>
                    <div class="ts-cta-banner-title" style="margin-bottom:8px">
                        <?php esc_html_e( 'Créez votre société — ', 'topsocietes' ); ?>
                        <span style="color:#BFD8FF"><?php esc_html_e( '0 impôt société', 'topsocietes' ); ?></span>
                    </div>
                    <div class="ts-cta-banner-desc">
                        <?php esc_html_e( 'Europe · Asie · USA', 'topsocietes' ); ?>
                        &nbsp;|&nbsp;
                        <?php esc_html_e( '+ Introduction bancaire incluse', 'topsocietes' ); ?>
                    </div>
                </div>
                <a href="https://www.topsocietes.com/" target="_blank" rel="noopener noreferrer" class="ts-btn-primary" style="flex-shrink:0;font-size:15px;padding:14px 32px">
                    <?php esc_html_e( 'Découvrir TOPsocietes.com →', 'topsocietes' ); ?>
                </a>
            </div>
        </section>

    </div><!-- .ts-container -->
</div><!-- .ts-page -->

<?php get_footer();
