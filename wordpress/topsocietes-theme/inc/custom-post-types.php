<?php
/**
 * Custom Post Types & Taxonomies — TOPsocietes
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────────────────────────
   1. CPT : entreprise
───────────────────────────────────────────── */
add_action( 'init', 'topsocietes_register_cpt_entreprise' );
function topsocietes_register_cpt_entreprise(): void {
    register_post_type( 'entreprise', [
        'labels' => [
            'name'                  => __( 'Entreprises', 'topsocietes' ),
            'singular_name'         => __( 'Entreprise', 'topsocietes' ),
            'add_new'               => __( 'Ajouter', 'topsocietes' ),
            'add_new_item'          => __( 'Ajouter une entreprise', 'topsocietes' ),
            'edit_item'             => __( 'Modifier l\'entreprise', 'topsocietes' ),
            'new_item'              => __( 'Nouvelle entreprise', 'topsocietes' ),
            'view_item'             => __( 'Voir la fiche', 'topsocietes' ),
            'view_items'            => __( 'Voir les fiches', 'topsocietes' ),
            'search_items'          => __( 'Rechercher une entreprise', 'topsocietes' ),
            'not_found'             => __( 'Aucune entreprise trouvée.', 'topsocietes' ),
            'not_found_in_trash'    => __( 'Aucune entreprise dans la corbeille.', 'topsocietes' ),
            'all_items'             => __( 'Toutes les entreprises', 'topsocietes' ),
            'menu_name'             => __( 'Entreprises', 'topsocietes' ),
            'archives'              => __( 'Annuaire', 'topsocietes' ),
            'attributes'            => __( 'Attributs', 'topsocietes' ),
            'parent_item_colon'     => '',
            'featured_image'        => __( 'Logo de l\'entreprise', 'topsocietes' ),
            'set_featured_image'    => __( 'Définir le logo', 'topsocietes' ),
            'remove_featured_image' => __( 'Supprimer le logo', 'topsocietes' ),
            'use_featured_image'    => __( 'Utiliser comme logo', 'topsocietes' ),
        ],
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => true,
        'query_var'          => true,
        'rewrite'            => [ 'slug' => 'entreprise', 'with_front' => false ],
        'capability_type'    => 'post',
        'has_archive'        => 'entreprises',
        'hierarchical'       => false,
        'menu_position'      => 5,
        'menu_icon'          => 'dashicons-building',
        'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ],
        'taxonomies'         => [ 'secteur', 'region' ],
        'show_in_graphql'    => false,
    ] );
}

/* ─────────────────────────────────────────────
   2. Taxonomie : secteur
───────────────────────────────────────────── */
add_action( 'init', 'topsocietes_register_tax_secteur' );
function topsocietes_register_tax_secteur(): void {
    register_taxonomy( 'secteur', 'entreprise', [
        'labels' => [
            'name'              => __( 'Secteurs', 'topsocietes' ),
            'singular_name'     => __( 'Secteur', 'topsocietes' ),
            'search_items'      => __( 'Rechercher un secteur', 'topsocietes' ),
            'all_items'         => __( 'Tous les secteurs', 'topsocietes' ),
            'edit_item'         => __( 'Modifier le secteur', 'topsocietes' ),
            'update_item'       => __( 'Mettre à jour', 'topsocietes' ),
            'add_new_item'      => __( 'Ajouter un secteur', 'topsocietes' ),
            'new_item_name'     => __( 'Nom du secteur', 'topsocietes' ),
            'menu_name'         => __( 'Secteurs', 'topsocietes' ),
            'not_found'         => __( 'Aucun secteur trouvé.', 'topsocietes' ),
        ],
        'public'             => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => true,
        'hierarchical'       => true,
        'rewrite'            => [ 'slug' => 'secteur', 'with_front' => false ],
        'show_admin_column'  => true,
        'show_tag_cloud'     => false,
    ] );

    // Méta "icon" pour les secteurs
    register_term_meta( 'secteur', 'icon', [
        'show_in_rest'      => true,
        'single'            => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ] );
}

/* ─────────────────────────────────────────────
   3. Taxonomie : region
───────────────────────────────────────────── */
add_action( 'init', 'topsocietes_register_tax_region' );
function topsocietes_register_tax_region(): void {
    register_taxonomy( 'region', 'entreprise', [
        'labels' => [
            'name'          => __( 'Régions', 'topsocietes' ),
            'singular_name' => __( 'Région', 'topsocietes' ),
            'all_items'     => __( 'Toutes les régions', 'topsocietes' ),
            'menu_name'     => __( 'Régions', 'topsocietes' ),
        ],
        'public'            => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'hierarchical'      => false,
        'rewrite'           => [ 'slug' => 'region', 'with_front' => false ],
        'show_admin_column' => true,
    ] );
}

/* ─────────────────────────────────────────────
   4. Meta Boxes
───────────────────────────────────────────── */
add_action( 'add_meta_boxes', 'topsocietes_add_meta_boxes' );
function topsocietes_add_meta_boxes(): void {
    add_meta_box(
        'ts_infos_legales',
        __( 'Informations légales', 'topsocietes' ),
        'topsocietes_meta_box_legales',
        'entreprise',
        'normal',
        'high'
    );
    add_meta_box(
        'ts_donnees_financieres',
        __( 'Données financières', 'topsocietes' ),
        'topsocietes_meta_box_finances',
        'entreprise',
        'normal',
        'default'
    );
    add_meta_box(
        'ts_contact',
        __( 'Contact & Web', 'topsocietes' ),
        'topsocietes_meta_box_contact',
        'entreprise',
        'side',
        'default'
    );
    add_meta_box(
        'ts_apparence',
        __( 'Apparence sur le site', 'topsocietes' ),
        'topsocietes_meta_box_apparence',
        'entreprise',
        'side',
        'default'
    );
}

/* ── Meta Box : Informations légales ── */
function topsocietes_meta_box_legales( WP_Post $post ): void {
    wp_nonce_field( 'ts_save_entreprise', 'ts_nonce' );

    $fields = [
        '_ts_siren'           => [ __( 'SIREN (9 chiffres)', 'topsocietes' ), 'text' ],
        '_ts_siret'           => [ __( 'SIRET siège (14 chiffres)', 'topsocietes' ), 'text' ],
        '_ts_forme_juridique' => [ __( 'Forme juridique (SA, SAS, SARL…)', 'topsocietes' ), 'text' ],
        '_ts_code_naf'        => [ __( 'Code NAF / APE', 'topsocietes' ), 'text' ],
        '_ts_libelle_naf'     => [ __( 'Libellé NAF', 'topsocietes' ), 'text' ],
        '_ts_capital'         => [ __( 'Capital social (€)', 'topsocietes' ), 'number' ],
        '_ts_date_creation'   => [ __( 'Date de création', 'topsocietes' ), 'date' ],
        '_ts_statut'          => [ __( 'Statut (active / fermée)', 'topsocietes' ), 'text' ],
    ];

    echo '<table class="form-table"><tbody>';
    foreach ( $fields as $key => [ $label, $type ] ) {
        $val = esc_attr( get_post_meta( $post->ID, $key, true ) );
        echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
        echo '<td><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . $val . '" class="regular-text"></td></tr>';
    }
    echo '</tbody></table>';

    // Adresse
    echo '<h4 style="margin:16px 0 8px">' . esc_html__( 'Adresse du siège', 'topsocietes' ) . '</h4>';
    echo '<table class="form-table"><tbody>';
    foreach ( [
        '_ts_adresse'     => [ __( 'Adresse (rue, n°)', 'topsocietes' ), 'text' ],
        '_ts_code_postal' => [ __( 'Code postal', 'topsocietes' ), 'text' ],
        '_ts_ville'       => [ __( 'Ville', 'topsocietes' ), 'text' ],
    ] as $key => [ $label, $type ] ) {
        $val = esc_attr( get_post_meta( $post->ID, $key, true ) );
        echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
        echo '<td><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . $val . '" class="regular-text"></td></tr>';
    }
    echo '</tbody></table>';
}

/* ── Meta Box : Données financières ── */
function topsocietes_meta_box_finances( WP_Post $post ): void {
    $fields = [
        '_ts_ca'                   => [ __( 'CA (valeur brute en €)', 'topsocietes' ), 'number' ],
        '_ts_ca_display'           => [ __( 'CA (affiché — ex: 7,2 Md€)', 'topsocietes' ), 'text' ],
        '_ts_effectif'             => [ __( 'Effectif (nombre)', 'topsocietes' ), 'number' ],
        '_ts_effectif_display'     => [ __( 'Effectif (affiché — ex: 9 400 salariés)', 'topsocietes' ), 'text' ],
        '_ts_result_net_display'   => [ __( 'Résultat net (affiché)', 'topsocietes' ), 'text' ],
        '_ts_marge_display'        => [ __( 'Marge nette (affiché — ex: 8,4 %)', 'topsocietes' ), 'text' ],
        '_ts_score_fiabilite'      => [ __( 'Score de fiabilité (0-100)', 'topsocietes' ), 'number' ],
    ];

    echo '<table class="form-table"><tbody>';
    foreach ( $fields as $key => [ $label, $type ] ) {
        $val = esc_attr( get_post_meta( $post->ID, $key, true ) );
        echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th>';
        echo '<td><input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . $val . '" class="regular-text"></td></tr>';
    }
    echo '</tbody></table>';

    // Dirigeants (JSON)
    $dirigeants = esc_textarea( get_post_meta( $post->ID, '_ts_dirigeants', true ) );
    echo '<h4 style="margin:16px 0 8px">' . esc_html__( 'Dirigeants (JSON)', 'topsocietes' ) . '</h4>';
    echo '<p class="description" style="margin-bottom:6px">' . esc_html__( 'Format : [{"nom":"Jean DUPONT","role":"Président","depuis":"2018"},…]', 'topsocietes' ) . '</p>';
    echo '<textarea name="_ts_dirigeants" rows="4" class="large-text">' . $dirigeants . '</textarea>';

    // Historique CA (JSON)
    $ca_history = esc_textarea( get_post_meta( $post->ID, '_ts_ca_history', true ) );
    echo '<h4 style="margin:16px 0 8px">' . esc_html__( 'Historique CA (JSON)', 'topsocietes' ) . '</h4>';
    echo '<p class="description" style="margin-bottom:6px">' . esc_html__( 'Format : [{"year":"2023","val":"7,2 M€","pct":100},…]', 'topsocietes' ) . '</p>';
    echo '<textarea name="_ts_ca_history" rows="4" class="large-text">' . $ca_history . '</textarea>';
}

/* ── Meta Box : Contact ── */
function topsocietes_meta_box_contact( WP_Post $post ): void {
    foreach ( [
        '_ts_website' => [ __( 'Site web', 'topsocietes' ), 'url' ],
        '_ts_email'   => [ __( 'Email', 'topsocietes' ), 'email' ],
        '_ts_tel'     => [ __( 'Téléphone', 'topsocietes' ), 'tel' ],
    ] as $key => [ $label, $type ] ) {
        $val = esc_attr( get_post_meta( $post->ID, $key, true ) );
        echo '<p><label><strong>' . esc_html( $label ) . '</strong><br>';
        echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" value="' . $val . '" style="width:100%"></label></p>';
    }
}

/* ── Meta Box : Apparence ── */
function topsocietes_meta_box_apparence( WP_Post $post ): void {
    $color    = esc_attr( get_post_meta( $post->ID, '_ts_color', true ) ?: '#3b82f6' );
    $initials = esc_attr( get_post_meta( $post->ID, '_ts_initiales', true ) );
    $premium  = (bool) get_post_meta( $post->ID, '_ts_premium', true );

    echo '<p><label><strong>' . esc_html__( 'Couleur d\'accent', 'topsocietes' ) . '</strong><br>';
    echo '<input type="color" name="_ts_color" value="' . $color . '"></label></p>';

    echo '<p><label><strong>' . esc_html__( 'Initiales (2 lettres)', 'topsocietes' ) . '</strong><br>';
    echo '<input type="text" name="_ts_initiales" value="' . $initials . '" maxlength="3" style="width:80px"></label></p>';

    echo '<p><label><input type="checkbox" name="_ts_premium" value="1"' . checked( $premium, true, false ) . '> ';
    echo esc_html__( 'Profil Premium', 'topsocietes' ) . '</label></p>';
}

/* ─────────────────────────────────────────────
   5. Sauvegarde des méta
───────────────────────────────────────────── */
add_action( 'save_post_entreprise', 'topsocietes_save_entreprise_meta' );
function topsocietes_save_entreprise_meta( int $post_id ): void {
    if ( ! isset( $_POST['ts_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ts_nonce'] ) ), 'ts_save_entreprise' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $text_fields = [
        '_ts_siren', '_ts_siret', '_ts_forme_juridique', '_ts_code_naf', '_ts_libelle_naf',
        '_ts_statut', '_ts_adresse', '_ts_code_postal', '_ts_ville', '_ts_ca_display',
        '_ts_effectif_display', '_ts_result_net_display', '_ts_marge_display',
        '_ts_website', '_ts_email', '_ts_tel', '_ts_initiales', '_ts_color',
    ];
    foreach ( $text_fields as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
        }
    }

    $num_fields = [ '_ts_ca', '_ts_effectif', '_ts_capital', '_ts_score_fiabilite' ];
    foreach ( $num_fields as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_post_meta( $post_id, $field, floatval( $_POST[ $field ] ) );
        }
    }

    $date_fields = [ '_ts_date_creation' ];
    foreach ( $date_fields as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
        }
    }

    // JSON fields — validation légère
    foreach ( [ '_ts_dirigeants', '_ts_ca_history' ] as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            $raw = wp_unslash( $_POST[ $field ] );
            $decoded = json_decode( $raw );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                update_post_meta( $post_id, $field, wp_slash( wp_json_encode( $decoded ) ) );
            }
        }
    }

    // Checkbox premium
    update_post_meta( $post_id, '_ts_premium', isset( $_POST['_ts_premium'] ) ? '1' : '' );

    // Regénérer les initiales auto si vide
    if ( ! get_post_meta( $post_id, '_ts_initiales', true ) ) {
        update_post_meta( $post_id, '_ts_initiales', topsocietes_initiales( get_the_title( $post_id ) ) );
    }
}

/* ─────────────────────────────────────────────
   6. Colonnes admin personnalisées
───────────────────────────────────────────── */
add_filter( 'manage_entreprise_posts_columns', 'topsocietes_entreprise_columns' );
function topsocietes_entreprise_columns( array $cols ): array {
    return [
        'cb'        => $cols['cb'],
        'title'     => __( 'Raison sociale', 'topsocietes' ),
        'ts_siren'  => __( 'SIREN', 'topsocietes' ),
        'ts_ville'  => __( 'Ville', 'topsocietes' ),
        'ts_ca'     => __( 'CA', 'topsocietes' ),
        'ts_premium'=> __( 'Premium', 'topsocietes' ),
        'date'      => __( 'Importée le', 'topsocietes' ),
    ];
}

add_action( 'manage_entreprise_posts_custom_column', 'topsocietes_entreprise_column_content', 10, 2 );
function topsocietes_entreprise_column_content( string $col, int $post_id ): void {
    switch ( $col ) {
        case 'ts_siren':  echo esc_html( get_post_meta( $post_id, '_ts_siren', true ) ?: '–' ); break;
        case 'ts_ville':  echo esc_html( get_post_meta( $post_id, '_ts_ville', true )  ?: '–' ); break;
        case 'ts_ca':     echo esc_html( get_post_meta( $post_id, '_ts_ca_display', true ) ?: '–' ); break;
        case 'ts_premium':echo get_post_meta( $post_id, '_ts_premium', true ) ? '⭐' : '–'; break;
    }
}

add_filter( 'manage_edit-entreprise_sortable_columns', 'topsocietes_sortable_columns' );
function topsocietes_sortable_columns( array $cols ): array {
    $cols['ts_ca']    = 'ts_ca';
    $cols['ts_ville'] = 'ts_ville';
    return $cols;
}
