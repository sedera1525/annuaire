<?php
/**
 * TOPsocietes — Page de réglages du thème
 *
 * Accessible via Apparence › Réglages du thème.
 * Stocke toutes les options dans l'entrée 'topsocietes_options'.
 */

defined( 'ABSPATH' ) || exit;

/* ─────────────────────────────────────────────
   Aide-mémoire : récupérer une option
─────────────────────────────────────────────── */

/**
 * Retourne la valeur d'une option du thème.
 *
 * @param string $key     Clé de l'option.
 * @param string $default Valeur par défaut.
 */
function ts_get_option( string $key, string $default = '' ): string {
    static $opts = null;
    if ( null === $opts ) {
        $opts = (array) get_option( 'topsocietes_options', [] );
    }
    return isset( $opts[ $key ] ) && '' !== $opts[ $key ] ? (string) $opts[ $key ] : $default;
}

/* ─────────────────────────────────────────────
   Page admin — Apparence › Réglages du thème
─────────────────────────────────────────────── */

add_action( 'admin_menu', 'ts_add_settings_page' );
function ts_add_settings_page(): void {
    add_theme_page(
        __( 'Réglages TOPsocietes', 'topsocietes' ),
        __( 'Réglages du thème', 'topsocietes' ),
        'manage_options',
        'topsocietes-settings',
        'ts_render_settings_page'
    );
}

add_action( 'admin_enqueue_scripts', 'ts_settings_admin_styles' );
function ts_settings_admin_styles( string $hook ): void {
    if ( 'appearance_page_topsocietes-settings' !== $hook ) {
        return;
    }
    wp_enqueue_media();
    wp_add_inline_style( 'wp-admin', '
        .ts-admin-wrap { max-width: 860px; }
        .ts-admin-wrap .nav-tab-wrapper { margin-bottom: 0; }
        .ts-admin-panel { display: none; background: #fff; border: 1px solid #c3c4c7;
            border-top: none; padding: 24px 28px; border-radius: 0 0 4px 4px; }
        .ts-admin-panel.active { display: block; }
        .ts-admin-panel h2 { margin-top: 0; padding-top: 0; border-bottom: 1px solid #f0f0f1;
            padding-bottom: 12px; color: #1d2327; font-size: 15px; }
        .ts-admin-panel .form-table th { width: 220px; }
        .ts-admin-panel .description { color: #646970; font-size: 12px; margin-top: 4px; }
        .ts-settings-save { margin-top: 20px; }
        .ts-admin-logo { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .ts-slide-block { border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px 24px; margin-bottom: 16px; background: #fafafa; }
        .ts-slide-block h3 { margin: 0 0 16px; font-size: 14px; color: #1d2327; display:flex; align-items:center; gap:10px; }
        .ts-slide-preview { width: 120px; height: 68px; object-fit: cover; border-radius: 4px; border: 1px solid #c3c4c7; display:none; margin-top:6px; }
        .ts-slide-preview.visible { display: block; }
        .ts-admin-logo svg { flex-shrink: 0; }
        .ts-admin-logo-title { font-size: 22px; font-weight: 700; color: #1d2327; }
        .ts-admin-logo-title span { color: #3b82f6; }
        .ts-admin-version { font-size: 11px; color: #646970; font-weight: 400; }
    ' );
    // JS pour les onglets + media picker slides
    wp_add_inline_script( 'jquery', '
        jQuery(function($){
            $(".ts-admin-wrap .nav-tab").on("click", function(e){
                e.preventDefault();
                var target = $(this).attr("href");
                $(".ts-admin-wrap .nav-tab").removeClass("nav-tab-active");
                $(this).addClass("nav-tab-active");
                $(".ts-admin-panel").removeClass("active");
                $(target).addClass("active");
                history.replaceState(null, null, $(this).attr("href"));
            });
            // Activer l\'onglet en fonction du hash URL
            var hash = window.location.hash || "#ts-tab-header";
            $(".ts-admin-wrap .nav-tab[href=\'" + hash + "\']").trigger("click");

            // Media picker pour les slides
            $(".ts-slide-pick-img").on("click", function(e){
                e.preventDefault();
                var btn = $(this);
                var inputId = btn.data("input");
                var previewId = btn.data("preview");
                var frame = wp.media({
                    title: "Choisir une image",
                    button: { text: "Utiliser cette image" },
                    multiple: false
                });
                frame.on("select", function(){
                    var attachment = frame.state().get("selection").first().toJSON();
                    $("#" + inputId).val(attachment.url);
                    var $prev = $("#" + previewId);
                    $prev.attr("src", attachment.url).addClass("visible");
                });
                frame.open();
            });
            // Afficher les previews existantes au chargement
            $(".ts-slide-preview").each(function(){
                if ($(this).attr("src")) { $(this).addClass("visible"); }
            });
        });
    ' );
}

/* ─────────────────────────────────────────────
   Enregistrement des options (Settings API)
─────────────────────────────────────────────── */

add_action( 'admin_init', 'ts_settings_init' );
function ts_settings_init(): void {
    register_setting( 'topsocietes_options_group', 'topsocietes_options', [
        'sanitize_callback' => 'ts_sanitize_options',
    ] );
}

function ts_sanitize_options( mixed $input ): array {
    if ( ! is_array( $input ) ) {
        return [];
    }

    $text_fields = [
        'header_cta_text',
        'footer_brand_desc',
        'footer_col1_title',
        'footer_col2_title',
        'footer_col3_title',
        'footer_copyright',
        'ga_id',
    ];
    $url_fields = [
        'header_cta_url',
        'social_linkedin',
        'social_twitter',
        'social_facebook',
        'social_instagram',
    ];

    // Champs slider (4 slides)
    for ( $i = 1; $i <= 4; $i++ ) {
        $text_fields[] = "slide_{$i}_title";
        $text_fields[] = "slide_{$i}_accent";
        $text_fields[] = "slide_{$i}_subtitle";
        $text_fields[] = "slide_{$i}_btn_text";
        $url_fields[]  = "slide_{$i}_img";
        $url_fields[]  = "slide_{$i}_btn_url";
    }

    $clean = [];
    foreach ( $text_fields as $f ) {
        $clean[ $f ] = sanitize_text_field( $input[ $f ] ?? '' );
    }
    foreach ( $url_fields as $f ) {
        $raw = $input[ $f ] ?? '';
        $clean[ $f ] = str_starts_with( $raw, '/' )
            ? '/' . ltrim( sanitize_text_field( $raw ), '/' )
            : esc_url_raw( $raw );
    }
    // Checkboxes slider actif
    for ( $i = 1; $i <= 4; $i++ ) {
        $clean[ "slide_{$i}_active" ] = ! empty( $input[ "slide_{$i}_active" ] ) ? '1' : '0';
    }
    return $clean;
}

/* ─────────────────────────────────────────────
   Rendu de la page
─────────────────────────────────────────────── */

function ts_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $saved = get_settings_errors( 'topsocietes_options_group' );
    ?>
    <div class="wrap ts-admin-wrap">

        <div class="ts-admin-logo">
            <svg width="36" height="36" viewBox="0 0 40 40" fill="none">
                <rect x="3"  y="18" width="8" height="18" rx="2" fill="url(#asg1)"/>
                <rect x="14" y="10" width="8" height="26" rx="2" fill="url(#asg2)"/>
                <rect x="25" y="4"  width="8" height="32" rx="2" fill="url(#asg3)"/>
                <path d="M1 38 Q20 28 39 38" stroke="url(#asg4)" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                <defs>
                    <linearGradient id="asg1" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#f97316"/><stop offset="1" stop-color="#ef4444"/></linearGradient>
                    <linearGradient id="asg2" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#f59e0b"/><stop offset="1" stop-color="#f97316"/></linearGradient>
                    <linearGradient id="asg3" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#3b82f6"/><stop offset="1" stop-color="#7c3aed"/></linearGradient>
                    <linearGradient id="asg4" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#ef4444"/><stop offset="0.5" stop-color="#f59e0b"/><stop offset="1" stop-color="#3b82f6"/></linearGradient>
                </defs>
            </svg>
            <div>
                <div class="ts-admin-logo-title">TOP<span>societes</span>.com <span class="ts-admin-version">v<?php echo esc_html( TS_VERSION ); ?></span></div>
                <div style="color:#646970;font-size:12px"><?php esc_html_e( 'Réglages du thème', 'topsocietes' ); ?></div>
            </div>
        </div>

        <?php settings_errors( 'topsocietes_options_group' ); ?>

        <h2 class="nav-tab-wrapper">
            <a href="#ts-tab-header"   class="nav-tab"><?php esc_html_e( 'En-tête', 'topsocietes' ); ?></a>
            <a href="#ts-tab-slider"   class="nav-tab"><?php esc_html_e( 'Slider', 'topsocietes' ); ?></a>
            <a href="#ts-tab-footer"   class="nav-tab"><?php esc_html_e( 'Pied de page', 'topsocietes' ); ?></a>
            <a href="#ts-tab-social"   class="nav-tab"><?php esc_html_e( 'Réseaux sociaux', 'topsocietes' ); ?></a>
            <a href="#ts-tab-analytics" class="nav-tab"><?php esc_html_e( 'Analytique', 'topsocietes' ); ?></a>
        </h2>

        <form method="post" action="options.php">
            <?php settings_fields( 'topsocietes_options_group' ); ?>

            <!-- ═══ Onglet En-tête ═══ -->
            <div id="ts-tab-header" class="ts-admin-panel">
                <h2><?php esc_html_e( 'En-tête', 'topsocietes' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ts_header_cta_text"><?php esc_html_e( 'Texte bouton CTA', 'topsocietes' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_header_cta_text" name="topsocietes_options[header_cta_text]"
                                value="<?php echo esc_attr( ts_get_option( 'header_cta_text', __( 'Publier mon entreprise', 'topsocietes' ) ) ); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'Bouton affiché en haut à droite de la navigation.', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ts_header_cta_url"><?php esc_html_e( 'URL bouton CTA', 'topsocietes' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_header_cta_url" name="topsocietes_options[header_cta_url]"
                                value="<?php echo esc_attr( ts_get_option( 'header_cta_url', '/tarifs/' ) ); ?>"
                                class="regular-text" placeholder="/tarifs/">
                            <p class="description"><?php esc_html_e( 'URL relative (/tarifs/) ou absolue (https://…).', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ═══ Onglet Slider ═══ -->
            <div id="ts-tab-slider" class="ts-admin-panel">
                <h2><?php esc_html_e( 'Slider d\'accueil', 'topsocietes' ); ?></h2>
                <p class="description" style="margin-bottom:20px">
                    <?php esc_html_e( 'Configurez jusqu\'à 4 slides affichés en haut de la page d\'accueil. Cochez "Actif" pour afficher un slide.', 'topsocietes' ); ?>
                </p>
                <?php for ( $i = 1; $i <= 4; $i++ ) :
                    $active   = ts_get_option( "slide_{$i}_active", $i <= 2 ? '1' : '0' );
                    $img      = ts_get_option( "slide_{$i}_img" );
                    $title    = ts_get_option( "slide_{$i}_title" );
                    $accent   = ts_get_option( "slide_{$i}_accent" );
                    $subtitle = ts_get_option( "slide_{$i}_subtitle" );
                    $btn_text = ts_get_option( "slide_{$i}_btn_text" );
                    $btn_url  = ts_get_option( "slide_{$i}_btn_url" );
                    $input_id = "ts_slide_{$i}_img";
                    $prev_id  = "ts_slide_{$i}_preview";
                ?>
                <div class="ts-slide-block">
                    <h3>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                            <input type="checkbox" name="topsocietes_options[slide_<?php echo $i; ?>_active]" value="1" <?php checked( $active, '1' ); ?>>
                            <?php printf( esc_html__( 'Slide %d', 'topsocietes' ), $i ); ?>
                        </label>
                    </h3>
                    <table class="form-table" role="presentation" style="margin:0">
                        <tr>
                            <th style="width:200px"><label for="<?php echo esc_attr( $input_id ); ?>"><?php esc_html_e( 'Texte du badge', 'topsocietes' ); ?></label></th>
                            <td>
                                <input type="text" id="<?php echo esc_attr( $input_id ); ?>"
                                    name="topsocietes_options[slide_<?php echo $i; ?>_img]"
                                    value="<?php echo esc_attr( $img ); ?>"
                                    class="large-text"
                                    placeholder="<?php esc_attr_e( 'ex : Europe · Asie · USA — 0 % impôt société', 'topsocietes' ); ?>">
                                <p class="description"><?php esc_html_e( 'Texte affiché dans la pastille au-dessus du titre. Laissez vide pour masquer.', 'topsocietes' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Titre', 'topsocietes' ); ?></label></th>
                            <td>
                                <input type="text" name="topsocietes_options[slide_<?php echo $i; ?>_title]"
                                    value="<?php echo esc_attr( $title ); ?>" class="regular-text"
                                    placeholder="<?php esc_attr_e( 'ex : Création de', 'topsocietes' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Mot accentué', 'topsocietes' ); ?></label></th>
                            <td>
                                <input type="text" name="topsocietes_options[slide_<?php echo $i; ?>_accent]"
                                    value="<?php echo esc_attr( $accent ); ?>" class="regular-text"
                                    placeholder="<?php esc_attr_e( 'ex : sociétés', 'topsocietes' ); ?>">
                                <p class="description"><?php esc_html_e( 'Ce mot apparaît en orange à la suite du titre.', 'topsocietes' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Sous-titre', 'topsocietes' ); ?></label></th>
                            <td>
                                <input type="text" name="topsocietes_options[slide_<?php echo $i; ?>_subtitle]"
                                    value="<?php echo esc_attr( $subtitle ); ?>" class="large-text"
                                    placeholder="<?php esc_attr_e( 'ex : en Europe, Asie et USA…', 'topsocietes' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Texte bouton', 'topsocietes' ); ?></label></th>
                            <td>
                                <input type="text" name="topsocietes_options[slide_<?php echo $i; ?>_btn_text]"
                                    value="<?php echo esc_attr( $btn_text ); ?>" class="regular-text"
                                    placeholder="<?php esc_attr_e( 'ex : Contactez-nous', 'topsocietes' ); ?>">
                                <p class="description"><?php esc_html_e( 'Laissez vide pour masquer le bouton.', 'topsocietes' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'URL bouton', 'topsocietes' ); ?></label></th>
                            <td>
                                <input type="text" name="topsocietes_options[slide_<?php echo $i; ?>_btn_url]"
                                    value="<?php echo esc_attr( $btn_url ); ?>" class="regular-text"
                                    placeholder="/tarifs/">
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endfor; ?>
            </div>

            <!-- ═══ Onglet Pied de page ═══ -->
            <div id="ts-tab-footer" class="ts-admin-panel">
                <h2><?php esc_html_e( 'Pied de page', 'topsocietes' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ts_footer_brand_desc"><?php esc_html_e( 'Description de la marque', 'topsocietes' ); ?></label></th>
                        <td>
                            <textarea id="ts_footer_brand_desc" name="topsocietes_options[footer_brand_desc]"
                                rows="3" class="large-text"><?php echo esc_textarea( ts_get_option( 'footer_brand_desc' ) ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Texte affiché sous le logo en pied de page. Laissez vide pour utiliser le texte par défaut.', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ts_footer_col1_title"><?php esc_html_e( 'Titre colonne 1', 'topsocietes' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_footer_col1_title" name="topsocietes_options[footer_col1_title]"
                                value="<?php echo esc_attr( ts_get_option( 'footer_col1_title', __( 'Annuaire', 'topsocietes' ) ) ); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'Correspond au menu Footer — Annuaire.', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ts_footer_col2_title"><?php esc_html_e( 'Titre colonne 2', 'topsocietes' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_footer_col2_title" name="topsocietes_options[footer_col2_title]"
                                value="<?php echo esc_attr( ts_get_option( 'footer_col2_title', __( 'Services', 'topsocietes' ) ) ); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'Correspond au menu Footer — Services.', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ts_footer_col3_title"><?php esc_html_e( 'Titre colonne 3', 'topsocietes' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_footer_col3_title" name="topsocietes_options[footer_col3_title]"
                                value="<?php echo esc_attr( ts_get_option( 'footer_col3_title', __( 'Légal', 'topsocietes' ) ) ); ?>"
                                class="regular-text">
                            <p class="description"><?php esc_html_e( 'Correspond au menu Footer — Légal.', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ts_footer_copyright"><?php esc_html_e( 'Texte copyright', 'topsocietes' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_footer_copyright" name="topsocietes_options[footer_copyright]"
                                value="<?php echo esc_attr( ts_get_option( 'footer_copyright' ) ); ?>"
                                class="large-text" placeholder="<?php esc_attr_e( 'Laissez vide pour le texte automatique', 'topsocietes' ); ?>">
                            <p class="description"><?php esc_html_e( 'Texte affiché en bas du footer. Laissez vide pour générer automatiquement avec l\'année en cours.', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- ═══ Onglet Réseaux sociaux ═══ -->
            <div id="ts-tab-social" class="ts-admin-panel">
                <h2><?php esc_html_e( 'Réseaux sociaux', 'topsocietes' ); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    $socials = [
                        'social_linkedin'  => [ 'LinkedIn',    'https://linkedin.com/company/…' ],
                        'social_twitter'   => [ 'X / Twitter', 'https://x.com/…' ],
                        'social_facebook'  => [ 'Facebook',    'https://facebook.com/…' ],
                        'social_instagram' => [ 'Instagram',   'https://instagram.com/…' ],
                    ];
                    foreach ( $socials as $key => [ $label, $placeholder ] ) : ?>
                    <tr>
                        <th scope="row"><label for="ts_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
                        <td>
                            <input type="url" id="ts_<?php echo esc_attr( $key ); ?>"
                                name="topsocietes_options[<?php echo esc_attr( $key ); ?>]"
                                value="<?php echo esc_attr( ts_get_option( $key ) ); ?>"
                                class="large-text" placeholder="<?php echo esc_attr( $placeholder ); ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                <p class="description" style="padding:0 0 0 220px">
                    <?php esc_html_e( 'Les icônes de réseaux sociaux s\'affichent dans le pied de page si le champ est renseigné.', 'topsocietes' ); ?>
                </p>
            </div>

            <!-- ═══ Onglet Analytique ═══ -->
            <div id="ts-tab-analytics" class="ts-admin-panel">
                <h2><?php esc_html_e( 'Analytique', 'topsocietes' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ts_ga_id"><?php esc_html_e( 'Google Analytics ID', 'topsocietes' ); ?></label></th>
                        <td>
                            <input type="text" id="ts_ga_id" name="topsocietes_options[ga_id]"
                                value="<?php echo esc_attr( ts_get_option( 'ga_id' ) ); ?>"
                                class="regular-text" placeholder="G-XXXXXXXXXX">
                            <p class="description"><?php esc_html_e( 'Identifiant GA4 (G-XXXXXXXX). Laissez vide pour désactiver.', 'topsocietes' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="ts-settings-save">
                <?php submit_button( __( 'Enregistrer les réglages', 'topsocietes' ), 'primary', 'submit', false ); ?>
            </div>
        </form>
    </div>
    <?php
}

/* ─────────────────────────────────────────────
   Google Analytics — injection dans <head>
─────────────────────────────────────────────── */

add_action( 'wp_head', 'ts_inject_ga', 1 );
function ts_inject_ga(): void {
    $ga_id = ts_get_option( 'ga_id' );
    if ( ! $ga_id || is_admin() ) {
        return;
    }
    ?>
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $ga_id ); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo esc_js( $ga_id ); ?>');
</script>
    <?php
}
