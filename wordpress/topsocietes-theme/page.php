<?php
/**
 * Template générique pour les pages WordPress (slug quelconque).
 * Appelle the_content() pour que les shortcodes soient exécutés.
 */
get_header();
?>
<div class="ts-page">
    <div class="ts-container ts-section">
        <?php while ( have_posts() ) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <?php the_content(); ?>
            </article>
        <?php endwhile; ?>
    </div>
</div>
<?php get_footer();
