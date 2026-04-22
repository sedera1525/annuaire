<?php
/**
 * Template bare pour [societies_search], [societies_tarifs], etc.
 * Bypasse entièrement le header/footer du thème — seuls wp_head() et wp_footer()
 * sont appelés pour que les scripts/styles WordPress fonctionnent.
 */
if (!defined('ABSPATH')) exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;padding:0;background:#f1f4f9}</style>
<?php wp_head(); ?>
</head>
<body <?php body_class('sc-bare-page'); ?>>
<?php wp_body_open(); ?>
<?php while (have_posts()) { the_post(); the_content(); } ?>
<?php wp_footer(); ?>
</body>
</html>
