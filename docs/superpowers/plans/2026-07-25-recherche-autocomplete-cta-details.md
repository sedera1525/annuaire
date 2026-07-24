# Recherche autocomplétée + CTA « détails complets » — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Barre de recherche moderne avec autocomplétion (entreprises + catégories + villes) et remplacement des paywalls par champ par un unique bouton « Voir les détails complets ».

**Architecture:** Composant client `SearchAutocomplete` appelant l'API en same-origin grâce à un rewrite Next `/api/:path*` → FastAPI. Helpers de filtrage purs dans `lib/suggest.ts`. Fiche : zone gated simplifiée avec un seul CTA `FullDetailsCTA`.

**Tech Stack:** Next.js 16 (App Router, client components), TypeScript strict, Tailwind, Vitest + @testing-library, Playwright.

## Global Constraints

- Backend FastAPI **inchangé**. Endpoints (tous publics) : `GET /api/categories` → `[{category,count}]` ; `GET /api/cities` → `[{city,count}]` ; `GET /api/search?q=&per_page=` ; sonde auth `GET /api/fiches/stats`.
- Port dev Next : **3100** (`npm run dev` déjà configuré). Backend : `API_URL` défaut `http://localhost:8090`.
- Appels API **client** en **same-origin** (`/api/...`) via rewrite Next — jamais d'URL absolue vers :8090 côté navigateur.
- TypeScript **strict**, pas de `any`. `npx tsc --noEmit` et `npm run lint` doivent passer.
- Champs gated (inchangés) : `phone`, `url`, `domain`, `emails`, `contacts`.
- Toutes commandes depuis `web/` sauf mention contraire. Prérequis E2E : FastAPI up sur :8090 (à jour, route `by-slug` incluse).

---

### Task 1: Rewrite `/api` → FastAPI (appels client same-origin)

**Files:**
- Modify: `web/next.config.ts`
- Test: `web/e2e/api-proxy.spec.ts`

**Interfaces:**
- Consumes: rien.
- Produces: toute requête vers `/api/*` sur le serveur Next (3100) est proxifiée vers FastAPI.

- [ ] **Step 1: E2E d'échec (le proxy n'existe pas encore)**

Create `web/e2e/api-proxy.spec.ts` :
```ts
import { test, expect } from "@playwright/test";

test("/api/categories est proxifié vers FastAPI", async ({ request }) => {
  const res = await request.get("/api/categories");
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(Array.isArray(body)).toBeTruthy();
  expect(body[0]).toHaveProperty("category");
});
```

- [ ] **Step 2: Lancer → échec** (sans rewrite, `/api/categories` sur :3100 renvoie 404 Next)

Run: `npx playwright test api-proxy --reporter=list`
Expected: FAIL (404 / pas de propriété `category`).

- [ ] **Step 3: Ajouter le rewrite**

Replace `web/next.config.ts` avec :
```ts
import type { NextConfig } from "next";

const API_URL = process.env.API_URL ?? "http://localhost:8090";

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${API_URL}/api/:path*` },
    ];
  },
};

export default nextConfig;
```

- [ ] **Step 4: Redémarrer le dev + relancer l'E2E → succès**

Run:
```bash
fuser -k 3100/tcp 2>/dev/null; sleep 1
nohup npm run dev > /tmp/nextdev3100.log 2>&1 &
until grep -qE "Ready in" /tmp/nextdev3100.log; do sleep 1; done
npx playwright test api-proxy --reporter=list
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/next.config.ts web/e2e/api-proxy.spec.ts && git commit -m "feat(web): rewrite /api vers FastAPI (appels client same-origin)"
```

---

### Task 2: Helpers de suggestion (lib/suggest.ts)

**Files:**
- Create: `web/lib/suggest.ts`
- Test: `web/lib/suggest.test.ts`

**Interfaces:**
- Consumes: rien.
- Produces:
  - `type SuggestionKind = "company" | "category" | "city"`
  - `interface Suggestion { kind: SuggestionKind; label: string; href: string; meta?: string }`
  - `interface LabeledItem { label: string; count?: number }`
  - `filterByLabel(items: LabeledItem[], q: string, limit: number): LabeledItem[]`

- [ ] **Step 1: Test d'échec**

Create `web/lib/suggest.test.ts` :
```ts
import { describe, it, expect } from "vitest";
import { filterByLabel } from "@/lib/suggest";

const items = [
  { label: "Restaurant", count: 84475 },
  { label: "Café", count: 100 },
  { label: "Coiffeur", count: 50 },
];

describe("filterByLabel", () => {
  it("q vide → renvoie les premiers (borné)", () => {
    expect(filterByLabel(items, "", 2).map((i) => i.label)).toEqual(["Restaurant", "Café"]);
  });
  it("filtre insensible à la casse et aux accents", () => {
    expect(filterByLabel(items, "cafe", 5).map((i) => i.label)).toEqual(["Café"]);
  });
  it("respecte la limite", () => {
    expect(filterByLabel(items, "c", 1)).toHaveLength(1);
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `npm run test -- suggest`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémentation**

Create `web/lib/suggest.ts` :
```ts
export type SuggestionKind = "company" | "category" | "city";

export interface Suggestion {
  kind: SuggestionKind;
  label: string;
  href: string;
  meta?: string;
}

export interface LabeledItem {
  label: string;
  count?: number;
}

function normalize(s: string): string {
  return s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "");
}

export function filterByLabel(items: LabeledItem[], q: string, limit: number): LabeledItem[] {
  const nq = normalize(q.trim());
  const base = nq ? items.filter((it) => normalize(it.label).includes(nq)) : items;
  return base.slice(0, limit);
}
```

- [ ] **Step 4: Lancer → succès**

Run: `npm run test -- suggest && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/lib/suggest.ts web/lib/suggest.test.ts && git commit -m "feat(web): helpers de suggestion (filterByLabel + types)"
```

---

### Task 3: Composant `SearchAutocomplete` (client)

**Files:**
- Create: `web/components/SearchAutocomplete.tsx`
- Test: `web/components/SearchAutocomplete.test.tsx`

**Interfaces:**
- Consumes: `filterByLabel`, types `Suggestion`/`LabeledItem` (Task 2) ; `toSlug` (existant) ; rewrite `/api` (Task 1).
- Produces: `<SearchAutocomplete defaultQuery?: string; debounceMs?: number />` — barre de recherche client avec dropdown groupé et navigation clavier.

- [ ] **Step 1: Test d'échec**

Create `web/components/SearchAutocomplete.test.tsx` :
```tsx
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SearchAutocomplete } from "@/components/SearchAutocomplete";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

const categories = [{ category: "Restaurant", count: 84475 }, { category: "Coiffeur", count: 50 }];
const cities = [{ city: "Paris", count: 244018 }, { city: "Lyon", count: 500 }];

function stubFetch() {
  vi.stubGlobal("fetch", vi.fn(async (input: string | URL) => {
    const url = String(input);
    if (url.includes("/api/categories")) return { ok: true, json: async () => categories } as Response;
    if (url.includes("/api/cities")) return { ok: true, json: async () => cities } as Response;
    if (url.includes("/api/search")) return { ok: true, json: async () => ({
      results: [{ title: "KaraFun Paris" }], total: 1, page: 1, per_page: 6, pages: 1, elapsed: 0,
    }) } as Response;
    return { ok: false, json: async () => ({}) } as Response;
  }));
}

beforeEach(() => { push.mockReset(); stubFetch(); });
afterEach(() => vi.unstubAllGlobals());

describe("SearchAutocomplete", () => {
  it("au focus (champ vide) affiche les exemples préchargés", async () => {
    render(<SearchAutocomplete debounceMs={0} />);
    await userEvent.click(screen.getByRole("combobox"));
    await waitFor(() => expect(screen.getByText("Restaurant")).toBeTruthy());
    expect(screen.getByText("Paris")).toBeTruthy();
  });

  it("à la frappe affiche des suggestions d'entreprises", async () => {
    render(<SearchAutocomplete debounceMs={0} />);
    await userEvent.type(screen.getByRole("combobox"), "kara");
    await waitFor(() => expect(screen.getByText("KaraFun Paris")).toBeTruthy());
  });

  it("Entrée sans sélection lance une recherche", async () => {
    render(<SearchAutocomplete debounceMs={0} />);
    await userEvent.type(screen.getByRole("combobox"), "boulangerie{Enter}");
    await waitFor(() => expect(push).toHaveBeenCalledWith("/recherche?q=boulangerie"));
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `npm run test -- SearchAutocomplete`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémentation**

Create `web/components/SearchAutocomplete.tsx` :
```tsx
"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { toSlug } from "@/lib/slug";
import { filterByLabel, type LabeledItem, type Suggestion } from "@/lib/suggest";

const LIMIT = 6;

export function SearchAutocomplete({
  defaultQuery = "",
  debounceMs = 250,
}: {
  defaultQuery?: string;
  debounceMs?: number;
}) {
  const router = useRouter();
  const [query, setQuery] = useState(defaultQuery);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [categories, setCategories] = useState<LabeledItem[]>([]);
  const [cities, setCities] = useState<LabeledItem[]>([]);
  const [companies, setCompanies] = useState<Suggestion[]>([]);
  const boxRef = useRef<HTMLDivElement>(null);

  // Préchargement des exemples (catégories + villes)
  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [c, v] = await Promise.all([
          fetch("/api/categories").then((r) => (r.ok ? r.json() : [])),
          fetch("/api/cities").then((r) => (r.ok ? r.json() : [])),
        ]);
        if (!alive) return;
        setCategories((c as { category: string; count: number }[]).map((x) => ({ label: x.category, count: x.count })));
        setCities((v as { city: string; count: number }[]).map((x) => ({ label: x.city, count: x.count })));
      } catch {
        /* réseau indisponible : pas d'exemples, pas de crash */
      }
    })();
    return () => { alive = false; };
  }, []);

  // Suggestions d'entreprises (debounce)
  useEffect(() => {
    const q = query.trim();
    if (!q) { setCompanies([]); return; }
    const id = setTimeout(async () => {
      try {
        const res = await fetch(`/api/search?q=${encodeURIComponent(q)}&per_page=${LIMIT}`);
        if (!res.ok) return;
        const data = await res.json();
        const items: { title: string }[] = data.results ?? [];
        setCompanies(items.map((it) => ({
          kind: "company", label: it.title, href: `/entreprise/${toSlug(it.title)}`,
        })));
      } catch {
        /* ignore */
      }
    }, debounceMs);
    return () => clearTimeout(id);
  }, [query, debounceMs]);

  const suggestions: Suggestion[] = useMemo(() => {
    const cat = filterByLabel(categories, query, LIMIT).map<Suggestion>((c) => ({
      kind: "category", label: c.label, href: `/recherche?categorie=${encodeURIComponent(c.label)}`,
      meta: c.count ? c.count.toLocaleString("fr-FR") : undefined,
    }));
    const cit = filterByLabel(cities, query, LIMIT).map<Suggestion>((c) => ({
      kind: "city", label: c.label, href: `/recherche?ville=${encodeURIComponent(c.label)}`,
      meta: c.count ? c.count.toLocaleString("fr-FR") : undefined,
    }));
    return [...companies, ...cat, ...cit];
  }, [companies, categories, cities, query]);

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, []);

  function go(s: Suggestion) { setOpen(false); router.push(s.href); }
  function submit() { setOpen(false); router.push(`/recherche?q=${encodeURIComponent(query.trim())}`); }

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === "ArrowDown") { e.preventDefault(); setActive((i) => Math.min(i + 1, suggestions.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive((i) => Math.max(i - 1, -1)); }
    else if (e.key === "Enter") {
      e.preventDefault();
      if (active >= 0 && suggestions[active]) go(suggestions[active]);
      else if (query.trim()) submit();
    } else if (e.key === "Escape") { setOpen(false); }
  }

  const icon = (k: Suggestion["kind"]) => (k === "company" ? "🏢" : k === "category" ? "🏷️" : "📍");

  return (
    <div ref={boxRef} className="relative w-full max-w-2xl">
      <input
        type="search"
        role="combobox"
        aria-expanded={open}
        aria-controls="autocomplete-list"
        autoComplete="off"
        value={query}
        onChange={(e) => { setQuery(e.target.value); setOpen(true); setActive(-1); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        placeholder="Rechercher une entreprise, une catégorie, une ville…"
        aria-label="Rechercher"
        className="w-full rounded-full border border-gray-300 px-5 py-3 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
      />
      {open && suggestions.length > 0 && (
        <ul
          id="autocomplete-list"
          role="listbox"
          className="absolute z-10 mt-2 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg"
        >
          {suggestions.map((s, i) => (
            <li
              key={`${s.kind}-${s.label}`}
              role="option"
              data-kind={s.kind}
              aria-selected={i === active}
              onMouseDown={(e) => { e.preventDefault(); go(s); }}
              onMouseEnter={() => setActive(i)}
              className={`flex cursor-pointer items-center justify-between px-4 py-2.5 ${i === active ? "bg-blue-50" : ""}`}
            >
              <span className="flex items-center gap-2">
                <span aria-hidden>{icon(s.kind)}</span>
                <span>{s.label}</span>
              </span>
              {s.meta && <span className="text-xs text-gray-400">{s.meta}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
```

- [ ] **Step 4: Lancer → succès**

Run: `npm run test -- SearchAutocomplete && npx tsc --noEmit`
Expected: PASS (3 tests).

> Si `@testing-library/user-event` n'est pas installé : `npm install -D @testing-library/user-event` puis relancer.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/components/SearchAutocomplete.tsx web/components/SearchAutocomplete.test.tsx web/package.json web/package-lock.json && git commit -m "feat(web): composant SearchAutocomplete (3 sections + clavier)"
```

---

### Task 4: `FullDetailsCTA` + refonte zone gated de la fiche

**Files:**
- Create: `web/components/FullDetailsCTA.tsx`
- Create: `web/components/FullDetailsCTA.test.tsx`
- Modify: `web/app/entreprise/[slug]/page.tsx`
- Delete: `web/components/Paywall.tsx`, `web/components/Paywall.test.tsx`
- Modify: `web/e2e/fiche.spec.ts`, `web/e2e/fiche-authed.spec.ts`

**Interfaces:**
- Consumes: `getCompanyBySlug`, `getCompany`, `isAuthenticated`, `gateCompany` (existants).
- Produces: `<FullDetailsCTA />` (bouton unique → `/login`) ; fiche visiteur affiche champs masqués + un seul CTA.

- [ ] **Step 1: Test d'échec (FullDetailsCTA)**

Create `web/components/FullDetailsCTA.test.tsx` :
```tsx
import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { FullDetailsCTA } from "@/components/FullDetailsCTA";

describe("FullDetailsCTA", () => {
  it("bouton unique vers /login", () => {
    render(<FullDetailsCTA />);
    const link = screen.getByRole("link", { name: /voir les détails complets/i });
    expect(link.getAttribute("href")).toBe("/login");
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `npm run test -- FullDetailsCTA`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémenter FullDetailsCTA**

Create `web/components/FullDetailsCTA.tsx` :
```tsx
import Link from "next/link";

export function FullDetailsCTA() {
  return (
    <Link
      href="/login"
      className="inline-flex items-center justify-center rounded-md bg-blue-600 px-5 py-2.5 font-medium text-white hover:bg-blue-700"
    >
      Voir les détails complets
    </Link>
  );
}
```

- [ ] **Step 4: Lancer → succès**

Run: `npm run test -- FullDetailsCTA`
Expected: PASS.

- [ ] **Step 5: Refondre la zone gated de la fiche**

Dans `web/app/entreprise/[slug]/page.tsx` :
- remplacer l'import `import { Paywall } from "@/components/Paywall";` par `import { FullDetailsCTA } from "@/components/FullDetailsCTA";`
- remplacer entièrement le bloc `<section className="mt-6 grid gap-3"> … </section>` par :
```tsx
      {authed ? (
        <section className="mt-6 grid gap-3">
          <div>
            <span className="text-sm font-medium">Téléphone : </span>
            <span>{raw.phone || "—"}</span>
          </div>
          <div>
            <span className="text-sm font-medium">Site web : </span>
            {raw.url ? (
              <a className="text-blue-600 underline" href={raw.url}>{raw.url}</a>
            ) : <span>—</span>}
          </div>
          <div>
            <span className="text-sm font-medium">Emails : </span>
            <span>{raw.emails.length > 0 ? raw.emails.join(", ") : "—"}</span>
          </div>
        </section>
      ) : (
        <section className="mt-6">
          <ul className="grid gap-2 text-gray-400 select-none">
            <li>Téléphone : ••• •• •• ••</li>
            <li>Site web : ••••••••••••</li>
            <li>Emails : ••••••@••••••</li>
          </ul>
          <div className="mt-4">
            <FullDetailsCTA />
          </div>
        </section>
      )}
```
> `authed` et `raw` existent déjà dans le composant (issus de `isAuthenticated` et `resolve`). La variable `company` (issue de `gateCompany`) n'est plus utilisée dans cette section ; garder son usage pour le `<h1>`/entête tel quel.

- [ ] **Step 6: Supprimer Paywall**

```bash
rm web/components/Paywall.tsx web/components/Paywall.test.tsx
```

- [ ] **Step 7: Mettre à jour l'E2E fiche visiteur**

Replace `web/e2e/fiche.spec.ts` avec :
```ts
import { test, expect } from "@playwright/test";

test("fiche publique : données publiques + bouton détails complets", async ({ page }) => {
  await page.goto("/recherche?q=paris");
  await page.getByRole("link").first().click();
  await expect(page).toHaveURL(/\/entreprise\//);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  // Un seul bouton « Voir les détails complets », plus de « Se connecter pour voir »
  await expect(page.getByRole("link", { name: /voir les détails complets/i })).toBeVisible();
  await expect(page.getByText(/se connecter pour voir/i)).toHaveCount(0);
  // Preuve SEO : JSON-LD présent
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(1);
});
```

- [ ] **Step 8: Mettre à jour l'E2E fiche authentifiée**

Dans `web/e2e/fiche-authed.spec.ts`, remplacer la dernière assertion :
```ts
  // Au moins un champ gated ne doit plus afficher le paywall
  await expect(page.getByText(/réservé aux membres/)).toHaveCount(0);
```
par :
```ts
  // Connecté : pas de CTA « Voir les détails complets »
  await expect(page.getByRole("link", { name: /voir les détails complets/i })).toHaveCount(0);
```

- [ ] **Step 9: Vérifs + E2E fiche**

Run:
```bash
npm run test && npx tsc --noEmit
fuser -k 3100/tcp 2>/dev/null; sleep 1
nohup npm run dev > /tmp/nextdev3100.log 2>&1 &
until grep -qE "Ready in" /tmp/nextdev3100.log; do sleep 1; done
npx playwright test fiche --reporter=list
```
Expected: unités PASS ; E2E fiche PASS (fiche-authed SKIPPED sans identifiants).

- [ ] **Step 10: Commit**

```bash
cd .. && git add web/components/FullDetailsCTA.tsx web/components/FullDetailsCTA.test.tsx web/app/entreprise web/e2e/fiche.spec.ts web/e2e/fiche-authed.spec.ts && git rm web/components/Paywall.tsx web/components/Paywall.test.tsx && git commit -m "feat(web): CTA unique 'Voir les détails complets' remplace les paywalls par champ"
```

---

### Task 5: Brancher l'autocomplétion + filtre catégorie

**Files:**
- Modify: `web/app/page.tsx`
- Modify: `web/app/recherche/page.tsx`
- Test: `web/e2e/autocomplete.spec.ts`
- Modify: `web/e2e/home.spec.ts`

**Interfaces:**
- Consumes: `SearchAutocomplete` (Task 3), `searchCompanies` (existant).
- Produces: accueil et `/recherche` utilisent l'autocomplétion ; `/recherche?categorie=` filtre par catégorie.

- [ ] **Step 1: Accueil utilise l'autocomplétion**

Dans `web/app/page.tsx`, remplacer l'import et l'usage de `SearchBar` par `SearchAutocomplete` :
```tsx
import { SearchAutocomplete } from "@/components/SearchAutocomplete";

export default function Home() {
  return (
    <main className="mx-auto flex max-w-3xl flex-col items-center gap-6 p-10 text-center">
      <h1 className="text-3xl font-bold">Annuaire des entreprises françaises</h1>
      <p className="text-gray-600">Recherchez parmi des millions d&apos;entreprises.</p>
      <SearchAutocomplete />
    </main>
  );
}
```

- [ ] **Step 2: `/recherche` utilise l'autocomplétion + lit `categorie`**

Dans `web/app/recherche/page.tsx` :
- remplacer `import { SearchBar } from "@/components/SearchBar";` par `import { SearchAutocomplete } from "@/components/SearchAutocomplete";`
- remplacer `<SearchBar defaultQuery={q} />` par `<SearchAutocomplete defaultQuery={q} />`
- lire le paramètre catégorie et le passer à l'API. Remplacer le bloc de lecture des params + l'appel `searchCompanies` par :
```tsx
  const sp = await searchParams;
  const q = sp.q ?? "";
  const city = sp.ville ?? "";
  const category = sp.categorie ?? "";
  const page = Math.max(1, Number(sp.page ?? "1") || 1);

  const data = await searchCompanies({ q, city, category, page, per_page: 20, sort_by: "rating" });
```
- inclure `categorie` dans `makeHref` (conservation du filtre en pagination). Remplacer le corps de `makeHref` par :
```tsx
  const makeHref = (p: number) => {
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (city) params.set("ville", city);
    if (category) params.set("categorie", category);
    params.set("page", String(p));
    return `/recherche?${params.toString()}`;
  };
```

- [ ] **Step 3: E2E autocomplétion (d'échec avant branchement complet)**

Create `web/e2e/autocomplete.spec.ts` :
```ts
import { test, expect } from "@playwright/test";

test("focus affiche des exemples, la frappe filtre, le clic navigue", async ({ page }) => {
  await page.goto("/");
  const box = page.getByRole("combobox");
  await box.click();
  // exemples préchargés (une catégorie très courante)
  await expect(page.getByRole("option").first()).toBeVisible();

  await box.fill("restau");
  // cible explicitement la suggestion de type "catégorie" (les entreprises peuvent aussi matcher)
  const catOption = page.locator('li[role="option"][data-kind="category"]').first();
  await expect(catOption).toBeVisible();
  await catOption.click();
  await expect(page).toHaveURL(/\/recherche\?categorie=/);
});
```

- [ ] **Step 4: Mettre à jour l'E2E accueil** (le rôle passe de `searchbox` simple à `combobox`)

Replace `web/e2e/home.spec.ts` avec :
```ts
import { test, expect } from "@playwright/test";

test("l'accueil affiche la barre d'autocomplétion", async ({ page }) => {
  await page.goto("/");
  const box = page.getByRole("combobox");
  await expect(box).toBeVisible();
  await box.fill("boulangerie");
  await box.press("Enter");
  await expect(page).toHaveURL(/\/recherche\?q=boulangerie/);
});
```

- [ ] **Step 5: Lancer les E2E → succès**

Run:
```bash
fuser -k 3100/tcp 2>/dev/null; sleep 1
nohup npm run dev > /tmp/nextdev3100.log 2>&1 &
until grep -qE "Ready in" /tmp/nextdev3100.log; do sleep 1; done
npx playwright test autocomplete home recherche --reporter=list
```
Expected: PASS.

- [ ] **Step 6: Suite complète + typecheck + lint**

Run: `npm run test && npx tsc --noEmit && npm run lint && npx playwright test`
Expected: unités PASS ; lint/tsc clean ; E2E PASS (fiche-authed SKIPPED).

- [ ] **Step 7: Commit**

```bash
cd .. && git add web/app/page.tsx web/app/recherche/page.tsx web/e2e/autocomplete.spec.ts web/e2e/home.spec.ts && git commit -m "feat(web): autocomplétion sur accueil + /recherche, filtre catégorie"
```

---

## Note

Le composant `SearchBar` d'origine reste dans le repo (non supprimé) — il n'est plus utilisé par les pages mais sert de fallback simple. Le supprimer serait un nettoyage optionnel hors périmètre.
