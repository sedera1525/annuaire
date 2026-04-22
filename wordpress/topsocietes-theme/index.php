<?php
/**
 * Template de secours générique (WordPress l'exige).
 */
get_header();
?>
<div class="ts-page">
    <div class="ts-container ts-section">
        <?php if ( have_posts() ) : ?>
            <div class="ts-companies-grid">
                <?php while ( have_posts() ) : the_post(); ?>
                    <article id="post-<?php the_ID(); ?>" <?php post_class( 'ts-company-card' ); ?>>
                        <h2 class="ts-company-name">
                            <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                        </h2>
                        <div class="ts-company-city"><?php the_excerpt(); ?></div>
                    </article>
                <?php endwhile; ?>
            </div>
            <div class="ts-pagination">
                <?php the_posts_pagination( [
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'class'     => 'ts-pagination',
                ] ); ?>
            </div>
        <?php else : ?>
            <p><?php esc_html_e( 'Aucun contenu trouvé.', 'topsocietes' ); ?></p>
        <?php endif; ?>
    </div>
</div>
<?php get_footer();
