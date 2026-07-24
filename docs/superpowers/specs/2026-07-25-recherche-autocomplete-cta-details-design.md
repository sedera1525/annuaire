# Design — Recherche autocomplétée + CTA « détails complets » (tranche 1bis)

**Date :** 2026-07-25
**Statut :** validé (design), à décliner en plan
**Branche :** `feature/front-nextjs-annuaire-public` (continuité de la tranche 1)

## Contexte

La tranche 1 a livré le front public Next.js (recherche SSR + fiche ISR freemium + SEO).
Cette itération améliore l'UX de recherche et simplifie le paywall de la fiche.

## Décisions de cadrage (validées)

- **Autocomplétion** : suggestions **Entreprises + Catégories + Villes**, dropdown groupé
  en 3 sections.
- **État vide (au focus)** : afficher les **top catégories** et **top villes** préchargées
  (données réelles, avec volumes).
- **Approche technique** : A — composant **client** + **rewrite Next** `/api/:path*` →
  FastAPI, pour appeler l'API en same-origin (zéro CORS, cohérent avec la prod).
- **Paywall fiche** : remplacer les liens « Se connecter pour voir » (répétés par champ)
  par **un seul bouton** « Voir les détails complets » → `/login` (connexion/abonnement).
  Le modèle **freemium reste** (téléphone / emails / site masqués tant que non membre).

## Contexte API (ancrage, backend inchangé)

- `GET /api/categories` → `[{category, count}]`, top 100 (public).
- `GET /api/cities` → `[{city, count}]`, top 50 (public).
- `GET /api/search?q=…&per_page=6` → suggestions d'entreprises (public).
- Pas d'endpoint `/suggest` : les suggestions d'entreprises réutilisent `/api/search`.

## Architecture / fichiers

- `next.config.ts` — **rewrite** `/api/:path*` → `${API_URL}/api/:path*` (appels client same-origin).
- `lib/suggest.ts` — helpers **purs** testables :
  - `filterByLabel(items, q, limit)` — filtre catégories/villes (insensible casse/accents, borné).
  - types `Suggestion` (kind: `company | category | city`, label, href, meta?).
- `components/SearchAutocomplete.tsx` — composant **client** (`"use client"`) :
  - préchargement `/api/categories` + `/api/cities` au montage ;
  - au focus champ vide : sections « Catégories populaires » + « Villes populaires » ;
  - à la frappe (debounce ~250 ms) : `/api/search?q=…&per_page=6` (entreprises) +
    filtrage client des catégories/villes ;
  - navigation clavier (↑/↓/Entrée/Échap), item surligné, accessible (`role="listbox"`).
- `components/FullDetailsCTA.tsx` — bouton unique « Voir les détails complets » → `/login`
  (remplace `Paywall`).
- `app/page.tsx` & `app/recherche/page.tsx` — utilisent `SearchAutocomplete` (au lieu de `SearchBar`).
- `app/recherche/page.tsx` — lit en plus `?categorie=` → `searchCompanies({ category })`.
- `app/entreprise/[slug]/page.tsx` — zone gated : champs masqués + **un** `FullDetailsCTA`
  (visiteur) ; valeurs réelles sans bouton (connecté).
- `components/Paywall.tsx` + test — **supprimés** (remplacés par `FullDetailsCTA`).

## Comportement de sélection (autocomplétion)

| Type | Action |
|---|---|
| Entreprise | `/entreprise/[slug]` (via `toSlug`) |
| Catégorie | `/recherche?categorie=<cat>` |
| Ville | `/recherche?ville=<ville>` |
| Entrée sans sélection | `/recherche?q=<saisie>` |

## Tests (TDD)

- **Unitaire `lib/suggest.ts`** : `filterByLabel` (casse, accents, limite, vide).
- **Unitaire `SearchAutocomplete`** (Vitest + testing-library, `fetch` mocké) :
  focus vide → exemples préchargés rendus ; frappe → section Entreprises rendue depuis le mock ;
  filtrage catégories/villes ; navigation clavier (flèche + Entrée déclenche la navigation).
- **Unitaire `FullDetailsCTA`** : bouton présent, `href="/login"`.
- **E2E** : focus → exemples visibles ; taper « rest » → suggestions ; clic catégorie →
  `/recherche?categorie=…`. Fiche visiteur → un seul bouton « Voir les détails complets »,
  plus de « Se connecter pour voir ».

## Hors périmètre (YAGNI)

Endpoint `/suggest` dédié côté backend, historique de recherches, suggestions
personnalisées, recherche vocale.
