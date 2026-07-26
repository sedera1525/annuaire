# Annuaire — Societies

**Annuaire d'entreprises françaises** : recherche et consultation de fiches société à
partir d'une très grande base de données, avec interface d'administration, workflow de
modification de fiches, génération de contenu par IA, page de prospection commerciale, et
un connecteur pour publier les fiches sur un site WordPress externe.

---

## But du projet

Exploiter une base de plusieurs millions d'entreprises françaises pour :

- offrir un **annuaire public** rapide (recherche par nom, ville, catégorie ; fiche
  entreprise détaillée avec coordonnées, note, carte) ;
- **enrichir** les fiches via de la génération de texte par IA (OpenAI) ;
- **administrer** le contenu (édition, validation, régénération de fiches) ;
- **prospecter** : identifier les entreprises **sans site web** mais joignables, comme
  cibles commerciales pour une offre de création de site ;
- **publier** les fiches sur un site WordPress via un connecteur dédié.

---

## Fonctionnalités principales

- 🔍 **Recherche** multi-critères (nom, ville, code postal, catégorie) avec cache.
- 🏢 **Fiche entreprise publique** (bannière, logo, coordonnées, note, carte Google Maps),
  URL en `/entreprise/<slug>`.
- 🛠️ **Back-office** : liste et édition des fiches, génération/ régénération IA par lots.
- 📇 **Prospection** : liste paginée + export CSV des entreprises sans site web et
  joignables ; chaque prospect a un **lien démo** vers sa fiche publique (site à présenter).
- 🔌 **Connecteur WordPress** (plugin PHP) pour publier les fiches sur un site externe.

---

## Architecture (monorepo)

Le dépôt regroupe trois composants indépendants :

| Dossier | Rôle | Techno |
|---|---|---|
| **racine** (`main.py`, `routers/`, `services/`, `core/`, `static/`) | API + back-office | Python / FastAPI |
| **`web/`** | Front public de l'annuaire | Next.js (App Router) |
| **`wordpress/`** | Connecteur & thème pour publication externe | PHP (plugin WordPress) |

Le front Next.js consomme l'API FastAPI (`API_URL`, défaut `http://localhost:8090`).

---

## Stack technique

**Backend**
- Python 3.11 · **FastAPI** + **Uvicorn**
- **DuckDB** (base principale, volumineuse) + **SQLite** (`fiches.db`, fiches éditables)
- **OpenAI** (génération de contenu) · **slowapi** (rate limiting) · **Redis** (cache optionnel)
- **Sentry** (suivi d'erreurs) · auth par **bcrypt** / **cryptography**
- Templates **Jinja** (`templates/`) + assets (`static/`)

**Frontend**
- **Next.js 16** (App Router, Turbopack) · TypeScript · Tailwind CSS
- Tests **Vitest** (unitaires) + **Playwright** (E2E)

**Infra**
- **Docker** / docker-compose (healthcheck sur `/api/status`)

---

## Prérequis

- Python **3.11+**
- Node.js **20+** (pour le front `web/`)
- Docker + docker-compose (optionnel, pour le déploiement conteneurisé)
- Les **fichiers de données** (DuckDB / CSV) — **non versionnés** (voir plus bas).

---

## Installation & lancement local

### 1. Backend (API + back-office)

```bash
# via le script (crée le venv, installe les dépendances, lance l'app)
./start.sh

# …ou manuellement :
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env        # puis renseigner les variables (voir Configuration)
python3 main.py
```

L'API écoute sur le port **`APP_PORT`** (défaut **8090**) → http://localhost:8090
Back-office et page de prospection : `/` (admin), `/prospection` (protégé par login).

### 2. Frontend (annuaire public)

```bash
cd web
npm install
cp .env.local.example .env.local   # si présent ; sinon créer .env.local (voir Configuration)
npm run dev                         # http://localhost:3100
```

### 3. Docker (tout-en-un)

```bash
docker compose up        # healthcheck interne sur /api/status
```

---

## Configuration

### Backend — `.env` (copié depuis `.env.example`)

| Variable | Rôle |
|---|---|
| `OPENAI_API_KEY`, `OPENAI_MODEL` | Génération de contenu IA |
| `APP_HOST`, `APP_PORT` | Écoute du serveur (défaut `0.0.0.0:8090`) |
| `APP_USERNAME`, `APP_PASSWORD` | Identifiants back-office (**hash bcrypt**) |
| `SECRET_KEY` | Clé de session |
| `ALLOWED_ORIGINS` | Origines CORS autorisées |
| `PUBLIC_SITE_URL` | URL du front Next.js (liens de fiche/démo, défaut `http://localhost:3100`) |
| `REDIS_URL` | Cache Redis (optionnel ; sinon cache mémoire) |
| `SENTRY_DSN`, `CSRF_ENABLED` | Monitoring / sécurité |

### Frontend — `web/.env.local`

| Variable | Rôle |
|---|---|
| `API_URL` | URL de l'API backend (défaut `http://localhost:8090`) |
| `NEXT_PUBLIC_SITE_URL` | URL publique du front (défaut `http://localhost:3100`) |

> ⚠️ **Secrets** : ne jamais committer `.env`, `web/.env.local`, ni le dossier `.claude/`
> (ils sont exclus par `.gitignore`). Les identifiants vivent uniquement dans ces fichiers locaux.

---

## Base de données

- **DuckDB** = base principale, **fichiers très volumineux** (`societies.duckdb` ~1,7 Go,
  `0.csv` ~16 Go, dumps `.gz` ~1 Go). Ils sont **exclus du dépôt** (`.gitignore`) et doivent
  être fournis séparément puis placés à la racine.
- **SQLite** (`fiches.db`) : fiches éditables / modifications.
- ⚠️ Ne **jamais** ouvrir/`cat` ces fichiers en entier (RAM) — toujours requêter avec `LIMIT`.

---

## Tests

### Backend

```bash
source .venv/bin/activate
pytest tests/ -v      # tests API (pytest + httpx)
mypy .                # typage (config dans mypy.ini)
```

Toute modification doit être couverte par un test. Les endpoints « data » renvoient `503`
sans DuckDB prête ; la logique pure est testée sans base, et les correctifs sur données
réelles sont vérifiés en live.

### Frontend

```bash
cd web
npm test          # Vitest (unitaires)
npm run e2e        # Playwright (E2E)
npm run lint       # ESLint
```

---

## Structure du projet

```
.
├── main.py               # point d'entrée FastAPI (middlewares, static, montage routers)
├── routers/              # endpoints HTTP (un fichier par domaine : search, prospection, …)
├── services/             # logique applicative / accès données (DuckDB, OpenAI)
├── core/                 # briques transverses (auth, config, db, utils)
├── templates/, static/   # front back-office (Jinja + assets)
├── tests/                # tests pytest
├── web/                  # front public Next.js (annuaire)
├── wordpress/            # connecteur WordPress (plugin PHP) — composant séparé
├── requirements.txt
├── docker-compose.yml
└── .env.example
```

**Conventions** : toute la logique métier vit dans `core/`, `services/`, `routers/` —
`main.py` reste un simple point d'entrée. Imports triés, type hints sur le code nouveau.

---

## Workflow git

- Branches d'intégration : **`stable`** consolide les branches de feature ; **`main`** reçoit
  `stable` une fois validé.
- Une branche par feature ; commits petits et fréquents ; jamais de commit direct sur `main`.
- Gros binaires (`*.duckdb`, `*.csv`, `*.gz`, `fiches.db`), secrets (`.env`, `.claude/`) et
  artefacts sont exclus via `.gitignore`.

---

## Sécurité

- Secrets uniquement dans `.env` / `web/.env.local` — jamais en dur dans le code.
- Endpoints d'administration protégés par authentification + rate limiting.
- Le dossier `.claude/` (outillage local) n'est pas versionné.
