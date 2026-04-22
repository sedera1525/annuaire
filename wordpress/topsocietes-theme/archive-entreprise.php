<?php
/**
 * Template: Archive Entreprises
 */
get_header();
?>
<div class="ts-page">
    <div class="ts-container ts-section">

        <div class="ts-section-header">
            <div class="ts-eyebrow"><?php esc_html_e( 'Annuaire', 'topsocietes' ); ?></div>
            <h1 class="ts-section-title">
                <?php
                if ( is_tax( 'secteur' ) ) {
                    printf( esc_html__( 'Entreprises — %s', 'topsocietes' ), '<span class="ts-accent">' . esc_html( single_term_title( '', false ) ) . '</span>' );
                } elseif ( is_tax( 'region' ) ) {
                    printf( esc_html__( 'Entreprises en %s', 'topsocietes' ), '<span class="ts-accent">' . esc_html( single_term_title( '', false ) ) . '</span>' );
                } else {
                    echo '<span class="ts-accent">' . esc_html__( 'Toutes les entreprises', 'topsocietes' ) . '</span>';
                }
                ?>
            </h1>
        </div>

        <?php if ( have_posts() ) : ?>
            <div class="ts-companies-grid">
                <?php
                $rank = ( get_query_var( 'paged', 1 ) - 1 ) * get_option( 'posts_per_page' ) + 1;
                while ( have_posts() ) :
                    the_post();
                    $pid      = get_the_ID();
                    $color    = get_post_meta( $pid, '_ts_color', true )   ?: '#3b82f6';
                    $initials = get_post_meta( $pid, '_ts_initiales', true ) ?: topsocietes_initiales( get_the_title() );
                    $city     = get_post_meta( $pid, '_ts_ville', true );
                    $ca       = get_post_meta( $pid, '_ts_ca_display', true );
                    $eff      = get_post_meta( $pid, '_ts_effectif_display', true );
                    $sector   = wp_get_post_terms( $pid, 'secteur', [ 'fields' => 'names' ] );
                ?>
                <a href="<?php the_permalink(); ?>" class="ts-company-card" style="--rank:<?php echo esc_attr( $rank++ ); ?>">
                    <div class="ts-company-card-header">
                        <div class="ts-company-avatar" style="background:<?php echo esc_attr( $color ); ?>22;color:<?php echo esc_attr( $color ); ?>">
                            <?php echo esc_html( $initials ); ?>
                        </div>
                        <div>
                            <div class="ts-company-name"><?php the_title(); ?></div>
                            <?php if ( $city ) : ?>
                                <div class="ts-company-city">📍 <?php echo esc_html( $city ); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ( $sector && ! is_wp_error( $sector ) ) : ?>
                        <div class="ts-company-tags">
                            <?php foreach ( array_slice( $sector, 0, 2 ) as $s ) : ?>
                                <span class="ts-tag"><?php echo esc_html( $s ); ?></span>
                            <?php endforeach; ?>
                        </div>
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
                <?php endwhile; ?>
            </div>

            <nav class="ts-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'topsocietes' ); ?>">
                <?php the_posts_pagination( [
                    'prev_text'          => '‹',
                    'next_text'          => '›',
                    'before_page_number' => '',
                ] ); ?>
            </nav>

        <?php else : ?>
            <p style="color:var(--ts-text-muted)"><?php esc_html_e( 'Aucune entreprise trouvée.', 'topsocietes' ); ?></p>
        <?php endif; ?>
    </div>
</div>
<?php get_footer();
