# Design — Front Next.js : annuaire public (tranche 1)

**Date :** 2026-07-24
**Statut :** validé (design), à décliner en plan d'implémentation
**Branche :** `feature/front-nextjs-annuaire-public`

## Contexte

Societies est une application FastAPI (Python) d'annuaire d'entreprises françaises
(~4,5 M sociétés) avec un front actuel constitué d'un unique fichier HTML/JS vanilla
(`static/index.html`, ~3000 lignes) servi par FastAPI. Un connecteur WordPress publiait
jusqu'ici des fiches sur un site public indexé.

**Décision :** abandonner WordPress et construire un nouveau front moderne. Après cadrage,
la première tranche est **l'annuaire public** (recherche + fiche société), en modèle
**freemium**, avec un fort besoin SEO. La stack retenue est **Next.js (App Router) +
TypeScript**, consommant l'API FastAPI existante (inchangée).

## Décisions de cadrage (validées)

- **Périmètre :** une tranche d'abord, pas de big-bang. Le reste de l'app continue de tourner.
- **Tranche 1 :** annuaire public = recherche + fiche société.
- **Public/SEO :** oui, pages publiques indexables par Google (remplace le rôle de WordPress).
- **Modèle d'accès :** freemium — fiche partielle publique (indexable), détails complets
  derrière login/abonnement.
- **Approche :** A — Next.js SSR/ISR, front séparé consommant FastAPI (vs SPA prerender,
  vs SSR Jinja). Retenue car seule option combinant stack moderne + SEO industriel à cette échelle.
- **Déploiement :** back + front sur le **même serveur**, même domaine via reverse proxy
  → cookie d'auth FastAPI partagé sans CORS.
- **Sitemap :** option (a) — d'abord les entreprises ayant une fiche éditoriale
  (`only_with_fiche`), puis élargissement ultérieur.

## Contexte API existant (ancrage)

Endpoints réutilisés par le front (aucune modification backend requise pour la tranche 1) :

- `GET /api/search` — filtres (`q`, `city`, `zip_code`, `category`, `has_phone`,
  `has_website`, `page`, `per_page`, `sort_by`, `only_with_fiche`). Renvoie
  `{ results[], total, page, per_page, pages, elapsed }`. Chaque résultat :
  `title, category, phone, url, domain, addr_street, city, zip_code, region,
  rating_value, rating_votes, contacts, logo, snippet, is_claimed, latitude,
  longitude, address_full, emails[]`.
- `GET /api/company/by-slug/{slug}` — résolution slug → `{ title, city, category, zip_code }`
  (règle façon `sanitize_title` WordPress). Mécanisme d'URL SEO.
- `GET /api/company/{title}` — fiche complète.
- Contenu éditorial IA : stocké dans `fiches.db` (SQLite), statut `done`.
- **Auth :** cookie de session (`COOKIE_NAME`) + CSRF double-submit, timeout 8h,
  sessions persistées en SQLite.

⚠️ Les entreprises sont clés **par `title`** (pas de SIREN exposé). Les slugs dérivent du titre.

## Architecture

Next.js = front public (rendu serveur + SEO). FastAPI = **inchangé** (API + back-office).
Le front ne parle qu'à l'API via HTTP ; il n'accède jamais directement à DuckDB/SQLite.

```
societies/                     ← repo actuel (FastAPI, inchangé)
├── main.py, routers/, ...
└── web/                        ← NOUVEAU : app Next.js isolée
    ├── app/
    │   ├── page.tsx                    # accueil + recherche (statique)
    │   ├── recherche/page.tsx          # résultats (SSR, indexable)
    │   ├── entreprise/[slug]/page.tsx  # fiche société (ISR, indexable)
    │   ├── layout.tsx
    │   ├── sitemap.ts / sitemap/[n]/route.ts
    │   └── robots.ts
    ├── lib/
    │   ├── api.ts              # client typé + validation runtime (zod) vers FastAPI
    │   └── types.ts           # types Company, SearchResult (dérivés de l'API réelle)
    ├── components/            # SearchBar, CompanyCard, FicheHeader, Paywall, Pagination...
    ├── package.json, tsconfig.json (strict), next.config.ts
    └── .env.local            # API_URL (interne) / NEXT_PUBLIC_API_URL
```

**Choix techniques :** Next.js App Router, TypeScript **strict**, **Tailwind CSS**,
tests **Vitest** (unitaire) + **Playwright** (E2E). Pas de state manager au départ (YAGNI) —
les Server Components portent le fetch.

## URLs, routing & SEO

| Page | URL | Rendu |
|---|---|---|
| Accueil + recherche | `/` | Statique |
| Résultats de recherche | `/recherche?q=...&ville=...&page=2` | SSR (indexable) |
| Fiche société | `/entreprise/[slug]` | ISR (rendu à la demande + cache, revalidate ~24h) |

- **Slugs :** réutilisation de `/api/company/by-slug/{slug}`, même règle que l'API.
- **ISR plutôt que SSG :** 4,5 M pages ne peuvent être pré-générées ; rendu à la première
  visite puis mise en cache.
- **Par fiche :** `generateMetadata()` (title, meta description, Open Graph, canonical) +
  **JSON-LD** `Organization`/`LocalBusiness`.
- **Sitemaps :** index paginé (`≤ 50 000` URLs/fichier) alimenté par l'API, limité aux
  entreprises avec fiche éditoriale (option a). `robots.ts` pour les règles de crawl.

## Modèle freemium & flux de données

| Public (SSR, indexable) | Gated (login/abonnement) |
|---|---|
| Nom, catégorie/activité | Téléphone |
| Ville, CP, région, adresse | Emails / contacts |
| Note & nb d'avis | Site web / domaine |
| Snippet, logo | Contenu éditorial IA complet |
| Extrait du contenu éditorial | Export / données enrichies |

**Flux de rendu `/entreprise/[slug]` :**
1. Server Component → `lib/api.ts` → `/api/company/by-slug/{slug}` puis `/api/company/{title}`.
2. Rendu serveur des seuls champs **publics** → HTML indexable + JSON-LD.
3. Champs gated remplacés par `<Paywall>` (flou + CTA « Se connecter / S'abonner »).
4. Si session authentifiée détectée (cookie FastAPI présent), rendu des champs complets.

**Auth :** pas de réimplémentation. Le front transmet le cookie de session FastAPI aux
appels API. Back + front sur le **même domaine** (reverse proxy) → cookie partagé, pas de CORS.

**Contrat de données :** types TS (`Company`, `SearchResult`) dans `lib/types.ts`, dérivés
des réponses réelles ; `lib/api.ts` valide au runtime (zod) pour se prémunir des dérives d'API.

## Tests

- **Vitest (unitaire) :** `lib/api.ts` (parsing, validation, erreurs) + logique freemium
  (champs masqués selon état auth). API FastAPI mockée.
- **Playwright (E2E) :** (1) recherche → résultats ; (2) fiche visiteur → paywall + données
  publiques présentes ; (3) fiche connecté → données complètes. Vérifie aussi `<title>`,
  meta description et présence du JSON-LD (preuve SEO). E2E contre FastAPI local (:8090).

## Déploiement

- `next build` → serveur Node Next.js (ex. port 3000).
- Reverse proxy (Nginx/Caddy) sur le même serveur / même domaine : `/` → Next (3000),
  `/api` + `/static` → FastAPI (8090). Cookie d'auth partagé.
- FastAPI inchangé.

## Séquence d'implémentation

Chaque étape est testée (TDD) avant la suivante :

1. Scaffold `web/` (Next + TS strict + Tailwind + Vitest/Playwright) + `lib/types.ts` &
   `lib/api.ts` (avec tests unitaires).
2. Page **recherche** `/recherche` (SSR) : liste + pagination, branchée sur `/api/search`.
3. Page **fiche** `/entreprise/[slug]` (ISR) : d'abord **public seul** + `<Paywall>`,
   avec métadonnées SEO + JSON-LD.
4. **Détection de session** → rendu des champs gated pour l'utilisateur connecté.
5. **Sitemap** (fiches à contenu) + `robots.ts`.
6. Accueil `/` + polish.

## Hors périmètre (YAGNI, tranche 1)

Back-office admin, édition de fiches, génération IA, emails, import CSV, SEO tooling interne,
abonnements Stripe — restent sur l'application existante pour l'instant.
