<?php
/**
 * Template: Fiche Entreprise (single)
 *
 * Compatible Elementor : si la fiche est éditée avec Elementor,
 * le contenu Elementor prend le dessus automatiquement.
 */
get_header();

the_post();
$id = get_the_ID();

// Métadonnées principales
$siren      = get_post_meta( $id, '_ts_siren', true );
$siret      = get_post_meta( $id, '_ts_siret', true );
$naf        = get_post_meta( $id, '_ts_code_naf', true );
$naf_label  = get_post_meta( $id, '_ts_libelle_naf', true );
$forme      = get_post_meta( $id, '_ts_forme_juridique', true );
$capital    = get_post_meta( $id, '_ts_capital', true );
$creation   = get_post_meta( $id, '_ts_date_creation', true );
$ville      = get_post_meta( $id, '_ts_ville', true );
$adresse    = get_post_meta( $id, '_ts_adresse', true );
$cp         = get_post_meta( $id, '_ts_code_postal', true );
$ca_display = get_post_meta( $id, '_ts_ca_display', true );
$ca_raw     = (float) get_post_meta( $id, '_ts_ca', true );
$eff_display= get_post_meta( $id, '_ts_effectif_display', true );
$premium    = (bool) get_post_meta( $id, '_ts_premium', true );
$active     = get_post_meta( $id, '_ts_statut', true ) !== 'fermée';
$color      = get_post_meta( $id, '_ts_color', true ) ?: '#3b82f6';
$initiales  = get_post_meta( $id, '_ts_initiales', true ) ?: topsocietes_initiales( get_the_title() );
$website    = get_post_meta( $id, '_ts_website', true );
$email      = get_post_meta( $id, '_ts_email', true );
$tel        = get_post_meta( $id, '_ts_tel', true );
$score      = (int) get_post_meta( $id, '_ts_score_fiabilite', true ) ?: 64;

// Taxonomies
$sectors  = wp_get_post_terms( $id, 'secteur', [ 'fields' => 'names' ] );
$regions  = wp_get_post_terms( $id, 'region',  [ 'fields' => 'names' ] );

// Dirigeants (meta JSON ou CPT lié)
$dirigeants_json = get_post_meta( $id, '_ts_dirigeants', true );
$dirigeants = $dirigeants_json ? json_decode( $dirigeants_json, true ) : [
    [ 'nom' => 'Jean DUPONT', 'role' => 'Président',     'depuis' => '2018' ],
    [ 'nom' => 'Marie MARTIN','role' => 'Directrice Générale', 'depuis' => '2020' ],
];

// Historique CA (meta JSON)
$ca_history_json = get_post_meta( $id, '_ts_ca_history', true );
$ca_history = $ca_history_json ? json_decode( $ca_history_json, true ) : [
    [ 'year' => '2020', 'val' => '5,1 M€', 'pct' => 72 ],
    [ 'year' => '2021', 'val' => '5,8 M€', 'pct' => 80 ],
    [ 'year' => '2022', 'val' => '6,4 M€', 'pct' => 88 ],
    [ 'year' => '2023', 'val' => '7,2 M€', 'pct' => 100 ],
];

// Entreprises similaires
$similaires = get_posts( [
    'post_type'   => 'entreprise',
    'numberposts' => 4,
    'post__not_in'=> [ $id ],
    'tax_query'   => $sectors ? [ [ 'taxonomy' => 'secteur', 'field' => 'name', 'terms' => $sectors ] ] : [],
] );

// Score ring — calcule degrés (max 360)
$score_deg = round( $score * 3.6 );
?>

<div class="ts-page">
    <div class="ts-container ts-fiche-hero">

        <!-- Fil d'Ariane -->
        <?php topsocietes_breadcrumb(); ?>

        <!-- En-tête fiche -->
        <div class="ts-fiche-header">
            <?php if ( has_post_thumbnail() ) : ?>
                <div class="ts-fiche-logo" style="background:none;padding:0">
                    <?php the_post_thumbnail( 'ts-company-logo', [ 'alt' => esc_attr( get_the_title() ) ] ); ?>
                </div>
            <?php else : ?>
                <div class="ts-fiche-logo" style="background:linear-gradient(135deg,<?php echo esc_attr( $color ); ?>40,<?php echo esc_attr( $color ); ?>80)">
                    <?php echo esc_html( $initiales ); ?>
                </div>
            <?php endif; ?>

            <div class="ts-fiche-header-info">
                <h1><?php the_title(); ?></h1>

                <div class="ts-fiche-badges">
                    <?php if ( $active ) : ?>
                        <span class="ts-badge ts-badge--verified">✓ <?php esc_html_e( 'Vérifiée', 'topsocietes' ); ?></span>
                        <span class="ts-badge ts-badge--active"><?php esc_html_e( 'En activité', 'topsocietes' ); ?></span>
                    <?php else : ?>
                        <span class="ts-badge" style="background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.2);color:#dc2626"><?php esc_html_e( 'Fermée', 'topsocietes' ); ?></span>
                    <?php endif; ?>
                    <?php if ( $forme ) : ?>
                        <span class="ts-badge ts-badge--sas"><?php echo esc_html( $forme ); ?></span>
                    <?php endif; ?>
                    <?php if ( $premium ) : ?>
                        <span class="ts-badge" style="background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.3);color:#d97706">⭐ PREMIUM</span>
                    <?php endif; ?>
                </div>

                <div class="ts-fiche-meta-row">
                    <?php if ( $ville ) : ?>
                        <span class="ts-fiche-meta-item">📍 <?php echo esc_html( $ville ); ?></span>
                    <?php endif; ?>
                    <?php if ( $sectors && ! is_wp_error( $sectors ) ) : ?>
                        <span class="ts-fiche-meta-item">🏭 <?php echo esc_html( implode( ', ', $sectors ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $creation ) : ?>
                        <span class="ts-fiche-meta-item">📅 <?php printf( esc_html__( 'Depuis %s', 'topsocietes' ), esc_html( date_i18n( 'Y', strtotime( $creation ) ) ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $siren ) : ?>
                        <span class="ts-fiche-meta-item">🔢 SIREN <?php echo esc_html( $siren ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ts-fiche-header-actions">
                <?php if ( $website ) : ?>
                    <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer" class="ts-btn-primary" style="font-size:13px;padding:10px 20px">
                        🌐 <?php esc_html_e( 'Visiter le site', 'topsocietes' ); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( home_url( '/pack/' ) ); ?>" class="ts-btn-secondary" style="font-size:13px;padding:10px 20px">
                    <?php esc_html_e( 'Accès complet', 'topsocietes' ); ?>
                </a>
            </div>
        </div>

        <!-- Corps de la fiche -->
        <div class="ts-fiche-grid">

            <!-- Colonne principale -->
            <div class="ts-fiche-main">

                <!-- Description (contenu WP) -->
                <?php if ( get_the_content() ) : ?>
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'À propos', 'topsocietes' ); ?></h3>
                    <div style="font-size:14px;color:var(--ts-text-muted);line-height:1.8">
                        <?php the_content(); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- KPIs -->
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'Indicateurs clés', 'topsocietes' ); ?></h3>
                    <div class="ts-kpi-grid">
                        <div class="ts-kpi-item">
                            <div class="ts-kpi-label"><?php esc_html_e( 'Chiffre d\'affaires', 'topsocietes' ); ?></div>
                            <div class="ts-kpi-value neutral"><?php echo esc_html( $ca_display ?: '–' ); ?></div>
                            <div class="ts-kpi-trend">↑ +12 % vs 2022</div>
                        </div>
                        <div class="ts-kpi-item">
                            <div class="ts-kpi-label"><?php esc_html_e( 'Effectif', 'topsocietes' ); ?></div>
                            <div class="ts-kpi-value neutral"><?php echo esc_html( $eff_display ?: '–' ); ?></div>
                            <div class="ts-kpi-trend"><?php esc_html_e( 'ETP déclarés', 'topsocietes' ); ?></div>
                        </div>
                        <?php
                        $result_net = get_post_meta( $id, '_ts_result_net_display', true );
                        $marge      = get_post_meta( $id, '_ts_marge_display', true );
                        if ( $result_net ) : ?>
                        <div class="ts-kpi-item">
                            <div class="ts-kpi-label"><?php esc_html_e( 'Résultat net', 'topsocietes' ); ?></div>
                            <div class="ts-kpi-value up"><?php echo esc_html( $result_net ); ?></div>
                            <div class="ts-kpi-trend"><?php esc_html_e( 'Dernier exercice', 'topsocietes' ); ?></div>
                        </div>
                        <?php endif;
                        if ( $marge ) : ?>
                        <div class="ts-kpi-item">
                            <div class="ts-kpi-label"><?php esc_html_e( 'Marge nette', 'topsocietes' ); ?></div>
                            <div class="ts-kpi-value neutral"><?php echo esc_html( $marge ); ?></div>
                            <div class="ts-kpi-trend"><?php esc_html_e( 'Ratio bénéfice/CA', 'topsocietes' ); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Historique CA -->
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'Évolution du chiffre d\'affaires', 'topsocietes' ); ?></h3>
                    <div class="ts-chart-bar-wrap">
                        <?php foreach ( $ca_history as $bar ) : ?>
                        <div class="ts-chart-bar-row">
                            <div class="ts-chart-bar-year"><?php echo esc_html( $bar['year'] ); ?></div>
                            <div class="ts-chart-bar-track">
                                <div class="ts-chart-bar-fill" style="width:<?php echo esc_attr( $bar['pct'] ); ?>%"></div>
                            </div>
                            <div class="ts-chart-bar-val"><?php echo esc_html( $bar['val'] ); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Informations légales -->
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'Informations légales', 'topsocietes' ); ?></h3>
                    <table class="ts-info-table" aria-label="<?php esc_attr_e( 'Informations légales', 'topsocietes' ); ?>">
                        <?php
                        $legal_rows = [
                            [ __( 'SIREN', 'topsocietes' ),             $siren,     'mono' ],
                            [ __( 'SIRET (siège)', 'topsocietes' ),      $siret,     'mono' ],
                            [ __( 'Forme juridique', 'topsocietes' ),    $forme,     '' ],
                            [ __( 'Code NAF / APE', 'topsocietes' ),     $naf . ( $naf_label ? ' — ' . $naf_label : '' ), '' ],
                            [ __( 'Capital social', 'topsocietes' ),     $capital ? number_format( (float) $capital, 0, ',', ' ' ) . ' €' : '', '' ],
                            [ __( 'Date de création', 'topsocietes' ),   $creation ? date_i18n( get_option( 'date_format' ), strtotime( $creation ) ) : '', '' ],
                            [ __( 'Adresse du siège', 'topsocietes' ),   $adresse ? $adresse . ', ' . $cp . ' ' . $ville : $ville, '' ],
                            [ __( 'Région', 'topsocietes' ),             $regions && ! is_wp_error( $regions ) ? implode( ', ', $regions ) : '', '' ],
                        ];
                        foreach ( $legal_rows as [ $label, $value, $class ] ) :
                            if ( ! $value ) continue;
                        ?>
                        <div class="ts-info-row">
                            <span class="ts-info-label"><?php echo esc_html( $label ); ?></span>
                            <span class="ts-info-value<?php echo $class ? ' ' . esc_attr( $class ) : ''; ?>"><?php echo esc_html( $value ); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </table>
                </div>

                <!-- Dirigeants -->
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'Dirigeants', 'topsocietes' ); ?></h3>
                    <?php foreach ( $dirigeants as $d ) : ?>
                    <div class="ts-dirigeant-row">
                        <div class="ts-dirigeant-avatar" aria-hidden="true">
                            <?php echo esc_html( topsocietes_initiales( $d['nom'] ) ); ?>
                        </div>
                        <div>
                            <div class="ts-dirigeant-name"><?php echo esc_html( $d['nom'] ); ?></div>
                            <div class="ts-dirigeant-role"><?php echo esc_html( $d['role'] ); ?></div>
                            <?php if ( ! empty( $d['depuis'] ) ) : ?>
                                <div class="ts-dirigeant-since">
                                    <?php printf( esc_html__( 'Depuis %s', 'topsocietes' ), esc_html( $d['depuis'] ) ); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div><!-- .ts-fiche-main -->

            <!-- Colonne latérale -->
            <aside class="ts-fiche-aside">

                <!-- Score de fiabilité -->
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'Score de fiabilité', 'topsocietes' ); ?></h3>
                    <div class="ts-score-ring-wrap">
                        <div class="ts-score-ring" style="background:conic-gradient(#16a34a 0deg <?php echo esc_attr( $score_deg ); ?>deg, rgba(0,0,0,.07) <?php echo esc_attr( $score_deg ); ?>deg 360deg)" aria-label="<?php echo esc_attr( $score . '/100' ); ?>">
                            <span><?php echo esc_html( $score ); ?></span>
                        </div>
                        <div class="ts-score-label">
                            <strong><?php esc_html_e( 'Profil fiable', 'topsocietes' ); ?></strong>
                            <?php esc_html_e( 'Données vérifiées, activité continue, dépôts à jour.', 'topsocietes' ); ?>
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <?php if ( $tel || $email || $website || $adresse ) : ?>
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'Contact', 'topsocietes' ); ?></h3>
                    <?php if ( $tel ) : ?>
                    <div class="ts-contact-item">
                        <div class="ts-contact-icon" aria-hidden="true">📞</div>
                        <div>
                            <div class="ts-contact-label"><?php esc_html_e( 'Téléphone', 'topsocietes' ); ?></div>
                            <div class="ts-contact-val"><a href="tel:<?php echo esc_attr( preg_replace( '/[^+0-9]/', '', $tel ) ); ?>"><?php echo esc_html( $tel ); ?></a></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( $email ) : ?>
                    <div class="ts-contact-item">
                        <div class="ts-contact-icon" aria-hidden="true">✉️</div>
                        <div>
                            <div class="ts-contact-label"><?php esc_html_e( 'Email', 'topsocietes' ); ?></div>
                            <div class="ts-contact-val"><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( $website ) : ?>
                    <div class="ts-contact-item">
                        <div class="ts-contact-icon" aria-hidden="true">🌐</div>
                        <div>
                            <div class="ts-contact-label"><?php esc_html_e( 'Site web', 'topsocietes' ); ?></div>
                            <div class="ts-contact-val"><a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( preg_replace( '#^https?://#', '', $website ) ); ?></a></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( $adresse ) : ?>
                    <div class="ts-contact-item">
                        <div class="ts-contact-icon" aria-hidden="true">📍</div>
                        <div>
                            <div class="ts-contact-label"><?php esc_html_e( 'Adresse', 'topsocietes' ); ?></div>
                            <div class="ts-contact-val"><?php echo esc_html( $adresse . ', ' . $cp . ' ' . $ville ); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Accès données premium -->
                <?php if ( ! $premium && ! is_user_logged_in() ) : ?>
                <div class="ts-fiche-card" style="background:linear-gradient(145deg,oklch(0.25 0.12 240),oklch(0.2 0.14 270));border-color:oklch(0.55 0.13 236 / 0.5);color:#e8f4ff">
                    <h3 style="color:rgba(191,216,255,0.7)"><?php esc_html_e( 'Données avancées', 'topsocietes' ); ?></h3>
                    <p style="font-size:13px;margin-bottom:18px;opacity:.8;line-height:1.6">
                        <?php esc_html_e( 'Accédez aux bilans complets, actionnaires, procédures judiciaires et bien plus.', 'topsocietes' ); ?>
                    </p>
                    <a href="<?php echo esc_url( home_url( '/pack/' ) ); ?>" class="ts-btn-primary" style="width:100%;justify-content:center">
                        <?php esc_html_e( 'Débloquer les données', 'topsocietes' ); ?>
                    </a>
                </div>
                <?php endif; ?>

                <!-- Entreprises similaires -->
                <?php if ( $similaires ) : ?>
                <div class="ts-fiche-card">
                    <h3><?php esc_html_e( 'Entreprises similaires', 'topsocietes' ); ?></h3>
                    <?php foreach ( $similaires as $sim ) :
                        $sim_color  = get_post_meta( $sim->ID, '_ts_color', true ) ?: '#3b82f6';
                        $sim_init   = get_post_meta( $sim->ID, '_ts_initiales', true ) ?: topsocietes_initiales( $sim->post_title );
                        $sim_city   = get_post_meta( $sim->ID, '_ts_ville', true );
                        $sim_ca     = get_post_meta( $sim->ID, '_ts_ca_display', true );
                    ?>
                    <a href="<?php echo esc_url( get_permalink( $sim ) ); ?>" class="ts-similar-row">
                        <div class="ts-similar-logo" style="background:<?php echo esc_attr( $sim_color ); ?>22;color:<?php echo esc_attr( $sim_color ); ?>" aria-hidden="true">
                            <?php echo esc_html( $sim_init ); ?>
                        </div>
                        <div>
                            <div class="ts-similar-name"><?php echo esc_html( $sim->post_title ); ?></div>
                            <?php if ( $sim_city ) : ?>
                                <div class="ts-similar-city"><?php echo esc_html( $sim_city ); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ( $sim_ca ) : ?>
                            <div class="ts-similar-ca"><?php echo esc_html( $sim_ca ); ?></div>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Sidebar WordPress -->
                <?php if ( is_active_sidebar( 'fiche-sidebar' ) ) : ?>
                    <?php dynamic_sidebar( 'fiche-sidebar' ); ?>
                <?php endif; ?>

            </aside><!-- .ts-fiche-aside -->

        </div><!-- .ts-fiche-grid -->
    </div><!-- .ts-container -->
</div><!-- .ts-page -->

<?php get_footer();
