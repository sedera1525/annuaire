#!/bin/bash
set -e

WP=/var/www/html
DB_HOST="${WORDPRESS_DB_HOST%:*}"
DB_PORT="${WORDPRESS_DB_HOST#*:}"
DB_PORT="${DB_PORT:-3306}"

echo "⏳ Attente MySQL..."
until mysql -h"$DB_HOST" -P"$DB_PORT" -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" \
  "$WORDPRESS_DB_NAME" -e "SELECT 1" &>/dev/null; do
  sleep 3
done
echo "✅ MySQL prêt"

echo "⏳ Attente des fichiers WordPress..."
until [ -f "$WP/wp-load.php" ]; do sleep 3; done
echo "✅ Fichiers WordPress prêts"

cd "$WP"

# ─── Installation WordPress ──────────────────────────────────────────────────
if ! wp core is-installed --allow-root 2>/dev/null; then
  echo "📦 Installation WordPress..."
  wp core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email \
    --allow-root
  echo "✅ WordPress installé"
else
  echo "✅ WordPress déjà installé"
fi

# ─── Plugins WordPress.org (gratuits) ────────────────────────────────────────
echo "📦 Installation des plugins..."
FREE_PLUGINS=(
  advanced-custom-fields
  akismet
  cmb2
  code-snippets
  contact-form-7
  duplicate-page
  editorskit
  elementor
  justify-for-paragraph-block
  mailchimp-for-wp
  one-click-demo-import
  really-simple-ssl
  royal-elementor-addons
  ultimate-addons-for-elementor
  woocommerce
  wp-first-letter-avatar
  wp-import-export-lite
  wp-job-manager
  wp-private-message
  wpvivid-backups
  wordpress-seo
)

for slug in "${FREE_PLUGINS[@]}"; do
  if wp plugin is-installed "$slug" --allow-root 2>/dev/null; then
    wp plugin activate "$slug" --allow-root 2>/dev/null && echo "  ✅ $slug activé" || true
  else
    echo "  ⬇️  Installation $slug..."
    wp plugin install "$slug" --activate --allow-root 2>/dev/null \
      && echo "  ✅ $slug installé" \
      || echo "  ⚠️  $slug indisponible (premium ou slug incorrect)"
  fi
done

# Plugin custom societies-connector (déjà monté via volume)
wp plugin activate societies-connector --allow-root 2>/dev/null \
  && echo "  ✅ societies-connector activé" \
  || echo "  ⚠️  societies-connector non trouvé"

# ─── Configuration API Societies ─────────────────────────────────────────────
echo "🔧 Configuration API..."
wp option update societies_api_url "$SOCIETIES_API_URL" --allow-root
wp option update societies_api_username "$SOCIETIES_API_USER" --allow-root
wp option update societies_api_password "$SOCIETIES_API_PASS" --allow-root

# ─── WooCommerce ─────────────────────────────────────────────────────────────
echo "🛒 Configuration WooCommerce..."
wp wc tool run install_pages --user=1 --allow-root 2>/dev/null || true

# Produits d'abonnement
wp eval '
if (!get_page_by_title("Abonnement Basic", OBJECT, "product")) {
    $p = new WC_Product_Simple();
    $p->set_name("Abonnement Basic — Fiche Entreprise");
    $p->set_status("publish");
    $p->set_price("19.90");
    $p->set_regular_price("19.90");
    $p->set_description("Complétez les 6 questions verrouillées de votre fiche entreprise.");
    $p->set_catalog_visibility("visible");
    $p->save();
    echo "✅ Produit Basic créé\n";
}
if (!get_page_by_title("Abonnement Pro", OBJECT, "product")) {
    $p = new WC_Product_Simple();
    $p->set_name("Abonnement Pro — Fiche Premium");
    $p->set_status("publish");
    $p->set_price("49.90");
    $p->set_regular_price("49.90");
    $p->set_description("Fiche complète + mise en avant + statistiques de consultation.");
    $p->set_catalog_visibility("visible");
    $p->save();
    echo "✅ Produit Pro créé\n";
}
' --allow-root 2>/dev/null || true

# ─── Pages du site ────────────────────────────────────────────────────────────
echo "📄 Création des pages..."
for page_name in "annuaire" "tableau-de-bord-proprietaire"; do
  if ! wp post list --post_type=page --post_name="$page_name" --field=ID --allow-root 2>/dev/null | grep -q .; then
    if [ "$page_name" = "annuaire" ]; then
      wp post create --post_type=page --post_title="Annuaire des entreprises" \
        --post_name=annuaire --post_status=publish \
        --post_content='[societies_listings]' --allow-root 2>/dev/null || true
    else
      wp post create --post_type=page --post_title="Tableau de bord propriétaire" \
        --post_name=tableau-de-bord-proprietaire --post_status=publish \
        --post_content='[societies_owner_dashboard]' --allow-root 2>/dev/null || true
    fi
    echo "  ✅ Page $page_name créée"
  fi
done

# ─── Permaliens ──────────────────────────────────────────────────────────────
wp rewrite structure '/%postname%/' --allow-root 2>/dev/null || true
wp rewrite flush --allow-root 2>/dev/null || true

echo ""
echo "════════════════════════════════════════"
echo "✅  Setup WordPress terminé"
echo "   Site  : $WP_URL"
echo "   Admin : $WP_URL/wp-admin"
echo "   Login : $WP_ADMIN_USER / $WP_ADMIN_PASSWORD"
echo "════════════════════════════════════════"
echo ""
echo "⚠️  Plugins premium à installer manuellement (non disponibles sur WordPress.org) :"
echo "   - Slider Revolution"
echo "   - Apus Findus"
echo "   - Apus Framework"
echo "   - Apus WP Job Manager — WooCommerce Paid Listings"
