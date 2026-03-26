<?php
/**
 * Plugin Name:  Societies Connector
 * Description:  Connexion à l'API Societies — fiches entreprises, abonnements et tableau de bord propriétaire.
 * Version:      1.2.1
 * Author:       Societies
 * Text Domain:  societies
 */

if (!defined('ABSPATH')) exit;

define('SC_VERSION', '1.2.1');
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
        'societies_page_societies-subscriptions', 'toplevel_page_sc-mon-entreprise'])) return;
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

    // Authenticate if needed
    $cookie = get_transient('sc_session_cookie');
    if (!$cookie) {
        $cookie = sc_authenticate();
    }

    $args = [
        'method'  => $method,
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'application/json',
            'Cookie'       => "societies_session={$cookie}",
        ],
    ];
    if (!empty($body)) {
        $args['body'] = wp_json_encode($body);
    }

    $r = wp_remote_request($base . $endpoint, $args);

    if (is_wp_error($r)) {
        return ['error' => $r->get_error_message()];
    }

    // Re-auth on 401
    if (wp_remote_retrieve_response_code($r) === 401) {
        $cookie = sc_authenticate(true);
        $args['headers']['Cookie'] = "societies_session={$cookie}";
        $r = wp_remote_request($base . $endpoint, $args);
    }

    return json_decode(wp_remote_retrieve_body($r), true) ?: [];
}

function sc_authenticate(bool $force = false): string {
    if (!$force) {
        $cached = get_transient('sc_session_cookie');
        if ($cached) return $cached;
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

    if (is_wp_error($r)) return '';

    $raw = wp_remote_retrieve_header($r, 'set-cookie');
    $raw = is_array($raw) ? implode('; ', $raw) : $raw;
    if (preg_match('/societies_session=([^;]+)/', $raw, $m)) {
        set_transient('sc_session_cookie', $m[1], 6 * HOUR_IN_SECONDS);
        return $m[1];
    }
    return '';
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
      .sc-card{border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#fff;transition:box-shadow .2s}
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

    // ── Sauvegarde intro (GRATUIT) ────────────────────────────────────────────
    if (isset($_POST['sc_save_intro']) && check_admin_referer('sc_save_intro') && $company_title) {
        $intro_text = sanitize_textarea_field($_POST['sc_intro_text'] ?? '');
        $result = sc_api('/api/fiche/' . rawurlencode($company_title), 'PUT', [
            'intro_text' => $intro_text,
        ]);
        if (!isset($result['error'])) {
            update_user_meta($user_id, 'sc_intro_saved', '1');
        }
    }

    // ── Sauvegarde réponses ouvertes (ABONNEMENT requis) ──────────────────────
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
        $result = sc_api('/api/fiche/' . rawurlencode($company_title), 'PUT', [
            'open_answers' => $open_answers,
        ]);
        if (!isset($result['error'])) {
            update_user_meta($user_id, 'sc_open_answers', array_column($open_answers, 'r'));
            update_user_meta($user_id, 'sc_save_ok', '1');
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
        $fiche         = sc_api('/api/fiche/' . rawurlencode($company_title));
        $qa_answered   = $fiche['qa_answered']   ?? [];
        $open_questions = $fiche['open_questions'] ?? [];
        $intro         = $fiche['intro_text']    ?? '';
        $date          = $fiche['date_fr']        ?? '';
        $saved_answers = get_user_meta($user_id, 'sc_open_answers', true) ?: [];
        $save_ok       = get_user_meta($user_id, 'sc_save_ok', true);
        delete_user_meta($user_id, 'sc_save_ok');
    ?>
        <div class="sc-dashboard">
          <h2>📋 <?= esc_html($company_title) ?></h2>

          <?php
          $intro_saved = get_user_meta($user_id, 'sc_intro_saved', true);
          delete_user_meta($user_id, 'sc_intro_saved');
          ?>
          <?php if ($save_ok): ?>
          <div class="sc-success">✅ Vos réponses ont bien été enregistrées.</div>
          <?php endif; ?>
          <?php if ($intro_saved): ?>
          <div class="sc-success">✅ Votre présentation a bien été enregistrée.</div>
          <?php endif; ?>

          <!-- ── Présentation (éditable gratuitement) ── -->
          <h3>✏️ Votre présentation <span style="font-size:11px;color:#10b981;font-weight:normal;margin-left:6px">Gratuit</span></h3>
          <form method="post" class="sc-form">
            <?php wp_nonce_field('sc_save_intro'); ?>
            <textarea name="sc_intro_text" class="sc-textarea" rows="4"
              placeholder="Rédigez une présentation de votre entreprise (3 phrases recommandées)..."
              style="min-height:100px"><?= esc_textarea($intro) ?></textarea>
            <button type="submit" name="sc_save_intro" value="1" class="sc-btn" style="margin-top:10px">
              💾 Enregistrer la présentation
            </button>
          </form>

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
            <?php if ($date): ?><div class="sc-date">Décryptage du <?= esc_html($date) ?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>

          <?php if (!empty($open_questions)): ?>
          <h3>🔒 Questions à compléter
            <?php if (!$has_sub): ?>
            <span class="sc-badge-locked">Abonnement requis</span>
            <?php endif; ?>
          </h3>

          <?php if (!$has_sub): ?>
          <div class="sc-sub-banner">
            <strong>🔒 Accédez à votre fiche complète</strong><br>
            Abonnez-vous pour répondre aux 6 questions verrouillées et enrichir votre profil.
            <br><br>
            <a href="<?= esc_url(get_permalink(wc_get_page_id('shop'))) ?>" class="sc-btn">
              Voir nos abonnements →
            </a>
          </div>
          <?php endif; ?>

          <form method="post" <?= !$has_sub ? 'style="pointer-events:none;opacity:.55"' : '' ?>>
            <?php wp_nonce_field('sc_save_answers'); ?>
            <?php foreach ($open_questions as $i => $q): ?>
            <div class="sc-qa-item sc-locked">
              <div class="sc-q"><?= esc_html($q) ?></div>
              <textarea name="sc_open_answers[<?= $i ?>]"
                class="sc-textarea"
                <?= !$has_sub ? 'disabled' : '' ?>
                placeholder="Votre réponse..."
              ><?= esc_textarea($saved_answers[$i] ?? '') ?></textarea>
            </div>
            <?php endforeach; ?>
            <?php if ($has_sub): ?>
            <button type="submit" name="sc_save_answers" value="1" class="sc-btn" style="margin-top:16px">
              💾 Enregistrer mes réponses
            </button>
            <?php endif; ?>
          </form>
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
    $status      = $fiche['status'] ?? 'none';

    $stars_full  = (int) round((float)$rating);
    $stars_empty = 5 - $stars_full;
    $stars_html  = str_repeat('★', $stars_full) . str_repeat('☆', $stars_empty);

    ob_start(); ?>
    <div class="sc2-wrap">

      <!-- HERO -->
      <div class="sc2-hero">
        <div class="sc2-hero-inner">
          <img src="<?= esc_url($logo_url) ?>" alt="TOPsocietes.com" class="sc2-hero-logo">
          <h1 class="sc2-hero-name"><?= esc_html($company['title']) ?></h1>
          <?php if (!empty($company['category']) || !empty($company['city'])): ?>
          <div class="sc2-hero-sub">
            <?php if (!empty($company['category'])): ?><span><?= esc_html($company['category']) ?></span><?php endif; ?>
            <?php if (!empty($company['city'])): ?><span>📍 <?= esc_html($company['city']) ?><?= !empty($company['zip_code']) ? ' ' . esc_html($company['zip_code']) : '' ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($rating): ?>
        <div class="sc2-hero-rating">
          <div class="sc2-hero-score"><?= number_format((float)$rating, 1) ?></div>
          <div class="sc2-hero-stars"><?= $stars_html ?></div>
          <?php if ($votes): ?><div class="sc2-hero-votes"><?= number_format((int)$votes, 0, ',', ' ') ?> avis</div><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- CONTACT BAR -->
      <?php $has_contact = !empty($company['phone']) || !empty($company['website']) || !empty($company['address']); ?>
      <?php if ($has_contact): ?>
      <div class="sc2-contact-bar">
        <?php if (!empty($company['address'])): ?>
        <span class="sc2-contact-item">📍 <?= esc_html($company['address']) ?></span>
        <?php endif; ?>
        <?php if (!empty($company['phone'])): ?>
        <a href="tel:<?= esc_attr(preg_replace('/\s+/', '', $company['phone'])) ?>" class="sc2-contact-item sc2-contact-link">📞 <?= esc_html($company['phone']) ?></a>
        <?php endif; ?>
        <?php if (!empty($company['website'])): ?>
        <a href="<?= esc_url($company['website']) ?>" target="_blank" rel="noopener" class="sc2-contact-item sc2-contact-link">🌐 <?= esc_html(preg_replace('/^https?:\/\/(www\.)?/', '', rtrim($company['website'], '/'))) ?></a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($intro && $status === 'done'): ?>
      <!-- PRÉSENTATION -->
      <div class="sc2-intro-card">
        <div class="sc2-intro-label">Présentation</div>
        <p class="sc2-intro-text"><?= nl2br(esc_html($intro)) ?></p>
      </div>
      <?php endif; ?>

      <?php if (!empty($qa_answered)): ?>
      <!-- Q&A RÉPONDUES -->
      <div class="sc2-section">
        <h2 class="sc2-section-title">Ce que pensent les clients</h2>
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
      <!-- QUESTIONS OUVERTES -->
      <div class="sc2-section">
        <h2 class="sc2-section-title">Questions fréquentes</h2>
        <div class="sc2-faq-list">
          <?php foreach ($qa_open as $item): ?>
          <?php $q = $item['question'] ?? $item['q'] ?? ''; $a = $item['answer'] ?? $item['r'] ?? ''; if (!$q) continue; ?>
          <div class="sc2-faq-item">
            <div class="sc2-faq-q"><span class="sc2-faq-icon">Q</span><?= esc_html($q) ?></div>
            <?php if ($a): ?><div class="sc2-faq-a"><span class="sc2-faq-icon sc2-faq-icon-r">R</span><?= nl2br(esc_html($a)) ?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- BRANDING -->
      <div class="sc2-footer">
        <img src="<?= esc_url($logo_url) ?>" alt="TOPsocietes.com">
        <span>Fiche entreprise — TOPsocietes.com</span>
      </div>

    </div>
    <style>
    .sc2-wrap{max-width:860px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1f2937}

    /* HERO */
    .sc2-hero{background:#1a2744;border-radius:16px;padding:32px 36px;display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:4px;flex-wrap:wrap}
    .sc2-hero-inner{flex:1}
    .sc2-hero-logo{height:36px;width:auto;margin-bottom:16px;opacity:.9}
    .sc2-hero-name{margin:0 0 10px;font-size:28px;font-weight:800;color:#fff;line-height:1.2;text-transform:uppercase;letter-spacing:.5px}
    .sc2-hero-sub{display:flex;flex-wrap:wrap;gap:10px}
    .sc2-hero-sub span{background:rgba(255,255,255,.12);color:#cbd5e1;font-size:13px;padding:4px 12px;border-radius:20px}
    .sc2-hero-rating{text-align:center;background:rgba(255,255,255,.08);border-radius:12px;padding:14px 22px;flex-shrink:0}
    .sc2-hero-score{font-size:42px;font-weight:800;color:#e63946;line-height:1}
    .sc2-hero-stars{color:#f59e0b;font-size:18px;letter-spacing:2px;margin:4px 0}
    .sc2-hero-votes{color:#94a3b8;font-size:12px}

    /* CONTACT BAR */
    .sc2-contact-bar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px 20px;display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px}
    .sc2-contact-item{color:#475569;font-size:13px;display:inline-flex;align-items:center;gap:4px}
    .sc2-contact-link{color:#2563eb;text-decoration:none}
    .sc2-contact-link:hover{text-decoration:underline}

    /* INTRO */
    .sc2-intro-card{background:#1a2744;border-radius:12px;padding:24px 28px;margin-bottom:16px}
    .sc2-intro-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#64748b;margin-bottom:10px;color:#94a3b8}
    .sc2-intro-text{margin:0;color:#e2e8f0;line-height:1.8;font-size:15px}

    /* SECTIONS */
    .sc2-section{margin-bottom:24px}
    .sc2-section-title{font-size:18px;font-weight:700;color:#111827;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #e63946;display:inline-block}

    /* Q&A GRID */
    .sc2-qa-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px}
    .sc2-qa-card{background:#fff;border:1px solid #e5e7eb;border-left:3px solid #e63946;border-radius:10px;padding:18px 20px;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:box-shadow .2s}
    .sc2-qa-card:hover{box-shadow:0 4px 14px rgba(0,0,0,.08)}
    .sc2-qa-q{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#e63946;margin-bottom:8px}
    .sc2-qa-a{color:#374151;font-size:14px;line-height:1.7}

    /* FAQ LIST */
    .sc2-faq-list{display:flex;flex-direction:column;gap:0}
    .sc2-faq-item{padding:16px 0;border-bottom:1px solid #f1f5f9}
    .sc2-faq-item:last-child{border-bottom:none}
    .sc2-faq-q{display:flex;align-items:flex-start;gap:12px;font-size:15px;font-weight:600;color:#1f2937;margin-bottom:6px}
    .sc2-faq-a{display:flex;align-items:flex-start;gap:12px;font-size:14px;color:#6b7280;line-height:1.65;padding-left:4px}
    .sc2-faq-icon{background:#1a2744;color:#fff;font-size:11px;font-weight:800;width:22px;height:22px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
    .sc2-faq-icon-r{background:#e63946}

    /* FOOTER */
    .sc2-footer{margin-top:32px;padding-top:18px;border-top:1px solid #f1f5f9;display:flex;align-items:center;gap:12px;color:#9ca3af;font-size:12px}
    .sc2-footer img{height:28px;width:auto;opacity:.6}

    @media(max-width:640px){
      .sc2-hero{padding:22px 18px;flex-direction:column;align-items:flex-start}
      .sc2-hero-name{font-size:20px}
      .sc2-hero-rating{align-self:flex-start}
      .sc2-qa-grid{grid-template-columns:1fr}
    }
    </style>
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
});

add_action('admin_init', function() {
    register_setting('sc_options', 'societies_api_url');
    register_setting('sc_options', 'societies_api_username');
    register_setting('sc_options', 'societies_api_password', [
        'sanitize_callback' => function($new) {
            // Si le champ est vide, on conserve l'ancien mot de passe
            if (empty(trim($new))) {
                return get_option('societies_api_password', '');
            }
            return $new;
        },
    ]);
});

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
      <div style="max-width:580px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;margin-top:16px;box-shadow:0 1px 4px rgba(0,0,0,.05)">
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
            <div id="sc-search-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d1d5db;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.1);z-index:99;max-height:260px;overflow-y:auto"></div>
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
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px 28px;text-align:center;min-width:140px">
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

      <form method="post">
        <?php wp_nonce_field('sc_test'); ?>
        <button type="submit" name="sc_test" class="button button-secondary">🔌 Tester la connexion</button>
      </form>
    </div>
    <?php
}

function sc_admin_fiches() {
    $tab = sanitize_key($_GET['tab'] ?? 'list');

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
      <form method="post" style="margin-bottom:16px">
        <input type="hidden" name="page" value="societies-fiches">
        <input type="hidden" name="tab"  value="list">
        <?php wp_nonce_field('sc_create_all_pages'); ?>
        <button type="submit" name="sc_create_all_pages" value="1" class="button button-primary"
                onclick="return confirm('Créer toutes les pages manquantes ? Cela peut prendre du temps.')">
          📄 Créer toutes les pages manquantes
        </button>
      </form>
      <?php endif; ?>

      <?php if ($tab === 'list'): ?>
        <?php
        $page = max(1, intval($_GET['paged'] ?? 1));
        $q    = sanitize_text_field($_GET['q'] ?? '');
        $data = sc_api("/api/fiches?page={$page}&per_page=25" . ($q ? '&q=' . urlencode($q) : ''));
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
              <th style="width:35%">Entreprise</th>
              <th>Généré le</th>
              <th>Modèle</th>
              <th>Tokens</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($data['results'] as $f):
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
              <td><?= esc_html(substr($f['generated_at'] ?? '', 0, 16)) ?></td>
              <td><?= esc_html($f['model'] ?? '—') ?></td>
              <td><?= number_format($f['completion_tokens'] ?? 0) ?></td>
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
        if (($data['pages'] ?? 1) > 1):
            $base_url = admin_url("admin.php?page=societies-fiches&tab=list" . ($q ? '&q=' . urlencode($q) : ''));
        ?>
        <div style="margin-top:16px">
          <?php for ($i = 1; $i <= $data['pages']; $i++): ?>
          <a href="<?= $base_url ?>&paged=<?= $i ?>"
             class="button<?= $i === $page ? ' button-primary' : '' ?>"
             style="margin-right:4px"><?= $i ?></a>
          <?php endfor; ?>
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
    </style>';
}

add_action('wp_head', 'sc_enqueue_styles');
