# Societies — Guide de déploiement

## Architecture production

```
[Serveur VPS]                    [WordPress hébergé séparément]
  societies (Docker)    ←──────   Plugin societies-connector
  └─ FastAPI :8090                └─ appelle l'API via HTTPS
  └─ Nginx (reverse proxy SSL)    └─ WooCommerce + Stripe
  └─ societies.duckdb             └─ PDF Invoices automatiques
  └─ fiches.db
```

En production, WordPress est une installation réelle (hébergeur tiers, WP Engine, etc.).
Le bloc WordPress du `docker-compose.yml` **ne s'utilise qu'en local** pour les tests.

---

## Structure du projet

```
societies/
├── main.py                    # Backend FastAPI
├── static/index.html          # Frontend SPA admin
├── 0.csv                      # Base source (4.5M entreprises — monter en volume)
├── societies.duckdb           # Base DuckDB (auto-générée au 1er démarrage)
├── fiches.db                  # Fiches IA + réponses clients (SQLite)
├── .env                       # Configuration (NE PAS COMMITER)
├── Dockerfile
├── docker-compose.yml         # Dev local uniquement (inclut WordPress)
├── docker-compose.prod.yml    # Production (societies seul)
├── requirements.txt
└── wordpress/
    └── societies-connector/   # Plugin WordPress (servi via /api/plugin/download)
```

---

## Prérequis serveur

- VPS Ubuntu 22.04+ avec **Docker** et **Docker Compose v2**
- Nom de domaine pointant sur le VPS (ex : `api.monsite.fr`)
- Certificat SSL — Let's Encrypt via Certbot
- Ports 80 et 443 ouverts

---

## 1. Configuration `.env`

Copier `.env` sur le serveur et **changer toutes les valeurs par défaut** :

```bash
OPENAI_API_KEY=sk-...          # Clé OpenAI fournie par le client
OPENAI_MODEL=gpt-4.1-mini      # Modèle recommandé (rapport qualité/prix)

APP_HOST=0.0.0.0
APP_PORT=8090

# Changer obligatoirement avant déploiement
APP_USERNAME=admin
APP_PASSWORD=MotDePasseFort2024!

# Générer avec : python3 -c "import secrets; print(secrets.token_hex(32))"
SECRET_KEY=<générer>
```

> ⚠️ Ne jamais déployer avec `APP_PASSWORD=changeme123`

---

## 2. `docker-compose.prod.yml`

En production, utiliser ce fichier à la place de `docker-compose.yml` :

```yaml
services:
  societies:
    build: .
    ports:
      - "127.0.0.1:8090:8090"   # exposé en local uniquement (Nginx devant)
    volumes:
      - ./0.csv:/app/0.csv:ro
      - ./societies.duckdb:/app/societies.duckdb
      - ./fiches.db:/app/fiches.db
    env_file:
      - .env
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8090/api/status"]
      interval: 30s
      timeout: 10s
      retries: 3
```

Démarrer :

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

---

## 3. Reverse proxy Nginx (HTTPS)

```nginx
server {
    listen 443 ssl;
    server_name api.monsite.fr;

    ssl_certificate     /etc/letsencrypt/live/api.monsite.fr/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.monsite.fr/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:8090;
        proxy_set_header   Host $host;
        proxy_set_header   X-Real-IP $remote_addr;
        proxy_buffering    off;   # OBLIGATOIRE pour le streaming SSE (génération IA)
    }
}

server {
    listen 80;
    server_name api.monsite.fr;
    return 301 https://$host$request_uri;
}
```

Obtenir le certificat SSL :

```bash
certbot --nginx -d api.monsite.fr
```

---

## 4. Première utilisation

Au premier démarrage, l'app charge le CSV dans DuckDB (~3 min, opération unique).
Une barre de progression s'affiche dans l'interface admin.

---

## 5. Plugin WordPress — Installation

### Télécharger le plugin

1. Se connecter à l'interface Societies : `https://api.monsite.fr`
2. Cliquer **⬇ Plugin WP** dans le header
3. Ou accéder directement : `https://api.monsite.fr/api/plugin/download`
   *(connexion admin préalable requise)*

### Installer dans WordPress

1. WordPress Admin → **Extensions → Ajouter → Téléverser une extension**
2. Sélectionner `societies-connector.zip` → **Installer** → **Activer**

### Configurer la connexion API

WordPress Admin → **Societies → Réglages API**

| Champ | Valeur |
|---|---|
| URL de l'API | `https://api.monsite.fr` |
| Identifiant admin | valeur de `APP_USERNAME` |
| Mot de passe | valeur de `APP_PASSWORD` |

Cliquer **Tester la connexion** — doit afficher ✅ avec le nombre d'entreprises.

### Activer la licence du plugin

1. Dans l'interface Societies → bouton **⬇ Plugin WP** → cliquer **Générer la clé**
2. Copier la clé affichée (**une seule fois** — elle ne sera plus visible)
3. Dans WordPress, une bannière rouge demande l'activation → coller la clé → **Activer**
4. Le champ disparaît une fois activé. La clé brute n'est jamais stockée (SHA-256 uniquement).

---

## 6. Stripe — Configuration paiement

Le plugin WooCommerce Stripe Gateway est installé automatiquement.
La configuration des clés se fait dans WordPress Admin.

### Étapes

1. Créer un compte sur [stripe.com](https://stripe.com) et récupérer les clés API
2. WordPress Admin → **WooCommerce → Réglages → Paiements → Stripe**
3. Renseigner :
   - **Clé publique** (`pk_live_...`)
   - **Clé secrète** (`sk_live_...`)
   - **Secret webhook** (généré depuis le dashboard Stripe)
4. Désactiver le **mode test** pour la production
5. Configurer le webhook Stripe → URL : `https://votresite.fr/?wc-api=wc_stripe`

### Webhook Stripe (notifications automatiques)

Dans le dashboard Stripe → Développeurs → Webhooks → Ajouter un endpoint :

```
URL      : https://votresite.fr/?wc-api=wc_stripe
Événements : payment_intent.succeeded
             payment_intent.payment_failed
             charge.refunded
```

### Factures PDF automatiques

Le plugin **PDF Invoices & Packing Slips** est installé et configuré pour :
- Joindre la facture aux emails **"Commande en cours"** et **"Commande terminée"**
- Numérotation : `FAC-00001`, `FAC-00002`...

WordPress Admin → **WooCommerce → PDF Invoices** pour personnaliser logo/adresse.

### Emails automatiques WooCommerce (configurés)

| Email | Destinataire | Déclencheur |
|---|---|---|
| Nouvelle commande | Admin | Achat validé |
| Commande en cours | Client | Paiement reçu |
| Commande terminée | Client | Commande complétée |

---

## 7. Fonctionnement du plugin WordPress

### Côté admin WordPress

- **Societies → Tableau de bord** : statistiques et statut de connexion API
- **Societies → Fiches générées** : liste + bouton Regénérer par fiche
- **Societies → Rechercher & Générer** : chercher une entreprise → générer sa fiche IA
- **Societies → Abonnements** : clients abonnés + entreprises associées

### Côté client WordPress

| Action | Abonnement requis |
|---|---|
| Associer son entreprise | Non |
| Voir sa fiche IA générée | Non |
| Modifier sa présentation (intro) | **Non — gratuit** |
| Répondre aux 6 questions ouvertes | **Oui** |

- Menu **Mon entreprise** visible uniquement aux clients (non admins)
- Chaque client voit **uniquement** sa propre fiche — isolation garantie par `user_id`

### Shortcodes disponibles

```
[societies_listings]                         → annuaire public
[societies_owner_dashboard]                  → tableau de bord propriétaire (front-end)
[societies_by_sector sector="Restaurant"]    → liste entreprises par secteur (SEO)
[societies_by_city city="Lille"]             → liste entreprises par ville (SEO)
```

### API REST WordPress (proxy sans CORS)

```
GET /?rest_route=/societies/v1/search?q=...         → recherche
GET /?rest_route=/societies/v1/fiche/{titre}        → fiche entreprise
GET /?rest_route=/societies/v1/stats                → statistiques
GET /?rest_route=/societies/v1/seo/sector/{secteur} → entreprises par secteur
GET /?rest_route=/societies/v1/seo/city/{ville}     → entreprises par ville
GET /?rest_route=/societies/v1/seo/top-sectors      → top secteurs
GET /?rest_route=/societies/v1/seo/top-cities       → top villes
```

---

## 8. Génération de fiches IA en batch parallèle

### Depuis l'interface admin

WordPress Admin → **Societies → Rechercher & Générer**, ou interface Societies directement.

### Via API (batch production)

```bash
POST /api/generate/batch
{
  "companies":   [...],   # liste d'entreprises {title, category, city, zip_code, rating_value}
  "concurrency": 6,       # têtes parallèles (défaut 6, max 20)
  "max":         50       # entreprises par appel (défaut 50, max 200)
}
```

**Capacité estimée avec 6 têtes :**

| Têtes | Fiches/heure | Fiches/jour | Coût/jour (gpt-4.1-mini) |
|---|---|---|---|
| 1 | ~600 | ~14 400 | ~$1.44 |
| 6 | ~3 600 | ~86 400 | ~$8.64 |
| 20 | ~12 000 | ~288 000 | ~$28.80 |

> La cible du spec est **20 000 fiches/jour** → 6 têtes suffisent largement.

**Exemple appel en production :**

```bash
# Récupérer 50 entreprises et générer leurs fiches (6 en parallèle)
curl -X POST https://api.monsite.fr/api/generate/batch \
  --cookie "societies_session=<token>" \
  -H "Content-Type: application/json" \
  -d '{
    "companies": [
      {"title": "Dupont SARL", "category": "Restaurant", "city": "Paris", "zip_code": "75001"},
      ...
    ],
    "concurrency": 6
  }'
```

---

## 9. SEO — Pages secteur et ville

Les endpoints et shortcodes SEO permettent de créer des pages thématiques automatiques.

**Utilisation WordPress :**

```
# Page "Restaurants à Paris"
[societies_by_city city="Paris"][societies_by_sector sector="Restaurant"]

# Page "Escape rooms en France"
[societies_by_sector sector="Escape room center" limit="50"]
```

**Endpoints directs :**

```bash
GET /api/seo/top-sectors          → top 50 secteurs par volume
GET /api/seo/top-cities           → top 50 villes par volume
GET /api/seo/sector/Restaurant    → entreprises du secteur
GET /api/seo/city/Paris           → entreprises de la ville
```

---

## 10. Nom du dirigeant — Note

Le champ **nom du dirigeant** est mentionné dans les specs mais **absent du CSV source**.
Le fichier CSV fourni par Compliance Expertise Group contient : nom entreprise, adresse, ville, code postal, téléphone, site web, secteur, note, avis — mais pas le nom du dirigeant.

**Options pour l'ajouter plus tard :**
- Demander à Rado un export CSV enrichi avec le champ dirigeant
- Permettre au client de le saisir manuellement dans sa fiche (via le formulaire "Présentation")

---

## 11. Backups

```bash
# Planifier en cron (crontab -e)
# Sauvegarde quotidienne à 2h du matin
0 2 * * * cp /opt/societies/fiches.db /backups/fiches-$(date +\%Y\%m\%d).db
0 2 * * * cp /opt/societies/societies.duckdb /backups/societies-$(date +\%Y\%m\%d).duckdb
```

---

## 12. Commandes utiles

```bash
# Logs en temps réel
docker compose -f docker-compose.prod.yml logs -f

# Déployer une modification de main.py sans rebuild
docker cp main.py societies-societies-1:/app/main.py
docker restart societies-societies-1

# Déployer une modification du plugin WordPress
docker cp wordpress/societies-connector/societies-connector.php \
  societies-societies-1:/app/wordpress/societies-connector/societies-connector.php
# Puis retélécharger le zip depuis le backend et le réinstaller dans WordPress

# Vider le cache Docker (libère de l'espace disque)
docker builder prune
docker image prune

# Statut de santé
curl https://api.monsite.fr/api/status

# Tester le batch (6 têtes)
curl -X POST https://api.monsite.fr/api/generate/batch \
  --cookie "societies_session=<token>" \
  -H "Content-Type: application/json" \
  -d '{"companies":[{"title":"Test SARL","category":"Restaurant","city":"Paris"}],"concurrency":6}'
```

---

## 13. Références

| Ressource | Valeur |
|---|---|
| Panel OVH | https://auth.eu.ovhcloud.com/signin/ |
| Identifiant OVH | `5551-0647-70/devweb` |
| WordPress existant | http://entreprises-france.topsocietes.com/wp-login.php |
| Contact Rado | webmaster Compliance Expertise Group — sous-domaine, moteur recherche, CSV 35K villes |
| Contact Erick | CPU OVH + clé API OpenAI |

---

## 14. Checklist mise en production

### Backend (VPS)
- [ ] `APP_PASSWORD` changé (pas `changeme123`)
- [ ] `SECRET_KEY` régénéré (`python3 -c "import secrets; print(secrets.token_hex(32))"`)
- [ ] Certificat SSL actif (`certbot --nginx -d api.monsite.fr`)
- [ ] `0.csv` présent et monté en volume
- [ ] Premier démarrage : attendre la fin du chargement DuckDB (~3 min)
- [ ] Backup automatique planifié en cron

### Plugin WordPress
- [ ] Plugin installé et activé
- [ ] Connexion API testée (✅ dans Réglages API)
- [ ] Clé de licence générée et activée dans WordPress
- [ ] Première génération de fiche testée (Rechercher & Générer)

### Stripe / Paiements
- [ ] Clés Stripe Live renseignées (`pk_live_...` / `sk_live_...`)
- [ ] Mode test désactivé
- [ ] Webhook Stripe configuré (`/?wc-api=wc_stripe`)
- [ ] Test achat complet effectué (commande test → email reçu → facture PDF jointe)
- [ ] Produits WooCommerce vérifiés (Basic 19,90€ / Pro 49,90€)

### Tests fonctionnels
- [ ] Client peut s'inscrire, associer son entreprise et modifier sa présentation (gratuit)
- [ ] Client avec abonnement peut répondre aux 6 questions
- [ ] Génération batch 6 têtes testée en production
- [ ] Pages SEO shortcodes fonctionnelles


Flux complet pour un nouveau client :

S'inscrit sur WordPress → wp-admin → Mon entreprise
Tape son nom d'entreprise, clique sur le bon résultat
Clique Revendiquer cette entreprise
Modifie sa présentation (gratuit)
S'abonne → répond aux 6 questions ouvertes

