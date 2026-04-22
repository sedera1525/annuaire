<?php
/**
 * Template Name: Page Tarifs
 * Template Post Type: page
 */
get_header();

$plans = [
    [
        'name'     => __( 'Gratuit', 'topsocietes' ),
        'desc'     => __( 'Accès aux informations de base sur toutes les entreprises françaises.', 'topsocietes' ),
        'price'    => 0,
        'period'   => __( '/ mois', 'topsocietes' ),
        'featured' => false,
        'badge'    => '',
        'btn_text' => __( 'Commencer gratuitement', 'topsocietes' ),
        'btn_url'  => home_url( '/inscription/' ),
        'btn_style'=> 'outline',
        'features' => [
            [ true,  __( 'Recherche par nom / SIREN', 'topsocietes' ) ],
            [ true,  __( 'Fiche entreprise de base', 'topsocietes' ) ],
            [ true,  __( '5 recherches / jour', 'topsocietes' ) ],
            [ false, __( 'Données financières complètes', 'topsocietes' ) ],
            [ false, __( 'Export CSV', 'topsocietes' ) ],
            [ false, __( 'API Access', 'topsocietes' ) ],
        ],
    ],
    [
        'name'     => __( 'Pro', 'topsocietes' ),
        'desc'     => __( 'Idéal pour les professionnels de la prospection et du renseignement d\'entreprises.', 'topsocietes' ),
        'price_m'  => 49,
        'price_a'  => 39,
        'period'   => __( '/ mois', 'topsocietes' ),
        'featured' => true,
        'badge'    => __( 'Le plus populaire', 'topsocietes' ),
        'btn_text' => __( 'Commencer l\'essai gratuit', 'topsocietes' ),
        'btn_url'  => home_url( '/inscription-pro/' ),
        'btn_style'=> 'primary',
        'features' => [
            [ true,  __( 'Tout le plan Gratuit', 'topsocietes' ) ],
            [ true,  __( 'Recherches illimitées', 'topsocietes' ) ],
            [ true,  __( 'Données financières complètes', 'topsocietes' ) ],
            [ true,  __( 'Dirigeants & actionnaires', 'topsocietes' ) ],
            [ true,  __( 'Export CSV (500 / mois)', 'topsocietes' ) ],
            [ false, __( 'API Access', 'topsocietes' ) ],
        ],
    ],
    [
        'name'     => __( 'Entreprise', 'topsocietes' ),
        'desc'     => __( 'Pour les équipes et organisations avec des besoins de données à grande échelle.', 'topsocietes' ),
        'price_m'  => 199,
        'price_a'  => 149,
        'period'   => __( '/ mois', 'topsocietes' ),
        'featured' => false,
        'badge'    => '',
        'btn_text' => __( 'Contacter nos équipes', 'topsocietes' ),
        'btn_url'  => home_url( '/contact/' ),
        'btn_style'=> 'outline',
        'features' => [
            [ true, __( 'Tout le plan Pro', 'topsocietes' ) ],
            [ true, __( 'API Access complète', 'topsocietes' ) ],
            [ true, __( 'Export CSV illimité', 'topsocietes' ) ],
            [ true, __( 'Webhooks & Intégrations', 'topsocietes' ) ],
            [ true, __( 'Account Manager dédié', 'topsocietes' ) ],
            [ true, __( 'SLA 99,9 %', 'topsocietes' ) ],
        ],
    ],
];

$faqs = [
    [
        'q' => __( 'Les données sont-elles mises à jour en temps réel ?', 'topsocietes' ),
        'a' => __( 'Nos données sont synchronisées quotidiennement depuis le RCS (Registre du Commerce et des Sociétés), l\'INSEE et Infogreffe. Les données financières (bilans, CA) sont mises à jour annuellement dès leur dépôt.', 'topsocietes' ),
    ],
    [
        'q' => __( 'Puis-je annuler mon abonnement à tout moment ?', 'topsocietes' ),
        'a' => __( 'Oui, vous pouvez annuler votre abonnement depuis votre espace membre à tout moment. Aucun frais d\'annulation ne s\'applique. Votre accès reste actif jusqu\'à la fin de la période payée.', 'topsocietes' ),
    ],
    [
        'q' => __( 'L\'API est-elle disponible avec le plan Pro ?', 'topsocietes' ),
        'a' => __( 'L\'accès API complet est réservé au plan Entreprise. Le plan Pro bénéficie cependant d\'exports CSV et d\'un accès limité à l\'API (100 requêtes / jour).', 'topsocietes' ),
    ],
    [
        'q' => __( 'Puis-je tester le plan Pro avant de m\'engager ?', 'topsocietes' ),
        'a' => __( 'Absolument. Nous offrons un essai gratuit de 14 jours sur le plan Pro, sans carte bancaire requise. À l\'issue de l\'essai, vous choisissez librement de continuer ou non.', 'topsocietes' ),
    ],
    [
        'q' => __( 'Comment fonctionne la facturation annuelle ?', 'topsocietes' ),
        'a' => __( 'En optant pour la facturation annuelle, vous bénéficiez d\'une réduction de 20 % sur le tarif mensuel. La facturation est effectuée en une seule fois en début de période.', 'topsocietes' ),
    ],
];
?>

<div class="ts-page">
    <div class="ts-container">

        <!-- En-tête Tarifs -->
        <div class="ts-pricing-hero">
            <h1><?php esc_html_e( 'Des tarifs transparents, sans surprise', 'topsocietes' ); ?></h1>
            <p><?php esc_html_e( 'Choisissez le plan adapté à vos besoins. Changez ou annulez à tout moment.', 'topsocietes' ); ?></p>

            <!-- Toggle facturation -->
            <div class="ts-billing-toggle" role="group" aria-label="<?php esc_attr_e( 'Fréquence de facturation', 'topsocietes' ); ?>">
                <button type="button" class="ts-billing-btn active" data-billing="monthly" aria-pressed="true">
                    <?php esc_html_e( 'Mensuel', 'topsocietes' ); ?>
                </button>
                <button type="button" class="ts-billing-btn" data-billing="annual" aria-pressed="false">
                    <?php esc_html_e( 'Annuel', 'topsocietes' ); ?>
                    <span class="ts-save-badge">-20%</span>
                </button>
            </div>
        </div>

        <!-- Grille tarifs -->
        <div class="ts-pricing-grid" id="ts-pricing-grid">
            <?php foreach ( $plans as $plan ) : ?>
            <div class="ts-pricing-card<?php echo $plan['featured'] ? ' featured' : ''; ?>">
                <?php if ( $plan['badge'] ) : ?>
                    <div class="ts-plan-badge"><?php echo esc_html( $plan['badge'] ); ?></div>
                <?php endif; ?>

                <div class="ts-plan-name"><?php echo esc_html( $plan['name'] ); ?></div>
                <div class="ts-plan-desc"><?php echo esc_html( $plan['desc'] ); ?></div>

                <div class="ts-plan-price">
                    <span class="ts-plan-currency">€</span>
                    <?php if ( isset( $plan['price'] ) ) : ?>
                        <span class="ts-plan-amount">0</span>
                    <?php else : ?>
                        <span class="ts-plan-amount" data-monthly="<?php echo esc_attr( $plan['price_m'] ); ?>" data-annual="<?php echo esc_attr( $plan['price_a'] ); ?>">
                            <?php echo esc_html( $plan['price_m'] ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="ts-plan-period"><?php echo esc_html( $plan['period'] ); ?></span>
                </div>

                <?php if ( isset( $plan['price_m'], $plan['price_a'] ) ) : ?>
                <div class="ts-plan-orig" data-monthly-orig="" data-annual-orig="<?php echo esc_attr( $plan['price_m'] ); ?> €/mois">
                    <!-- Affiché dynamiquement via JS -->
                </div>
                <?php endif; ?>

                <div class="ts-plan-divider"></div>

                <ul class="ts-plan-features">
                    <?php foreach ( $plan['features'] as [ $included, $text ] ) : ?>
                    <li class="ts-plan-feature<?php echo $included ? ' included' : ''; ?>">
                        <span class="ts-feature-check <?php echo $included ? 'ts-check-yes' : 'ts-check-no'; ?>" aria-hidden="true">
                            <?php echo $included ? '✓' : '✕'; ?>
                        </span>
                        <?php echo esc_html( $text ); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <a href="<?php echo esc_url( $plan['btn_url'] ); ?>" class="ts-btn-<?php echo esc_attr( $plan['btn_style'] ); ?>" style="width:100%;justify-content:center;">
                    <?php echo esc_html( $plan['btn_text'] ); ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- FAQ -->
        <section class="ts-section">
            <div class="ts-section-header ts-section-header--center">
                <div class="ts-eyebrow"><?php esc_html_e( 'Questions fréquentes', 'topsocietes' ); ?></div>
                <h2 class="ts-section-title"><?php esc_html_e( 'FAQ', 'topsocietes' ); ?></h2>
            </div>

            <div id="ts-faq" style="max-width:720px;margin:0 auto">
                <?php foreach ( $faqs as $i => $faq ) : ?>
                <div class="ts-faq-item" id="faq-<?php echo esc_attr( $i ); ?>">
                    <button class="ts-faq-question" aria-expanded="false" aria-controls="faq-answer-<?php echo esc_attr( $i ); ?>">
                        <?php echo esc_html( $faq['q'] ); ?>
                        <span class="ts-faq-icon" aria-hidden="true">+</span>
                    </button>
                    <div class="ts-faq-answer" id="faq-answer-<?php echo esc_attr( $i ); ?>" role="region">
                        <?php echo esc_html( $faq['a'] ); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- CTA bas de page -->
        <section style="margin-bottom:80px">
            <div class="ts-cta-banner ts-cta-banner--dark">
                <div>
                    <div class="ts-cta-banner-title" style="color:#e8f4ff">
                        <?php esc_html_e( 'Prêt à commencer ?', 'topsocietes' ); ?>
                    </div>
                    <p class="ts-cta-banner-desc" style="color:rgba(191,216,255,0.8)">
                        <?php esc_html_e( 'Accédez gratuitement à plus de 4 millions de fiches entreprise. Aucune carte bancaire requise.', 'topsocietes' ); ?>
                    </p>
                </div>
                <a href="<?php echo esc_url( home_url( '/inscription/' ) ); ?>" class="ts-btn-primary" style="flex-shrink:0">
                    <?php esc_html_e( 'Créer un compte gratuit →', 'topsocietes' ); ?>
                </a>
            </div>
        </section>

    </div><!-- .ts-container -->
</div><!-- .ts-page -->

<?php get_footer();
