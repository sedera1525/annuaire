<?php
/**
 * Plugin Name:  Societies Connector
 * Description:  Connexion à l'API Societies — fiches entreprises, abonnements et tableau de bord propriétaire.
 * Version:      2.5.24
 * Author:       Societies
 * Text Domain:  societies
 */

if (!defined('ABSPATH')) exit;

define('SC_VERSION', '2.5.24');

// Force le rendu du shortcode plugin sur les pages dont le thème posséderait
// un template page-{slug}.php qui prendrait le dessus sur le_content().
add_filter('template_include', function(string $template): string {
    $sc_template = SC_DIR . 'templates/shortcode-page.php';
    if (!file_exists($sc_template)) return $template;
    if (is_page(['recherche', 'recherche-entreprises', 'tarifs']) || is_page_template(['page-recherche.php', 'page-tarifs.php'])) {
        return $sc_template;
    }
    return $template;
}, 99);
define('SC_DIR', plugin_dir_path(__FILE__));
define('SC_URL', plugin_dir_url(__FILE__));

// =============================================================================
// AUTO-UPDATE — vérifie les mises à jour depuis le backend Societies
// =============================================================================

add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;
    $api_url = rtrim(get_option('societies_api_url', ''), '/');
    if (!$api_url) return $transient;
    $response = wp_remote_get($api_url . '/api/plugin/info', ['timeout' => 10, 'sslverify' => false]);
    if (is_wp_error($response)) return $transient;
    $info = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($info['version'])) return $transient;
    if (version_compare($info['version'], SC_VERSION, '>')) {
        $license = get_option('sc_license_key', '');
        $slug    = 'societies-connector/societies-connector.php';
        $transient->response[$slug] = (object)[
            'slug'        => 'societies-connector',
            'plugin'      => $slug,
            'new_version' => $info['version'],
            'url'         => $api_url,
            'package'     => $api_url . '/api/plugin/download' . ($license ? '?license=' . urlencode($license) : ''),
        ];
    }
    return $transient;
});

add_filter('plugins_api', function($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'societies-connector') return $result;
    $api_url  = rtrim(get_option('societies_api_url', ''), '/');
    $response = wp_remote_get($api_url . '/api/plugin/info', ['timeout' => 10, 'sslverify' => false]);
    if (is_wp_error($response)) return $result;
    $info = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($info['version'])) return $result;
    $license = get_option('sc_license_key', '');
    return (object)[
        'name'          => 'Societies Connector',
        'slug'          => 'societies-connector',
        'version'       => $info['version'],
        'requires'      => $info['requires'] ?? '6.0',
        'tested'        => $info['tested'] ?? '6.9',
        'download_link' => $api_url . '/api/plugin/download' . ($license ? '?license=' . urlencode($license) : ''),
        'sections'      => $info['sections'] ?? [],
    ];
}, 10, 3);

// Auto-installation silencieuse dès qu'une nouvelle version est disponible
add_filter('auto_update_plugin', function($update, $item) {
    if (($item->slug ?? '') === 'societies-connector') return true;
    return $update;
}, 10, 2);

// =============================================================================
// INTÉGRATION AUTOMATIQUE — crée les pages WP des nouvelles fiches générées
// =============================================================================

// Trouve ou crée une page WP (ville ou métier) sous un parent donné.
function sc_get_or_create_parent_page(string $slug, string $display_title, int $parent_id = 0): int {
    $existing = get_posts([
        'post_type'   => 'page',
        'post_status' => ['publish'],
        'post_parent' => $parent_id,
        'name'        => $slug,
        'numberposts' => 1,
    ]);
    if ($existing) return (int) $existing[0]->ID;
    $post_id = wp_insert_post([
        'post_title'  => sanitize_text_field($display_title),
        'post_name'   => $slug,
        'post_status' => 'publish',
        'post_type'   => 'page',
        'post_parent' => $parent_id,
    ]);
    return is_wp_error($post_id) ? 0 : (int) $post_id;
}

// Résout le post_parent pour la structure /[ville]/[metier]/[nom]/.
// Crée les pages intermédiaires si elles n'existent pas encore.
// Retourne 0 en fallback si ville ou métier manquants.
function sc_resolve_page_parent(string $company_title): int {
    $data     = sc_api('/api/company/' . rawurlencode($company_title));
    $city     = $data['city']     ?? '';
    $category = $data['category'] ?? '';
    if (!$city || !$category) return 0;
    $city_id  = sc_get_or_create_parent_page(sanitize_title($city), $city, 0);
    if (!$city_id) return 0;
    return sc_get_or_create_parent_page(sanitize_title($category), $category, $city_id) ?: 0;
}

add_action('sc_auto_sync_fiches', 'sc_sync_fiches_to_pages');

function sc_sync_fiches_to_pages(): void {
    if (!sc_is_licensed()) return;
    $page = 1;
    do {
        $fiches = sc_api('/api/fiches?per_page=100&page=' . $page);
        if (!empty($fiches['error'])) break;
        $items = $fiches['results'] ?? [];
        if (empty($items)) break;
        foreach ($items as $f) {
            $title = $f['company_title'] ?? '';
            if (!$title) continue;
            $existing = get_posts([
                'post_type'   => 'page',
                'post_status' => ['publish', 'draft'],
                'meta_key'    => '_sc_company_title',
                'meta_value'  => $title,
                'numberposts' => 1,
            ]);
            if ($existing) continue;
            $post_id = wp_insert_post([
                'post_title'   => sanitize_text_field($title),
                'post_name'    => sanitize_title($title),
                'post_content' => '[societies_fiche title="' . esc_attr($title) . '"]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_parent'  => sc_resolve_page_parent($title),
            ]);
            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, '_sc_company_title', $title);
            }
        }
        $page++;
    } while (count($items) === 100);
}

// Planifie le cron si pas encore fait
add_action('wp', function() {
    if (!wp_next_scheduled('sc_auto_sync_fiches')) {
        wp_schedule_event(time(), 'thirtyminutes', 'sc_auto_sync_fiches');
    }
});

// Intervalle custom 30 min
add_filter('cron_schedules', function($schedules) {
    $schedules['thirtyminutes'] = ['interval' => 1800, 'display' => 'Toutes les 30 minutes'];
    return $schedules;
});

// Nettoie le cron à la désactivation du plugin
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('sc_auto_sync_fiches');
});

// =============================================================================
// REST API — Endpoint webhook : crée la page WP d'une fiche dès génération
// POST /wp-json/sc/v1/sync-fiche  { "title": "Nom entreprise" }
// Header X-SC-Secret: <societies_api_password>
// =============================================================================
add_action('rest_api_init', function() {
    register_rest_route('sc/v1', '/sync-fiche', [
        'methods'             => 'POST',
        'callback'            => 'sc_rest_sync_fiche',
        'permission_callback' => function(WP_REST_Request $req) {
            $secret = $req->get_header('X-SC-Secret');
            return $secret && $secret === get_option('societies_webhook_secret', '');
        },
    ]);
});

function sc_rest_sync_fiche(WP_REST_Request $request): WP_REST_Response {
    $title = sanitize_text_field($request->get_param('title') ?? '');
    if (!$title) return new WP_REST_Response(['error' => 'title required'], 400);

    $existing = get_posts([
        'post_type'   => 'page',
        'post_status' => ['publish', 'draft'],
        'meta_key'    => '_sc_company_title',
        'meta_value'  => $title,
        'numberposts' => 1,
    ]);
    if ($existing) {
        return new WP_REST_Response(['status' => 'exists', 'id' => $existing[0]->ID, 'url' => get_permalink($existing[0]->ID)], 200);
    }

    $parent_id = sc_resolve_page_parent($title);
    $post_id   = wp_insert_post([
        'post_title'   => sanitize_text_field($title),
        'post_name'    => sanitize_title($title),
        'post_content' => '[societies_fiche title="' . esc_attr($title) . '"]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_parent'  => $parent_id,
    ]);

    if (is_wp_error($post_id)) {
        return new WP_REST_Response(['error' => $post_id->get_error_message()], 500);
    }
    update_post_meta($post_id, '_sc_company_title', $title);
    return new WP_REST_Response(['status' => 'created', 'id' => $post_id, 'url' => get_permalink($post_id)], 201);
}

// =============================================================================
// LICENCE
// =============================================================================

function sc_is_licensed(): bool {
    return get_option('sc_license_activated') === 'yes';
}

// Bannière d'activation si non licencié
add_action('admin_notices', function() {
    if (sc_is_licensed()) return;
    $screen = get_current_screen();
    // Afficher uniquement sur les pages Societies ou le tableau de bord
    if (!$screen || !in_array($screen->id, ['dashboard', 'toplevel_page_societies',
        'societies_page_societies-settings', 'societies_page_societies-fiches',
        'societies_page_societies-subscriptions', 'societies_page_societies-moderation',
        'toplevel_page_sc-mon-entreprise'])) return;
    ?>
    <div class="notice notice-error" style="padding:16px 20px">
      <strong>🔒 Societies Connector — Activation requise</strong>
      <p style="margin:8px 0 12px;color:#555">
        Entrez la clé de licence disponible dans votre backend Societies
        (<code>Réglages API → Clé de licence</code>).
      </p>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php wp_nonce_field('sc_activate_license'); ?>
        <input type="text" name="sc_license_key" placeholder="XXXX-XXXX-XXXX-XXXX"
               style="font-family:monospace;font-size:14px;letter-spacing:2px;padding:6px 10px;width:240px;border:2px solid #d63638;border-radius:6px">
        <?php submit_button('Activer', 'primary', 'sc_activate', false); ?>
      </form>
      <?php if (!empty($_GET['sc_license_error'])): ?>
      <p style="color:#d63638;margin:8px 0 0;font-size:13px">
        ❌ <?= esc_html(urldecode($_GET['sc_license_error'])) ?>
      </p>
      <?php endif; ?>
    </div>
    <?php
});

// Traitement de l'activation
add_action('admin_init', function() {
    if (!isset($_POST['sc_activate']) || !check_admin_referer('sc_activate_license')) return;

    $key  = sanitize_text_field($_POST['sc_license_key'] ?? '');
    $base = rtrim(get_option('societies_api_url', 'http://societies:8090'), '/');

    $r = wp_remote_post($base . '/api/license/verify', [
        'body'    => wp_json_encode(['key' => $key]),
        'headers' => ['Content-Type' => 'application/json'],
        'timeout' => 10,
    ]);

    if (is_wp_error($r)) {
        wp_redirect(add_query_arg('sc_license_error', urlencode('Impossible de contacter le serveur.'), wp_get_referer()));
        exit;
    }

    $code = wp_remote_retrieve_response_code($r);
    $body = json_decode(wp_remote_retrieve_body($r), true);

    if ($code === 200 && !empty($body['ok'])) {
        // Stocker UNIQUEMENT le hash SHA-256 — la clé brute n'est jamais conservée
        update_option('sc_license_activated', 'yes');
        update_option('sc_license_hash', hash('sha256', $key));
        wp_redirect(remove_query_arg('sc_license_error', wp_get_referer() ?: admin_url()));
    } else {
        $msg = $body['detail'] ?? 'Clé de licence invalide.';
        wp_redirect(add_query_arg('sc_license_error', urlencode($msg), wp_get_referer()));
    }
    exit;
});

// =============================================================================
// API CLIENT
// =============================================================================
function sc_api(string $endpoint, string $method = 'GET', array $body = []): array {
    if (!sc_is_licensed()) return ['error' => 'Plugin non activé. Entrez votre clé de licence.'];
    $base = rtrim(get_option('societies_api_url', 'http://societies:8090'), '/');

    // WordPress bloque par défaut les hostnames internes (Docker, etc.)
    // On autorise le host configuré pour éviter "L'URL fournie n'est pas valide"
    $api_host = parse_url($base, PHP_URL_HOST);
    $allow_host = function(bool $external, string $host) use ($api_host): bool {
        return ($host === $api_host) ? true : $external;
    };
    add_filter('http_request_host_is_external', $allow_host, 10, 2);

    // Authenticate if needed
    $session = get_transient('sc_session_cookie');
    $csrf    = get_transient('sc_csrf_token');
    if (!$session) {
        [$session, $csrf] = sc_authenticate();
    }

    $headers = [
        'Content-Type' => 'application/json',
        'Cookie'       => "societies_session={$session}; csrf_token={$csrf}",
    ];
    // Double-submit CSRF pattern — requis pour POST/PUT/DELETE
    if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        $headers['X-CSRF-Token'] = $csrf;
    }

    $args = ['method' => $method, 'timeout' => 15, 'headers' => $headers];
    if (!empty($body)) {
        $args['body'] = wp_json_encode($body);
    }

    $r = wp_remote_request($base . $endpoint, $args);
    remove_filter('http_request_host_is_external', $allow_host, 10);

    if (is_wp_error($r)) {
        error_log('[SC API] Erreur WP HTTP: ' . $r->get_error_message() . ' | url=' . $base . $endpoint);
        return ['error' => $r->get_error_message()];
    }

    // Re-auth on 401
    if (wp_remote_retrieve_response_code($r) === 401) {
        [$session, $csrf] = sc_authenticate(true);
        $args['headers']['Cookie']       = "societies_session={$session}; csrf_token={$csrf}";
        $args['headers']['X-CSRF-Token'] = $csrf;
        add_filter('http_request_host_is_external', $allow_host, 10, 2);
        $r = wp_remote_request($base . $endpoint, $args);
        remove_filter('http_request_host_is_external', $allow_host, 10);
    }

    return json_decode(wp_remote_retrieve_body($r), true) ?: [];
}

function sc_authenticate(bool $force = false): array {
    if (!$force) {
        $session = get_transient('sc_session_cookie');
        $csrf    = get_transient('sc_csrf_token');
        if ($session && $csrf) return [$session, $csrf];
    }

    $base = rtrim(get_option('societies_api_url', 'http://societies:8090'), '/');
    $r = wp_remote_post($base . '/login', [
        'body'        => http_build_query([
            'username' => get_option('societies_api_username', 'admin'),
            'password' => get_option('societies_api_password', ''),
        ]),
        'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'timeout'     => 10,
        'redirection' => 0,
    ]);

    if (is_wp_error($r)) return ['', ''];

    $raw = wp_remote_retrieve_header($r, 'set-cookie');
    $raw = is_array($raw) ? implode('; ', $raw) : $raw;

    $session = '';
    $csrf    = '';
    if (preg_match('/societies_session=([^;,\s]+)/', $raw, $m)) {
        $session = $m[1];
        set_transient('sc_session_cookie', $session, 6 * HOUR_IN_SECONDS);
    }
    if (preg_match('/csrf_token=([^;,\s]+)/', $raw, $m)) {
        $csrf = $m[1];
        set_transient('sc_csrf_token', $csrf, 6 * HOUR_IN_SECONDS);
    }
    return [$session, $csrf];
}

// =============================================================================
// SUBSCRIPTION CHECK
// =============================================================================
function sc_user_has_subscription(int $user_id = 0): bool {
    if (!$user_id) $user_id = get_current_user_id();
    if (!$user_id || !function_exists('wc_get_orders')) return false;

    $orders = wc_get_orders([
        'customer' => $user_id,
        'status'   => ['completed', 'processing'],
        'limit'    => 1,
    ]);
    return !empty($orders);
}

/**
 * Retourne l'URL publique de la fiche WP pour une entreprise donnée.
 * Cherche d'abord par meta _sc_company_title, puis par titre de page.
 */
function sc_get_fiche_url(string $company_title): string {
    if (!$company_title) return home_url('/');
    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_key'       => '_sc_company_title',
        'meta_value'     => $company_title,
    ]);
    if ($pages) return get_permalink($pages[0]->ID);
    $page = get_page_by_title($company_title, OBJECT, 'page');
    if ($page) return get_permalink($page->ID);
    return home_url('/');
}

// =============================================================================
// NOTIFICATIONS EMAIL
// =============================================================================

// Email de bienvenue à l'inscription WordPress
add_action('user_register', function(int $user_id) {
    $user = get_userdata($user_id);
    if (!$user) return;

    $site_name = get_bloginfo('name') ?: 'TOPsocietes.com';
    $login_url = wp_login_url();

    // Lien vers la page du tableau de bord propriétaire
    global $wpdb;
    $dashboard_id  = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%societies_owner_dashboard%' AND post_status='publish' LIMIT 1");
    $dashboard_url = $dashboard_id ? get_permalink((int)$dashboard_id) : $login_url;

    $subject = $site_name . ' — Bienvenue sur votre espace entreprise';
    $message  = "Bonjour {$user->display_name},\n\n";
    $message .= "Votre compte a bien été créé sur {$site_name}.\n\n";
    $message .= "Accédez à votre tableau de bord et revendiquez votre fiche entreprise :\n";
    $message .= $dashboard_url . "\n\n";
    $message .= "Une fois connecté, recherchez votre entreprise et complétez votre profil pour améliorer votre visibilité.\n\n";
    $message .= "Cordialement,\nL'équipe {$site_name}";

    wp_mail($user->user_email, $subject, $message);
});

// Email de bienvenue après un achat WooCommerce complété
add_action('woocommerce_order_status_completed', function(int $order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    // Vérifie que la commande contient bien un pack Societies
    $has_sc_pack = false;
    $pack_name   = '';
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        $sc_pack = $product->get_meta('_sc_pack');
        if ($sc_pack) {
            $has_sc_pack = true;
            $pack_name   = $item->get_name();
            break;
        }
    }
    if (!$has_sc_pack) return;

    $email     = $order->get_billing_email();
    $firstname = $order->get_billing_first_name() ?: 'client';
    $site_name = get_bloginfo('name') ?: 'TOPsocietes.com';
    $login_url = wp_login_url();

    $subject  = $site_name . ' — Votre abonnement est actif';
    $message  = "Bonjour {$firstname},\n\n";
    $message .= "Merci pour votre abonnement « {$pack_name} » !\n\n";
    $message .= "Votre accès est maintenant actif. Connectez-vous à votre tableau de bord pour compléter votre fiche entreprise :\n";
    $message .= $login_url . "\n\n";
    $message .= "Depuis votre espace, vous pouvez :\n";
    $message .= "- Modifier votre présentation\n";
    $message .= "- Répondre aux questions sur votre activité\n";
    $message .= "- Mettre en valeur vos services\n\n";
    $message .= "Cordialement,\nL'équipe {$site_name}";

    wp_mail($email, $subject, $message);
});

// =============================================================================
// SHORTCODES
// =============================================================================

// [societies_listings city="" category="" limit="12"]
add_shortcode('societies_listings', function($atts) {
    $atts = shortcode_atts(['city' => '', 'category' => '', 'limit' => 12], $atts);
    $params = '?per_page=' . intval($atts['limit']) . '&sort_by=rating';
    if ($atts['city'])     $params .= '&city='     . urlencode($atts['city']);
    if ($atts['category']) $params .= '&category=' . urlencode($atts['category']);

    $data    = sc_api('/api/search' . $params);
    $results = $data['results'] ?? [];

    ob_start(); ?>
    <style>
      .sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin:20px 0}
      .sc-card{border:1px solid #e5e7eb;border-radius:10px;padding:16px;transition:box-shadow .2s}
      .sc-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1)}
      .sc-card h3{margin:0 0 4px;font-size:15px;color:#111}
      .sc-cat{color:#6b7280;font-size:12px;margin-bottom:8px}
      .sc-rating{color:#f59e0b;font-weight:700;font-size:13px}
      .sc-rating span{color:#9ca3af;font-weight:400}
      .sc-phone{margin-top:8px;font-size:13px;color:#374151}
      .sc-claimed{font-size:11px;color:#10b981;margin-top:4px}
      .sc-total{color:#9ca3af;font-size:13px;margin-top:8px}
    </style>
    <div class="sc-grid">
    <?php foreach ($results as $c): ?>
      <div class="sc-card">
        <h3><?= esc_html($c['title']) ?></h3>
        <div class="sc-cat"><?= esc_html($c['category']) ?><?= $c['city'] ? ' — ' . esc_html($c['city']) : '' ?></div>
        <?php if ($c['rating_value']): ?>
        <div class="sc-rating">★ <?= number_format($c['rating_value'], 1) ?>/5
          <span>(<?= number_format($c['rating_votes'] ?? 0, 0, ',', ' ') ?> avis)</span>
        </div>
        <?php endif; ?>
        <?php if ($c['phone']): ?>
        <div class="sc-phone">📞 <?= esc_html($c['phone']) ?></div>
        <?php endif; ?>
        <?php if (!empty($c['is_claimed'])): ?>
        <div class="sc-claimed">✅ Fiche revendiquée</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <p class="sc-total"><?= number_format($data['total'] ?? 0, 0, ',', ' ') ?> entreprises · page <?= $data['page'] ?? 1 ?>/<?= $data['pages'] ?? 1 ?></p>
    <?php
    return ob_get_clean();
});

// [societies_owner_dashboard]
add_shortcode('societies_owner_dashboard', function() {
    if (!is_user_logged_in()) {
        return '<p>Vous devez être <a href="' . esc_url(wp_login_url(get_permalink())) . '">connecté</a> pour accéder à votre tableau de bord.</p>';
    }

    $user_id = get_current_user_id();
    $user    = wp_get_current_user();

    // Association entreprise
    if (isset($_POST['sc_company_title']) && check_admin_referer('sc_link_company')) {
        $title   = sanitize_text_field($_POST['sc_company_title']);
        $company = sc_api('/api/company/' . rawurlencode($title));
        if (!isset($company['error']) && !empty($company['title'])) {
            update_user_meta($user_id, 'sc_company_title', $company['title']);
        } else {
            update_user_meta($user_id, 'sc_link_error', 'Entreprise introuvable dans la base.');
        }
    }

    $company_title = get_user_meta($user_id, 'sc_company_title', true);
    $has_sub       = sc_user_has_subscription($user_id);
    $user_email    = $user->user_email;

    // ── Soumettre présentation en modération ──────────────────────────────────
    if (isset($_POST['sc_save_intro']) && check_admin_referer('sc_save_intro') && $company_title) {
        $intro_text = sanitize_textarea_field($_POST['sc_intro_text'] ?? '');
        $result = sc_api('/api/modifications', 'POST', [
            'company_title' => $company_title,
            'field_name'    => 'intro_text',
            'field_value'   => $intro_text,
            'user_email'    => $user_email,
        ]);
        if (!isset($result['error'])) {
            update_user_meta($user_id, 'sc_mod_notice', 'intro_pending');
        }
    }

    // ── Soumettre réponses ouvertes en modération (abonnement requis) ─────────
    if (isset($_POST['sc_save_answers']) && check_admin_referer('sc_save_answers') && $company_title && $has_sub) {
        $raw_answers    = $_POST['sc_open_answers'] ?? [];
        $fiche_data     = sc_api('/api/fiche/' . rawurlencode($company_title));
        $questions      = $fiche_data['open_questions'] ?? [];
        $open_answers   = [];
        foreach ($questions as $i => $q) {
            $open_answers[] = [
                'q' => $q,
                'r' => sanitize_textarea_field($raw_answers[$i] ?? ''),
            ];
        }
        $result = sc_api('/api/modifications', 'POST', [
            'company_title' => $company_title,
            'field_name'    => 'open_answers',
            'field_value'   => json_encode($open_answers, JSON_UNESCAPED_UNICODE),
            'user_email'    => $user_email,
        ]);
        if (!isset($result['error'])) {
            update_user_meta($user_id, 'sc_mod_notice', 'answers_pending');
        }
    }

    ob_start();
    sc_enqueue_styles();

    if (!$company_title):
        $error = get_user_meta($user_id, 'sc_link_error', true);
        delete_user_meta($user_id, 'sc_link_error');
        ?>
        <div class="sc-dashboard">
          <h2>🏢 Associer mon entreprise</h2>
          <p>Entrez le nom exact de votre entreprise pour accéder à votre fiche :</p>
          <?php if ($error): ?><div class="sc-error"><?= esc_html($error) ?></div><?php endif; ?>
          <form method="post" class="sc-form">
            <?php wp_nonce_field('sc_link_company'); ?>
            <input type="text" name="sc_company_title" class="sc-input"
              placeholder="Ex : Get Out - Escape Game Lille" required>
            <button type="submit" class="sc-btn">Associer mon entreprise →</button>
          </form>
        </div>
    <?php else:
        $fiche          = sc_api('/api/fiche/' . rawurlencode($company_title));
        $qa_answered    = $fiche['qa_answered']   ?? [];
        $open_questions = $fiche['open_questions'] ?? [];
        $intro          = $fiche['intro_text']    ?? '';
        $date           = $fiche['date_fr']       ?? '';

        // Modifications en attente pour cet utilisateur
        $pending_mods = sc_api('/api/modifications?status=pending&company_title=' . rawurlencode($company_title));
        $pending_list = $pending_mods['results'] ?? [];
        $pending_fields = array_column($pending_list, 'field_name');

        $mod_notice = get_user_meta($user_id, 'sc_mod_notice', true);
        delete_user_meta($user_id, 'sc_mod_notice');
    ?>
        <div class="sc-dashboard">
          <h2 style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            📋 <?= esc_html($company_title) ?>
            <?php $fiche_url = sc_get_fiche_url($company_title); if ($fiche_url !== home_url('/')): ?>
            <a href="<?= esc_url($fiche_url) ?>" target="_blank" rel="noopener"
               style="font-size:13px;font-weight:600;color:#F97316;text-decoration:none;background:#fff7ed;border:1px solid #fed7aa;padding:4px 12px;border-radius:20px;white-space:nowrap">
              Voir ma fiche →
            </a>
            <?php endif; ?></h2>

          <?php if ($mod_notice === 'intro_pending'): ?>
          <div class="sc-success">✅ Votre présentation a été soumise — elle sera publiée après validation.</div>
          <?php elseif ($mod_notice === 'answers_pending'): ?>
          <div class="sc-success">✅ Vos réponses ont été soumises — elles seront publiées après validation.</div>
          <?php endif; ?>

          <?php if (!empty($pending_list)): ?>
          <div class="sc-mod-pending">
            ⏳ <strong><?= count($pending_list) ?> modification(s) en attente de validation</strong>
            <?php foreach ($pending_list as $mod): ?>
            <div class="sc-mod-item">
              <?= $mod['field_name'] === 'intro_text' ? 'Présentation' : 'Réponses aux questions' ?>
              — soumise le <?= esc_html(substr($mod['submitted_at'], 0, 10)) ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- ── Présentation ── -->
          <h3>✏️ Votre présentation <span style="font-size:11px;color:#10b981;font-weight:normal;margin-left:6px">Gratuit</span></h3>
          <?php if (in_array('intro_text', $pending_fields)): ?>
          <div class="sc-mod-info">⏳ Une modification est en attente de validation — vous ne pouvez pas soumettre une nouvelle version tant qu'elle n'est pas traitée.</div>
          <?php else: ?>
          <form method="post" class="sc-form">
            <?php wp_nonce_field('sc_save_intro'); ?>
            <textarea name="sc_intro_text" class="sc-textarea" rows="4"
              placeholder="Rédigez une présentation de votre entreprise (3 phrases recommandées)..."
              style="min-height:100px"><?= esc_textarea($intro) ?></textarea>
            <p class="sc-mod-note">📋 Votre modification sera soumise à validation avant publication.</p>
            <button type="submit" name="sc_save_intro" value="1" class="sc-btn" style="margin-top:8px">
              Soumettre la présentation →
            </button>
          </form>
          <?php endif; ?>

          <?php if ($intro): ?>
          <div class="sc-intro" style="margin-top:12px">
            <p><?= esc_html($intro) ?></p>
            <?php if ($date): ?><div class="sc-date">Décryptage du <?= esc_html($date) ?></div><?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($qa_answered)): ?>
          <h3>✅ Questions & Réponses générées</h3>
          <?php foreach ($qa_answered as $item): ?>
          <div class="sc-qa-item">
            <div class="sc-q"><?= esc_html($item['q']) ?></div>
            <div class="sc-r"><?= esc_html($item['r']) ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>

          <?php if (!empty($open_questions)): ?>
          <h3>🔐 Questions à compléter
            <?php if (!$has_sub): ?><span class="sc-badge-locked">Abonnement requis</span><?php endif; ?>
          </h3>

          <?php if (!$has_sub): ?>
          <div class="sc-sub-banner">
            <strong>🔐 Accédez à votre fiche complète</strong><br>
            Abonnez-vous pour répondre aux 6 questions verrouillées et enrichir votre profil.<br><br>
            <a href="<?= esc_url(get_permalink(wc_get_page_id('shop'))) ?>" class="sc-btn">Voir nos abonnements →</a>
          </div>
          <?php elseif (in_array('open_answers', $pending_fields)): ?>
          <div class="sc-mod-info">⏳ Des réponses sont en attente de validation.</div>
          <?php else: ?>
          <form method="post">
            <?php wp_nonce_field('sc_save_answers'); ?>
            <?php foreach ($open_questions as $i => $q): ?>
            <div class="sc-qa-item sc-locked">
              <div class="sc-q"><?= esc_html($q) ?></div>
              <textarea name="sc_open_answers[<?= $i ?>]" class="sc-textarea"
                placeholder="Votre réponse..."></textarea>
            </div>
            <?php endforeach; ?>
            <p class="sc-mod-note">📋 Vos réponses seront soumises à validation avant publication.</p>
            <button type="submit" name="sc_save_answers" value="1" class="sc-btn" style="margin-top:8px">
              Soumettre mes réponses →
            </button>
          </form>
          <?php endif; ?>
          <?php endif; ?>

          <hr style="margin:24px 0">
          <p style="font-size:13px;color:#9ca3af">
            <a href="<?= esc_url(add_query_arg('sc_unlink', '1')) ?>" style="color:#ef4444">
              Dissocier cette entreprise
            </a>
          </p>
        </div>
    <?php endif;
    return ob_get_clean();
});

// Dissociation entreprise
add_action('template_redirect', function() {
    if (is_user_logged_in() && isset($_GET['sc_unlink'])) {
        delete_user_meta(get_current_user_id(), 'sc_company_title');
        delete_user_meta(get_current_user_id(), 'sc_open_answers');
        wp_redirect(remove_query_arg('sc_unlink'));
        exit;
    }
});

// =============================================================================
// SHORTCODES SEO (secteur / ville)
// =============================================================================

// [societies_by_sector sector="Escape room center" limit="20"]
add_shortcode('societies_by_sector', function($atts) {
    $atts   = shortcode_atts(['sector' => '', 'limit' => 20], $atts);
    if (!$atts['sector']) return '';
    $data    = sc_api('/api/seo/sector/' . rawurlencode($atts['sector']) . '?limit=' . intval($atts['limit']));
    $results = $data['results'] ?? [];
    ob_start(); ?>
    <div class="sc-seo-list">
      <p class="sc-total"><?= number_format($data['total'] ?? 0, 0, ',', ' ') ?> entreprises — <?= esc_html($atts['sector']) ?></p>
      <?php foreach ($results as $c): ?>
      <div class="sc-seo-row">
        <strong><?= esc_html($c['title']) ?></strong>
        <span class="sc-seo-city"><?= esc_html($c['city'] ?? '') ?></span>
        <?php if ($c['rating_value']): ?>
        <span class="sc-seo-rating">★ <?= number_format($c['rating_value'], 1) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <style>
      .sc-seo-list{margin:16px 0}
      .sc-seo-row{display:flex;gap:12px;align-items:center;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:14px}
      .sc-seo-row strong{flex:1}
      .sc-seo-city{color:#6b7280;font-size:13px}
      .sc-seo-rating{color:#f59e0b;font-size:12px;font-weight:700}
    </style>
    <?php return ob_get_clean();
});

// [societies_by_city city="Lille" limit="20"]
add_shortcode('societies_by_city', function($atts) {
    $atts   = shortcode_atts(['city' => '', 'limit' => 20], $atts);
    if (!$atts['city']) return '';
    $data    = sc_api('/api/seo/city/' . rawurlencode($atts['city']) . '?limit=' . intval($atts['limit']));
    $results = $data['results'] ?? [];
    ob_start(); ?>
    <div class="sc-seo-list">
      <p class="sc-total"><?= number_format($data['total'] ?? 0, 0, ',', ' ') ?> entreprises à <?= esc_html($atts['city']) ?></p>
      <?php foreach ($results as $c): ?>
      <div class="sc-seo-row">
        <strong><?= esc_html($c['title']) ?></strong>
        <span class="sc-seo-city"><?= esc_html($c['category'] ?? '') ?></span>
        <?php if ($c['rating_value']): ?>
        <span class="sc-seo-rating">★ <?= number_format($c['rating_value'], 1) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
});

// =============================================================================
// SHORTCODE PAGE TARIFAIRE [societies_pricing]
// =============================================================================

add_shortcode('societies_pricing', function($atts) {
    $api_url = rtrim(get_option('societies_api_url', ''), '/');
    if (!$api_url) return '<p>API Societies non configurée.</p>';

    // Supprime les credentials éventuels de l'URL (user:pass@host) — l'endpoint est public
    $parsed   = parse_url($api_url);
    $base_url = ($parsed['scheme'] ?? 'http') . '://'
              . ($parsed['host'] ?? '')
              . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
              . rtrim($parsed['path'] ?? '', '/');

    $resp = wp_remote_get($base_url . '/api/public/packs', ['timeout' => 8, 'sslverify' => false]);
    if (is_wp_error($resp)) return '<p>Impossible de charger les offres.</p>';
    $data  = json_decode(wp_remote_retrieve_body($resp), true);
    $packs = $data['packs'] ?? [];
    if (empty($packs)) return '<p>Aucun pack disponible.</p>';

    ob_start(); ?>
    <style>
    .scp-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
              padding:40px 20px 60px;max-width:1300px;margin:0 auto;text-align:center}
    .scp-header{margin-bottom:48px}
    .scp-title{font-size:34px;font-weight:800;color:#1a2744;margin:0 0 12px}
    .scp-sub{font-size:16px;color:#6b7280;max-width:520px;margin:0 auto}
    .scp-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;text-align:left}
    .scp-card{border:2px solid #e8edf3;border-radius:20px;
              padding:28px 22px;display:flex;flex-direction:column;gap:0;
              transition:transform .2s,box-shadow .2s;position:relative;overflow:hidden}
    .scp-card:hover{transform:translateY(-6px);box-shadow:0 16px 48px rgba(0,0,0,.1)}
    .scp-card.featured{border-color:var(--sc-color,#10b981);box-shadow:0 8px 32px rgba(0,0,0,.08)}
    .scp-card.featured::before{content:'Le plus choisi';position:absolute;top:18px;right:-32px;
      background:var(--sc-color,#10b981);color:#fff;font-size:11px;font-weight:700;
      padding:4px 40px;transform:rotate(45deg);letter-spacing:.5px}
    .scp-star-offer{display:flex;align-items:flex-start;gap:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:20px;font-size:12px;color:#92400e;line-height:1.5}
    .scp-badge{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.8px;
               text-transform:uppercase;color:var(--sc-color,#10b981);
               background:color-mix(in srgb,var(--sc-color,#10b981) 12%,transparent);
               padding:4px 12px;border-radius:20px;margin-bottom:20px}
    .scp-price{margin-bottom:8px}
    .scp-price-amount{font-size:48px;font-weight:900;color:#1a2744;line-height:1}
    .scp-price-cur{font-size:24px;font-weight:700;vertical-align:super;margin-right:2px;color:#1a2744}
    .scp-price-period{font-size:14px;color:#9ca3af;margin-left:4px}
    .scp-desc{font-size:14px;color:#6b7280;margin:0 0 28px;line-height:1.6}
    .scp-btn{display:block;text-align:center;background:var(--sc-color,#10b981);
             color:#fff;font-size:15px;font-weight:700;padding:14px 24px;
             border-radius:12px;text-decoration:none;margin-bottom:28px;
             transition:opacity .2s;cursor:pointer}
    .scp-btn:hover{opacity:.88;text-decoration:none;color:#fff}
    .scp-divider{border:none;border-top:1px solid #f1f5f9;margin:0 0 20px}
    .scp-features{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:10px}
    .scp-feature{display:flex;align-items:flex-start;gap:10px;font-size:14px;color:#374151;line-height:1.45}
    .scp-check{color:var(--sc-color,#10b981);font-size:16px;flex-shrink:0;margin-top:1px}
    @media(max-width:1024px){.scp-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:600px){.scp-grid{grid-template-columns:1fr}.scp-title{font-size:26px}}
    </style>

    <div class="scp-wrap">
      <div class="scp-header">
        <h2 class="scp-title">Choisissez votre offre</h2>
        <p class="scp-sub">Boostez la visibilité de votre entreprise. Sans engagement, résiliable à tout moment.</p>
      </div>
      <div class="scp-grid">
      <?php foreach ($packs as $i => $pack):
          $color    = esc_attr($pack['color']);
          $name     = esc_html($pack['name']);
          $price    = intval($pack['price_ht']);
          $desc     = esc_html($pack['description']);
          $feats    = $pack['features'] ?? [];
          $slug     = $pack['slug'] ?? '';
          $buy_url  = esc_url($pack['buy_url'] ?? '#');
          $featured = ($slug === 'pack-premium');
          $has_star_offer = in_array($slug, ['pack-visibilite', 'pack-premium']);
      ?>
        <div class="scp-card<?= $featured ? ' featured' : '' ?>" style="--sc-color:<?= $color ?>">
          <span class="scp-badge"><?= $name ?></span>
          <div class="scp-price">
            <span class="scp-price-cur">€</span><span class="scp-price-amount"><?= $price ?></span>
            <span class="scp-price-period">HT / mois</span>
          </div>
          <p class="scp-desc"><?= $desc ?></p>
          <?php if ($has_star_offer): ?>
          <div class="scp-star-offer">⭐ <span><strong>Option gratuite valable 30 jours :</strong> Badge Note 5⭐<br>Si vous souhaitez ce badge en permanence, souscrivez au Pack Master.</span></div>
          <?php endif; ?>
          <a class="scp-btn" href="<?= $buy_url ?>">Commencer →</a>
          <hr class="scp-divider">
          <ul class="scp-features">
            <?php foreach ($feats as $f): ?>
              <li class="scp-feature"><span class="scp-check">✓</span><?= esc_html($f) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php return ob_get_clean();
});

// =============================================================================
// HELPERS MOTEUR DE RECHERCHE
// =============================================================================

function sc_category_icon(string $cat): string {
    $c = mb_strtolower($cat);
    $map = [
        // Français
        'restaurant' => '🍕','restaur' => '🍕','traiteur' => '🍽️','alimentation' => '🛒',
        'boulang' => '🥖','pâtisserie' => '🍰','boucherie' => '🥩','épicerie' => '🛒',
        'santé' => '🏥','médecin' => '🏥','médical' => '🏥','pharmacie' => '💊',
        'dentiste' => '🦷','vétérin' => '🐾','optique' => '👓',
        'artisan' => '🔧','plombier' => '🔧','électric' => '⚡','menuisier' => '🪚',
        'maçon' => '🧱','peintre' => '🎨','couvreur' => '🏠','chauffage' => '🔥',
        'immobilier' => '🏠','agence immob' => '🏠','promoteur' => '🏗️',
        'construction' => '🏗️','btp' => '🏗️','architecte' => '📐',
        'transport' => '🚗','déménag' => '🚛','taxi' => '🚕','logistique' => '📦',
        'commerce' => '🛒','magasin' => '🛍️','boutique' => '🛍️','grande surface' => '🏪',
        'technologie' => '💻','informatique' => '💻','web' => '🌐','numérique' => '💻',
        'télécommunication' => '📱','téléphonie' => '📱',
        'éducation' => '🎓','école' => '🎓','formation' => '📚','université' => '🎓',
        'enseignement' => '🎓','cours' => '📚',
        'finance' => '💰','banque' => '🏦','assurance' => '🛡️','comptable' => '📊',
        'conseil' => '💼','consulting' => '💼','avocat' => '⚖️','notaire' => '📜',
        'beauté' => '💄','coiffure' => '✂️','esthétique' => '💅','spa' => '🧖',
        'sport' => '⚽','fitness' => '💪','gym' => '💪','salle de sport' => '🏋️',
        'hôtel' => '🏨','hébergement' => '🏨','camping' => '⛺','tourisme' => '✈️',
        'agriculture' => '🌾','jardinerie' => '🌿','paysag' => '🌳',
        'industrie' => '⚙️','manufacture' => '🏭','usine' => '🏭',
        'auto' => '🚗','garage' => '🔧','carrosserie' => '🚘',
        'imprimerie' => '🖨️','publicité' => '📢','communication' => '📣',
        'nettoyage' => '🧹','entretien' => '🧽','gardiennage' => '🔒',
        'social' => '🤝','association' => '🤝','humanitaire' => '❤️',
        // Anglais (catégories Google / DuckDB)
        'food' => '🍕','bakery' => '🥖','coffee' => '☕','bar ' => '🍺',
        'health' => '🏥','doctor' => '🏥','hospital' => '🏥','clinic' => '🏥','pharmacy' => '💊',
        'dental' => '🦷','optician' => '👓','veterinar' => '🐾',
        'plumb' => '🔧','electric' => '⚡','carpenter' => '🪚','painting' => '🎨',
        'roofing' => '🏠','heating' => '🔥','locksmith' => '🔒',
        'real estate' => '🏠','property' => '🏠',
        'construction' => '🏗️','architect' => '📐','contractor' => '🏗️',
        'truck' => '🚛','transport' => '🚗','moving' => '🚛','taxi' => '🚕',
        'store' => '🛍️','shop' => '🛍️','market' => '🛒','supermarket' => '🏪',
        'technology' => '💻','software' => '💻','computer' => '💻','internet' => '🌐',
        'telecom' => '📱','phone' => '📱',
        'school' => '🎓','training' => '📚','university' => '🎓','education' => '🎓',
        'insurance' => '🛡️','bank' => '🏦','accounting' => '📊','finance' => '💰',
        'law' => '⚖️','lawyer' => '⚖️','legal' => '⚖️','notary' => '📜',
        'beauty' => '💄','hair' => '✂️','spa' => '🧖','nail' => '💅',
        'gym' => '💪','sport' => '⚽','fitness' => '💪',
        'hotel' => '🏨','motel' => '🏨','travel' => '✈️','tourism' => '✈️',
        'garden' => '🌳','landscap' => '🌳','farm' => '🌾','agriculture' => '🌾',
        'industr' => '⚙️','manufactur' => '🏭','factory' => '🏭',
        'auto' => '🚗','car ' => '🚗','vehicle' => '🚗','garage' => '🔧',
        'print' => '🖨️','advertising' => '📢','marketing' => '📣',
        'cleaning' => '🧹','laundry' => '🧺','security' => '🔒',
        'charity' => '❤️','nonprofit' => '🤝','church' => '⛪',
        'government' => '🏛️','public' => '🏛️','service' => '💼',
        'nursing' => '🏥','medical' => '🏥','care' => '🏥',
    ];
    foreach ($map as $key => $icon) {
        if (mb_strpos($c, $key) !== false) return $icon;
    }
    return '🏢';
}

function sc_format_count(int $n): string {
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M+';
    if ($n >= 1000)    return round($n / 1000) . 'k+';
    return (string)$n;
}

function sc_get_categories_clean(): array {
    $parsed   = parse_url(rtrim(get_option('societies_api_url', ''), '/'));
    $base_url = ($parsed['scheme'] ?? 'http') . '://'
              . ($parsed['host'] ?? '')
              . (isset($parsed['port']) ? ':' . $parsed['port'] : '')
              . rtrim($parsed['path'] ?? '', '/');
    $resp = wp_remote_get($base_url . '/api/categories', ['timeout' => 6, 'sslverify' => false]);
    if (is_wp_error($resp)) return [];
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    return is_array($data) ? $data : [];
}

// =============================================================================
// SHORTCODE MOTEUR DE RECHERCHE [societies_search]
// =============================================================================

add_shortcode('societies_search', function($atts) {
    $atts     = shortcode_atts(['per_page' => 10, 'cats_page' => '/toutes-les-categories/'], $atts);
    $uid      = 'sc-search-' . wp_rand(1000, 9999);
    $ajax_url = admin_url('admin-ajax.php');
    $pp       = intval($atts['per_page']);

    // Nombre de fiches générées (pas le total BDD)
    $fiches_stats = sc_api('/api/fiches/stats');
    $total_db = isset($fiches_stats['done']) ? number_format($fiches_stats['done'], 0, ',', ' ') : '–';

    // Top secteurs pour le dropdown
    $all_cats = sc_get_categories_clean();
    $top_cats = array_slice($all_cats, 0, 80);

    // Régions françaises
    $regions = ['Auvergne-Rhône-Alpes','Bourgogne-Franche-Comté','Bretagne','Centre-Val de Loire','Corse','Grand Est','Hauts-de-France','Île-de-France','Normandie','Nouvelle-Aquitaine','Occitanie','Pays de la Loire','Provence-Alpes-Côte d\'Azur','Guadeloupe','Martinique','Guyane','La Réunion','Mayotte'];

    ob_start();

    // CSS redesign moteur de recherche
    $sc_css =
    '.apus-page-loading,.apus-header,#apus-header,.header-mobile,#apus-header-mobile,.header-main,.apus-top-bar,.top-bar-wrap,nav.navbar,.page-heading,.page-header-wrap,.apus-breadcrumbs,ol.breadcrumb,.entry-header,.page-header,#page-header,.col-md-4.pull-right,.col-md-4.col-sm-12.col-xs-12.pull-right,aside.sidebar,aside.sidebar-right,.sidebar.sidebar-right,#secondary,#sidebar,.widget-area,.sidebar-area,.sidebar-right,[class*="sidebar"]:not([class*="sc2"]):not([class*="sc-"]),#apus-footer,footer.apus-footer,.show-sidebar-button,.btn-show-sidebar,.btn-toggle-sidebar,.sidebar-toggle,.toggle-sidebar,[data-toggle="sidebar"],.over-dark,.off-canvas-wrap,.js-off-canvas-overlay{display:none!important}'
    .'body{background:#f1f4f9!important;overflow-x:hidden}'
    .'#wrapper-container,#main-content,#main-content.col-md-8,.main-page,.row,.container.inner,.site-main,.entry-content,.hentry,.elementor-section,.elementor-container,.elementor-column,.elementor-column-wrap,.elementor-widget-container{max-width:100%!important;width:100%!important;margin:0!important;padding:0!important;float:none!important;box-shadow:none!important;border:none!important;background:transparent!important}'
    .'.sc-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;width:100vw;position:relative;left:50%;margin-left:-50vw;box-sizing:border-box;overflow-x:hidden}'
    .'.sc-hero{padding:56px 24px 44px;text-align:center;box-sizing:border-box;border-bottom:1px solid #e5e9f0}'
    .'.sc-badge{display:inline-flex;align-items:center;gap:8px;border:1.5px solid transparent;background:linear-gradient(#fff,#fff) padding-box,linear-gradient(135deg,#6366f1,#3b82f6) border-box;border-radius:50px;padding:7px 20px;font-size:12px;font-weight:700;color:#3b4fcf;letter-spacing:.3px;margin-bottom:22px}'
    .'.sc-badge-star{color:#f59e0b;font-style:normal}'
    .'.sc-hero-title{font-size:42px;font-weight:900;color:#111827;margin:0 0 12px;letter-spacing:-1.5px;line-height:1.1}'
    .'.sc-hero-title em{background:linear-gradient(135deg,#3b82f6,#6366f1);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-style:normal}'
    .'.sc-hero-sub{font-size:15px;color:#6b7280;margin:0 0 32px;line-height:1.6;max-width:580px;display:block;margin-left:auto;margin-right:auto}'
    .'.sc-search-row{max-width:800px;margin:0 auto;display:flex;align-items:stretch;border:2px solid #dde3ee;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden}'
    .'.sc-search-icon{display:flex;align-items:center;padding:0 14px;color:#9ca3af;font-size:17px;flex-shrink:0}'
    .'.sc-search-input{flex:1;border:none;outline:none;font-size:15px;color:#111827;background:transparent;padding:17px 4px;min-width:0}'
    .'.sc-search-input::placeholder{color:#b0bac9}'
    .'.sc-search-btn{background:#1e3a8a;color:#fff;border:none;padding:0 30px;font-size:15px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:background .2s}'
    .'.sc-search-btn:hover{background:#1e40af}'
    .'.sc-reset-btn{background:none;border:none;border-left:1px solid #e5e9f0;padding:0 16px;font-size:18px;color:#9ca3af;cursor:pointer;flex-shrink:0;line-height:1;transition:color .15s}'
    .'.sc-reset-btn:hover{color:#ef4444}'
    .'.sc-filters{max-width:1100px;margin:24px auto 0;padding:0 24px;display:flex;flex-wrap:wrap;align-items:center;gap:10px}'
    .'.sc-filter-select{border:1.5px solid #d1d5db;border-radius:8px;padding:9px 14px;font-size:13px;color:#374151;cursor:pointer;outline:none;transition:border-color .15s}'
    .'.sc-filter-select:hover,.sc-filter-select:focus{border-color:#6366f1}'
    .'.sc-pills{display:flex;gap:8px;flex-wrap:wrap}'
    .'.sc-pill{border:1.5px solid #d1d5db;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;transition:all .15s;line-height:1}'
    .'.sc-pill:hover{border-color:#6366f1;color:#6366f1;background:#f5f3ff}'
    .'.sc-pill.active{background:#1e3a8a;border-color:#1e3a8a;color:#fff}'
    .'.sc-results-section{max-width:1100px;margin:20px auto 0;padding:0 24px 64px}'
    .'.sc-results-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px}'
    .'.sc-results-count{font-size:14px;color:#374151}'
    .'.sc-results-count strong{font-weight:700;color:#111827}'
    .'.sc-list{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}'
    .'.sc-card{display:flex;flex-direction:column;border:1.5px solid #e5e9f0;border-radius:14px;padding:20px;text-decoration:none;color:inherit;background:#fff;transition:box-shadow .18s,border-color .18s,transform .18s}'
    .'.sc-card:hover{box-shadow:0 6px 24px rgba(0,0,0,.1);border-color:#c7d2fe;transform:translateY(-3px);text-decoration:none}'
    .'.sc-card-head{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px}'
    .'.sc-card-badge{width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#1e3a8a,#3b82f6);color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;letter-spacing:.5px}'
    .'.sc-card-info{flex:1;min-width:0}'
    .'.sc-card-name{font-size:14px;font-weight:700;color:#111827;margin-bottom:4px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}'
    .'.sc-card-cat{font-size:12px;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    .'.sc-card-foot{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;font-size:12px;margin-bottom:16px}'
    .'.sc-card-city{color:#6b7280}'
    .'.sc-card-rat{font-weight:700;color:#f59e0b}'
    .'.sc-card-cta{font-size:13px;font-weight:700;color:#1e3a8a;border:1.5px solid #c7d2fe;border-radius:8px;padding:9px 14px;text-align:center;transition:all .15s;background:#f5f7ff;margin-top:auto}'
    .'.sc-card:hover .sc-card-cta{background:#1e3a8a;color:#fff;border-color:#1e3a8a}'
    .'.sc-loader{display:flex;justify-content:center;padding:60px}'
    .'.sc-spinner{width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#6366f1;border-radius:50%;animation:sc-spin .7s linear infinite}'
    .'@keyframes sc-spin{to{transform:rotate(360deg)}}'
    .'.sc-empty{text-align:center;padding:60px 20px}'
    .'.sc-empty-icon{font-size:48px;margin-bottom:12px}'
    .'.sc-empty-title{font-size:18px;font-weight:600;color:#374151;margin-bottom:6px}'
    .'.sc-pagination{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:28px;flex-wrap:wrap}'
    .'.sc-pg-btn{border:1.5px solid #d1d5db;border-radius:8px;padding:8px 15px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;transition:all .15s;min-width:38px;text-align:center;line-height:1}'
    .'.sc-pg-btn:hover:not(:disabled){border-color:#6366f1;color:#6366f1}'
    .'.sc-pg-btn.active{background:#1e3a8a;border-color:#1e3a8a;color:#fff}'
    .'.sc-pg-btn:disabled{opacity:.38;cursor:default}'
    .'@media(max-width:860px){.sc-list{grid-template-columns:repeat(2,1fr)}}'
    .'@media(max-width:640px){.sc-hero{padding:36px 14px 32px}.sc-hero-title{font-size:26px;letter-spacing:-1px}.sc-search-btn{padding:0 16px;font-size:13px}.sc-filters{padding:14px 12px 0;gap:8px}.sc-results-section{padding:14px 12px 48px}.sc-list{grid-template-columns:1fr}}';
    echo '<style>' . $sc_css . '</style>';
    ?>

    <div class="sc-wrap">

    <!-- HERO -->
    <div class="sc-hero">
      <div class="sc-badge"><em class="sc-badge-star">★</em> <?= esc_html($total_db) ?> FICHES GÉNÉRÉES</div>
      <h1 class="sc-hero-title">Trouvez n'importe quelle <em>entreprise française</em></h1>
      <p class="sc-hero-sub">Secteur d'activité, catégories — recherchez par n'importe quel critère</p>
      <div class="sc-search-row">
        <span class="sc-search-icon">🔍</span>
        <input type="text" id="<?= esc_attr($uid) ?>-q" class="sc-search-input"
               placeholder="Nom, SIREN, ville, secteur..."
               oninput="scSearchDebounce('<?= esc_js($uid) ?>')"
               onkeydown="if(event.key==='Enter')scSearch('<?= esc_js($uid) ?>',1,true)"
               autocomplete="off">
        <button class="sc-search-btn" onclick="scSearch('<?= esc_js($uid) ?>',1,true)">Rechercher</button>
        <button class="sc-reset-btn" onclick="scReset('<?= esc_js($uid) ?>')" title="Réinitialiser">✕</button>
      </div>
    </div>

   
    <!-- RÉSULTATS -->
    <div class="sc-results-section" id="<?= esc_attr($uid) ?>-results-wrap" style="display:none">
      <div class="sc-results-header">
        <div id="<?= esc_attr($uid) ?>-status" class="sc-results-count"></div>
      </div>
      <div id="<?= esc_attr($uid) ?>-results" class="sc-list"></div>
      <div id="<?= esc_attr($uid) ?>-pagination" class="sc-pagination"></div>
    </div>

    </div><!-- .sc-wrap -->

    <?php
    $html = ob_get_clean();

    $ajax_url_js = esc_js($ajax_url);
    $js = <<<JSCODE
(function(){
  var _scTimers={},_scState={};
  function initSt(uid){if(!_scState[uid])_scState[uid]={page:1,form:''};}
  function dept(zip){return zip&&zip.length>=2?'('+zip.substring(0,2)+')':'';}
  if(!window.scSearchDebounce){
    window.scSearchDebounce=function(uid){clearTimeout(_scTimers[uid]);_scTimers[uid]=setTimeout(function(){scSearch(uid,1);},380);};
    window.scReset=function(uid){
      initSt(uid);
      var q=document.getElementById(uid+'-q');if(q)q.value='';
      var sec=document.getElementById(uid+'-sector');if(sec)sec.value='';
      var reg=document.getElementById(uid+'-region');if(reg)reg.value='';
      _scState[uid].form='';
      document.querySelectorAll('[id^="'+uid+'-pill-"]').forEach(function(b){b.classList.remove('active');});
      var all=document.getElementById(uid+'-pill-all');if(all)all.classList.add('active');
      var wrap=document.getElementById(uid+'-results-wrap');if(wrap)wrap.style.display='none';
    };
    window.scSetPill=function(uid,form){
      initSt(uid);_scState[uid].form=form;
      document.querySelectorAll('[id^="'+uid+'-pill-"]').forEach(function(b){b.classList.remove('active');});
      var key=form?uid+'-pill-'+form.toLowerCase().replace(/[^a-z0-9]/g,''):uid+'-pill-all';
      var el=document.getElementById(key);if(el)el.classList.add('active');
      scSearch(uid,1);
    };
    window.scSearch=function(uid,page,force){
      initSt(uid);
      var st=_scState[uid];st.page=page||1;
      var q=(document.getElementById(uid+'-q')||{}).value||'';q=q.trim();
      var sector=(document.getElementById(uid+'-sector')||{}).value||'';
      var region=(document.getElementById(uid+'-region')||{}).value||'';
      var sort='rating';
      var resEl=document.getElementById(uid+'-results');
      var statEl=document.getElementById(uid+'-status');
      var paginEl=document.getElementById(uid+'-pagination');
      var wrapEl=document.getElementById(uid+'-results-wrap');
      var hasInput=force||q.length>=2||sector||region||st.form;
      if(!hasInput){wrapEl.style.display='none';return;}
      wrapEl.style.display='block';
      if(page===1)resEl.innerHTML='<div class="sc-loader"><div class="sc-spinner"></div></div>';
      paginEl.innerHTML='';
      var qFull=q+(st.form?' '+st.form:'');
      var xhr=new XMLHttpRequest();
      xhr.open('POST','{$ajax_url_js}');
      xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
      xhr.onload=function(){
        var d=JSON.parse(xhr.responseText||'{}');
        if(!d.success){resEl.innerHTML='<div class="sc-empty"><div class="sc-empty-icon">⚠️</div><div class="sc-empty-title">'+(d.data&&d.data.error?scEsc(d.data.error):'Erreur connexion')+'</div></div>';return;}
        var items=d.data.results||[],total=d.data.total||0,off=(page-1)*{$pp};
        if(!items.length&&page===1){resEl.innerHTML='<div class="sc-empty"><div class="sc-empty-icon">🔍</div><div class="sc-empty-title">Aucun résultat</div><p style="color:#9ca3af">Essayez un autre terme ou filtre.</p></div>';statEl.innerHTML='';return;}
        statEl.innerHTML='<strong>'+total.toLocaleString('fr-FR')+'</strong> résultat'+(total>1?'s':'');
        resEl.innerHTML=items.map(function(c){
          var d2=dept(c.zip_code||'');
          var rat=c.rating_value&&c.rating_value>0?'<span class="sc-card-rat"><span style="color:#f59e0b">★</span> '+parseFloat(c.rating_value).toFixed(1)+(c.rating_votes?' ('+c.rating_votes+')':'')+'</span>':'';
          var url=scEsc(c.url||'#');
          var words=(c.title||'').trim().split(/\s+/);
          var badge=words.slice(0,2).map(function(w){return w[0]?w[0].toUpperCase():'';}).join('');
          return '<a href="'+url+'" class="sc-card">'
            +'<div class="sc-card-head">'
            +'<div class="sc-card-badge">'+badge+'</div>'
            +'<div class="sc-card-info">'
            +'<div class="sc-card-name">'+scEsc(c.title)+'</div>'
            +(c.category?'<div class="sc-card-cat">'+scEsc(c.category)+'</div>':'')
            +'</div></div>'
            +'<div class="sc-card-foot">'
            +(c.city?'<span class="sc-card-city">📍 '+scEsc(c.city)+(d2?' '+d2:'')+'</span>':'')
            +rat
            +'</div>'
            +'<div class="sc-card-cta">Voir la fiche →</div>'
            +'</a>';
        }).join('');
        var tp=Math.ceil(total/{$pp});
        if(tp>1){
          var s=Math.max(1,page-2),e=Math.min(tp,s+4);if(e-s<4)s=Math.max(1,e-4);
          var b='<button class="sc-pg-btn"'+(page<=1?' disabled':'')+' onclick="scSearch(\''+uid+'\','+(page-1)+')">← Précédent</button>';
          for(var p2=s;p2<=e;p2++)b+='<button class="sc-pg-btn'+(p2===page?' active':'')+'" onclick="scSearch(\''+uid+'\','+p2+')">'+p2+'</button>';
          b+='<button class="sc-pg-btn"'+(page>=tp?' disabled':'')+' onclick="scSearch(\''+uid+'\','+(page+1)+')">Suivant →</button>';
          paginEl.innerHTML=b;
        }
      };
      xhr.send('action=sc_search&q='+encodeURIComponent(qFull)+'&city='+encodeURIComponent(region)+'&sector='+encodeURIComponent(sector)+'&page='+page+'&per_page={$pp}');
    };
    window.scEsc=function(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');};
  }
})();
JSCODE;
    static $sc_search_js_done = false;
    if (!$sc_search_js_done) {
        $sc_search_js_done = true;
        add_action('wp_footer', function() use ($js) {
            echo '<script id="sc-search-engine">' . $js . '</script>';
        }, 5);
    }
    return $html;
});

// =============================================================================
// SHORTCODE TOUTES LES CATÉGORIES [societies_categories]
// =============================================================================

add_shortcode('societies_categories', function($atts) {
    $atts      = shortcode_atts(['per_page' => 12, 'search_page' => '/recherche-entreprises/'], $atts);
    $per_page  = intval($atts['per_page']);
    $ajax_url  = admin_url('admin-ajax.php');
    $all_cats  = sc_get_categories_clean();
    $initial   = array_slice($all_cats, 0, $per_page);
    $has_more  = count($all_cats) > $per_page;
    $uid       = 'sc-cats-' . wp_rand(1000,9999);

    ob_start(); ?>
    <style>
    .sc-allcats-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
                     max-width:1200px;margin:0 auto;padding:40px 32px 60px;box-sizing:border-box}
    .sc-allcats-title{font-size:32px;font-weight:900;color:#1a2744;margin:0 0 6px}
    .sc-allcats-sub{font-size:15px;color:#94a3b8;margin:0 0 32px}
    .sc-allcats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
    .sc-allcats-more{text-align:center;margin-top:32px}
    .sc-allcats-btn{background:#1a2744;color:#fff;border:none;border-radius:12px;
                    padding:14px 40px;font-size:15px;font-weight:700;cursor:pointer;
                    transition:background .2s}
    .sc-allcats-btn:hover{background:#e63946}
    .sc-allcats-btn:disabled{opacity:.5;cursor:not-allowed}
    @media(max-width:860px){.sc-allcats-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:480px){.sc-allcats-grid{grid-template-columns:repeat(2,1fr)}.sc-allcats-wrap{padding:24px 16px 40px}}
    </style>
    <div class="sc-allcats-wrap">
      <h1 class="sc-allcats-title">Toutes les catégories</h1>
      <p class="sc-allcats-sub">Explorez les entreprises par secteur d'activité.</p>
      <div class="sc-allcats-grid" id="<?= esc_attr($uid) ?>-grid">
        <?php foreach ($initial as $cat):
            $icon  = sc_category_icon($cat['category']);
            $label = sc_format_count(intval($cat['count']));
        ?>
        <a href="<?= esc_url($atts['search_page']) ?>?sector=<?= urlencode($cat['category']) ?>"
           class="sc-cat-card">
          <span class="sc-cat-icon"><?= $icon ?></span>
          <div class="sc-cat-name"><?= esc_html($cat['category']) ?></div>
          <div class="sc-cat-count"><?= esc_html($label) ?> entreprises</div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php if ($has_more): ?>
      <div class="sc-allcats-more">
        <button class="sc-allcats-btn" id="<?= esc_attr($uid) ?>-btn"
                onclick="scLoadMoreCats('<?= esc_js($uid) ?>')">
          Charger plus de catégories
        </button>
      </div>
      <?php endif; ?>
    </div>
    <?php
    $html = ob_get_clean();

    // Toutes les catégories encodées en JSON pour le JS
    $all_json = wp_json_encode(array_map(function($c) {
        return ['category' => $c['category'], 'count' => $c['count']];
    }, $all_cats));
    $search_page = esc_js($atts['search_page']);
    $pp = $per_page;

    // JS injecté via wp_footer pour contourner les filtres Elementor et le cache WP
    $js = "
(function(){
  var scAllCats={$all_json};
  var scCatsOffset={$pp};
  var scCatsPerLoad=12;
  window.scLoadMoreCats=function(uid){
    var grid=document.getElementById(uid+'-grid');
    var btn=document.getElementById(uid+'-btn');
    var batch=scAllCats.slice(scCatsOffset,scCatsOffset+scCatsPerLoad);
    if(!batch.length){if(btn)btn.style.display='none';return;}
    var icons={};
    batch.forEach(function(c){
      var count=c.count>=1000000?Math.round(c.count/1000000*10)/10+'M+':c.count>=1000?Math.round(c.count/1000)+'k+':c.count;
      grid.insertAdjacentHTML('beforeend',
        '<a href=\"{$search_page}?sector='+encodeURIComponent(c.category)+'\" class=\"sc-cat-card\">'+
        '<span class=\"sc-cat-icon\">\ud83c\udfe2</span>'+
        '<div class=\"sc-cat-name\">'+c.category+'</div>'+
        '<div class=\"sc-cat-count\">'+count+' entreprises</div>'+
        '</a>'
      );
    });
    scCatsOffset+=scCatsPerLoad;
    if(scCatsOffset>=scAllCats.length&&btn) btn.style.display='none';
  };
})();";
    static $sc_cats_js_done = false;
    if (!$sc_cats_js_done) {
        $sc_cats_js_done = true;
        add_action('wp_footer', function() use ($js) {
            echo '<script id="sc-cats-engine">' . $js . '</script>';
        }, 5);
    }
    return $html;
});

// AJAX handler — public (nopriv)
add_action('wp_ajax_sc_search',        'sc_search_ajax_handler');
add_action('wp_ajax_nopriv_sc_search', 'sc_search_ajax_handler');
function sc_search_ajax_handler() {
    $q        = sanitize_text_field($_POST['q'] ?? '');
    $city     = sanitize_text_field($_POST['city'] ?? '');
    $sector   = sanitize_text_field($_POST['sector'] ?? '');
    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = min(50, max(6, intval($_POST['per_page'] ?? 24)));

    if (strlen($q) < 2 && strlen($city) < 2 && strlen($sector) < 2) {
        wp_send_json_error(['message' => 'Query trop courte']);
    }

    $qs  = 'q=' . rawurlencode($q) . '&page=' . $page . '&per_page=' . $per_page;
    if ($city)   $qs .= '&city='     . rawurlencode($city);
    if ($sector) $qs .= '&category=' . rawurlencode($sector); // l'API attend "category", pas "sector"
    $data = sc_api('/api/search?' . $qs);
    if (isset($data['error'])) {
        error_log('[SC Search] Erreur API: ' . $data['error'] . ' | qs=' . $qs);
        wp_send_json_error($data);
    }
    if (empty($data) || (!isset($data['results']) && !isset($data['total']))) {
        error_log('[SC Search] Réponse vide ou inattendue | qs=' . $qs . ' | data=' . json_encode($data));
        wp_send_json_error(['error' => 'Réponse inattendue du backend', 'raw' => $data]);
    }

    $companies = $data['results'] ?? [];

    // Résolution des permalinks : meta _sc_company_title, sinon slug dérivé du titre
    $titles = array_column($companies, 'title');
    $url_map = [];
    if (!empty($titles)) {
        foreach ($titles as $t) {
            // 1) via meta
            $pages = get_posts([
                'post_type'   => 'page',
                'post_status' => 'publish',
                'meta_key'    => '_sc_company_title',
                'meta_value'  => $t,
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);
            if ($pages) { $url_map[$t] = get_permalink($pages[0]); continue; }
            // 2) via slug dérivé du titre
            $slug = sanitize_title($t);
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page) { $url_map[$t] = get_permalink($page->ID); continue; }
            // 3) via titre exact de la page
            $by_title = get_posts([
                'post_type'   => 'page',
                'post_status' => 'publish',
                'title'       => $t,
                'numberposts' => 1,
                'fields'      => 'ids',
            ]);
            if ($by_title) $url_map[$t] = get_permalink($by_title[0]);
        }
    }

    foreach ($companies as &$c) {
        if (!empty($url_map[$c['title']])) {
            $c['url'] = $url_map[$c['title']];
        } else {
            // Fallback : URL construite depuis city + category + slug du titre
            $parts = [];
            if (!empty($c['city']))     $parts[] = sanitize_title($c['city']);
            if (!empty($c['category'])) $parts[] = sanitize_title($c['category']);
            $parts[] = sanitize_title($c['title']);
            $c['url'] = home_url('/' . implode('/', $parts) . '/');
        }
    }

    wp_send_json_success(['results' => $companies, 'total' => $data['total'] ?? count($companies)]);
}

// =============================================================================
// SHORTCODE FICHE COMPLÈTE [societies_fiche title="Nom Entreprise"]
// =============================================================================

add_shortcode('societies_fiche', function($atts) {
    $atts  = shortcode_atts(['title' => ''], $atts);
    $title = trim($atts['title']);
    if (!$title) return '<p style="color:#ef4444">⚠️ Paramètre <code>title</code> manquant.</p>';

    $company  = sc_api('/api/company/' . rawurlencode($title));
    $fiche    = sc_api('/api/fiche/'   . rawurlencode($title));
    $logo_url = rtrim(get_option('societies_api_url', ''), '/') . '/static/logo.jpg';

    if (isset($company['error']) || empty($company['title'])) {
        return '<p style="color:#ef4444">Entreprise introuvable.</p>';
    }

    sc_enqueue_styles();

    $rating      = $company['rating_value'] ?? 0;
    $votes       = $company['rating_votes'] ?? 0;
    $intro       = $fiche['intro_text'] ?? '';
    $gen_date    = $fiche['generated_at'] ?? '';
    $qa_answered = $fiche['qa_answered'] ?? [];
    $qa_open     = $fiche['qa_open'] ?? [];
    // Fallback : utilise les questions templates si qa_open n'est pas encore stocké
    if (empty($qa_open) && !empty($fiche['open_questions'])) {
        $qa_open = array_map(fn($q) => ['q' => $q, 'r' => ''], $fiche['open_questions']);
    }
    $bonus_text  = $fiche['bonus_text'] ?? '';
    $status      = $fiche['status'] ?? 'none';

    // Supprime les phrases contenant des notes/étoiles (ne doit apparaître qu'après abonnement)
    if ($intro) {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $intro);
        $filtered  = array_filter($sentences, function($s) {
            return !preg_match('/étoile|\/5|\bavis\b|note.*sur|sur.*note|basée sur|moyenne de|satisfaction.*remarquable/iu', $s);
        });
        $intro = implode(' ', $filtered);
    }

    $claim_url = home_url('/revendiquer/');

    // Données complémentaires issues de l'API (optionnelles — présentes selon le dataset)
    $siren       = $company['siren'] ?? $company['siren_number'] ?? '';
    $siret       = $company['siret'] ?? '';
    $forme_jur   = $company['forme_juridique'] ?? $company['legal_form'] ?? '';
    $code_naf    = $company['code_naf'] ?? $company['naf_code'] ?? '';
    $libelle_naf = $company['libelle_naf'] ?? $company['naf_label'] ?? '';
    $capital     = $company['capital'] ?? 0;
    $date_creation = $company['date_creation'] ?? '';
    $ca_display  = $company['ca_display'] ?? '';
    $effectif_txt = $company['effectif_display'] ?? (!empty($company['employees']) ? $company['employees'] . ' salariés' : '');
    $score_fiab  = intval($company['score_fiabilite'] ?? 0);
    $dir_raw     = $company['dirigeants'] ?? [];
    $dirigeants  = is_array($dir_raw) ? $dir_raw : (json_decode((string)$dir_raw, true) ?: []);
    $ch_raw      = $company['ca_history'] ?? [];
    $ca_history  = is_array($ch_raw) ? $ch_raw : (json_decode((string)$ch_raw, true) ?: []);

    // Vérifie si le propriétaire de cette fiche a un abonnement actif
    $owner_users   = get_users(['meta_key' => 'sc_company_title', 'meta_value' => $company['title'], 'number' => 1]);
    $owner_sub     = !empty($owner_users) && sc_user_has_subscription($owner_users[0]->ID);

    // Badge initiales + score affiché même sans donnée
    $sc2_init = '';
    foreach (preg_split('/\s+/', trim($company['title'])) as $_w) {
        if ($_w) $sc2_init .= mb_strtoupper(mb_substr($_w, 0, 1));
        if (mb_strlen($sc2_init) >= 2) break;
    }
    if (!$sc2_init) $sc2_init = mb_strtoupper(mb_substr($company['title'], 0, 2));
    $score_display = $score_fiab ?: 64;
    $score_deg     = round($score_display * 3.6);
    $tarifs_url    = home_url('/tarifs/');

    ob_start(); ?>
    <div class="sc2-wrap">

      <!-- HERO -->
      <div class="sc2-hero">
        <div class="sc2-hero-left">
          <div class="sc2-hero-badge"><?= esc_html($sc2_init) ?></div>
          <div class="sc2-hero-info">
            <div class="sc2-hero-badges">
              <span class="sc2-badge sc2-badge--active">✓ En activité</span>
              <?php if ($forme_jur): ?><span class="sc2-badge sc2-badge--forme"><?= esc_html($forme_jur) ?></span><?php endif; ?>
              <?php if ($owner_sub): ?><span class="sc2-badge sc2-badge--premium">⭐ PREMIUM</span><?php endif; ?>
            </div>
            <h1 class="sc2-hero-name"><?= esc_html($company['title']) ?></h1>
            <div class="sc2-hero-meta">
              <?php if (!empty($company['category'])): ?><span>🏭 <?= esc_html($company['category']) ?></span><?php endif; ?>
              <?php if (!empty($company['city'])): ?><span>📍 <?= esc_html($company['city']) ?><?= !empty($company['zip_code']) ? ' ' . esc_html($company['zip_code']) : '' ?></span><?php endif; ?>
              <?php if ($date_creation): ?><span>📅 Depuis <?= esc_html(substr($date_creation, 0, 4)) ?></span><?php endif; ?>
              <?php if ($siren): ?><span>🔢 SIREN <?= esc_html($siren) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="sc2-hero-actions">
          <?php if (!empty($company['website'])): ?>
          <a href="<?= esc_url($company['website']) ?>" target="_blank" rel="noopener" class="sc2-btn-outline">🌐 Visiter le site</a>
          <?php endif; ?>
          <a href="<?= esc_url($tarifs_url) ?>" class="sc2-btn-primary-sm">Accès complet →</a>
        </div>
      </div>

      <!-- GRILLE DEUX COLONNES -->
      <div class="sc2-grid">

        <!-- COLONNE PRINCIPALE -->
        <div class="sc2-main">

          <?php if ($intro && $status === 'done'): ?>
          <div class="sc2-card">
            <h3 class="sc2-card-title">Présentation</h3>
            <p class="sc2-intro-text"><?= nl2br(esc_html($intro)) ?></p>
          </div>
          <?php endif; ?>

          <!-- KPI — toujours affiché (– si pas de donnée) -->
          <div class="sc2-card">
            <h3 class="sc2-card-title">Indicateurs clés</h3>
            <div class="sc2-kpi-grid">
              <div class="sc2-kpi-item">
                <div class="sc2-kpi-label">Chiffre d'affaires</div>
                <div class="sc2-kpi-value<?= $ca_display ? '' : ' sc2-kpi-na' ?>"><?= esc_html($ca_display ?: '–') ?></div>
              </div>
              <div class="sc2-kpi-item">
                <div class="sc2-kpi-label">Effectif</div>
                <div class="sc2-kpi-value<?= $effectif_txt ? '' : ' sc2-kpi-na' ?>"><?= esc_html($effectif_txt ?: '–') ?></div>
              </div>
            </div>
          </div>

          <?php if (!empty($ca_history)): ?>
          <div class="sc2-card">
            <h3 class="sc2-card-title">Évolution du chiffre d'affaires</h3>
            <div class="sc2-chart-wrap">
              <?php foreach ($ca_history as $bar): ?>
              <div class="sc2-chart-row">
                <div class="sc2-chart-year"><?= esc_html($bar['year'] ?? '') ?></div>
                <div class="sc2-chart-track"><div class="sc2-chart-fill" style="width:<?= esc_attr($bar['pct'] ?? 100) ?>%"></div></div>
                <div class="sc2-chart-val"><?= esc_html($bar['val'] ?? '') ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php
          $full_addr = '';
          if (!empty($company['address'])) {
              $full_addr = $company['address'];
              if (!empty($company['zip_code'])) $full_addr .= ', ' . $company['zip_code'];
              if (!empty($company['city']))     $full_addr .= ' ' . $company['city'];
          }
          $legal_rows = [
              ['SIREN',           $siren],
              ['SIRET (siège)',   $siret],
              ['Forme juridique', $forme_jur],
              ['Code NAF / APE',  $code_naf . ($libelle_naf ? ' — ' . $libelle_naf : '')],
              ['Capital social',  $capital ? number_format((float)$capital, 0, ',', ' ') . ' €' : ''],
              ['Date de création',$date_creation],
              ['Adresse',         $full_addr],
          ];
          $legal_has = array_filter($legal_rows, fn($r) => !empty($r[1]));
          if ($legal_has): ?>
          <div class="sc2-card">
            <h3 class="sc2-card-title">Informations légales</h3>
            <div class="sc2-legal-table">
              <?php foreach ($legal_rows as [$label, $value]):
                  if (!$value) continue; ?>
              <div class="sc2-legal-row">
                <span class="sc2-legal-label"><?= esc_html($label) ?></span>
                <span class="sc2-legal-value"><?= esc_html($value) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($dirigeants)): ?>
          <div class="sc2-card">
            <h3 class="sc2-card-title">Dirigeants</h3>
            <div class="sc2-dir-list">
              <?php foreach ($dirigeants as $d):
                  $d_name  = $d['nom'] ?? $d['name'] ?? '';
                  $d_role  = $d['role'] ?? $d['titre'] ?? '';
                  $d_since = $d['depuis'] ?? $d['since'] ?? '';
                  if (!$d_name) continue;
                  $dparts = preg_split('/\s+/', trim($d_name));
                  $dinit  = mb_strtoupper(mb_substr(implode('', array_map(fn($p) => mb_substr($p, 0, 1), $dparts)), 0, 2));
              ?>
              <div class="sc2-dir-row">
                <div class="sc2-dir-avatar" aria-hidden="true"><?= esc_html($dinit) ?></div>
                <div>
                  <div class="sc2-dir-name"><?= esc_html($d_name) ?></div>
                  <?php if ($d_role): ?><div class="sc2-dir-role"><?= esc_html($d_role) ?></div><?php endif; ?>
                  <?php if ($d_since): ?><div class="sc2-dir-since">Depuis <?= esc_html($d_since) ?></div><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($qa_answered)): ?>
          <div class="sc2-card">
            <h3 class="sc2-card-title">Analyse actuelle de l'entreprise</h3>
            <div class="sc2-qa-grid">
              <?php foreach ($qa_answered as $item): ?>
              <div class="sc2-qa-card">
                <div class="sc2-qa-q"><?= esc_html($item['question'] ?? $item['q'] ?? '') ?></div>
                <div class="sc2-qa-a"><?= nl2br(esc_html($item['answer'] ?? $item['r'] ?? $item['a'] ?? '')) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if (!empty($qa_open)): ?>
          <div class="sc2-card">
            <h3 class="sc2-card-title">Questions fréquentes</h3>
            <div class="sc2-faq-wrap"><div class="sc2-faq-list">
              <?php foreach ($qa_open as $item):
                  $q = $item['question'] ?? $item['q'] ?? '';
                  $a = $item['answer']   ?? $item['r'] ?? '';
                  if (!$q) continue; ?>
              <div class="sc2-faq-item<?= $a ? '' : ' sc2-faq-item--locked' ?>">
                <button type="button" class="sc2-faq-toggle" aria-expanded="false">
                  <span class="sc2-faq-icon">Q</span>
                  <span class="sc2-faq-q-text"><?= esc_html($q) ?></span>
                  <span class="sc2-faq-chevron">＋</span>
                </button>
                <?php if ($a): ?>
                <div class="sc2-faq-body" hidden>
                  <div class="sc2-faq-a"><span class="sc2-faq-icon sc2-faq-icon-r">R</span><?= nl2br(esc_html($a)) ?></div>
                </div>
                <?php else: ?>
                <div class="sc2-faq-body" hidden>
                  <div class="sc2-faq-locked">🔐 Réponse disponible avec un abonnement</div>
                </div>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div></div>
            <script>
            document.querySelectorAll('.sc2-faq-toggle').forEach(function(btn){
              btn.addEventListener('click',function(){
                var item=btn.closest('.sc2-faq-item'),body=item.querySelector('.sc2-faq-body'),chev=btn.querySelector('.sc2-faq-chevron'),open=btn.getAttribute('aria-expanded')==='true';
                btn.setAttribute('aria-expanded',open?'false':'true');
                body.hidden=open;chev.textContent=open?'＋':'－';
                item.classList.toggle('sc2-faq-item--open',!open);
              });
            });
            </script>
          </div>
          <?php endif; ?>

          <?php if ($bonus_text && $status === 'done'): ?>
          <div class="sc2-bonus-card">
            <p class="sc2-bonus-text"><?= nl2br(esc_html($bonus_text)) ?></p>
          </div>
          <?php endif; ?>

        </div><!-- .sc2-main -->

        <!-- COLONNE LATÉRALE -->
        <aside class="sc2-aside">

          <!-- Note / Rating -->
          <div class="sc2-aside-card">
            <?php if ($owner_sub): ?>
            <div class="sc2-rating-box">
              <div class="sc2-rating-score">5.0</div>
              <div class="sc2-rating-stars">★★★★★</div>
              <div class="sc2-rating-label">Entreprise vérifiée</div>
            </div>
            <?php else: ?>
            <div class="sc2-nodata-box">
              <div class="sc2-nodata-icon">⭐</div>
              <div class="sc2-nodata-label">Peu d'avis disponibles</div>
              <div class="sc2-nodata-sub">Soyez le premier à partager votre expérience !</div>
              <a href="<?= esc_url($claim_url) ?>" class="sc2-nodata-link">Donner un avis →</a>
            </div>
            <?php endif; ?>
          </div>

          <!-- Score de fiabilité — toujours affiché -->
          <div class="sc2-aside-card">
            <h4 class="sc2-aside-title">Score de fiabilité</h4>
            <div class="sc2-score-wrap">
              <div class="sc2-score-ring"
                   style="background:conic-gradient(#16a34a 0deg <?= esc_attr($score_deg) ?>deg,rgba(0,0,0,.07) <?= esc_attr($score_deg) ?>deg 360deg)"
                   aria-label="<?= esc_attr($score_display . '/100') ?>">
                <span><?= esc_html($score_display) ?></span>
              </div>
              <div class="sc2-score-label">
                <strong><?= $score_display >= 70 ? 'Profil fiable' : ($score_display >= 50 ? 'Profil modéré' : 'Profil à vérifier') ?></strong>
                <span>Données vérifiées, activité continue.</span>
              </div>
            </div>
          </div>

          <?php $has_contact_aside = !empty($company['phone']) || !empty($company['website']) || !empty($company['address']); ?>
          <?php if ($has_contact_aside): ?>
          <div class="sc2-aside-card">
            <h4 class="sc2-aside-title">Contact</h4>
            <?php if (!empty($company['phone'])): ?>
            <div class="sc2-contact-item">
              <span>📞</span>
              <a href="tel:<?= esc_attr(preg_replace('/\s+/', '', $company['phone'])) ?>" class="sc2-contact-val"><?= esc_html($company['phone']) ?></a>
            </div>
            <?php endif; ?>
            <?php if (!empty($company['website'])): ?>
            <div class="sc2-contact-item">
              <span>🌐</span>
              <a href="<?= esc_url($company['website']) ?>" target="_blank" rel="noopener" class="sc2-contact-val"><?= esc_html(preg_replace('/^https?:\/\/(www\.)?/', '', rtrim($company['website'], '/'))) ?></a>
            </div>
            <?php endif; ?>
            <?php if (!empty($company['address'])): ?>
            <div class="sc2-contact-item">
              <span>📍</span>
              <span class="sc2-contact-val"><?= esc_html($company['address']) ?></span>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- CTA "Cette entreprise est la vôtre?" -->
          <div class="sc2-aside-card sc2-aside-cta">
            <h4 class="sc2-aside-cta-title">Cette entreprise est la vôtre ?</h4>
            <p class="sc2-aside-cta-sub">Reprenez le contrôle de votre image en ligne.</p>
            <ul class="sc2-aside-cta-list">
              <li>✓ Améliorer votre visibilité en ligne</li>
              <li>✓ Renforcer votre image professionnelle</li>
              <li>✓ Publier et répondre aux questions</li>
              <li>✓ Contrôler votre présentation</li>
              <li>✓ Booster votre business</li>
            </ul>
            <a href="<?= esc_url($claim_url) ?>" class="sc2-aside-cta-btn">Gérer gratuitement ma fiche →</a>
          </div>

          <!-- Revendiquer -->
          <div class="sc2-aside-card" style="text-align:center">
            <div style="font-size:13px;font-weight:700;color:var(--sc-navy);margin-bottom:6px">Revendiquer cette fiche</div>
            <div style="font-size:12px;color:#6b7280;margin-bottom:14px;line-height:1.5">Reprenez le contrôle de votre image en ligne.</div>
            <a href="<?= esc_url($claim_url) ?>" class="sc2-btn-primary-sm" style="display:block;text-align:center">Revendiquer cette fiche →</a>
            <div style="font-size:11px;color:#9ca3af;margin-top:10px">Créer gratuitement votre page entreprise TOPsocietes.com</div>
          </div>

        </aside><!-- .sc2-aside -->

      </div><!-- .sc2-grid -->

      <div class="sc2-disclaimer">⭐ Note interne basée sur notre perception du profil de l'entreprise, calculée en fonction des éléments positifs et négatifs identifiés.</div>

    </div><!-- .sc2-wrap -->
    <style>
    :root{--sc-grad:linear-gradient(135deg,#F97316 0%,#EC4899 40%,#8B5CF6 70%,#06B6D4 100%);--sc-grad-btn:linear-gradient(135deg,#F97316,#EC4899);--sc-navy:#1e2d5a;--sc-orange:#F97316;--sc-purple:#8B5CF6}

    /* WRAP */
    .sc2-wrap{max-width:1100px;margin:0 auto;padding:0 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1f2937}

    /* HERO */
    .sc2-hero{background:linear-gradient(135deg,#fff8f4 0%,#fdf4ff 60%,#f0f4ff 100%);border-radius:20px;padding:28px 32px;display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:24px;flex-wrap:wrap;border:1.5px solid #ede8ff;box-shadow:0 4px 28px rgba(139,92,246,.09);position:relative;overflow:hidden}
    .sc2-hero::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--sc-grad)}
    .sc2-hero-left{display:flex;align-items:center;gap:18px;flex:1;min-width:0}
    .sc2-hero-badge{width:60px;height:60px;border-radius:14px;background:var(--sc-grad-btn);color:#fff;font-size:18px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;letter-spacing:.5px}
    .sc2-hero-info{flex:1;min-width:0}
    .sc2-hero-badges{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
    .sc2-badge{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;border:1.5px solid}
    .sc2-badge--active{background:rgba(22,163,74,.08);border-color:rgba(22,163,74,.25);color:#15803d}
    .sc2-badge--forme{background:#f0f4ff;border-color:#c7d2fe;color:#4338ca}
    .sc2-badge--premium{background:rgba(217,119,6,.08);border-color:rgba(217,119,6,.25);color:#b45309}
    .sc2-hero-name{margin:0 0 10px;font-size:26px;font-weight:900;color:var(--sc-navy);line-height:1.15;text-transform:uppercase;letter-spacing:.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sc2-hero-meta{display:flex;flex-wrap:wrap;gap:6px}
    .sc2-hero-meta span{color:#475569;font-size:12px;padding:3px 10px;border-radius:20px;border:1.5px solid #e8e0ff;font-weight:500}
    .sc2-hero-actions{display:flex;flex-direction:column;gap:8px;flex-shrink:0;align-items:stretch}
    .sc2-btn-outline{display:inline-block;border:1.5px solid #d1d5db;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:600;color:#374151;text-decoration:none;transition:border-color .15s;text-align:center}
    .sc2-btn-outline:hover{border-color:#6366f1;color:#4338ca}
    .sc2-btn-primary-sm{display:inline-block;background:var(--sc-grad-btn);color:#fff;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:700;text-decoration:none;text-align:center;box-shadow:0 3px 10px rgba(249,115,22,.3)}
    .sc2-btn-primary-sm:hover{opacity:.88;color:#fff}

    /* GRID */
    .sc2-grid{display:grid;grid-template-columns:1fr 290px;gap:20px;align-items:start}

    /* CARDS MAIN */
    .sc2-main{display:flex;flex-direction:column;gap:0}
    .sc2-card{background:#fff;border:1.5px solid #f0e8ff;border-radius:16px;padding:24px 28px;margin-bottom:18px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
    .sc2-card-title{font-size:16px;font-weight:800;color:var(--sc-navy);margin:0 0 18px;padding-bottom:12px;border-bottom:2px solid #f5f0ff}

    /* ASIDE */
    .sc2-aside{display:flex;flex-direction:column;gap:14px;position:sticky;top:20px}
    .sc2-aside-card{background:#fff;border:1.5px solid #f0e8ff;border-radius:16px;padding:20px 22px;box-shadow:0 2px 12px rgba(0,0,0,.05)}
    .sc2-aside-title{font-size:13px;font-weight:800;color:var(--sc-navy);margin:0 0 12px}

    /* RATING */
    .sc2-rating-box,.sc2-nodata-box{text-align:center;padding:4px 0}
    .sc2-rating-score{font-size:40px;font-weight:900;line-height:1;background:var(--sc-grad-btn);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
    .sc2-rating-stars{color:#f59e0b;font-size:18px;letter-spacing:2px;margin:6px 0}
    .sc2-rating-label{color:#94a3b8;font-size:12px}
    .sc2-nodata-icon{font-size:28px;margin-bottom:6px}
    .sc2-nodata-label{font-size:13px;font-weight:800;color:#1f2937;margin-bottom:4px}
    .sc2-nodata-sub{font-size:11px;color:#6b7280;margin-bottom:10px;line-height:1.4}
    .sc2-nodata-link{display:inline-block;font-size:12px;background:var(--sc-grad-btn);color:#fff;text-decoration:none;font-weight:700;padding:7px 14px;border-radius:8px;box-shadow:0 3px 10px rgba(249,115,22,.3)}
    .sc2-nodata-link:hover{opacity:.88}

    /* SCORE RING */
    .sc2-score-wrap{display:flex;align-items:center;gap:14px}
    .sc2-score-ring{width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .sc2-score-ring span{width:52px;height:52px;background:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:900;color:#16a34a}
    .sc2-score-label{display:flex;flex-direction:column;gap:3px}
    .sc2-score-label strong{font-size:12px;font-weight:700;color:#1f2937}
    .sc2-score-label span{font-size:11px;color:#6b7280;line-height:1.4}

    /* CONTACT */
    .sc2-contact-item{display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;font-size:13px}
    .sc2-contact-item:last-child{margin-bottom:0}
    .sc2-contact-val{color:#374151;text-decoration:none;word-break:break-all;font-weight:500}
    a.sc2-contact-val:hover{color:var(--sc-orange);text-decoration:underline}

    /* CTA ASIDE */
    .sc2-aside-cta{background:linear-gradient(145deg,#1a2744,#2d1b4e)!important;border-color:#3d2d6e!important}
    .sc2-aside-cta-title{font-size:13px;font-weight:800;color:rgba(255,255,255,.85);margin:0 0 6px}
    .sc2-aside-cta-sub{font-size:12px;color:rgba(255,255,255,.65);margin:0 0 14px;line-height:1.5}
    .sc2-aside-cta-list{list-style:none;margin:0 0 14px;padding:0;display:flex;flex-direction:column;gap:5px}
    .sc2-aside-cta-list li{font-size:12px;color:rgba(255,255,255,.75)}
    .sc2-aside-cta-btn{display:block;text-align:center;background:var(--sc-grad-btn);color:#fff;font-size:13px;font-weight:700;padding:10px 14px;border-radius:10px;text-decoration:none;box-shadow:0 4px 14px rgba(249,115,22,.4)}
    .sc2-aside-cta-btn:hover{opacity:.88;color:#fff}

    /* INTRO */
    .sc2-intro-text{margin:0;color:#374151;line-height:1.9;font-size:14px}

    /* KPI */
    .sc2-kpi-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .sc2-kpi-item{border:1.5px solid #f0e8ff;border-radius:12px;padding:16px;text-align:center}
    .sc2-kpi-label{font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
    .sc2-kpi-value{font-size:22px;font-weight:900;color:var(--sc-navy)}
    .sc2-kpi-na{color:#d1d5db!important;font-size:28px}

    /* CA CHART */
    .sc2-chart-wrap{display:flex;flex-direction:column;gap:10px}
    .sc2-chart-row{display:grid;grid-template-columns:44px 1fr 80px;align-items:center;gap:10px}
    .sc2-chart-year{font-size:12px;font-weight:700;color:#6b7280;text-align:right}
    .sc2-chart-track{background:#f1f5f9;border-radius:4px;height:10px;overflow:hidden}
    .sc2-chart-fill{height:100%;background:var(--sc-grad-btn);border-radius:4px}
    .sc2-chart-val{font-size:13px;font-weight:700;color:#1f2937}

    /* LEGAL */
    .sc2-legal-table{border:1px solid #f5f0ff;border-radius:12px;overflow:hidden}
    .sc2-legal-row{display:flex;align-items:flex-start;padding:10px 16px;border-bottom:1px solid #f5f0ff;gap:12px}
    .sc2-legal-row:last-child{border-bottom:none}
    .sc2-legal-label{font-size:12px;color:#6b7280;font-weight:500;min-width:130px;flex-shrink:0}
    .sc2-legal-value{font-size:13px;color:#1f2937;font-weight:600;word-break:break-word}

    /* DIRIGEANTS */
    .sc2-dir-list{display:flex;flex-direction:column;gap:10px}
    .sc2-dir-row{display:flex;align-items:center;gap:14px;border:1.5px solid #f0e8ff;border-radius:12px;padding:14px 16px}
    .sc2-dir-avatar{width:40px;height:40px;border-radius:50%;background:var(--sc-grad-btn);color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .sc2-dir-name{font-size:14px;font-weight:700;color:#1f2937;margin-bottom:2px}
    .sc2-dir-role{font-size:12px;color:#6b7280}
    .sc2-dir-since{font-size:11px;color:#9ca3af;margin-top:2px}

    /* Q&A */
    .sc2-qa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
    .sc2-qa-card{border:1.5px solid #f0e8ff;border-left:4px solid var(--sc-orange);border-radius:0 14px 14px 14px;padding:20px 22px;box-shadow:0 2px 10px rgba(0,0,0,.05);transition:box-shadow .2s,transform .15s}
    .sc2-qa-card:hover{box-shadow:0 6px 22px rgba(249,115,22,.12);transform:translateY(-2px)}
    .sc2-qa-q{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;background:var(--sc-grad-btn);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:10px}
    .sc2-qa-a{color:#374151;font-size:13px;line-height:1.8}

    /* FAQ */
    .sc2-faq-wrap{border:1px solid #f0e8ff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .sc2-faq-list{display:flex;flex-direction:column}
    .sc2-faq-item{border-bottom:1px solid #f5f0ff}
    .sc2-faq-item:last-child{border-bottom:none}
    .sc2-faq-toggle{display:flex;align-items:center;gap:12px;width:100%;background:none;border:none;padding:18px 20px;cursor:pointer;text-align:left;font-family:inherit;transition:background .15s}
    .sc2-faq-toggle:hover{background:#fdf8ff}
    .sc2-faq-q-text{flex:1;font-size:14px;font-weight:600;color:#1f2937}
    .sc2-faq-chevron{font-size:18px;color:var(--sc-orange);flex-shrink:0}
    .sc2-faq-item--open .sc2-faq-chevron{color:var(--sc-purple)}
    .sc2-faq-body{padding:0 20px 18px 54px}
    .sc2-faq-a{display:flex;align-items:flex-start;gap:10px;font-size:13px;color:#6b7280;line-height:1.75}
    .sc2-faq-icon{background:var(--sc-grad-btn);color:#fff;font-size:11px;font-weight:800;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
    .sc2-faq-icon-r{background:var(--sc-grad)}
    .sc2-faq-locked{font-size:13px;color:#9ca3af;padding:6px 0}

    /* BONUS */
    .sc2-bonus-card{background:linear-gradient(135deg,#f0f4ff,#fdf4ff);border:1px solid #e0e7ff;border-radius:14px;padding:22px 26px;margin-bottom:18px;box-shadow:0 2px 10px rgba(59,91,219,.07)}
    .sc2-bonus-text{margin:0;color:#1e2d5a;font-size:14px;line-height:1.85}

    /* DISCLAIMER */
    .sc2-disclaimer{font-size:11px;color:#94a3b8;font-style:italic;text-align:center;padding:12px;margin-top:8px;margin-bottom:16px}

    @media(max-width:860px){
      .sc2-grid{grid-template-columns:1fr}
      .sc2-aside{position:static}
      .sc2-hero-name{white-space:normal}
    }
    @media(max-width:540px){
      .sc2-hero{padding:18px 16px}
      .sc2-hero-name{font-size:20px}
      .sc2-hero-actions{flex-direction:row}
      .sc2-card{padding:18px 16px}
      .sc2-kpi-grid{grid-template-columns:1fr 1fr}
      .sc2-faq-toggle{padding:14px 14px}
      .sc2-faq-body{padding:0 14px 14px 46px}
      .sc2-qa-grid{grid-template-columns:1fr}
    }
    </style>
    <?php
    $html = ob_get_clean();
    // Supprime le bouton "Show Sidebar" / "Afficher la barre latérale" du thème Findus
    add_action('wp_footer', function() {
        echo '<script>
(function(){
  var _SIDEBAR_TEXTS=["Show Sidebar","Afficher la barre lat\u00e9rale","Hide Sidebar","Masquer la barre lat\u00e9rale"];
  function removeSidebarToggle(){
    // Supprimer les boutons/liens qui contiennent le texte
    document.querySelectorAll("button,a,.show-sidebar-button,.btn-show-sidebar,.sidebar-toggle,[class*=\'sidebar-toggle\'],[class*=\'show-sidebar\']").forEach(function(el){
      var t=el.textContent.trim();
      if(_SIDEBAR_TEXTS.indexOf(t)!==-1||(el.innerHTML&&el.innerHTML.trim().replace(/<[^>]+>/g,"").trim()===t&&_SIDEBAR_TEXTS.indexOf(t)!==-1)){
        el.style.display="none";
        el.setAttribute("aria-hidden","true");
      }
    });
    // Cibler aussi les nœuds texte directs
    var walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,null,false);
    var node,toHide=[];
    while((node=walker.nextNode())){
      if(_SIDEBAR_TEXTS.indexOf(node.nodeValue.trim())!==-1) toHide.push(node);
    }
    toHide.forEach(function(n){
      if(n.parentElement) n.parentElement.style.display="none";
    });
  }
  document.addEventListener("DOMContentLoaded",removeSidebarToggle);
  setTimeout(removeSidebarToggle,300);
  setTimeout(removeSidebarToggle,1000);
  var obs=new MutationObserver(function(){removeSidebarToggle();});
  obs.observe(document.body,{childList:true,subtree:true});
})();
</script>';
    }, 20);
    return $html;
});

// =============================================================================
// PAGE D'ACCUEIL SOUS-DOMAINE [societies_home]
// =============================================================================
add_shortcode('societies_home', function() {
    sc_enqueue_styles();
    $status  = sc_api('/api/status');
    $stats   = sc_api('/api/fiches/stats');
    $sectors = sc_api('/api/seo/top-sectors');
    $cities  = sc_api('/api/seo/top-cities');

    $total_co   = number_format($status['rows'] ?? 0, 0, ',', ' ');
    $total_done = number_format($stats['done'] ?? 0, 0, ',', ' ');
    $logo_url   = rtrim(get_option('societies_api_url', ''), '/') . '/static/logo.jpg';
    $claim_url  = home_url('/revendiquer/');

    ob_start(); ?>
    <div class="sc-home">

      <!-- HERO -->
      <div class="sc-home-hero">
        <img src="<?= esc_url($logo_url) ?>" alt="TOPsocietes.com" class="sc-home-logo">
        <h1 class="sc-home-title">Avis et décryptage des entreprises françaises</h1>
        <p class="sc-home-sub">Note clients, réputation et analyses pour des millions d'entreprises</p>
        <div class="sc-home-sw">
          <input type="text" id="sch-input" class="sc-home-si"
                 placeholder="Rechercher une entreprise..." autocomplete="off"
                 oninput="schSearch(this.value)">
          <div id="sch-drop" class="sc-home-sd"></div>
        </div>
      </div>

      <!-- STATS -->
      <div class="sc-home-stats">
        <div class="sc-home-stat">
          <div class="sc-home-stat-n"><?= $total_co ?></div>
          <div class="sc-home-stat-l">entreprises référencées</div>
        </div>
        <div class="sc-home-stat">
          <div class="sc-home-stat-n"><?= $total_done ?></div>
          <div class="sc-home-stat-l">fiches analysées par IA</div>
        </div>
        <div class="sc-home-stat">
          <div class="sc-home-stat-n">Gratuit</div>
          <div class="sc-home-stat-l">pour revendiquer votre fiche</div>
        </div>
      </div>

      <!-- TOP SECTEURS -->
      <?php if (!empty($sectors['results'])): ?>
      <div class="sc-home-section">
        <h2 class="sc-home-sh">Secteurs populaires</h2>
        <div class="sc-home-pills">
          <?php foreach (array_slice($sectors['results'], 0, 20) as $s):
            if (empty($s['sector'])) continue; ?>
          <span class="sc-home-pill">
            <?= esc_html($s['sector']) ?>
            <span class="sc-home-pill-c"><?= number_format($s['count'] ?? 0, 0, ',', ' ') ?></span>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- TOP VILLES -->
      <?php if (!empty($cities['results'])): ?>
      <div class="sc-home-section">
        <h2 class="sc-home-sh">Villes principales</h2>
        <div class="sc-home-pills">
          <?php foreach (array_slice($cities['results'], 0, 20) as $c):
            if (empty($c['city'])) continue; ?>
          <span class="sc-home-pill">
            <?= esc_html($c['city']) ?>
            <span class="sc-home-pill-c"><?= number_format($c['count'] ?? 0, 0, ',', ' ') ?></span>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- CTA PROPRIÉTAIRE -->
      <div class="sc-home-owner">
        <div>
          <strong>Cette entreprise est la vôtre ?</strong>
          <p>Revendiquez votre fiche gratuitement et répondez aux questions de vos clients.</p>
        </div>
        <a href="<?= esc_url($claim_url) ?>" class="sc-btn sc-home-owner-btn">Revendiquer votre fiche →</a>
      </div>

    </div>
    <style>
    .sc-home{max-width:900px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
    /* Hero */
    .sc-home-hero{background:#1a2744;border-radius:20px;padding:48px 48px 44px;text-align:center;color:#fff;margin-bottom:24px}
    .sc-home-logo{height:42px;width:auto;margin-bottom:24px;opacity:.9}
    .sc-home-title{font-size:30px;font-weight:800;margin:0 0 12px;line-height:1.25;color:#fff}
    .sc-home-sub{font-size:15px;color:#94a3b8;margin:0 0 28px}
    /* Search */
    .sc-home-sw{position:relative;max-width:540px;margin:0 auto}
    .sc-home-si{width:100%;padding:16px 22px;border:none;border-radius:12px;font-size:16px;outline:none;box-shadow:0 4px 24px rgba(0,0,0,.25);box-sizing:border-box}
    .sc-home-sd{display:none;position:absolute;top:calc(100% + 4px);left:0;right:0;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.15);z-index:200;max-height:280px;overflow-y:auto}
    .sc-home-sd a{display:block;padding:12px 18px;text-decoration:none;color:#1f2937;border-bottom:1px solid #f3f4f6;font-size:14px;transition:background .1s}
    .sc-home-sd a:last-child{border-bottom:none}
    .sc-home-sd a:hover{background:#f8fafc}
    .sc-home-sd-meta{font-size:12px;color:#9ca3af;display:block}
    /* Stats */
    .sc-home-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:28px}
    .sc-home-stat{border:1px solid #e5e7eb;border-radius:14px;padding:22px;text-align:center}
    .sc-home-stat-n{font-size:26px;font-weight:800;color:#1a2744}
    .sc-home-stat-l{font-size:13px;color:#6b7280;margin-top:4px}
    /* Sections */
    .sc-home-section{margin-bottom:24px}
    .sc-home-sh{font-size:17px;font-weight:700;color:#1f2937;margin:0 0 14px;padding-bottom:8px;border-bottom:2px solid #e63946;display:inline-block}
    .sc-home-pills{display:flex;flex-wrap:wrap;gap:8px}
    .sc-home-pill{display:inline-flex;align-items:center;gap:6px;border:1px solid #e5e7eb;border-radius:20px;padding:7px 14px;font-size:13px;color:#374151}
    .sc-home-pill-c{background:#f1f5f9;color:#6b7280;font-size:11px;padding:2px 7px;border-radius:10px}
    /* CTA owner */
    .sc-home-owner{display:flex;align-items:center;justify-content:space-between;gap:16px;background:#1a2744;border-radius:16px;padding:24px 32px;margin-top:8px;flex-wrap:wrap}
    .sc-home-owner strong{color:#fff;font-size:17px}
    .sc-home-owner p{color:#94a3b8;margin:6px 0 0;font-size:14px}
    .sc-home-owner-btn{background:#e63946;white-space:nowrap}
    .sc-home-owner-btn:hover{background:#c1121f}
    @media(max-width:640px){
      .sc-home-hero{padding:32px 20px}
      .sc-home-title{font-size:22px}
      .sc-home-stats{grid-template-columns:1fr}
      .sc-home-owner{flex-direction:column;align-items:flex-start}
    }
    </style>
    <script>
    var _schT;
    function schSearch(q) {
        var drop = document.getElementById('sch-drop');
        if (q.length < 2) { drop.style.display='none'; return; }
        clearTimeout(_schT);
        _schT = setTimeout(function() {
            fetch('/?rest_route=/societies/v1/search&q=' + encodeURIComponent(q) + '&per_page=8')
            .then(function(r){ return r.json(); })
            .then(function(d) {
                var results = d.results || [];
                if (!results.length) { drop.style.display='none'; return; }
                drop.innerHTML = results.map(function(c) {
                    var meta = [c.category, c.city ? c.city : ''].filter(Boolean).join(' — ');
                    return '<a href="#" onclick="schNav(event,\'' + c.title.replace(/'/g,"\\'").replace(/"/g,'&quot;') + '\'); return false;">'
                        + '<strong>' + c.title + '</strong>'
                        + (meta ? '<span class="sc-home-sd-meta">' + meta + '</span>' : '')
                        + '</a>';
                }).join('');
                drop.style.display = 'block';
            });
        }, 250);
    }
    function schNav(e, title) {
        fetch('/?rest_route=/societies/v1/page-url&title=' + encodeURIComponent(title))
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d.url) window.location.href = d.url; });
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.sc-home-sw')) {
            var drop = document.getElementById('sch-drop');
            if (drop) drop.style.display = 'none';
        }
    });
    </script>
    <?php
    return ob_get_clean();
});

// =============================================================================
// PAGE FRONT-END "MON ENTREPRISE" — création automatique
// =============================================================================
add_action('init', function() {
    // Créer la page "Mon entreprise" si elle n'existe pas encore
    if (get_option('sc_client_page_id')) return;
    $page_id = wp_insert_post([
        'post_title'   => 'Mon entreprise',
        'post_name'    => 'mon-entreprise',
        'post_content' => '[societies_owner_dashboard]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ]);
    if ($page_id && !is_wp_error($page_id)) {
        update_option('sc_client_page_id', $page_id);
    }
});

// Rediriger les clients (non-admin) hors du wp-admin vers la page Mon entreprise
add_action('admin_init', function() {
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!is_user_logged_in() || current_user_can('manage_options') || current_user_can('editor')) return;
    $page_id = get_option('sc_client_page_id');
    if ($page_id) {
        wp_redirect(get_permalink($page_id));
        exit;
    }
});

// Lien "Mon entreprise" dans la barre d'admin WordPress (pour les clients connectés)
add_action('admin_bar_menu', function(WP_Admin_Bar $bar) {
    if (!is_user_logged_in() || current_user_can('manage_options')) return;
    $page_id = get_option('sc_client_page_id');
    if (!$page_id) return;
    $user_id = get_current_user_id();
    $company = get_user_meta($user_id, 'sc_company_title', true);
    $bar->add_node([
        'id'    => 'sc-mon-entreprise',
        'title' => '🏢 ' . ($company ? esc_html($company) : 'Mon entreprise'),
        'href'  => get_permalink($page_id),
        'meta'  => ['class' => 'sc-adminbar-link'],
    ]);
}, 100);

// Bouton flottant "Mon entreprise" sur le front-end pour les clients connectés
add_action('wp_footer', function() {
    if (!is_user_logged_in() || current_user_can('manage_options')) return;
    $page_id = get_option('sc_client_page_id');
    if (!$page_id || is_page($page_id)) return;
    $url     = get_permalink($page_id);
    $user_id = get_current_user_id();
    $company = get_user_meta($user_id, 'sc_company_title', true);
    $label   = $company ? esc_html($company) : 'Mon entreprise';
    echo '<a href="' . esc_url($url) . '" style="
        position:fixed;bottom:24px;right:24px;z-index:9999;
        background:#2563eb;color:#fff;text-decoration:none;
        padding:12px 20px;border-radius:50px;font-size:14px;font-weight:600;
        box-shadow:0 4px 16px rgba(37,99,235,.4);
        display:flex;align-items:center;gap:8px;
        transition:background .2s
    " onmouseover="this.style.background=\'#1d4ed8\'" onmouseout="this.style.background=\'#2563eb\'">
        🏢 ' . $label . '
    </a>';
});

// =============================================================================
// =============================================================================
// AJAX — bulk création pages (batch de 50 pour éviter timeout)
// =============================================================================
add_action('wp_ajax_sc_bulk_create_batch', function() {
    check_ajax_referer('sc_bulk_create', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Non autorisé');

    $page    = max(1, (int)($_POST['api_page'] ?? 1));
    $fiches  = sc_api('/api/fiches?per_page=50&page=' . $page);

    if (!empty($fiches['error'])) {
        wp_send_json_error($fiches['error']);
    }

    $items   = $fiches['results'] ?? [];
    $total   = (int)($fiches['total'] ?? 0);
    $created = 0;
    $skipped = 0;

    foreach ($items as $f) {
        $title = $f['company_title'] ?? '';
        if (!$title) { $skipped++; continue; }
        $existing = get_posts([
            'post_type'   => 'page',
            'post_status' => ['publish', 'draft'],
            'meta_key'    => '_sc_company_title',
            'meta_value'  => $title,
            'numberposts' => 1,
        ]);
        if ($existing) { $skipped++; continue; }
        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($title),
            'post_name'    => sanitize_title($title),
            'post_content' => '[societies_fiche title="' . esc_attr($title) . '"]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_parent'  => sc_resolve_page_parent($title),
        ]);
        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_sc_company_title', $title);
            $created++;
        } else {
            $skipped++;
        }
    }

    wp_send_json_success([
        'created'   => $created,
        'skipped'   => $skipped,
        'has_more'  => count($items) === 50,
        'next_page' => $page + 1,
        'total'     => $total,
        'done_up_to'=> $page * 50,
    ]);
});

// =============================================================================
// ADMIN
// =============================================================================
add_action('admin_menu', function() {
    add_menu_page('Societies', 'Societies', 'manage_options',
        'societies', 'sc_admin_dashboard', 'dashicons-building', 30);
    add_submenu_page('societies', 'Tableau de bord', 'Tableau de bord',
        'manage_options', 'societies', 'sc_admin_dashboard');
    add_submenu_page('societies', 'Réglages API', 'Réglages API',
        'manage_options', 'societies-settings', 'sc_admin_settings');
    add_submenu_page('societies', 'Fiches générées', 'Fiches générées',
        'manage_options', 'societies-fiches', 'sc_admin_fiches');
    add_submenu_page('societies', 'Abonnements & Clients', 'Abonnements',
        'manage_options', 'societies-subscriptions', 'sc_admin_subscriptions');
    add_submenu_page('societies', 'Modération fiches', 'Modération',
        'manage_options', 'societies-moderation', 'sc_admin_moderation');
    add_submenu_page('societies', 'Thème', 'Thème',
        'manage_options', 'societies-theme', 'sc_admin_theme');
});

// Téléchargement du thème en ZIP — intercepté avant tout rendu HTML
add_action('admin_init', function() {
    if (!isset($_GET['page'], $_GET['sc_theme_dl']) || $_GET['page'] !== 'societies-theme') return;
    if (!current_user_can('manage_options')) wp_die('Accès refusé.');
    check_admin_referer('sc_theme_dl');

    $theme_dir = get_theme_root() . '/topsocietes-theme';
    if (!is_dir($theme_dir)) wp_die('Thème introuvable.');

    $zip_path = sys_get_temp_dir() . '/topsocietes-theme-' . date('Ymd') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_die('Impossible de créer l\'archive ZIP.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $file) {
        $relative = 'topsocietes-theme/' . $iterator->getSubPathname();
        if ($file->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($file->getPathname(), $relative);
        }
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="topsocietes-theme-' . date('Ymd') . '.zip"');
    header('Content-Length: ' . filesize($zip_path));
    header('Cache-Control: no-cache');
    readfile($zip_path);
    unlink($zip_path);
    exit;
});

add_action('admin_init', function() {
    register_setting('sc_options', 'societies_api_url');
    register_setting('sc_options', 'societies_api_username');
    register_setting('sc_options', 'societies_api_password', [
        'sanitize_callback' => function($new) {
            if (empty(trim($new))) return get_option('societies_api_password', '');
            return $new;
        },
    ]);
    register_setting('sc_options', 'societies_webhook_secret', [
        'sanitize_callback' => function($new) {
            if (empty(trim($new))) return get_option('societies_webhook_secret', '');
            return sanitize_text_field($new);
        },
    ]);
    register_setting('sc_options', 'societies_subdomain_mode', ['sanitize_callback' => 'absint']);
    register_setting('sc_options', 'societies_footer_links', ['sanitize_callback' => 'wp_kses_post']);
});

// =============================================================================
// MODE SOUS-DOMAINE — masquer header/footer thème + footer custom
// =============================================================================
add_action('wp_head', function() {
    ?>
<style id="sc-subdomain-css">
/* Cache le header et le footer du thème */
.wp-site-blocks > header.wp-block-template-part,
.wp-site-blocks > footer.wp-block-template-part { display: none !important; }
/* Cache la barre admin WP + suppression espace blanc */
#wpadminbar { display: none !important; }
html { margin-top: 0 !important; padding-top: 0 !important; }
body { padding-top: 0 !important; margin-top: 0 !important; }
.elementor-location-header { margin-top: 0 !important; padding-top: 0 !important; }
/* Header Elementor : logo réduit */
.elementor-location-header .elementor-widget-image img { max-height:36px !important; width:auto !important; }
/* Fallback sc-topbar */
.sc-topbar { background:linear-gradient(135deg,#F97316 0%,#EC4899 40%,#8B5CF6 70%,#06B6D4 100%); padding:10px 24px; display:flex; align-items:center; gap:16px; box-shadow:0 3px 16px rgba(249,115,22,.3); }
.sc-topbar a { color:#fff; text-decoration:none; font-size:13px; font-weight:600; }
.sc-topbar a:hover { color:rgba(255,255,255,.8); }
/* Titre de page / fil d'Ariane — fond bleu (Apus theme selectors) */
.apus-page-heading, .page-heading, .page-header-wrap, #page-header, .page-header,
[class*="apus-page-heading"], [class*="page-heading"], .apus-module.apus-breadcrumbs { background:rgb(43,79,202) !important; }
/* Footer custom */
.sc-site-footer { background:linear-gradient(135deg,#F97316 0%,#EC4899 40%,#8B5CF6 70%,#06B6D4 100%); color:#fff; padding:28px 24px; margin-top:24px; font-size:13px; box-shadow:0 -3px 16px rgba(249,115,22,.2); }
.sc-site-footer-inner { max-width:860px; margin:0 auto; display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:16px; }
.sc-site-footer-links { display:flex; flex-wrap:wrap; gap:20px; }
.sc-site-footer-links a { color:rgba(255,255,255,.85); text-decoration:none; }
.sc-site-footer-links a:hover { color:#fff; text-decoration:underline; }
.sc-site-footer-copy { color:rgba(255,255,255,.6); font-size:12px; }
</style>
    <?php
});

// Enqueue les assets Elementor pour que le header soit correctement stylé
add_action('wp_enqueue_scripts', function() {
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::instance()->frontend->enqueue_styles();
        \Elementor\Plugin::instance()->frontend->enqueue_scripts();
    }
});

// Render le header Elementor (post 1574 = Main Header)
// Fallback sur sc-topbar si Elementor n'est pas disponible
add_action('wp_body_open', function() {
    if (class_exists('\Elementor\Plugin')) {
        $content = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display(1574, true);
        if (trim($content)) {
            echo $content;
            return;
        }
    }
    // Fallback sc-topbar
    $logo_url = rtrim(get_option('societies_api_url', ''), '/') . '/static/logo.jpg';
    ?>
<div class="sc-topbar">
  <a href="<?= esc_url(home_url('/')) ?>">
    <img src="<?= esc_url($logo_url) ?>" alt="TOPsocietes.com" style="height:30px;width:auto;display:block">
  </a>
</div>
    <?php
}, 1);

add_action('wp_footer', function() {
    $links_raw = get_option('societies_footer_links', '');
    // Liens par défaut si non configurés
    if (empty(trim($links_raw))) {
        $links_raw = json_encode([
            ['label' => 'Accueil', 'url' => home_url('/')],
            ['label' => 'Mentions légales', 'url' => '#'],
            ['label' => 'Contact', 'url' => '#'],
            ['label' => 'Créer votre page', 'url' => 'https://www.topsocietes.com'],
        ]);
    }
    $links = json_decode($links_raw, true) ?: [];
    $year  = date('Y');
    ?>
<div class="sc-site-footer">
  <div class="sc-site-footer-inner">
    <div class="sc-site-footer-links">
      <?php foreach ($links as $l): ?>
      <a href="<?= esc_url($l['url'] ?? '#') ?>"><?= esc_html($l['label'] ?? '') ?></a>
      <?php endforeach; ?>
    </div>
    <div class="sc-site-footer-copy">© <?= $year ?> TOPsocietes.com — Tous droits réservés</div>
  </div>
</div>
    <?php
}, 99);

// =============================================================================
// DASHBOARD CLIENT (back office limité)
// =============================================================================
function sc_client_dashboard() {
    if (!is_user_logged_in()) {
        wp_die('Accès non autorisé.');
    }
    if (current_user_can('manage_options')) {
        wp_redirect(admin_url('admin.php?page=societies'));
        exit;
    }

    $user_id       = get_current_user_id();
    $company_title = get_user_meta($user_id, 'sc_company_title', true);
    $has_sub       = sc_user_has_subscription($user_id);
    $notice        = null;

    // ── Association entreprise ────────────────────────────────────────────────
    if (isset($_POST['sc_link']) && check_admin_referer('sc_client_link')) {
        $title   = sanitize_text_field($_POST['sc_company_title'] ?? '');
        $company = sc_api('/api/company/' . rawurlencode($title));
        if (!isset($company['error']) && !empty($company['title'])) {
            update_user_meta($user_id, 'sc_company_title', $company['title']);
            $company_title = $company['title'];
            $notice = ['type' => 'success', 'msg' => "Entreprise « {$company['title']} » associée avec succès."];
        } else {
            $notice = ['type' => 'error', 'msg' => 'Entreprise introuvable dans la base.'];
        }
    }

    // ── Sauvegarde intro (GRATUIT) ────────────────────────────────────────────
    if (isset($_POST['sc_save_intro']) && check_admin_referer('sc_save_intro') && $company_title) {
        $intro_text = sanitize_textarea_field($_POST['sc_intro_text'] ?? '');
        $result = sc_api('/api/fiche/' . rawurlencode($company_title), 'PUT', [
            'intro_text' => $intro_text,
        ]);
        if (!isset($result['error'])) {
            $notice = ['type' => 'success', 'msg' => 'Votre présentation a bien été enregistrée.'];
        } else {
            $notice = ['type' => 'error', 'msg' => 'Erreur : ' . $result['error']];
        }
    }

    // ── Sauvegarde réponses ouvertes (ABONNEMENT requis) ──────────────────────
    if (isset($_POST['sc_save_answers']) && check_admin_referer('sc_save_answers') && $company_title && $has_sub) {
        $raw_answers = $_POST['sc_open_answers'] ?? [];
        $fiche       = sc_api('/api/fiche/' . rawurlencode($company_title));
        $questions   = $fiche['open_questions'] ?? [];

        // Construire les paires {q, r}
        $open_answers = [];
        foreach ($questions as $i => $q) {
            $open_answers[] = [
                'q' => $q,
                'r' => sanitize_textarea_field($raw_answers[$i] ?? ''),
            ];
        }

        $result = sc_api('/api/fiche/' . rawurlencode($company_title), 'PUT', [
            'open_answers' => $open_answers,
        ]);

        if (!isset($result['error'])) {
            update_user_meta($user_id, 'sc_open_answers', array_column($open_answers, 'r'));
            $notice = ['type' => 'success', 'msg' => 'Vos réponses ont bien été enregistrées sur votre fiche.'];
        } else {
            $notice = ['type' => 'error', 'msg' => 'Erreur lors de la sauvegarde : ' . $result['error']];
        }
    }

    // ── Dissociation ──────────────────────────────────────────────────────────
    if (isset($_GET['sc_unlink']) && current_user_can('read')) {
        delete_user_meta($user_id, 'sc_company_title');
        delete_user_meta($user_id, 'sc_open_answers');
        $company_title = '';
        $notice = ['type' => 'success', 'msg' => 'Entreprise dissociée.'];
    }

    $fiche          = $company_title ? sc_api('/api/fiche/' . rawurlencode($company_title)) : [];
    $qa_answered    = $fiche['qa_answered']   ?? [];
    $open_questions = $fiche['open_questions'] ?? [];
    $qa_open        = $fiche['qa_open']        ?? [];
    $intro          = $fiche['intro_text']     ?? '';
    $date           = $fiche['date_fr']        ?? '';
    $saved_answers  = get_user_meta($user_id, 'sc_open_answers', true) ?: [];

    // Fusionner les réponses déjà sauvegardées (qa_open de l'API en priorité)
    if (!empty($qa_open)) {
        $saved_answers = array_column($qa_open, 'r');
    }
    ?>
    <div class="wrap">
      <h1><?= sc_admin_logo() ?>Mon entreprise</h1>

      <?php if ($notice): ?>
      <div class="notice notice-<?= $notice['type'] ?> is-dismissible"><p><?= esc_html($notice['msg']) ?></p></div>
      <?php endif; ?>

      <?php if (!$company_title): ?>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin:12px 0;font-size:13px;color:#1e40af;max-width:580px">
        <strong>Comment ça marche ?</strong><br>
        Recherchez votre entreprise ci-dessous → Revendiquez-la → Modifiez votre présentation gratuitement → Avec un abonnement, répondez aux 6 questions qui s'affichent sur votre fiche publique.
      </div>
      <?php endif; ?>

      <?php if (!$company_title): ?>
      <!-- Revendication entreprise -->
      <div style="max-width:580px;border:1px solid #e5e7eb;border-radius:12px;padding:32px;margin-top:16px;box-shadow:0 1px 4px rgba(0,0,0,.05)">
        <h2 style="margin-top:0;font-size:18px">🏢 Revendiquer mon entreprise</h2>
        <p style="color:#6b7280;margin-bottom:20px;font-size:14px">
          Recherchez votre entreprise dans notre base pour accéder à votre fiche et la personnaliser.
        </p>
        <form method="post" id="sc-claim-form">
          <?php wp_nonce_field('sc_client_link'); ?>
          <div style="position:relative">
            <input type="text" id="sc-search-input" autocomplete="off"
                   placeholder="Tapez le nom de votre entreprise..."
                   style="width:100%;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;box-sizing:border-box;margin-bottom:4px"
                   oninput="scSearchCompany(this.value)">
            <div id="sc-search-results" style="display:none;position:absolute;top:100%;left:0;right:0;border:1px solid #d1d5db;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:99;max-height:260px;overflow-y:auto"></div>
          </div>
          <input type="hidden" name="sc_company_title" id="sc-company-hidden" required>
          <div id="sc-selected" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin:8px 0;font-size:14px;color:#15803d"></div>
          <div style="margin-top:12px">
            <?php submit_button('✅ Revendiquer cette entreprise', 'primary', 'sc_link', false,
              ['id'=>'sc-claim-btn','disabled'=>'disabled','style'=>'opacity:.5;cursor:not-allowed']); ?>
          </div>
        </form>
      </div>
      <style>
        .sc-result-item{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:13px}
        .sc-result-item:hover{background:#f9fafb}
        .sc-result-item strong{display:block;color:#111;margin-bottom:2px}
        .sc-result-item span{color:#6b7280;font-size:12px}
      </style>
      <script>
      let _scSearchTimer = null;
      function scSearchCompany(q) {
        document.getElementById('sc-company-hidden').value = '';
        document.getElementById('sc-selected').style.display = 'none';
        const btn = document.getElementById('sc-claim-btn');
        btn.disabled = true; btn.style.opacity = '.5'; btn.style.cursor = 'not-allowed';
        clearTimeout(_scSearchTimer);
        const box = document.getElementById('sc-search-results');
        if (q.length < 2) { box.style.display = 'none'; return; }
        _scSearchTimer = setTimeout(async () => {
          const r = await fetch('/?rest_route=/societies/v1/search&q=' + encodeURIComponent(q) + '&per_page=8');
          const d = await r.json();
          const results = d.results || [];
          if (!results.length) { box.innerHTML = '<div class="sc-result-item"><span>Aucun résultat</span></div>'; box.style.display = 'block'; return; }
          box.innerHTML = results.map(c =>
            `<div class="sc-result-item" onclick="scSelectCompany(${JSON.stringify(c.title)}, ${JSON.stringify((c.category||'') + (c.city ? ' — ' + c.city : ''))})">
               <strong>${c.title}</strong>
               <span>${c.category || ''}${c.city ? ' — ' + c.city : ''}${c.rating_value ? ' ★ ' + c.rating_value.toFixed(1) : ''}</span>
             </div>`
          ).join('');
          box.style.display = 'block';
        }, 300);
      }
      function scSelectCompany(title, meta) {
        document.getElementById('sc-search-input').value = title;
        document.getElementById('sc-company-hidden').value = title;
        document.getElementById('sc-search-results').style.display = 'none';
        const sel = document.getElementById('sc-selected');
        sel.innerHTML = '✅ <strong>' + title + '</strong> — ' + meta;
        sel.style.display = 'block';
        const btn = document.getElementById('sc-claim-btn');
        btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
      }
      document.addEventListener('click', e => {
        if (!e.target.closest('#sc-claim-form')) document.getElementById('sc-search-results').style.display = 'none';
      });
      </script>

      <?php else: ?>
      <!-- Fiche -->
      <div style="max-width:720px">

        <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
          <div>
            <h2 style="margin:0"><?= esc_html($company_title) ?></h2>
            <span style="color:#6b7280;font-size:13px">
              <?= $has_sub
                  ? '<span style="color:#10b981">✅ Abonnement actif</span>'
                  : '<span style="color:#f59e0b">⚠️ Sans abonnement — questions verrouillées</span>' ?>
            </span>
          </div>
          <a href="<?= esc_url(add_query_arg('sc_unlink', '1')) ?>"
             style="margin-left:auto;color:#ef4444;font-size:12px;text-decoration:none"
             onclick="return confirm('Dissocier cette entreprise ?')">
            Dissocier
          </a>
        </div>

        <?php if (empty($fiche) || ($fiche['status'] ?? 'none') === 'none'): ?>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:24px;color:#6b7280;text-align:center">
          <p>Aucune fiche générée pour cette entreprise.</p>
          <p style="font-size:13px">Contactez l'administrateur pour générer votre fiche.</p>
        </div>

        <?php else: ?>

        <!-- ── Présentation (éditable gratuitement) ── -->
        <h3 style="margin-bottom:8px">
          ✏️ Votre présentation
          <span style="font-size:11px;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:10px;margin-left:6px;font-weight:normal">Gratuit</span>
        </h3>
        <form method="post" style="margin-bottom:20px">
          <?php wp_nonce_field('sc_save_intro'); ?>
          <textarea name="sc_intro_text" rows="4"
            placeholder="Rédigez une présentation de votre entreprise (3 phrases recommandées)..."
            style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px 14px;font-size:13px;resize:vertical;min-height:90px;font-family:inherit;margin-bottom:10px"><?= esc_textarea($intro) ?></textarea>
          <?php submit_button('💾 Enregistrer la présentation', 'primary', 'sc_save_intro', false); ?>
        </form>

        <?php if ($intro): ?>
        <div style="background:#f0f9ff;border-left:4px solid #3b82f6;padding:14px 18px;border-radius:0 8px 8px 0;margin-bottom:20px">
          <p style="margin:0;line-height:1.7;color:#1e3a5f"><?= esc_html($intro) ?></p>
          <?php if ($date): ?><div style="font-size:11px;color:#9ca3af;margin-top:6px;font-style:italic">Décryptage du <?= esc_html($date) ?></div><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($qa_answered)): ?>
        <h3>Analyse générée</h3>
        <?php foreach ($qa_answered as $item): ?>
        <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:10px 0;background:#fff">
          <div style="font-weight:600;margin-bottom:6px"><?= esc_html($item['q']) ?></div>
          <div style="color:#374151;line-height:1.6"><?= esc_html($item['r']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- Questions ouvertes -->
        <h3 style="margin-top:28px">
          Vos réponses
          <?php if (!$has_sub): ?>
          <span style="background:#6b7280;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:normal">
            Abonnement requis
          </span>
          <?php endif; ?>
        </h3>

        <?php if (!$has_sub): ?>
        <div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:16px 20px;margin-bottom:16px">
          <strong>🔒 Débloquez vos réponses</strong><br>
          Abonnez-vous pour répondre aux questions de votre fiche et enrichir votre profil.
          <br><br>
          <a href="<?= esc_url(get_permalink(wc_get_page_id('shop'))) ?>" class="button button-primary">
            Voir nos abonnements →
          </a>
        </div>
        <?php endif; ?>

        <form method="post" <?= !$has_sub ? 'style="pointer-events:none;opacity:.55"' : '' ?>>
          <?php wp_nonce_field('sc_save_answers'); ?>
          <?php foreach ($open_questions as $i => $q): ?>
          <div style="border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:10px 0;background:<?= !$has_sub ? '#f9fafb' : '#fff' ?>">
            <div style="font-weight:600;margin-bottom:8px"><?= esc_html($q) ?></div>
            <textarea name="sc_open_answers[<?= $i ?>]"
                      <?= !$has_sub ? 'disabled' : '' ?>
                      placeholder="Votre réponse..."
                      style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px 10px;font-size:13px;resize:vertical;min-height:80px;font-family:inherit"
            ><?= esc_textarea($saved_answers[$i] ?? '') ?></textarea>
          </div>
          <?php endforeach; ?>

          <?php if ($has_sub): ?>
          <?php submit_button('💾 Enregistrer mes réponses', 'primary', 'sc_save_answers'); ?>
          <?php endif; ?>
        </form>

        <?php endif; // fiche exists ?>
      </div>
      <?php endif; // company linked ?>
    </div>
    <?php
}

function sc_admin_logo(): string {
    $url = rtrim(get_option('societies_api_url', ''), '/') . '/static/logo.jpg';
    return '<img src="' . esc_url($url) . '" alt="TOPsocietes.com" style="height:36px;width:auto;vertical-align:middle;margin-right:10px">';
}

function sc_admin_dashboard() {
    $status = sc_api('/api/status');
    $stats  = sc_api('/api/fiches/stats');
    ?>
    <div class="wrap">
      <h1><?= sc_admin_logo() ?>Tableau de bord</h1>

      <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0">
        <?php
        $cards = [
            'Entreprises en base' => number_format((int)($status['rows'] ?? 0), 0, ',', ' '),
            'Fiches générées'     => number_format((int)($stats['done'] ?? 0), 0, ',', ' '),
            'Fiches en erreur'    => number_format((int)($stats['error'] ?? 0), 0, ',', ' '),
            'Fiches supprimées'   => number_format((int)($stats['deleted'] ?? 0), 0, ',', ' '),
        ];
        foreach ($cards as $label => $val): ?>
        <div style="border:1px solid #e5e7eb;border-radius:10px;padding:20px 28px;text-align:center;min-width:140px">
          <div style="font-size:28px;font-weight:700;color:#111"><?= $val ?></div>
          <div style="color:#6b7280;font-size:13px"><?= $label ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <h2>API Societies</h2>
      <p>URL : <code><?= esc_html(get_option('societies_api_url', 'http://societies:8090')) ?></code>
         — Statut : <?= isset($status['error']) ? '❌ ' . esc_html($status['error']) : '✅ ' . esc_html($status['message'] ?? 'OK') ?>
      </p>
      <a href="<?= admin_url('admin.php?page=societies-settings') ?>" class="button">⚙️ Réglages API</a>
      <a href="<?= admin_url('admin.php?page=societies-fiches') ?>" class="button" style="margin-left:8px">📋 Voir les fiches</a>
    </div>
    <?php
}

function sc_admin_settings() {
    $test_result = null;
    if (isset($_POST['sc_test']) && check_admin_referer('sc_test')) {
        delete_transient('sc_session_cookie');
        delete_transient('sc_csrf_token');
        $test_result = sc_api('/api/status');
    }
    ?>
    <div class="wrap">
      <h1><?= sc_admin_logo() ?>Réglages API</h1>

      <?php if ($test_result !== null): ?>
      <?php if (isset($test_result['error'])): ?>
      <div class="notice notice-error"><p>❌ <?= esc_html($test_result['error']) ?></p></div>
      <?php else: ?>
      <div class="notice notice-success"><p>✅ Connexion OK —
        <?= number_format($test_result['rows'] ?? 0, 0, ',', ' ') ?> entreprises</p></div>
      <?php endif; ?>
      <?php endif; ?>

      <?php $configured = get_option('societies_api_url') && get_option('societies_api_password'); ?>

      <?php if ($configured): ?>
      <!-- Connexion configurée — afficher masqué -->
      <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:16px 20px;margin-bottom:20px">
        <p style="margin:0 0 8px;font-weight:600;color:#166534">✅ Backend configuré</p>
        <p style="margin:0;color:#374151;font-size:13px">
          URL : <code><?= esc_html(preg_replace('/^(https?:\/\/)/', '$1***@', get_option('societies_api_url'))) ?></code><br>
          Identifiant : <code><?= esc_html(get_option('societies_api_username', 'admin')) ?></code><br>
          Mot de passe : <code>••••••••••••</code>
        </p>
        <button type="button" onclick="document.getElementById('sc-settings-form').style.display='block';this.style.display='none'"
          style="margin-top:12px;background:none;border:1px solid #d1d5db;border-radius:6px;padding:6px 14px;cursor:pointer;font-size:13px;color:#6b7280">
          🔧 Modifier les paramètres
        </button>
      </div>
      <div id="sc-settings-form" style="display:none">
      <?php else: ?>
      <div id="sc-settings-form">
      <?php endif; ?>

      <form method="post" action="options.php">
        <?php settings_fields('sc_options'); ?>
        <table class="form-table">
          <tr>
            <th>URL de l'API</th>
            <td>
              <input type="url" name="societies_api_url" class="regular-text"
                value="<?= esc_attr(get_option('societies_api_url', 'http://societies:8090')) ?>">
              <p class="description">Depuis Docker : <code>http://societies:8090</code></p>
            </td>
          </tr>
          <tr>
            <th>Identifiant admin</th>
            <td><input type="text" name="societies_api_username" class="regular-text"
              value="<?= esc_attr(get_option('societies_api_username', 'admin')) ?>"></td>
          </tr>
          <tr>
            <th>Mot de passe</th>
            <td>
              <input type="password" name="societies_api_password" class="regular-text"
                value="" autocomplete="new-password"
                placeholder="<?= get_option('societies_api_password') ? '••••••••••••  (laisser vide pour conserver)' : 'Entrez le mot de passe' ?>">
            </td>
          </tr>
        </table>
        <?php submit_button('Enregistrer'); ?>
      </form>
      </div>

      <hr style="margin:24px 0">
      <h2>Webhook secret (FastAPI → WordPress)</h2>
      <p class="description" style="margin-bottom:12px">Clé secrète utilisée par le backend FastAPI pour créer les pages WordPress automatiquement lors de la génération d'une fiche. Copiez-la dans <code>WP_API_PASSWORD</code> de votre fichier <code>.env</code>.</p>
      <form method="post" action="options.php">
        <?php settings_fields('sc_options'); ?>
        <table class="form-table">
          <tr>
            <th>Secret webhook</th>
            <td>
              <?php $wh_secret = get_option('societies_webhook_secret', ''); ?>
              <?php if ($wh_secret): ?>
              <code style="background:#f0f4ff;padding:6px 12px;border-radius:6px;font-size:13px;user-select:all"><?= esc_html($wh_secret) ?></code>
              <p class="description" style="margin-top:6px">Copiez cette valeur dans <code>WP_API_PASSWORD</code> dans le fichier <code>.env</code> du backend.</p>
              <?php else: ?>
              <p class="description">Aucun secret configuré — générez-en un ci-dessous.</p>
              <?php endif; ?>
              <input type="hidden" name="societies_webhook_secret" value="<?= esc_attr($wh_secret) ?>">
            </td>
          </tr>
        </table>
        <?php submit_button('Enregistrer le secret', 'secondary', 'submit', false); ?>
      </form>
      <form method="post">
        <?php wp_nonce_field('sc_gen_webhook_secret'); ?>
        <button name="sc_gen_webhook_secret" value="1" class="button button-primary" style="margin-top:4px">🔑 Générer un nouveau secret</button>
      </form>
      <?php
      if (isset($_POST['sc_gen_webhook_secret']) && check_admin_referer('sc_gen_webhook_secret')) {
          $new_secret = bin2hex(random_bytes(24));
          update_option('societies_webhook_secret', $new_secret);
          echo '<div class="notice notice-success" style="margin-top:12px"><p>✅ Nouveau secret généré : <code style="user-select:all">' . esc_html($new_secret) . '</code> — copiez-le dans <code>WP_API_PASSWORD</code>.</p></div>';
      }
      ?>

      <hr style="margin:24px 0">
      <h2>Mode sous-domaine</h2>
      <form method="post" action="options.php">
        <?php settings_fields('sc_options'); ?>
        <table class="form-table">
          <tr>
            <th>Activer le mode sous-domaine</th>
            <td>
              <label>
                <input type="checkbox" name="societies_subdomain_mode" value="1"
                  <?php checked(1, get_option('societies_subdomain_mode')); ?>>
                Masquer le header/footer du thème et afficher le footer TOPsocietes
              </label>
              <p class="description">À activer sur le sous-domaine fiches. Cache le menu de navigation du thème et injecte un footer personnalisé.</p>
            </td>
          </tr>
          <tr>
            <th>Liens du footer <span style="font-weight:400;font-size:12px">(JSON)</span></th>
            <td>
              <textarea name="societies_footer_links" rows="6" class="large-text code"
                placeholder='[{"label":"Accueil","url":"/"},{"label":"Mentions légales","url":"/mentions-legales"}]'
              ><?= esc_textarea(get_option('societies_footer_links', '')) ?></textarea>
              <p class="description">Format JSON : <code>[{"label":"Texte","url":"https://..."}]</code>. Laisser vide pour les liens par défaut.</p>
            </td>
          </tr>
        </table>
        <?php submit_button('Enregistrer le mode sous-domaine'); ?>
      </form>

      <form method="post">
        <?php wp_nonce_field('sc_test'); ?>
        <button type="submit" name="sc_test" class="button button-secondary">🔌 Tester la connexion</button>
      </form>
    </div>
    <?php
}

function sc_admin_fiches() {
    $tab = sanitize_key($_GET['tab'] ?? 'list');

    // ── Action : migrer les slugs existants vers /[ville]/[metier]/[nom]/ ──────
    $migrate_notice = null;
    if (isset($_POST['sc_migrate_slugs']) && check_admin_referer('sc_migrate_slugs')) {
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => ['publish', 'draft'],
            'meta_key'       => '_sc_company_title',
            'posts_per_page' => -1,
            'post_parent'    => 0,
        ]);
        $migrated = 0;
        $skipped  = 0;
        foreach ($pages as $p) {
            $title     = get_post_meta($p->ID, '_sc_company_title', true);
            if (!$title) { $skipped++; continue; }
            $parent_id = sc_resolve_page_parent($title);
            if (!$parent_id) { $skipped++; continue; }
            wp_update_post(['ID' => $p->ID, 'post_parent' => $parent_id]);
            $migrated++;
        }
        $migrate_notice = ['type' => 'success',
            'msg' => "{$migrated} pages migrées vers /ville/métier/nom/, {$skipped} ignorées (ville ou catégorie introuvable)."];
    }

    // ── Action : créer toutes les pages manquantes en bulk ────────────────────
    $bulk_notice = null;
    if (isset($_POST['sc_create_all_pages']) && check_admin_referer('sc_create_all_pages')) {
        $created = 0;
        $skipped = 0;
        $page    = 1;
        do {
            $fiches = sc_api('/api/fiches?per_page=100&page=' . $page);
            if (!empty($fiches['error'])) {
                $bulk_notice = ['type' => 'error', 'msg' => 'Erreur API : ' . esc_html($fiches['error'])];
                break;
            }
            $items = $fiches['results'] ?? $fiches['items'] ?? [];
            if (empty($items)) break;
            foreach ($items as $f) {
                $title = $f['company_title'] ?? '';
                if (!$title) { $skipped++; continue; }
                $existing = get_posts([
                    'post_type'   => 'page',
                    'post_status' => ['publish', 'draft'],
                    'meta_key'    => '_sc_company_title',
                    'meta_value'  => $title,
                    'numberposts' => 1,
                ]);
                if ($existing) { $skipped++; continue; }
                $post_id = wp_insert_post([
                    'post_title'   => sanitize_text_field($title),
                    'post_name'    => sanitize_title($title),
                    'post_content' => '[societies_fiche title="' . esc_attr($title) . '"]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_parent'  => sc_resolve_page_parent($title),
                ]);
                if (!is_wp_error($post_id)) {
                    update_post_meta($post_id, '_sc_company_title', $title);
                    $created++;
                } else {
                    $skipped++;
                }
            }
            $page++;
        } while (!empty($items) && count($items) === 100);
        $bulk_notice = ['type' => 'success', 'msg' => "{$created} pages créées, {$skipped} ignorées (déjà existantes)."];
    }

    // ── Action : créer une page WordPress pour la fiche ───────────────────────
    $page_notice = null;
    if (isset($_POST['sc_create_page']) && check_admin_referer('sc_create_page')) {
        $company_title = sanitize_text_field($_POST['sc_company_title'] ?? '');
        if ($company_title) {
            $existing = get_posts([
                'post_type'   => 'page',
                'post_status' => ['publish', 'draft'],
                'meta_key'    => '_sc_company_title',
                'meta_value'  => $company_title,
                'numberposts' => 1,
            ]);
            if ($existing) {
                $page_notice = ['type' => 'warning',
                    'msg'      => "Une page existe déjà pour « {$company_title} ».",
                    'edit_url' => get_edit_post_link($existing[0]->ID),
                    'view_url' => get_permalink($existing[0]->ID)];
            } else {
                $post_id = wp_insert_post([
                    'post_title'   => sanitize_text_field($company_title),
                    'post_name'    => sanitize_title($company_title),
                    'post_content' => '[societies_fiche title="' . esc_attr($company_title) . '"]',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_parent'  => sc_resolve_page_parent($company_title),
                ]);
                if (!is_wp_error($post_id)) {
                    update_post_meta($post_id, '_sc_company_title', $company_title);
                    $page_notice = ['type' => 'success',
                        'msg'      => "Page créée pour « {$company_title} ».",
                        'edit_url' => get_edit_post_link($post_id),
                        'view_url' => get_permalink($post_id)];
                } else {
                    $page_notice = ['type' => 'error', 'msg' => 'Erreur lors de la création de la page.'];
                }
            }
        }
    }

    // ── Action : générer une fiche ────────────────────────────────────────────
    $generate_notice = null;
    if ($tab === 'search' && isset($_POST['sc_generate']) && check_admin_referer('sc_generate')) {
        $title  = sanitize_text_field($_POST['sc_company_title'] ?? '');
        $result = sc_api('/api/generate', 'POST', ['title' => $title]);
        if (isset($result['error'])) {
            $generate_notice = ['type' => 'error', 'msg' => $result['error']];
        } elseif (isset($result['status'])) {
            $generate_notice = ['type' => 'success',
                'msg' => "Fiche générée pour « {$title} » (statut : {$result['status']})"];
        } else {
            $generate_notice = ['type' => 'warning', 'msg' => 'Réponse inattendue de l\'API.'];
        }
    }

    $tabs = [
        'list'   => '📋 Fiches générées',
        'search' => '🔍 Rechercher & Générer',
    ];
    ?>
    <div class="wrap">
      <h1><?= sc_admin_logo() ?>Fiches entreprises</h1>

      <nav class="nav-tab-wrapper" style="margin-bottom:20px">
        <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= admin_url("admin.php?page=societies-fiches&tab={$key}") ?>"
           class="nav-tab <?= $tab === $key ? 'nav-tab-active' : '' ?>">
          <?= $label ?>
        </a>
        <?php endforeach; ?>
      </nav>

      <?php if ($generate_notice): ?>
      <div class="notice notice-<?= $generate_notice['type'] ?> is-dismissible">
        <p><?= esc_html($generate_notice['msg']) ?></p>
      </div>
      <?php endif; ?>

      <?php if ($bulk_notice): ?>
      <div class="notice notice-<?= $bulk_notice['type'] ?> is-dismissible" style="padding:12px 16px">
        <p style="margin:0"><?= esc_html($bulk_notice['msg']) ?></p>
      </div>
      <?php endif; ?>

      <?php if ($page_notice): ?>
      <div class="notice notice-<?= $page_notice['type'] ?> is-dismissible" style="padding:12px 16px">
        <p style="margin:0"><?= esc_html($page_notice['msg']) ?>
          <?php if (!empty($page_notice['view_url'])): ?>
          — <a href="<?= esc_url($page_notice['view_url']) ?>" target="_blank">Voir la page →</a>
          &nbsp;<a href="<?= esc_url($page_notice['edit_url']) ?>">Modifier</a>
          <?php endif; ?>
        </p>
      </div>
      <?php endif; ?>

      <?php if ($tab === 'list'): ?>
      <?php if ($migrate_notice): ?>
      <div class="notice notice-<?= $migrate_notice['type'] ?> is-dismissible"><p><?= esc_html($migrate_notice['msg']) ?></p></div>
      <?php endif; ?>

      <div style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <button id="sc-bulk-btn" class="button button-primary" onclick="scBulkStart()">
          📄 Créer toutes les pages manquantes
        </button>
        <form method="post" style="display:inline"
              onsubmit="return confirm('Migrer les slugs des pages existantes vers /ville/métier/nom/ ? Cette opération est irréversible.')">
          <?php wp_nonce_field('sc_migrate_slugs'); ?>
          <button name="sc_migrate_slugs" value="1" class="button">
            🔀 Migrer les slugs existants → /ville/métier/nom/
          </button>
        </form>
        <span id="sc-bulk-status" style="font-size:13px;color:#666"></span>
        <div id="sc-bulk-bar" style="display:none;background:#e5e7eb;border-radius:4px;height:8px;width:400px;max-width:100%">
          <div id="sc-bulk-fill" style="background:#1a2744;height:8px;border-radius:4px;width:0%;transition:width .3s"></div>
        </div>
      </div>
      <script>
      var scBulkNonce = '<?= wp_create_nonce('sc_bulk_create') ?>';
      var scBulkTotal = 0, scBulkCreated = 0, scBulkSkipped = 0;
      function scBulkStart() {
        if (!confirm('Créer toutes les pages manquantes ? Cela peut prendre du temps.')) return;
        document.getElementById('sc-bulk-btn').disabled = true;
        document.getElementById('sc-bulk-bar').style.display = 'block';
        scBulkTotal = 0; scBulkCreated = 0; scBulkSkipped = 0;
        scBulkBatch(1);
      }
      function scBulkBatch(page) {
        document.getElementById('sc-bulk-status').textContent = 'Traitement page ' + page + '…';
        var fd = new FormData();
        fd.append('action', 'sc_bulk_create_batch');
        fd.append('nonce', scBulkNonce);
        fd.append('api_page', page);
        fetch(ajaxurl, {method:'POST', body:fd})
          .then(r => r.json())
          .then(function(res) {
            if (!res.success) {
              document.getElementById('sc-bulk-status').textContent = '❌ Erreur : ' + res.data;
              document.getElementById('sc-bulk-btn').disabled = false;
              return;
            }
            var d = res.data;
            if (!scBulkTotal && d.total) scBulkTotal = d.total;
            scBulkCreated += d.created;
            scBulkSkipped += d.skipped;
            var pct = scBulkTotal ? Math.min(100, Math.round(d.done_up_to / scBulkTotal * 100)) : 0;
            document.getElementById('sc-bulk-fill').style.width = pct + '%';
            document.getElementById('sc-bulk-status').textContent =
              scBulkCreated + ' créées, ' + scBulkSkipped + ' ignorées (' + pct + '%)';
            if (d.has_more) {
              setTimeout(function(){ scBulkBatch(d.next_page); }, 200);
            } else {
              document.getElementById('sc-bulk-status').textContent =
                '✅ Terminé — ' + scBulkCreated + ' pages créées, ' + scBulkSkipped + ' ignorées.';
              document.getElementById('sc-bulk-btn').disabled = false;
            }
          })
          .catch(function(e) {
            document.getElementById('sc-bulk-status').textContent = '❌ Erreur réseau';
            document.getElementById('sc-bulk-btn').disabled = false;
          });
      }
      </script>
      <?php endif; ?>

      <?php if ($tab === 'list'): ?>
        <?php
        $page     = max(1, intval($_GET['paged']    ?? 1));
        $q        = sanitize_text_field($_GET['q']   ?? '');
        $sort_by  = in_array($_GET['sort_by'] ?? '', ['rating', 'generated_at'], true) ? $_GET['sort_by'] : 'generated_at';
        $sort_dir = ($_GET['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $data     = sc_api("/api/fiches?page={$page}&per_page=25&sort_by={$sort_by}&sort_dir={$sort_dir}" . ($q ? '&q=' . urlencode($q) : ''));

        // URL de base pour les liens de tri (sans paged ni sort)
        $sort_base = admin_url('admin.php?page=societies-fiches&tab=list' . ($q ? '&q=' . urlencode($q) : ''));
        $note_dir  = ($sort_by === 'rating' && $sort_dir === 'desc') ? 'asc' : 'desc';
        $note_url  = $sort_base . '&sort_by=rating&sort_dir=' . $note_dir;
        $note_icon = $sort_by === 'rating' ? ($sort_dir === 'asc' ? ' ▲' : ' ▼') : '';
        ?>
        <form method="get" style="margin-bottom:16px">
          <input type="hidden" name="page" value="societies-fiches">
          <input type="hidden" name="tab"  value="list">
          <input type="search" name="q" value="<?= esc_attr($q) ?>"
                 placeholder="Rechercher une entreprise..." style="width:300px;margin-right:8px">
          <?php submit_button('Rechercher', 'secondary', '', false); ?>
        </form>

        <?php if (!empty($data['results'])): ?>
        <p style="color:#6b7280">
          <?= number_format($data['total'] ?? 0, 0, ',', ' ') ?> fiches —
          page <?= $data['page'] ?>/<?= $data['pages'] ?>
        </p>
        <table class="wp-list-table widefat fixed striped">
          <thead>
            <tr>
              <th style="width:30%">Entreprise</th>
              <th>Catégorie</th>
              <th>Ville</th>
              <th><a href="<?= esc_url($note_url) ?>" style="text-decoration:none;color:inherit">Note<?= $note_icon ?></a></th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['results'] as $f):
              $company = sc_api('/api/company/' . rawurlencode($f['company_title']));
              // Vérifier si une page WP existe déjà
              $wp_pages = get_posts([
                  'post_type'   => 'page',
                  'post_status' => ['publish', 'draft'],
                  'meta_key'    => '_sc_company_title',
                  'meta_value'  => $f['company_title'],
                  'numberposts' => 1,
              ]);
              $existing_page = $wp_pages[0] ?? null;
            ?>
            <tr>
              <td><strong><?= esc_html($f['company_title']) ?></strong></td>
              <td><?= esc_html($company['category'] ?? '—') ?></td>
              <td><?= esc_html($company['city'] ?? '—') ?></td>
              <td><?= ($company['rating_value'] ?? 0) ? '★ ' . number_format((float)$company['rating_value'], 1) : '—' ?></td>
              <td style="white-space:nowrap">
                <form method="post" style="display:inline">
                  <input type="hidden" name="page" value="societies-fiches">
                  <input type="hidden" name="tab"  value="<?= esc_attr($tab) ?>">
                  <?php wp_nonce_field('sc_generate'); ?>
                  <input type="hidden" name="sc_company_title"
                         value="<?= esc_attr($f['company_title']) ?>">
                  <button type="submit" name="sc_generate" value="1"
                          class="button button-small"
                          onclick="return confirm('Regénérer la fiche de « <?= esc_js($f['company_title']) ?> » ?')">
                    🔄 Regénérer
                  </button>
                </form>
                <?php if ($existing_page): ?>
                <a href="<?= esc_url(get_permalink($existing_page->ID)) ?>" target="_blank"
                   class="button button-small" style="margin-left:4px">👁 Voir</a>
                <?php else: ?>
                <form method="post" style="display:inline;margin-left:4px">
                  <input type="hidden" name="page" value="societies-fiches">
                  <input type="hidden" name="tab"  value="list">
                  <?php wp_nonce_field('sc_create_page'); ?>
                  <input type="hidden" name="sc_company_title"
                         value="<?= esc_attr($f['company_title']) ?>">
                  <button type="submit" name="sc_create_page" value="1"
                          class="button button-small button-primary">
                    📄 Créer page
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php
        // Pagination
        $total_pages = (int)($data['pages'] ?? 1);
        if ($total_pages > 1):
            $base_url = admin_url("admin.php?page=societies-fiches&tab=list"
                . ($q       ? '&q='        . urlencode($q)       : '')
                . ($sort_by !== 'generated_at' ? '&sort_by=' . urlencode($sort_by) : '')
                . ($sort_dir !== 'desc'         ? '&sort_dir=' . urlencode($sort_dir) : '')
                . '&paged=%#%');
        ?>
        <div class="tablenav bottom" style="margin-top:12px">
          <div class="tablenav-pages">
            <span class="displaying-num"><?= number_format($data['total'] ?? 0, 0, ',', ' ') ?> fiches</span>
            <?php echo paginate_links([
                'base'      => $base_url,
                'format'    => '',
                'current'   => $page,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'type'      => 'plain',
            ]); ?>
          </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:32px;text-align:center;color:#6b7280">
          <p style="font-size:16px">Aucune fiche générée.</p>
          <a href="<?= admin_url('admin.php?page=societies-fiches&tab=search') ?>"
             class="button button-primary">🔍 Rechercher des entreprises →</a>
        </div>
        <?php endif; ?>

      <?php elseif ($tab === 'search'): ?>
        <?php
        $sq      = sanitize_text_field($_GET['sq'] ?? '');
        $results = [];
        if ($sq) {
            $res     = sc_api('/api/search?q=' . urlencode($sq) . '&per_page=20');
            $results = $res['results'] ?? [];
        }
        ?>
        <p style="color:#6b7280;margin-bottom:16px">
          Recherchez une entreprise dans la base de données, puis générez ou regénérez sa fiche IA.
        </p>

        <form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:24px">
          <input type="hidden" name="page" value="societies-fiches">
          <input type="hidden" name="tab"  value="search">
          <input type="search" name="sq" value="<?= esc_attr($sq) ?>"
                 placeholder="Nom de l'entreprise, ville..."
                 style="width:360px;padding:6px 10px;font-size:14px" autofocus>
          <?php submit_button('Rechercher', 'primary', '', false); ?>
        </form>

        <?php if ($sq && empty($results)): ?>
        <p>Aucun résultat pour « <?= esc_html($sq) ?> ».</p>

        <?php elseif (!empty($results)): ?>
        <p style="color:#6b7280"><?= count($results) ?> résultats</p>
        <table class="wp-list-table widefat fixed striped">
          <thead>
            <tr>
              <th style="width:35%">Entreprise</th>
              <th>Catégorie</th>
              <th>Ville</th>
              <th>Note</th>
              <th style="width:18%">Fiche IA</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $c):
                $fiche  = sc_api('/api/fiche/' . rawurlencode($c['title']));
                $status = $fiche['status'] ?? 'none';
                $badge  = match($status) {
                    'done'    => '<span style="color:#10b981;font-weight:600">✅ Générée</span>',
                    'error'   => '<span style="color:#ef4444;font-weight:600">❌ Erreur</span>',
                    'pending' => '<span style="color:#f59e0b;font-weight:600">⏳ En cours</span>',
                    default   => '<span style="color:#9ca3af">— Aucune</span>',
                };
            ?>
            <tr>
              <td><strong><?= esc_html($c['title']) ?></strong></td>
              <td><?= esc_html($c['category'] ?? '—') ?></td>
              <td><?= esc_html($c['city'] ?? '—') ?></td>
              <td><?= $c['rating_value'] ? '★ ' . number_format($c['rating_value'], 1) : '—' ?></td>
              <td>
                <?= $badge ?><br>
                <form method="post" style="margin-top:6px">
                  <input type="hidden" name="page" value="societies-fiches">
                  <input type="hidden" name="tab"  value="search">
                  <input type="hidden" name="sq"   value="<?= esc_attr($sq) ?>">
                  <?php wp_nonce_field('sc_generate'); ?>
                  <input type="hidden" name="sc_company_title" value="<?= esc_attr($c['title']) ?>">
                  <button type="submit" name="sc_generate" value="1"
                          class="button button-primary button-small">
                    <?= $status === 'done' ? '🔄 Regénérer' : '✨ Générer la fiche' ?>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

      <?php endif; ?>
    </div>
    <?php
}

// =============================================================================
// PAGE ADMIN — MODÉRATION DES MODIFICATIONS
// =============================================================================
function sc_admin_moderation() {
    // ── Actions Approuver / Rejeter ──────────────────────────────────────────
    if (isset($_POST['sc_mod_approve']) && check_admin_referer('sc_mod_action')) {
        $mod_id      = intval($_POST['sc_mod_id'] ?? 0);
        $mod_company = sanitize_text_field($_POST['sc_mod_company'] ?? '');
        $mod_field   = sanitize_text_field($_POST['sc_mod_field']   ?? '');

        // Lire la valeur actuelle depuis l'API AVANT d'approuver
        $fiche_before = $mod_company ? sc_api('/api/fiche/' . rawurlencode($mod_company)) : [];
        $cur_val = '';
        if ($mod_field === 'intro_text') {
            $cur_val = $fiche_before['intro_text'] ?? '';
        }

        // Approuver via l'API — les données viennent du backend
        $result = sc_api('/api/modifications/' . $mod_id . '/approve', 'PUT');
        if (!isset($result['error'])) {
            $email      = $result['user_email']    ?? '';
            $company    = $result['company_title'] ?? $mod_company;
            $field_name = $result['field_name']    ?? $mod_field;
            $new_val    = $result['field_value']   ?? '';
            if ($email) {
                $site_name   = get_bloginfo('name') ?: 'TOPsocietes.com';
                $fiche_url   = sc_get_fiche_url($company);
                $field_label = ($field_name === 'intro_text') ? 'Présentation' : 'Réponses aux questions';
                $subject = $site_name . ' — Votre modification a été validée';
                $message  = "Bonjour,\n\n";
                $message .= "Votre modification pour la fiche « {$company} » a été validée et est maintenant visible sur {$site_name}.\n\n";
                $message .= "👉 Voir votre fiche : {$fiche_url}\n\n";
                if ($field_name) {
                    $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                    $message .= "Champ modifié : {$field_label}\n\n";
                    if ($cur_val) {
                        $message .= "Avant :\n" . mb_substr($cur_val, 0, 300) . (mb_strlen($cur_val) > 300 ? '…' : '') . "\n\n";
                    }
                    if ($new_val) {
                        $message .= "Après :\n" . mb_substr($new_val, 0, 300) . (mb_strlen($new_val) > 300 ? '…' : '') . "\n";
                    }
                    $message .= "━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                }
                $message .= "Cordialement,\nL'équipe {$site_name}";
                wp_mail($email, $subject, $message);
            }
            echo '<div class="notice notice-success"><p>✅ Modification validée' . ($email ? " — email envoyé à <strong>" . esc_html($email) . "</strong>" : '') . '.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
        }
    }

    if (isset($_POST['sc_mod_reject']) && check_admin_referer('sc_mod_action')) {
        $mod_id = intval($_POST['sc_mod_id'] ?? 0);
        $reason = sanitize_text_field($_POST['sc_mod_reason'] ?? '');
        $result = sc_api('/api/modifications/' . $mod_id . '/reject', 'PUT', ['reason' => $reason]);
        if (!isset($result['error'])) {
            echo '<div class="notice notice-warning"><p>🚫 Modification rejetée.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
        }
    }

    // ── Affichage ─────────────────────────────────────────────────────────────
    $filter  = sanitize_text_field($_GET['status'] ?? 'pending');
    $page    = max(1, intval($_GET['mod_page'] ?? 1));
    $data    = sc_api('/api/modifications?status=' . urlencode($filter) . '&page=' . $page . '&per_page=20');
    $mods    = $data['results'] ?? [];
    $total   = $data['total']   ?? 0;
    $pages   = max(1, (int)ceil($total / 20));

    $status_labels = ['pending' => '⏳ En attente', 'approved' => '✅ Validées', 'rejected' => '🚫 Rejetées'];
    ?>
    <div class="wrap">
      <h1>📝 Modération des fiches</h1>

      <!-- Filtres statut -->
      <ul class="subsubsub" style="margin-bottom:16px">
        <?php foreach ($status_labels as $s => $label): ?>
        <?php $count_data = sc_api('/api/modifications?status=' . $s . '&per_page=1'); ?>
        <li>
          <a href="<?= admin_url('admin.php?page=societies-moderation&status=' . $s) ?>"
             <?= $filter === $s ? 'style="font-weight:700"' : '' ?>>
            <?= $label ?> <span class="count">(<?= $count_data['total'] ?? 0 ?>)</span>
          </a>
          <?= $s !== 'rejected' ? ' |' : '' ?>
        </li>
        <?php endforeach; ?>
      </ul>

      <?php if (empty($mods)): ?>
      <p style="color:#6b7280">Aucune modification <?= esc_html(strtolower($status_labels[$filter] ?? $filter)) ?>.</p>
      <?php else: ?>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>Entreprise</th>
            <th>Type</th>
            <th>Modification (actuel → proposé)</th>
            <th>Email</th>
            <th>Date</th>
            <?php if ($filter === 'pending'): ?><th>Actions</th><?php endif; ?>
            <?php if ($filter === 'rejected'): ?><th>Raison</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mods as $mod): ?>
          <?php
          $fiche       = sc_api('/api/fiche/' . urlencode($mod['company_title']));
          $field       = $mod['field_name'];
          $new_val     = $mod['field_value'];
          $current_raw = '';
          if ($field === 'intro_text') {
              $current_raw = $fiche['intro_text'] ?? '';
          } elseif ($field === 'open_answers') {
              $current_raw = $fiche['qa_open'] ?? '[]';
          }
          ?>
          <tr>
            <td>
              <?php $mod_fiche_url = sc_get_fiche_url($mod['company_title']); ?>
              <?php if ($mod_fiche_url !== home_url('/')): ?>
              <a href="<?= esc_url($mod_fiche_url) ?>" target="_blank" rel="noopener" style="font-weight:600;color:#1e2d5a;text-decoration:none">
                <?= esc_html($mod['company_title']) ?> ↗
              </a>
              <?php else: ?>
              <strong><?= esc_html($mod['company_title']) ?></strong>
              <?php endif; ?>
            </td>
            <td><?= $field === 'intro_text' ? 'Présentation' : 'Réponses' ?></td>
            <td style="max-width:400px;font-size:12px;word-break:break-word">
              <?php if ($field === 'intro_text'): ?>
                <?php if ($current_raw): ?>
                <div style="background:#f3f4f6;border-left:3px solid #9ca3af;padding:6px 8px;margin-bottom:6px;color:#6b7280">
                  <span style="font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.5px">Actuel</span><br>
                  <?= esc_html(mb_substr($current_raw, 0, 200)) ?><?= mb_strlen($current_raw) > 200 ? '…' : '' ?>
                </div>
                <?php endif; ?>
                <div style="background:#f0fdf4;border-left:3px solid #22c55e;padding:6px 8px;color:#166534">
                  <span style="font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.5px">Proposé</span><br>
                  <?= esc_html(mb_substr($new_val, 0, 200)) ?><?= mb_strlen($new_val) > 200 ? '…' : '' ?>
                </div>
              <?php else: ?>
                <?php
                $current_answers = json_decode($current_raw, true) ?: [];
                $new_answers     = json_decode($new_val, true) ?: [];
                foreach ($new_answers as $i => $item):
                    $q       = esc_html($item['q'] ?? '');
                    $new_r   = $item['r'] ?? '';
                    $cur_r   = $current_answers[$i]['r'] ?? '';
                ?>
                <div style="margin-bottom:8px">
                  <div style="font-weight:600;color:#374151;margin-bottom:2px"><?= $q ?></div>
                  <?php if ($cur_r): ?>
                  <div style="background:#f3f4f6;border-left:3px solid #9ca3af;padding:4px 8px;color:#6b7280;margin-bottom:2px">
                    <span style="font-size:10px;font-weight:600;text-transform:uppercase">Actuel</span> <?= esc_html(mb_substr($cur_r, 0, 120)) ?>
                  </div>
                  <?php endif; ?>
                  <?php if ($new_r): ?>
                  <div style="background:#f0fdf4;border-left:3px solid #22c55e;padding:4px 8px;color:#166534">
                    <span style="font-size:10px;font-weight:600;text-transform:uppercase">Proposé</span> <?= esc_html(mb_substr($new_r, 0, 120)) ?>
                  </div>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td style="font-size:12px"><?= esc_html($mod['user_email'] ?? '—') ?></td>
            <td style="font-size:12px"><?= esc_html(substr($mod['submitted_at'] ?? '', 0, 16)) ?></td>
            <?php if ($filter === 'pending'): ?>
            <td style="white-space:nowrap">
              <?php if ($mod_fiche_url !== home_url('/')): ?>
              <a href="<?= esc_url($mod_fiche_url) ?>" target="_blank" rel="noopener"
                 class="button button-small" style="margin-bottom:6px;display:inline-block">
                👁 Voir la fiche
              </a><br>
              <?php endif; ?>
              <form method="post" style="display:inline">
                <?php wp_nonce_field('sc_mod_action'); ?>
                <input type="hidden" name="sc_mod_id"      value="<?= intval($mod['id']) ?>">
                <input type="hidden" name="sc_mod_company" value="<?= esc_attr($mod['company_title'] ?? '') ?>">
                <input type="hidden" name="sc_mod_field"   value="<?= esc_attr($mod['field_name']    ?? '') ?>">
                <button name="sc_mod_approve" value="1" class="button button-primary button-small">✅ Valider</button>
              </form>
              <form method="post" style="display:inline;margin-left:4px"
                    onsubmit="var r=prompt('Raison du refus (optionnel):','');if(r!==null)this.sc_mod_reason.value=r;else return false;">
                <?php wp_nonce_field('sc_mod_action'); ?>
                <input type="hidden" name="sc_mod_id" value="<?= intval($mod['id']) ?>">
                <input type="hidden" name="sc_mod_reason" value="">
                <button name="sc_mod_reject" value="1" class="button button-small" style="color:#ef4444">🚫 Refuser</button>
              </form>
            </td>
            <?php endif; ?>
            <?php if ($filter === 'rejected'): ?>
            <td style="font-size:12px;color:#ef4444"><?= esc_html($mod['rejection_reason'] ?? '—') ?></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($pages > 1): ?>
      <div style="margin-top:12px">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
        <a href="<?= admin_url('admin.php?page=societies-moderation&status=' . $filter . '&mod_page=' . $p) ?>"
           class="button button-small" <?= $p === $page ? 'style="font-weight:700"' : '' ?>>
          <?= $p ?>
        </a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
}

function sc_admin_subscriptions() {
    $users_with_sub = [];
    if (function_exists('wc_get_orders')) {
        $orders = wc_get_orders(['limit' => 50, 'status' => ['completed', 'processing']]);
        foreach ($orders as $order) {
            $uid  = $order->get_customer_id();
            $user = get_userdata($uid);
            if ($user) {
                $company = get_user_meta($uid, 'sc_company_title', true);
                $users_with_sub[$uid] = [
                    'email'   => $user->user_email,
                    'company' => $company ?: '(non associée)',
                    'plan'    => implode(', ', array_map(fn($i) => $i->get_name(), $order->get_items())),
                    'date'    => $order->get_date_created()?->format('d/m/Y'),
                    'total'   => strip_tags($order->get_formatted_order_total()),
                ];
            }
        }
    }
    ?>
    <div class="wrap">
      <h1><?= sc_admin_logo() ?>Abonnements & Clients</h1>

      <h2>Plans disponibles</h2>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr><th>Plan</th><th>Prix</th><th>Inclus</th><th>Produit WooCommerce</th></tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>Basic</strong></td>
            <td>19,90 €</td>
            <td>Compléter les 6 questions verrouillées</td>
            <td><a href="<?= admin_url('edit.php?post_type=product') ?>">Gérer →</a></td>
          </tr>
          <tr>
            <td><strong>Pro</strong></td>
            <td>49,90 €</td>
            <td>Fiche complète + mise en avant + stats</td>
            <td><a href="<?= admin_url('edit.php?post_type=product') ?>">Gérer →</a></td>
          </tr>
        </tbody>
      </table>

      <h2 style="margin-top:32px">Clients abonnés (<?= count($users_with_sub) ?>)</h2>
      <?php if ($users_with_sub): ?>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr><th>Email</th><th>Entreprise associée</th><th>Plan</th><th>Date</th><th>Montant</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users_with_sub as $uid => $info): ?>
          <tr>
            <td><?= esc_html($info['email']) ?></td>
            <td><?= esc_html($info['company']) ?></td>
            <td><?= esc_html($info['plan']) ?></td>
            <td><?= esc_html($info['date']) ?></td>
            <td><?= esc_html($info['total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <p>Aucun client abonné pour l'instant.</p>
      <?php endif; ?>
    </div>
    <?php
}

// =============================================================================
// REST API PROXY (évite les problèmes CORS côté frontend)
// =============================================================================
add_action('rest_api_init', function() {
    register_rest_route('societies/v1', '/search', [
        'methods'             => 'GET',
        'callback'            => fn($r) => sc_api('/api/search?' . http_build_query($r->get_query_params())),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('societies/v1', '/fiche/(?P<title>.+)', [
        'methods'             => 'GET',
        'callback'            => fn($r) => sc_api('/api/fiche/' . rawurlencode($r['title'])),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('societies/v1', '/stats', [
        'methods'             => 'GET',
        'callback'            => fn() => sc_api('/api/fiches/stats'),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('societies/v1', '/seo/sector/(?P<sector>.+)', [
        'methods'             => 'GET',
        'callback'            => fn($r) => sc_api('/api/seo/sector/' . rawurlencode($r['sector'])
                                              . '?limit=' . intval($r->get_param('limit') ?: 20)),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('societies/v1', '/seo/city/(?P<city>.+)', [
        'methods'             => 'GET',
        'callback'            => fn($r) => sc_api('/api/seo/city/' . rawurlencode($r['city'])
                                              . '?limit=' . intval($r->get_param('limit') ?: 20)),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('societies/v1', '/seo/top-sectors', [
        'methods'             => 'GET',
        'callback'            => fn() => sc_api('/api/seo/top-sectors'),
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('societies/v1', '/seo/top-cities', [
        'methods'             => 'GET',
        'callback'            => fn() => sc_api('/api/seo/top-cities'),
        'permission_callback' => '__return_true',
    ]);
    // Résoudre le permalink d'une fiche par son titre — utilisé par [societies_home]
    register_rest_route('societies/v1', '/page-url', [
        'methods'             => 'GET',
        'callback'            => function($r) {
            $title = sanitize_text_field($r->get_param('title') ?? '');
            if (!$title) return ['url' => null];
            $pages = get_posts([
                'post_type'   => 'page',
                'name'        => sanitize_title($title),
                'numberposts' => 1,
                'post_status' => 'publish',
            ]);
            return ['url' => $pages ? get_permalink($pages[0]->ID) : null];
        },
        'permission_callback' => '__return_true',
    ]);
});

// =============================================================================
// STYLES FRONTEND
// =============================================================================
function sc_enqueue_styles() {
    static $done = false;
    if ($done) return;
    $done = true;
    echo '<style>
    .sc-dashboard{max-width:720px;font-family:inherit}
    .sc-form{margin-top:16px}
    .sc-input{width:100%;max-width:420px;padding:10px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-bottom:12px;display:block}
    .sc-btn{background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 22px;font-size:14px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
    .sc-btn:hover{background:#1d4ed8;color:#fff}
    .sc-intro{background:#f0f9ff;border-left:4px solid #3b82f6;padding:14px 18px;border-radius:0 8px 8px 0;margin:16px 0}
    .sc-intro p{margin:0;line-height:1.7;color:#1e3a5f}
    .sc-date{font-size:11px;color:#9ca3af;margin-top:6px;font-style:italic}
    .sc-qa-item{border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin:10px 0;background:#fff}
    .sc-q{font-weight:600;margin-bottom:6px;color:#111}
    .sc-r{color:#374151;line-height:1.6}
    .sc-locked{background:#f9fafb}
    .sc-textarea{width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px 10px;font-size:13px;resize:vertical;min-height:80px;margin-top:8px;font-family:inherit}
    .sc-badge-locked{background:#6b7280;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:normal}
    .sc-sub-banner{background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:16px 20px;margin:16px 0}
    .sc-success{background:#d1fae5;border:1px solid #10b981;border-radius:8px;padding:12px 16px;margin:12px 0;color:#065f46}
    .sc-error{background:#fee2e2;border:1px solid #ef4444;border-radius:8px;padding:12px 16px;margin:12px 0;color:#7f1d1d}
    .sc-mod-pending{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin:12px 0;color:#1e40af;font-size:13px}
    .sc-mod-item{margin-top:6px;padding-left:12px;color:#3b82f6;font-size:12px}
    .sc-mod-info{background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 14px;color:#166534;font-size:13px;margin:10px 0}
    .sc-mod-note{font-size:12px;color:#6b7280;margin:10px 0 4px;font-style:italic}
    </style>';
}

add_action('wp_head', 'sc_enqueue_styles');

// =============================================================================
// ADMIN PAGE — THÈME (téléchargement ZIP)
// =============================================================================
function sc_admin_theme() {
    if (!current_user_can('manage_options')) return;

    $theme_dir  = get_theme_root() . '/topsocietes-theme';
    $theme_ok   = is_dir($theme_dir);
    $dl_url     = wp_nonce_url(admin_url('admin.php?page=societies-theme&sc_theme_dl=1'), 'sc_theme_dl');

    // Calcul taille du dossier
    $dir_size = 0;
    if ($theme_ok) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) $dir_size += $f->getSize();
    }
    $size_fmt = $dir_size > 1048576 ? round($dir_size / 1048576, 1) . ' Mo' : round($dir_size / 1024) . ' Ko';

    // Compter les fichiers
    $file_count = $theme_ok ? iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme_dir, RecursiveDirectoryIterator::SKIP_DOTS))) : 0;

    echo sc_admin_logo(); ?>
    <div class="wrap">
      <h1 style="display:flex;align-items:center;gap:10px">🎨 Thème TOPsocietes</h1>

      <?php if (!$theme_ok): ?>
      <div class="notice notice-error"><p>⚠️ Thème introuvable : <code><?= esc_html($theme_dir) ?></code></p></div>
      <?php else: ?>

      <div style="max-width:600px;margin-top:24px">
        <div style="background:#fff;border:1px solid #ddd;border-radius:10px;padding:28px 32px;box-shadow:0 2px 8px rgba(0,0,0,.06)">
          <h2 style="margin-top:0;font-size:18px">Exporter le thème</h2>
          <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:24px">
            <tr style="border-bottom:1px solid #f0f0f0">
              <td style="padding:10px 0;color:#6b7280;width:40%">Nom du thème</td>
              <td style="padding:10px 0;font-weight:600">topsocietes-theme</td>
            </tr>
            <tr style="border-bottom:1px solid #f0f0f0">
              <td style="padding:10px 0;color:#6b7280">Emplacement</td>
              <td style="padding:10px 0;font-family:monospace;font-size:12px"><?= esc_html($theme_dir) ?></td>
            </tr>
            <tr style="border-bottom:1px solid #f0f0f0">
              <td style="padding:10px 0;color:#6b7280">Fichiers</td>
              <td style="padding:10px 0;font-weight:600"><?= esc_html($file_count) ?> fichiers</td>
            </tr>
            <tr>
              <td style="padding:10px 0;color:#6b7280">Taille estimée</td>
              <td style="padding:10px 0;font-weight:600"><?= esc_html($size_fmt) ?></td>
            </tr>
          </table>
          <a href="<?= esc_url($dl_url) ?>"
             style="display:inline-flex;align-items:center;gap:8px;background:#1e3a8a;color:#fff;font-size:14px;font-weight:700;padding:12px 24px;border-radius:8px;text-decoration:none;transition:background .2s"
             onmouseover="this.style.background='#1e40af'" onmouseout="this.style.background='#1e3a8a'">
            ⬇️ Télécharger le thème (.zip)
          </a>
          <p style="font-size:12px;color:#9ca3af;margin-top:12px">
            L'archive sera nommée <code>topsocietes-theme-<?= date('Ymd') ?>.zip</code>.
          </p>
        </div>
      </div>

      <?php endif; ?>
    </div>
    <?php
}

// =============================================================================
// CSS GLOBAL — masquage header/sidebar/footer thème via wp_head (fiable)
// =============================================================================
add_action('wp_head', function() {
    echo '<style id="sc-global-hide">
.apus-page-loading,.apus-header,#apus-header,.header-mobile,#apus-header-mobile,
.header-main,.apus-top-bar,.top-bar-wrap,.col-md-4.pull-right,
.col-md-4.col-sm-12.col-xs-12.pull-right,aside.sidebar,aside.sidebar-right,
.sidebar.sidebar-right,#secondary,#sidebar,.widget-area,.sidebar-area,.sidebar-right,
[class*="sidebar"]:not([class*="sc2"]):not([class*="sc-"]),#apus-footer,footer.apus-footer,
.show-sidebar-button,.btn-show-sidebar,.btn-toggle-sidebar,.sidebar-toggle,.toggle-sidebar,
[data-toggle="sidebar"],.off-canvas-wrap,.js-off-canvas-overlay{display:none!important}
body{background:#fff!important}
</style>';
}, 99);

// =============================================================================
// FOOTER — Masquage colonnes thème + footer personnalisé en bas (retour-4)
// =============================================================================

// Vide les widgets texte contenant du Lorem Ipsum
add_filter('widget_text_content', function($content) {
    if (stripos($content, 'Lorem') !== false) return '';
    return $content;
}, 1);
add_filter('widget_text', function($content) {
    if (stripos($content, 'Lorem') !== false) return '';
    return $content;
}, 1);

// CSS global : masque la section Elementor des colonnes footer (logo, Liens utiles, Contact fictif)
// Section a3b0e40 = colonnes → cachée ; section 4b67268 = copyright → conservée
add_action('wp_head', function() {
    echo '<style id="sc-footer-hide">
.elementor-element-a3b0e40,.elementor-element-4b67268{display:none!important}
</style>';
}, 5);

// JS : fallback — masque les colonnes footer thème et les widgets Lorem Ipsum
add_action('wp_footer', function() {
    echo '<script>
(function(){
  function fixFooter(){
    // Masquer colonnes thème footer (pas le copyright)
    var footer=document.querySelector("#apus-footer,footer.apus-footer");
    if(footer){
      Array.from(footer.children).forEach(function(c){
        var cls=c.className||"";
        var isCopy=/copyright|footer-bottom|bottom/i.test(cls)||/copyright/i.test(c.innerHTML);
        if(!isCopy) c.style.display="none";
      });
    }
    // Masquer widgets Lorem Ipsum
    document.querySelectorAll(".textwidget,.widget_text").forEach(function(el){
      if(el.textContent.indexOf("Lorem")>-1){
        var w=el.closest(".widget,.elementor-widget,.footer-widget");
        if(w) w.style.display="none";
      }
    });
  }
  document.addEventListener("DOMContentLoaded",fixFooter);
  setTimeout(fixFooter,400);
})();
</script>';
}, 98);

// (ancien footer sombre #sc-custom-footer supprimé — remplacé par .sc-site-footer ci-dessus)

// =============================================================================
// SESSION PERSISTANTE 30 JOURS (point 12)
// =============================================================================
add_filter('auth_cookie_expiration', function($expiration, $user_id, $remember) {
    return 30 * DAY_IN_SECONDS; // 30 jours quelle que soit l'option "se souvenir de moi"
}, 10, 3);

// =============================================================================
// POPUP COLLECTE EMAIL VISITEURS — DÉSACTIVÉ (réactiver en changeant false→true)
// =============================================================================
if (false) :
add_action('wp_footer', function() {
    if (is_user_logged_in()) return;
    $api_url = rtrim(get_option('societies_api_url', ''), '/');
    if (!$api_url) return;
    ?>
<div id="sc-email-popup" style="display:none;position:fixed;bottom:24px;right:24px;z-index:99999;width:320px;background:#1a2744;border-radius:14px;padding:24px;box-shadow:0 8px 32px rgba(0,0,0,.4);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif">
  <button onclick="scClosePopup()" style="position:absolute;top:10px;right:14px;background:none;border:none;color:#94a3b8;font-size:18px;cursor:pointer;line-height:1">✕</button>
  <p style="color:#fff;font-size:15px;font-weight:700;margin:0 0 6px">📬 Restez informé</p>
  <p style="color:#94a3b8;font-size:13px;margin:0 0 14px;line-height:1.5">Recevez nos actualités et offres exclusives sur les entreprises françaises.</p>
  <div style="display:flex;gap:8px">
    <input id="sc-popup-email" type="email" placeholder="votre@email.fr" style="flex:1;padding:9px 12px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#fff;font-size:13px;outline:none">
    <button onclick="scSubmitEmail()" style="background:#e63946;color:#fff;border:none;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap">OK</button>
  </div>
  <p id="sc-popup-msg" style="font-size:12px;color:#10b981;margin:8px 0 0;min-height:16px"></p>
</div>
<script>
(function(){
  var POPUP_KEY='sc_email_popup_shown';
  if(localStorage.getItem(POPUP_KEY)) return;
  setTimeout(function(){
    var el=document.getElementById('sc-email-popup');
    if(el) el.style.display='block';
  }, 8000);
  window.scClosePopup=function(){
    document.getElementById('sc-email-popup').style.display='none';
    localStorage.setItem(POPUP_KEY,'1');
  };
  window.scSubmitEmail=function(){
    var email=document.getElementById('sc-popup-email').value.trim();
    var msg=document.getElementById('sc-popup-msg');
    if(!email||!email.includes('@')){msg.style.color='#ef4444';msg.textContent='Email invalide.';return;}
    msg.style.color='#94a3b8';msg.textContent='Envoi...';
    fetch('<?= esc_js($api_url) ?>/api/emails/collect',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({email:email,source_url:location.href,source_page:document.title})
    }).then(function(r){return r.json();}).then(function(d){
      if(d.ok){
        msg.style.color='#10b981';
        msg.textContent='✅ Merci ! Vous êtes bien inscrit.';
        localStorage.setItem(POPUP_KEY,'1');
        setTimeout(function(){document.getElementById('sc-email-popup').style.display='none';},2500);
      } else {
        msg.style.color='#ef4444';msg.textContent=d.error||'Erreur.';
      }
    }).catch(function(){msg.style.color='#ef4444';msg.textContent='Erreur réseau.';});
  };
})();
</script>
<?php
}, 100);
endif; // fin désactivation popup

// =============================================================================
// BANDEAU PUBLICITAIRE TOPSOCIETES.COM — affiché en bas de chaque page
// =============================================================================
add_action('wp_footer', function() {
    echo '
<a href="https://www.topsocietes.com" target="_blank" rel="noopener sponsored" id="sc-promo-banner" style="
    display:block;
    background:linear-gradient(135deg,#fff7ed 0%,#fdf2f8 50%,#f0f9ff 100%);
    border-top:3px solid transparent;
    border-image:linear-gradient(135deg,#F97316,#EC4899,#8B5CF6,#06B6D4) 1;
    padding:16px 32px;
    font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;
    text-decoration:none;
    transition:opacity .2s;
">
  <div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap">

    <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
      <div style="background:linear-gradient(135deg,#F97316,#EC4899);border-radius:10px;padding:10px 14px;flex-shrink:0">
        <span style="font-size:22px">🌍</span>
      </div>
      <div>
        <p style="margin:0 0 3px;font-size:16px;font-weight:800;color:#1e2d5a;letter-spacing:.2px">
          Créez votre société — <span style="background:linear-gradient(135deg,#F97316,#EC4899);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">0 impôt société</span>
        </p>
        <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.4">
          Europe · Asie · USA &nbsp;|&nbsp; <span style="color:#475569;font-weight:600">+ Introduction bancaire incluse</span>
        </p>
      </div>
    </div>

    <span style="
        display:inline-flex;align-items:center;gap:8px;
        background:linear-gradient(135deg,#F97316,#EC4899);color:#fff;
        font-size:14px;font-weight:700;
        padding:11px 22px;border-radius:10px;
        white-space:nowrap;flex-shrink:0;
        box-shadow:0 4px 16px rgba(249,115,22,.3);
    ">
      Découvrir TOPsocietes.com <span style="font-size:16px">→</span>
    </span>

  </div>
</a>';
}, 97);

// =============================================================================
// SHORTCODE PAGE TARIFS UTILISATEUR [societies_tarifs]
// =============================================================================

add_shortcode('societies_tarifs', function() {
    $plans = [
        [
            'name'     => 'Gratuit',
            'desc'     => 'Accès aux informations de base sur toutes les entreprises françaises.',
            'price'    => 0,
            'featured' => false,
            'badge'    => '',
            'btn_text' => 'Commencer gratuitement',
            'btn_url'  => home_url('/inscription/'),
            'btn_style'=> 'outline',
            'features' => [
                [true,  'Recherche par nom / SIREN'],
                [true,  'Fiche entreprise de base'],
                [true,  '5 recherches / jour'],
                [false, 'Données financières complètes'],
                [false, 'Export CSV'],
                [false, 'API Access'],
            ],
        ],
        [
            'name'     => 'Pro',
            'desc'     => "Idéal pour les professionnels de la prospection et du renseignement d'entreprises.",
            'price_m'  => 49,
            'price_a'  => 39,
            'featured' => true,
            'badge'    => 'Le plus populaire',
            'btn_text' => "Commencer l'essai gratuit",
            'btn_url'  => home_url('/inscription-pro/'),
            'btn_style'=> 'primary',
            'features' => [
                [true,  'Tout le plan Gratuit'],
                [true,  'Recherches illimitées'],
                [true,  'Données financières complètes'],
                [true,  'Dirigeants & actionnaires'],
                [true,  'Export CSV (500 / mois)'],
                [false, 'API Access'],
            ],
        ],
        [
            'name'     => 'Entreprise',
            'desc'     => 'Pour les équipes et organisations avec des besoins de données à grande échelle.',
            'price_m'  => 199,
            'price_a'  => 149,
            'featured' => false,
            'badge'    => '',
            'btn_text' => 'Contacter nos équipes',
            'btn_url'  => home_url('/contact/'),
            'btn_style'=> 'outline',
            'features' => [
                [true, 'Tout le plan Pro'],
                [true, 'API Access complète'],
                [true, 'Export CSV illimité'],
                [true, 'Webhooks & Intégrations'],
                [true, 'Account Manager dédié'],
                [true, 'SLA 99,9 %'],
            ],
        ],
    ];

    $faqs = [
        [
            'q' => 'Les données sont-elles mises à jour en temps réel ?',
            'a' => "Nos données sont synchronisées quotidiennement depuis le RCS (Registre du Commerce et des Sociétés), l'INSEE et Infogreffe. Les données financières (bilans, CA) sont mises à jour annuellement dès leur dépôt.",
        ],
        [
            'q' => 'Puis-je annuler mon abonnement à tout moment ?',
            'a' => "Oui, vous pouvez annuler votre abonnement depuis votre espace membre à tout moment. Aucun frais d'annulation ne s'applique. Votre accès reste actif jusqu'à la fin de la période payée.",
        ],
        [
            'q' => "L'API est-elle disponible avec le plan Pro ?",
            'a' => "L'accès API complet est réservé au plan Entreprise. Le plan Pro bénéficie cependant d'exports CSV et d'un accès limité à l'API (100 requêtes / jour).",
        ],
        [
            'q' => "Puis-je tester le plan Pro avant de m'engager ?",
            'a' => "Absolument. Nous offrons un essai gratuit de 14 jours sur le plan Pro, sans carte bancaire requise. À l'issue de l'essai, vous choisissez librement de continuer ou non.",
        ],
        [
            'q' => 'Comment fonctionne la facturation annuelle ?',
            'a' => 'En optant pour la facturation annuelle, vous bénéficiez d\'une réduction de 20 % sur le tarif mensuel. La facturation est effectuée en une seule fois en début de période.',
        ],
    ];

    ob_start(); ?>
    <style>
    .spt-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;padding:40px 20px 60px;max-width:1160px;margin:0 auto}
    .spt-hero{text-align:center;margin-bottom:48px}
    .spt-title{font-size:34px;font-weight:800;color:#1a2744;margin:0 0 12px}
    .spt-sub{font-size:16px;color:#6b7280;max-width:520px;margin:0 auto 28px}
    .spt-toggle{display:inline-flex;background:#f1f5f9;border-radius:50px;padding:4px;gap:0}
    .spt-toggle-btn{background:none;border:none;border-radius:50px;padding:10px 22px;font-size:14px;font-weight:600;color:#6b7280;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:8px;font-family:inherit}
    .spt-toggle-btn.active{color:#1a2744;box-shadow:0 2px 8px rgba(0,0,0,.12)}
    .spt-save-badge{background:#10b981;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px}
    .spt-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-bottom:64px}
    .spt-card{border:2px solid #e8edf3;border-radius:20px;padding:32px 24px;display:flex;flex-direction:column;position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s}
    .spt-card:hover{transform:translateY(-6px);box-shadow:0 16px 48px rgba(0,0,0,.1)}
    .spt-card.featured{border-color:#6366f1;box-shadow:0 8px 32px rgba(99,102,241,.15)}
    .spt-card-badge{position:absolute;top:18px;right:-34px;background:linear-gradient(135deg,#6366f1,#8B5CF6);color:#fff;font-size:11px;font-weight:700;padding:5px 48px;transform:rotate(45deg);letter-spacing:.5px}
    .spt-plan-name{font-size:22px;font-weight:800;color:#1a2744;margin-bottom:8px}
    .spt-plan-desc{font-size:13px;color:#6b7280;line-height:1.6;margin-bottom:24px;min-height:48px}
    .spt-price{margin-bottom:24px}
    .spt-price-cur{font-size:24px;font-weight:700;color:#1a2744;vertical-align:top;margin-top:10px;display:inline-block}
    .spt-price-amount{font-size:52px;font-weight:900;color:#1a2744;line-height:1}
    .spt-price-period{font-size:14px;color:#9ca3af;margin-left:4px}
    .spt-price-orig{font-size:12px;color:#9ca3af;margin-top:4px;min-height:18px}
    .spt-divider{border:none;border-top:1px solid #f1f5f9;margin:0 0 20px}
    .spt-features{list-style:none;padding:0;margin:0 0 28px;display:flex;flex-direction:column;gap:10px;flex:1}
    .spt-feature{display:flex;align-items:flex-start;gap:10px;font-size:14px;color:#374151;line-height:1.45}
    .spt-check-yes{color:#10b981;font-size:16px;flex-shrink:0;font-weight:700}
    .spt-check-no{color:#d1d5db;font-size:16px;flex-shrink:0}
    .spt-btn-primary{display:block;text-align:center;background:linear-gradient(135deg,#6366f1,#8B5CF6);color:#fff;font-size:15px;font-weight:700;padding:14px 24px;border-radius:12px;text-decoration:none;transition:opacity .2s}
    .spt-btn-primary:hover{opacity:.88;color:#fff;text-decoration:none}
    .spt-btn-outline{display:block;text-align:center;color:#1a2744;border:2px solid #e2e8f0;font-size:15px;font-weight:700;padding:14px 24px;border-radius:12px;text-decoration:none;transition:all .2s}
    .spt-btn-outline:hover{border-color:#6366f1;color:#6366f1;text-decoration:none}
    .spt-faq-header{text-align:center;margin-bottom:32px}
    .spt-faq-eyebrow{font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#6366f1;margin-bottom:8px}
    .spt-faq-title{font-size:28px;font-weight:800;color:#1a2744;margin:0}
    .spt-faq-list{max-width:720px;margin:0 auto 64px}
    .spt-faq-item{border-bottom:1px solid #e8edf3}
    .spt-faq-item:first-child{border-top:1px solid #e8edf3}
    .spt-faq-btn{display:flex;align-items:center;justify-content:space-between;width:100%;background:none;border:none;padding:20px 0;cursor:pointer;text-align:left;font-family:inherit;font-size:15px;font-weight:600;color:#1a2744;gap:12px}
    .spt-faq-icon{font-size:20px;color:#6366f1;flex-shrink:0;transition:transform .2s;line-height:1}
    .spt-faq-item--open .spt-faq-icon{transform:rotate(45deg)}
    .spt-faq-answer{font-size:14px;color:#6b7280;line-height:1.75;padding:0 0 20px;display:none}
    .spt-faq-item--open .spt-faq-answer{display:block}
    .spt-cta{background:linear-gradient(135deg,#1a2744,#1e3a8a);border-radius:20px;padding:48px 40px;display:flex;align-items:center;justify-content:space-between;gap:32px;flex-wrap:wrap}
    .spt-cta-title{font-size:26px;font-weight:800;color:#e8f4ff;margin:0 0 8px}
    .spt-cta-desc{font-size:15px;color:rgba(191,216,255,.8);margin:0}
    .spt-cta-btn{flex-shrink:0;background:linear-gradient(135deg,#6366f1,#8B5CF6);color:#fff;font-size:15px;font-weight:700;padding:16px 32px;border-radius:12px;text-decoration:none;transition:opacity .2s;white-space:nowrap}
    .spt-cta-btn:hover{opacity:.88;color:#fff;text-decoration:none}
    @media(max-width:900px){.spt-grid{grid-template-columns:1fr;max-width:420px;margin-left:auto;margin-right:auto}}
    @media(max-width:600px){.spt-title{font-size:26px}.spt-cta{padding:32px 24px;flex-direction:column}.spt-cta-title{font-size:20px}}
    </style>

    <div class="spt-wrap">
      <div class="spt-hero">
        <h1 class="spt-title">Des tarifs transparents, sans surprise</h1>
        <p class="spt-sub">Choisissez le plan adapté à vos besoins. Changez ou annulez à tout moment.</p>
        <div class="spt-toggle" role="group" aria-label="Fréquence de facturation">
          <button type="button" class="spt-toggle-btn active" data-billing="monthly" aria-pressed="true">Mensuel</button>
          <button type="button" class="spt-toggle-btn" data-billing="annual" aria-pressed="false">
            Annuel <span class="spt-save-badge">-20%</span>
          </button>
        </div>
      </div>

      <div class="spt-grid">
        <?php foreach ($plans as $plan):
            $is_free = isset($plan['price']);
            $pm = $plan['price_m'] ?? 0;
            $pa = $plan['price_a'] ?? 0;
        ?>
        <div class="spt-card<?= $plan['featured'] ? ' featured' : '' ?>">
          <?php if ($plan['badge']): ?>
          <div class="spt-card-badge"><?= esc_html($plan['badge']) ?></div>
          <?php endif; ?>
          <div class="spt-plan-name"><?= esc_html($plan['name']) ?></div>
          <div class="spt-plan-desc"><?= esc_html($plan['desc']) ?></div>
          <div class="spt-price">
            <span class="spt-price-cur">€</span><span class="spt-price-amount"<?= !$is_free ? ' data-monthly="'.esc_attr($pm).'" data-annual="'.esc_attr($pa).'"' : '' ?>><?= $is_free ? '0' : esc_html($pm) ?></span><span class="spt-price-period">/ mois</span>
            <div class="spt-price-orig"<?= !$is_free ? ' data-annual-orig="au lieu de '.esc_attr($pm).' €/mois"' : '' ?>></div>
          </div>
          <hr class="spt-divider">
          <ul class="spt-features">
            <?php foreach ($plan['features'] as [$ok, $text]): ?>
            <li class="spt-feature">
              <span class="<?= $ok ? 'spt-check-yes' : 'spt-check-no' ?>" aria-hidden="true"><?= $ok ? '✓' : '✕' ?></span>
              <?= esc_html($text) ?>
            </li>
            <?php endforeach; ?>
          </ul>
          <a href="<?= esc_url($plan['btn_url']) ?>" class="spt-btn-<?= esc_attr($plan['btn_style']) ?>"><?= esc_html($plan['btn_text']) ?></a>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="spt-faq-header">
        <div class="spt-faq-eyebrow">Questions fréquentes</div>
        <h2 class="spt-faq-title">FAQ</h2>
      </div>
      <div class="spt-faq-list">
        <?php foreach ($faqs as $i => $faq): ?>
        <div class="spt-faq-item" id="spt-faq-<?= esc_attr($i) ?>">
          <button type="button" class="spt-faq-btn" aria-expanded="false" aria-controls="spt-faq-ans-<?= esc_attr($i) ?>">
            <?= esc_html($faq['q']) ?>
            <span class="spt-faq-icon" aria-hidden="true">+</span>
          </button>
          <div class="spt-faq-answer" id="spt-faq-ans-<?= esc_attr($i) ?>" role="region">
            <?= esc_html($faq['a']) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="spt-cta">
        <div>
          <div class="spt-cta-title">Prêt à commencer ?</div>
          <p class="spt-cta-desc">Accédez gratuitement à plus de 4 millions de fiches entreprise. Aucune carte bancaire requise.</p>
        </div>
        <a href="<?= esc_url(home_url('/inscription/')) ?>" class="spt-cta-btn">Créer un compte gratuit →</a>
      </div>
    </div>
    <script>
    (function(){
      var grid = document.querySelector('.spt-grid');
      document.querySelectorAll('.spt-toggle-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          document.querySelectorAll('.spt-toggle-btn').forEach(function(b){ b.classList.remove('active'); b.setAttribute('aria-pressed','false'); });
          btn.classList.add('active'); btn.setAttribute('aria-pressed','true');
          var billing = btn.getAttribute('data-billing');
          grid.querySelectorAll('.spt-price-amount[data-monthly]').forEach(function(el) {
            el.textContent = billing === 'annual' ? el.getAttribute('data-annual') : el.getAttribute('data-monthly');
          });
          grid.querySelectorAll('.spt-price-orig[data-annual-orig]').forEach(function(el) {
            el.textContent = billing === 'annual' ? el.getAttribute('data-annual-orig') : '';
          });
        });
      });
      document.querySelectorAll('.spt-faq-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var item = btn.closest('.spt-faq-item');
          var open = item.classList.contains('spt-faq-item--open');
          document.querySelectorAll('.spt-faq-item--open').forEach(function(i){ i.classList.remove('spt-faq-item--open'); i.querySelector('.spt-faq-btn').setAttribute('aria-expanded','false'); });
          if (!open) { item.classList.add('spt-faq-item--open'); btn.setAttribute('aria-expanded','true'); }
        });
      });
    })();
    </script>
    <?php return ob_get_clean();
});
