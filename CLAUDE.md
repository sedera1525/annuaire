# CLAUDE.md — Societies

## Projet
Societies : application web d'**annuaire d'entreprises (France)**. Recherche/consultation
de fiches société à partir d'une grosse base, interface d'administration, workflow de
modifications de fiches, génération de contenu par IA, et un connecteur WordPress pour
publier les fiches sur un site externe.

## Stack
- **Python 3.11** / **FastAPI** + **Uvicorn**
- **DuckDB** (base principale, volumineuse) + **SQLite** (`fiches.db`)
- **OpenAI** (génération de contenu), **slowapi** (rate limiting), **Sentry** (erreurs),
  **Redis**, auth via **bcrypt** / **cryptography**
- Front : templates Jinja (`templates/`) + assets (`static/`)
- Déploiement : **Docker** / docker-compose

## Comment lancer le projet
```bash
./start.sh             # crée venv, installe requirements, lance main.py sur :8000
# ou manuellement :
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python3 main.py
# ou via Docker :
docker compose up      # healthcheck sur /api/status
```

## Comment lancer les tests (IMPORTANT)
```bash
source .venv/bin/activate
pytest tests/ -v
mypy .                 # config dans mypy.ini (check_untyped_defs activé)
```
- **Toute modification doit être couverte par un test** (`tests/`, pytest + httpx sur l'API).
- Ne jamais dire « c'est fini » sans avoir lancé `pytest` et vu le **vert**, et sans que
  `mypy .` passe.

## Conventions de code (NON négociables)
- Respecter le style des fichiers voisins avant d'écrire.
- **Toute la logique métier vit dans `core/`, `services/`, `routers/`** — `main.py` n'est
  que le point d'entrée (montage app, middlewares, routers). Ne pas y mettre de logique.
- Imports triés ; type hints sur le code nouveau (mypy : `check_untyped_defs`,
  `no_implicit_optional`).
- Noms descriptifs et explicites.

## Architecture / structure
- `main.py` → entrée FastAPI (CORS, Sentry, rate limit, static, montage des routers).
- `routers/` → endpoints HTTP (un fichier par domaine fonctionnel).
- `services/` → logique applicative / accès données (DuckDB, OpenAI…).
- `core/` → briques transverses (auth, config, utilitaires).
- `templates/` (Jinja) + `static/` → front.
- `wordpress/` → connecteur WordPress (plugin PHP `societies-connector`), séparé du backend.
- Respecter cette séparation ; ne pas créer de nouveau dossier de base sans validation.

## Base de données
- **DuckDB** = base principale, **fichiers très volumineux** (`societies.duckdb` ~1,7 Go,
  `0.csv` ~16 Go, `.duckdb.gz` ~1 Go). **NE JAMAIS lire/ouvrir/`cat` ces fichiers
  directement** — passer par des requêtes DuckDB ciblées et limitées (`LIMIT`).
- `fiches.db` (SQLite) pour les fiches éditables / modifications.
- Pas de dump complet, pas de scan de table entière sans raison.

## Sécurité (à respecter)
- Secrets dans `.env` (Sentry DSN, clés API, identifiants) — **jamais en dur** dans le code.
- ⚠️ `.claude/settings.json` contient des identifiants FTP/HTTP en clair (déploiement
  WordPress). Ne pas les recopier ailleurs ; à terme, les sortir vers `.env`/secrets.
- Endpoints d'admin protégés (auth + rate limit) : ne pas exposer de route sensible sans auth.

## Règles de travail (pour Claude)
- Comprendre avant de coder ; si la demande est ambiguë, poser des questions.
- Faire ce qui est demandé, rien de plus. Pas de gros refactor non sollicité.
- Pas de fichiers de doc créés sauf demande explicite (il y a déjà GUIDE.md, docs/, amelioration*.md).
- Ne pas changer les dépendances (`requirements.txt`) sans validation.
- Ne pas supprimer de tests sans validation.
- Réponses concises.

## Git
- Repo sur **`main`** → créer une **branche par feature**, ne jamais committer directement sur `main`.
- Commits petits et fréquents, messages clairs.
- Commit/push **seulement** sur demande.
- ⚠️ `.gitignore` doit exclure les gros binaires (`*.duckdb`, `*.csv`, `*.gz`, `.env`,
  `fiches.db`) — vérifier avant tout `git add`.

## Pièges connus
- **Données massives** : ouvrir un `.csv`/`.duckdb` en entier sature la RAM/le contexte — toujours requêter avec `LIMIT`.
- Deux ports selon le mode : `main.py` local sur **:8000**, Docker exposé différemment (healthcheck interne `/api/status`).
- Connecteur WordPress (PHP) = composant à part, déployé par FTP ; ne pas le confondre avec le backend Python.
- `mypy.ini` n'est pas en mode `strict` global — ne pas présumer un typage strict partout.
