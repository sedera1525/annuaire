<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="ts-bg-grid" aria-hidden="true"></div>
<div class="ts-bg-orbs" aria-hidden="true"></div>

<div class="ts-site-wrapper">

<header class="ts-nav" role="banner">
    <?php topsocietes_logo(); ?>

    <button class="ts-nav-toggle" id="ts-nav-toggle" aria-controls="ts-primary-menu" aria-expanded="false" aria-label="<?php esc_attr_e( 'Ouvrir le menu', 'topsocietes' ); ?>">
        <span></span><span></span><span></span>
    </button>

    <nav id="ts-primary-nav" aria-label="<?php esc_attr_e( 'Navigation principale', 'topsocietes' ); ?>">
        <?php
        wp_nav_menu( [
            'theme_location' => 'primary',
            'menu_id'        => 'ts-primary-menu',
            'container'      => false,
            'menu_class'     => 'ts-nav-menu',
            'fallback_cb'    => 'topsocietes_primary_menu_fallback',
        ] );
        ?>
    </nav>
</header>
<?php
/**
 * Menu de secours si aucun menu n'est assigné.
 */
function topsocietes_primary_menu_fallback(): void {
    echo '<ul class="ts-nav-menu">';
    echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Accueil', 'topsocietes' ) . '</a></li>';
    echo '<li><a href="' . esc_url( home_url( '/recherche/' ) ) . '">' . esc_html__( 'Recherche', 'topsocietes' ) . '</a></li>';
    echo '<li><a href="' . esc_url( home_url( '/tarifs/' ) ) . '">' . esc_html__( 'Tarifs', 'topsocietes' ) . '</a></li>';
    echo '<li><a href="' . esc_url( home_url( '/a-propos/' ) ) . '">' . esc_html__( 'À propos', 'topsocietes' ) . '</a></li>';
    echo '</ul>';
}
