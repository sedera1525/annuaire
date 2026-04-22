<?php
/**
 * Template minimaliste pour [societies_search], [societies_tarifs], etc.
 * Hérite du header/footer du thème actif mais bypasse les templates
 * page-{slug}.php qui pourraient écraser le contenu du shortcode.
 */
if (!defined('ABSPATH')) exit;
get_header();
while (have_posts()) {
    the_post();
    the_content();
}
get_footer();
