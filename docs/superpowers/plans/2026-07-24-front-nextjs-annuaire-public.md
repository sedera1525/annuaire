# Front Next.js — Annuaire public (tranche 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construire un front public Next.js (recherche + fiche société, freemium, SEO) qui consomme l'API FastAPI existante, sans modifier le backend.

**Architecture:** App Next.js isolée dans `web/`, App Router + rendu serveur (SSR pour la recherche, ISR pour les fiches). Toute la donnée vient de l'API FastAPI via un client typé (`web/lib/api.ts`). Le gating freemium est présentationnel (l'API `/api/company/*` est publique et renvoie tout). La détection de session sonde une route protégée (`/api/fiches/stats` → 200/401).

**Tech Stack:** Next.js 15 (App Router), React 19, TypeScript strict, Tailwind CSS, zod (validation runtime), Vitest (unitaire), Playwright (E2E).

## Global Constraints

- Backend FastAPI **inchangé** — aucune route Python modifiée/ajoutée. Valeurs verbatim :
  - Base URL API interne (serveur) : variable `API_URL`, défaut `http://localhost:8090`.
  - Endpoints consommés : `GET /api/search`, `GET /api/company/by-slug/{slug}`, `GET /api/company/{title}`, sonde d'auth `GET /api/fiches/stats`.
  - Cookie de session FastAPI : nom lu dynamiquement, transmis tel quel via l'en-tête `cookie`.
- TypeScript **strict** (`"strict": true`), pas de `any` implicite.
- Champs **gated** (masqués si non authentifié), verbatim : `phone`, `url`, `domain`, `emails`, `contacts`.
- Slug SEO : même règle que l'API (`sanitize_title` façon WordPress — minuscules, ASCII, `[^a-z0-9]+` → `-`, trim `-`).
- Tests obligatoires : toute unité livrée est couverte. `npx tsc --noEmit` doit passer.
- Tous les chemins ci-dessous sont relatifs à la racine du repo `societies/`. Les commandes s'exécutent depuis `web/` sauf mention contraire.
- Prérequis E2E : FastAPI tourne sur `:8090` (déjà le cas via `python3 main.py`).

---

### Task 1: Scaffold de l'app Next.js + outillage de test

**Files:**
- Create: `web/` (via create-next-app)
- Create: `web/vitest.config.ts`
- Create: `web/playwright.config.ts`
- Create: `web/lib/smoke.test.ts`
- Create: `web/.env.local`
- Modify: `.gitignore` (racine) — ajouter les artefacts Node

**Interfaces:**
- Consumes: rien.
- Produces: projet `web/` compilable, `npm run test` (Vitest) et `npx playwright test` fonctionnels.

- [ ] **Step 1: Scaffolder l'app**

Depuis la racine du repo :
```bash
npx create-next-app@latest web --typescript --tailwind --app --eslint --no-src-dir --import-alias "@/*" --use-npm --yes
```

- [ ] **Step 2: Installer les dépendances de test + zod**

```bash
cd web
npm install zod
npm install -D vitest @vitejs/plugin-react jsdom @testing-library/react @testing-library/jest-dom @playwright/test
npx playwright install chromium
```

- [ ] **Step 3: Config Vitest**

Create `web/vitest.config.ts` :
```ts
import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "node:path";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    include: ["lib/**/*.test.ts", "lib/**/*.test.tsx", "components/**/*.test.tsx"],
  },
  resolve: {
    alias: { "@": path.resolve(__dirname, ".") },
  },
});
```

Ajouter à `web/package.json` dans `"scripts"` :
```json
"test": "vitest run",
"test:watch": "vitest",
"e2e": "playwright test"
```

- [ ] **Step 4: Config Playwright**

Create `web/playwright.config.ts` :
```ts
import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  timeout: 30_000,
  use: { baseURL: "http://localhost:3000" },
  webServer: {
    command: "npm run dev",
    url: "http://localhost:3000",
    reuseExistingServer: true,
    timeout: 60_000,
  },
});
```

- [ ] **Step 5: Variable d'environnement**

Create `web/.env.local` :
```
API_URL=http://localhost:8090
```

- [ ] **Step 6: Test de fumée (échec attendu)**

Create `web/lib/smoke.test.ts` :
```ts
import { describe, it, expect } from "vitest";
import { ping } from "@/lib/smoke";

describe("smoke", () => {
  it("returns pong", () => {
    expect(ping()).toBe("pong");
  });
});
```

- [ ] **Step 7: Lancer le test → échec**

Run: `cd web && npm run test`
Expected: FAIL — `Cannot find module '@/lib/smoke'`.

- [ ] **Step 8: Implémentation minimale**

Create `web/lib/smoke.ts` :
```ts
export function ping(): string {
  return "pong";
}
```

- [ ] **Step 9: Lancer le test → succès + typecheck**

Run: `cd web && npm run test && npx tsc --noEmit`
Expected: PASS, aucune erreur TS.

- [ ] **Step 10: Ignore Node dans le .gitignore racine**

Modify `.gitignore` (racine), ajouter à la fin :
```
# Front Next.js
web/node_modules/
web/.next/
web/.env.local
web/playwright-report/
web/test-results/
```

- [ ] **Step 11: Commit**

```bash
cd .. && git add web .gitignore && git commit -m "feat(web): scaffold Next.js app + Vitest/Playwright"
```

---

### Task 2: Types & schémas de validation (lib/types.ts)

**Files:**
- Create: `web/lib/types.ts`
- Test: `web/lib/types.test.ts`

**Interfaces:**
- Consumes: rien.
- Produces:
  - `companySearchResultSchema`, `searchResponseSchema`, `companySchema`, `companySlugSchema` (zod).
  - Types : `CompanySearchResult`, `SearchResponse`, `Company`, `CompanySlug`.

- [ ] **Step 1: Test d'échec**

Create `web/lib/types.test.ts` :
```ts
import { describe, it, expect } from "vitest";
import { searchResponseSchema, companySchema } from "@/lib/types";

describe("searchResponseSchema", () => {
  it("parse une réponse /api/search valide", () => {
    const parsed = searchResponseSchema.parse({
      results: [{ title: "ACME", category: "Café", city: "Paris", zip_code: "75001",
        region: "IDF", rating_value: 4.5, rating_votes: 12, logo: "", snippet: "s",
        phone: "0102", url: "http://a", domain: "a.fr", emails: ["x@a.fr"] }],
      total: 1, page: 1, per_page: 50, pages: 1, elapsed: 0.01,
    });
    expect(parsed.results[0].title).toBe("ACME");
    expect(parsed.total).toBe(1);
  });

  it("tolère les champs optionnels manquants/nuls", () => {
    const parsed = searchResponseSchema.parse({
      results: [{ title: "SoloCorp" }],
      total: 1, page: 1, per_page: 50, pages: 1, elapsed: 0,
    });
    expect(parsed.results[0].title).toBe("SoloCorp");
    expect(parsed.results[0].emails).toEqual([]);
  });
});

describe("companySchema", () => {
  it("exige un title", () => {
    expect(() => companySchema.parse({})).toThrow();
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `cd web && npm run test -- types`
Expected: FAIL — `Cannot find module '@/lib/types'`.

- [ ] **Step 3: Implémentation**

Create `web/lib/types.ts` :
```ts
import { z } from "zod";

// Élément de résultat renvoyé par /api/search
export const companySearchResultSchema = z.object({
  title: z.string(),
  category: z.string().nullish().transform((v) => v ?? ""),
  phone: z.string().nullish().transform((v) => v ?? ""),
  url: z.string().nullish().transform((v) => v ?? ""),
  domain: z.string().nullish().transform((v) => v ?? ""),
  addr_street: z.string().nullish().transform((v) => v ?? ""),
  city: z.string().nullish().transform((v) => v ?? ""),
  zip_code: z.string().nullish().transform((v) => v ?? ""),
  region: z.string().nullish().transform((v) => v ?? ""),
  address_full: z.string().nullish().transform((v) => v ?? ""),
  rating_value: z.number().nullish().transform((v) => v ?? 0),
  rating_votes: z.number().nullish().transform((v) => v ?? 0),
  logo: z.string().nullish().transform((v) => v ?? ""),
  snippet: z.string().nullish().transform((v) => v ?? ""),
  emails: z.array(z.string()).nullish().transform((v) => v ?? []),
});
export type CompanySearchResult = z.infer<typeof companySearchResultSchema>;

export const searchResponseSchema = z.object({
  results: z.array(companySearchResultSchema),
  total: z.number(),
  page: z.number(),
  per_page: z.number(),
  pages: z.number(),
  elapsed: z.number(),
});
export type SearchResponse = z.infer<typeof searchResponseSchema>;

// Fiche complète renvoyée par /api/company/{title} — superset tolérant
export const companySchema = companySearchResultSchema.extend({
  contacts: z.unknown().nullish(),
  latitude: z.number().nullish(),
  longitude: z.number().nullish(),
  is_claimed: z.union([z.boolean(), z.number()]).nullish(),
}).passthrough();
export type Company = z.infer<typeof companySchema>;

// /api/company/by-slug/{slug}
export const companySlugSchema = z.object({
  title: z.string(),
  city: z.string().nullish().transform((v) => v ?? ""),
  category: z.string().nullish().transform((v) => v ?? ""),
  zip_code: z.string().nullish().transform((v) => v ?? ""),
});
export type CompanySlug = z.infer<typeof companySlugSchema>;
```

- [ ] **Step 4: Lancer → succès**

Run: `cd web && npm run test -- types && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/lib/types.ts web/lib/types.test.ts && git commit -m "feat(web): types & schémas zod de l'API"
```

---

### Task 3: Génération de slug (lib/slug.ts)

**Files:**
- Create: `web/lib/slug.ts`
- Test: `web/lib/slug.test.ts`

**Interfaces:**
- Consumes: rien.
- Produces: `toSlug(title: string): string`.

- [ ] **Step 1: Test d'échec**

Create `web/lib/slug.test.ts` :
```ts
import { describe, it, expect } from "vitest";
import { toSlug } from "@/lib/slug";

describe("toSlug", () => {
  it("minuscule + tirets", () => {
    expect(toSlug("Café de la Paix")).toBe("cafe-de-la-paix");
  });
  it("gère & et espaces multiples", () => {
    expect(toSlug("JACK & JONES")).toBe("jack-jones");
  });
  it("supprime les tirets de bord", () => {
    expect(toSlug("  --Hello--  ")).toBe("hello");
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `cd web && npm run test -- slug`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémentation** (miroir de `to_slug` de `routers/search.py`)

Create `web/lib/slug.ts` :
```ts
export function toSlug(title: string): string {
  return title
    .toLowerCase()
    .trim()
    .normalize("NFKD")
    .replace(/[̀-ͯ]/g, "") // enlève les diacritiques combinants
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}
```

- [ ] **Step 4: Lancer → succès**

Run: `cd web && npm run test -- slug`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/lib/slug.ts web/lib/slug.test.ts && git commit -m "feat(web): helper toSlug (miroir sanitize_title)"
```

---

### Task 4: Client API (lib/api.ts)

**Files:**
- Create: `web/lib/api.ts`
- Test: `web/lib/api.test.ts`

**Interfaces:**
- Consumes: `web/lib/types.ts` (schémas + types).
- Produces:
  - `interface SearchParams { q?: string; city?: string; zip_code?: string; category?: string; page?: number; per_page?: number; sort_by?: string; only_with_fiche?: boolean; }`
  - `searchCompanies(params: SearchParams, cookie?: string): Promise<SearchResponse>`
  - `getCompanyBySlug(slug: string, cookie?: string): Promise<CompanySlug | null>`
  - `getCompany(title: string, cookie?: string): Promise<Company | null>`

- [ ] **Step 1: Test d'échec**

Create `web/lib/api.test.ts` :
```ts
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { searchCompanies, getCompanyBySlug, getCompany } from "@/lib/api";

const okJson = (body: unknown) =>
  ({ ok: true, status: 200, json: async () => body }) as Response;

beforeEach(() => { vi.restoreAllMocks(); });
afterEach(() => { vi.unstubAllGlobals(); });

describe("searchCompanies", () => {
  it("construit la query et parse la réponse", async () => {
    const fetchMock = vi.fn(async () =>
      okJson({ results: [{ title: "ACME" }], total: 1, page: 1, per_page: 50, pages: 1, elapsed: 0 }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const res = await searchCompanies({ q: "acme", page: 2, per_page: 50 });

    expect(res.results[0].title).toBe("ACME");
    const url = String(fetchMock.mock.calls[0][0]);
    expect(url).toContain("/api/search");
    expect(url).toContain("q=acme");
    expect(url).toContain("page=2");
  });
});

describe("getCompanyBySlug", () => {
  it("renvoie null quand l'API renvoie null", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => okJson(null)));
    expect(await getCompanyBySlug("inexistant")).toBeNull();
  });
});

describe("getCompany", () => {
  it("renvoie null sur 404", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({ ok: false, status: 404 }) as Response));
    expect(await getCompany("Inconnu")).toBeNull();
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `cd web && npm run test -- api`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémentation**

Create `web/lib/api.ts` :
```ts
import {
  searchResponseSchema, companySchema, companySlugSchema,
  type SearchResponse, type Company, type CompanySlug,
} from "@/lib/types";

const API_URL = process.env.API_URL ?? "http://localhost:8090";

export interface SearchParams {
  q?: string; city?: string; zip_code?: string; category?: string;
  page?: number; per_page?: number; sort_by?: string; only_with_fiche?: boolean;
}

function headers(cookie?: string): HeadersInit {
  return cookie ? { cookie } : {};
}

export async function searchCompanies(params: SearchParams, cookie?: string): Promise<SearchResponse> {
  const qs = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== "") qs.set(k, String(v));
  }
  const res = await fetch(`${API_URL}/api/search?${qs.toString()}`, {
    headers: headers(cookie),
    cache: "no-store",
  });
  if (!res.ok) throw new Error(`search failed: ${res.status}`);
  return searchResponseSchema.parse(await res.json());
}

export async function getCompanyBySlug(slug: string, cookie?: string): Promise<CompanySlug | null> {
  const res = await fetch(`${API_URL}/api/company/by-slug/${encodeURIComponent(slug)}`, {
    headers: headers(cookie),
    next: { revalidate: 86400 },
  });
  if (!res.ok) return null;
  const body = await res.json();
  if (body === null) return null;
  return companySlugSchema.parse(body);
}

export async function getCompany(title: string, cookie?: string): Promise<Company | null> {
  const res = await fetch(`${API_URL}/api/company/${encodeURIComponent(title)}`, {
    headers: headers(cookie),
    next: { revalidate: 86400 },
  });
  if (!res.ok) return null;
  return companySchema.parse(await res.json());
}
```

- [ ] **Step 4: Lancer → succès**

Run: `cd web && npm run test -- api && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/lib/api.ts web/lib/api.test.ts && git commit -m "feat(web): client API typé (search/company/by-slug)"
```

---

### Task 5: Gating freemium (lib/freemium.ts)

**Files:**
- Create: `web/lib/freemium.ts`
- Test: `web/lib/freemium.test.ts`

**Interfaces:**
- Consumes: `web/lib/types.ts` (`Company`).
- Produces:
  - `GATED_FIELDS: readonly ["phone","url","domain","emails","contacts"]`
  - `gateCompany(company: Company, isAuthenticated: boolean): { company: Company; hidden: string[] }`

- [ ] **Step 1: Test d'échec**

Create `web/lib/freemium.test.ts` :
```ts
import { describe, it, expect } from "vitest";
import { gateCompany, GATED_FIELDS } from "@/lib/freemium";
import { companySchema } from "@/lib/types";

const full = companySchema.parse({
  title: "ACME", city: "Paris", category: "Café",
  phone: "0102030405", url: "http://acme.fr", domain: "acme.fr",
  emails: ["x@acme.fr"], contacts: [{ type: "Mail", value: "x@acme.fr" }],
});

describe("gateCompany", () => {
  it("visiteur non authentifié : champs gated masqués", () => {
    const { company, hidden } = gateCompany(full, false);
    expect(company.phone).toBe("");
    expect(company.emails).toEqual([]);
    expect(company.url).toBe("");
    expect(hidden).toEqual([...GATED_FIELDS]);
    expect(company.title).toBe("ACME"); // champ public intact
  });

  it("utilisateur authentifié : rien n'est masqué", () => {
    const { company, hidden } = gateCompany(full, true);
    expect(company.phone).toBe("0102030405");
    expect(hidden).toEqual([]);
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `cd web && npm run test -- freemium`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémentation**

Create `web/lib/freemium.ts` :
```ts
import type { Company } from "@/lib/types";

export const GATED_FIELDS = ["phone", "url", "domain", "emails", "contacts"] as const;

export function gateCompany(
  company: Company,
  isAuthenticated: boolean,
): { company: Company; hidden: string[] } {
  if (isAuthenticated) return { company, hidden: [] };

  const masked: Company = { ...company };
  masked.phone = "";
  masked.url = "";
  masked.domain = "";
  masked.emails = [];
  masked.contacts = null;
  return { company: masked, hidden: [...GATED_FIELDS] };
}
```

- [ ] **Step 4: Lancer → succès**

Run: `cd web && npm run test -- freemium && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/lib/freemium.ts web/lib/freemium.test.ts && git commit -m "feat(web): gating freemium des champs de fiche"
```

---

### Task 6: Détection de session (lib/auth.ts)

**Files:**
- Create: `web/lib/auth.ts`
- Test: `web/lib/auth.test.ts`

**Interfaces:**
- Consumes: rien.
- Produces: `isAuthenticated(cookie?: string): Promise<boolean>` (sonde `GET /api/fiches/stats` : 200 → true, sinon false).

- [ ] **Step 1: Test d'échec**

Create `web/lib/auth.test.ts` :
```ts
import { describe, it, expect, vi, afterEach } from "vitest";
import { isAuthenticated } from "@/lib/auth";

afterEach(() => vi.unstubAllGlobals());

describe("isAuthenticated", () => {
  it("false sans cookie (aucun appel réseau)", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    expect(await isAuthenticated(undefined)).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("true si la sonde renvoie 200", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({ ok: true, status: 200 }) as Response));
    expect(await isAuthenticated("session=abc")).toBe(true);
  });

  it("false si la sonde renvoie 401", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({ ok: false, status: 401 }) as Response));
    expect(await isAuthenticated("session=abc")).toBe(false);
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `cd web && npm run test -- auth`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémentation**

Create `web/lib/auth.ts` :
```ts
const API_URL = process.env.API_URL ?? "http://localhost:8090";

export async function isAuthenticated(cookie?: string): Promise<boolean> {
  if (!cookie) return false;
  try {
    const res = await fetch(`${API_URL}/api/fiches/stats`, {
      headers: { cookie },
      cache: "no-store",
    });
    return res.ok;
  } catch {
    return false;
  }
}
```

- [ ] **Step 4: Lancer → succès**

Run: `cd web && npm run test -- auth && npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd .. && git add web/lib/auth.ts web/lib/auth.test.ts && git commit -m "feat(web): détection de session via sonde /api/fiches/stats"
```

---

### Task 7: Page recherche `/recherche` (SSR) + composants

**Files:**
- Create: `web/components/CompanyCard.tsx`
- Create: `web/components/Pagination.tsx`
- Create: `web/components/SearchBar.tsx`
- Create: `web/app/recherche/page.tsx`
- Test: `web/components/CompanyCard.test.tsx`
- Test: `web/e2e/recherche.spec.ts`

**Interfaces:**
- Consumes: `searchCompanies` (Task 4), `toSlug` (Task 3), types (Task 2).
- Produces: route `/recherche?q=&ville=&page=` rendue serveur ; `CompanyCard`, `Pagination`, `SearchBar` réutilisables par les autres pages.

- [ ] **Step 1: Test unitaire d'échec (CompanyCard)**

Create `web/components/CompanyCard.test.tsx` :
```tsx
import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { CompanyCard } from "@/components/CompanyCard";
import { companySearchResultSchema } from "@/lib/types";

describe("CompanyCard", () => {
  it("affiche le nom et lie vers /entreprise/<slug>", () => {
    const c = companySearchResultSchema.parse({ title: "Café de la Paix", city: "Paris" });
    render(<CompanyCard company={c} />);
    expect(screen.getByText("Café de la Paix")).toBeTruthy();
    const link = screen.getByRole("link");
    expect(link.getAttribute("href")).toBe("/entreprise/cafe-de-la-paix");
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `cd web && npm run test -- CompanyCard`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémenter CompanyCard**

Create `web/components/CompanyCard.tsx` :
```tsx
import Link from "next/link";
import { toSlug } from "@/lib/slug";
import type { CompanySearchResult } from "@/lib/types";

export function CompanyCard({ company }: { company: CompanySearchResult }) {
  return (
    <Link
      href={`/entreprise/${toSlug(company.title)}`}
      className="block rounded-lg border border-gray-200 p-4 hover:shadow-md transition"
    >
      <h2 className="font-semibold text-lg">{company.title}</h2>
      <p className="text-sm text-gray-600">
        {[company.category, company.city, company.zip_code].filter(Boolean).join(" · ")}
      </p>
      {company.rating_votes > 0 && (
        <p className="mt-1 text-sm text-amber-600">
          ★ {company.rating_value.toFixed(1)} ({company.rating_votes} avis)
        </p>
      )}
    </Link>
  );
}
```

- [ ] **Step 4: Lancer → succès**

Run: `cd web && npm run test -- CompanyCard`
Expected: PASS.

- [ ] **Step 5: Implémenter Pagination**

Create `web/components/Pagination.tsx` :
```tsx
import Link from "next/link";

export function Pagination({ page, pages, makeHref }: {
  page: number; pages: number; makeHref: (p: number) => string;
}) {
  return (
    <nav className="flex items-center gap-4 mt-6" aria-label="Pagination">
      {page > 1 && <Link className="underline" href={makeHref(page - 1)}>← Précédent</Link>}
      <span className="text-sm text-gray-600">Page {page} / {pages}</span>
      {page < pages && <Link className="underline" href={makeHref(page + 1)}>Suivant →</Link>}
    </nav>
  );
}
```

- [ ] **Step 6: Implémenter SearchBar**

Create `web/components/SearchBar.tsx` :
```tsx
export function SearchBar({ defaultQuery = "" }: { defaultQuery?: string }) {
  return (
    <form action="/recherche" method="get" className="flex gap-2 w-full max-w-2xl">
      <input
        type="search"
        name="q"
        defaultValue={defaultQuery}
        placeholder="Rechercher une entreprise…"
        className="flex-1 rounded-md border border-gray-300 px-4 py-2"
        aria-label="Rechercher une entreprise"
      />
      <button type="submit" className="rounded-md bg-blue-600 px-4 py-2 text-white">
        Rechercher
      </button>
    </form>
  );
}
```

- [ ] **Step 7: Implémenter la page `/recherche` (Server Component)**

Create `web/app/recherche/page.tsx` :
```tsx
import { searchCompanies } from "@/lib/api";
import { CompanyCard } from "@/components/CompanyCard";
import { Pagination } from "@/components/Pagination";
import { SearchBar } from "@/components/SearchBar";

export const dynamic = "force-dynamic";

type SP = Record<string, string | undefined>;

export function generateMetadata({ searchParams }: { searchParams: Promise<SP> }) {
  return searchParams.then((sp) => ({
    title: sp.q ? `Recherche : ${sp.q} — Annuaire` : "Recherche d'entreprises — Annuaire",
    description: "Recherchez parmi des millions d'entreprises françaises.",
  }));
}

export default async function RecherchePage({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const q = sp.q ?? "";
  const city = sp.ville ?? "";
  const page = Math.max(1, Number(sp.page ?? "1") || 1);

  const data = await searchCompanies({ q, city, page, per_page: 20, sort_by: "rating" });

  const makeHref = (p: number) => {
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (city) params.set("ville", city);
    params.set("page", String(p));
    return `/recherche?${params.toString()}`;
  };

  return (
    <main className="mx-auto max-w-4xl p-6">
      <SearchBar defaultQuery={q} />
      <p className="mt-4 text-sm text-gray-600">
        {data.total.toLocaleString("fr-FR")} résultats ({data.elapsed}s)
      </p>
      <div className="mt-4 grid gap-3">
        {data.results.map((c) => <CompanyCard key={c.title} company={c} />)}
      </div>
      <Pagination page={data.page} pages={data.pages} makeHref={makeHref} />
    </main>
  );
}
```

- [ ] **Step 8: E2E d'échec**

Create `web/e2e/recherche.spec.ts` :
```ts
import { test, expect } from "@playwright/test";

test("la recherche affiche des résultats", async ({ page }) => {
  await page.goto("/recherche?q=paris");
  await expect(page.getByRole("link").first()).toBeVisible();
  await expect(page.getByText(/résultats/)).toBeVisible();
});
```

- [ ] **Step 9: Lancer l'E2E → succès** (FastAPI doit tourner sur :8090)

Run: `cd web && npm run e2e -- recherche`
Expected: PASS (le webServer `next dev` démarre automatiquement).

- [ ] **Step 10: Typecheck + commit**

```bash
cd web && npx tsc --noEmit && cd .. && git add web/components web/app/recherche web/e2e/recherche.spec.ts && git commit -m "feat(web): page recherche SSR + CompanyCard/Pagination/SearchBar"
```

---

### Task 8: Page fiche `/entreprise/[slug]` (ISR) — public + Paywall + SEO

**Files:**
- Create: `web/components/Paywall.tsx`
- Create: `web/components/CompanyJsonLd.tsx`
- Create: `web/app/entreprise/[slug]/page.tsx`
- Test: `web/components/Paywall.test.tsx`
- Test: `web/e2e/fiche.spec.ts`

**Interfaces:**
- Consumes: `getCompanyBySlug`, `getCompany` (Task 4), `gateCompany`/`GATED_FIELDS` (Task 5), `isAuthenticated` (Task 6), `toSlug` (Task 3).
- Produces: route `/entreprise/[slug]` en ISR, publique, avec `<Paywall>` sur les champs gated, `generateMetadata` et JSON-LD.

- [ ] **Step 1: Test unitaire d'échec (Paywall)**

Create `web/components/Paywall.test.tsx` :
```tsx
import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { Paywall } from "@/components/Paywall";

describe("Paywall", () => {
  it("affiche un CTA de connexion", () => {
    render(<Paywall label="Téléphone" />);
    expect(screen.getByText(/Téléphone/)).toBeTruthy();
    expect(screen.getByRole("link", { name: /connecter/i }).getAttribute("href")).toBe("/login");
  });
});
```

- [ ] **Step 2: Lancer → échec**

Run: `cd web && npm run test -- Paywall`
Expected: FAIL — module introuvable.

- [ ] **Step 3: Implémenter Paywall**

Create `web/components/Paywall.tsx` :
```tsx
import Link from "next/link";

export function Paywall({ label }: { label: string }) {
  return (
    <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 p-3">
      <p className="text-sm text-gray-500">{label} — réservé aux membres</p>
      <Link href="/login" className="text-sm font-medium text-blue-600 underline">
        Se connecter pour voir
      </Link>
    </div>
  );
}
```

- [ ] **Step 4: Lancer → succès**

Run: `cd web && npm run test -- Paywall`
Expected: PASS.

- [ ] **Step 5: Implémenter CompanyJsonLd**

Create `web/components/CompanyJsonLd.tsx` :
```tsx
import type { Company } from "@/lib/types";

export function CompanyJsonLd({ company }: { company: Company }) {
  const data: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    name: company.title,
    address: {
      "@type": "PostalAddress",
      streetAddress: company.addr_street || undefined,
      addressLocality: company.city || undefined,
      postalCode: company.zip_code || undefined,
      addressCountry: "FR",
    },
  };
  if (company.rating_votes > 0) {
    data.aggregateRating = {
      "@type": "AggregateRating",
      ratingValue: company.rating_value,
      reviewCount: company.rating_votes,
    };
  }
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: JSON.stringify(data) }}
    />
  );
}
```

- [ ] **Step 6: Implémenter la page fiche (ISR, publique — gating anonyme)**

Create `web/app/entreprise/[slug]/page.tsx` :
```tsx
import { notFound } from "next/navigation";
import { cookies } from "next/headers";
import { getCompanyBySlug, getCompany } from "@/lib/api";
import { gateCompany } from "@/lib/freemium";
import { isAuthenticated } from "@/lib/auth";
import { Paywall } from "@/components/Paywall";
import { CompanyJsonLd } from "@/components/CompanyJsonLd";
import type { Metadata } from "next";

export const revalidate = 86400; // ISR : re-rendu au plus une fois/jour

async function resolve(slug: string) {
  const ref = await getCompanyBySlug(slug);
  if (!ref) return null;
  return getCompany(ref.title);
}

export async function generateMetadata(
  { params }: { params: Promise<{ slug: string }> },
): Promise<Metadata> {
  const { slug } = await params;
  const company = await resolve(slug);
  if (!company) return { title: "Entreprise introuvable" };
  const loc = [company.city, company.zip_code].filter(Boolean).join(" ");
  return {
    title: `${company.title}${loc ? ` — ${loc}` : ""} | Annuaire`,
    description: company.snippet || `${company.title}, ${company.category} à ${company.city}.`,
    alternates: { canonical: `/entreprise/${slug}` },
    openGraph: { title: company.title, description: company.snippet || "" },
  };
}

export default async function FichePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const raw = await resolve(slug);
  if (!raw) notFound();

  const cookieHeader = (await cookies()).toString();
  const authed = await isAuthenticated(cookieHeader || undefined);
  const { company } = gateCompany(raw, authed);

  return (
    <main className="mx-auto max-w-3xl p-6">
      <CompanyJsonLd company={raw} />
      <h1 className="text-2xl font-bold">{company.title}</h1>
      <p className="text-gray-600">
        {[company.category, company.address_full || company.city].filter(Boolean).join(" · ")}
      </p>
      {company.rating_votes > 0 && (
        <p className="mt-1 text-amber-600">★ {company.rating_value.toFixed(1)} ({company.rating_votes} avis)</p>
      )}
      {company.snippet && <p className="mt-4">{company.snippet}</p>}

      <section className="mt-6 grid gap-3">
        <div>
          <span className="text-sm font-medium">Téléphone : </span>
          {company.phone ? <span>{company.phone}</span> : <Paywall label="Téléphone" />}
        </div>
        <div>
          <span className="text-sm font-medium">Site web : </span>
          {company.url ? (
            <a className="text-blue-600 underline" href={company.url}>{company.url}</a>
          ) : <Paywall label="Site web" />}
        </div>
        <div>
          <span className="text-sm font-medium">Emails : </span>
          {company.emails.length > 0 ? (
            <span>{company.emails.join(", ")}</span>
          ) : <Paywall label="Emails" />}
        </div>
      </section>
    </main>
  );
}
```

- [ ] **Step 7: E2E d'échec (visiteur anonyme)**

Create `web/e2e/fiche.spec.ts` :
```ts
import { test, expect } from "@playwright/test";

test("fiche publique : données publiques + paywall visible", async ({ page }) => {
  await page.goto("/recherche?q=paris");
  const firstCard = page.getByRole("link").first();
  await firstCard.click();
  await expect(page).toHaveURL(/\/entreprise\//);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  // Champ gated → paywall pour un visiteur non connecté
  await expect(page.getByText(/réservé aux membres/).first()).toBeVisible();
  // Preuve SEO : JSON-LD présent
  const jsonLd = page.locator('script[type="application/ld+json"]');
  await expect(jsonLd).toHaveCount(1);
});
```

- [ ] **Step 8: Lancer l'E2E → succès**

Run: `cd web && npm run e2e -- fiche`
Expected: PASS.

- [ ] **Step 9: Typecheck + commit**

```bash
cd web && npx tsc --noEmit && cd .. && git add web/components/Paywall.tsx web/components/CompanyJsonLd.tsx web/app/entreprise web/components/Paywall.test.tsx web/e2e/fiche.spec.ts && git commit -m "feat(web): page fiche ISR publique + paywall + JSON-LD/SEO"
```

---

### Task 9: Fiche pour utilisateur authentifié (champs complets)

**Files:**
- Create: `web/e2e/fiche-authed.spec.ts`

**Interfaces:**
- Consumes: page fiche (Task 8), sonde `isAuthenticated` (Task 6), login FastAPI `POST /login`.
- Produces: preuve E2E que la session débloque les champs gated. Aucune modification de code applicatif (la logique existe déjà via `gateCompany` + `isAuthenticated`).

> Ce test se connecte via l'API réelle avec des identifiants fournis par l'environnement. S'ils sont absents, le test est **sauté** (pas d'échec, pas de secret en dur).

- [ ] **Step 1: Écrire l'E2E authentifié**

Create `web/e2e/fiche-authed.spec.ts` :
```ts
import { test, expect } from "@playwright/test";

const USER = process.env.E2E_USERNAME;
const PASS = process.env.E2E_PASSWORD;

test("fiche connecté : téléphone/site débloqués (pas de paywall)", async ({ page, context }) => {
  test.skip(!USER || !PASS, "E2E_USERNAME / E2E_PASSWORD non fournis");

  // Connexion via le formulaire FastAPI, servi sur le même domaine en prod ;
  // en dev, on passe par l'API directement puis on injecte le cookie.
  const res = await context.request.post("http://localhost:8090/login", {
    form: { username: USER!, password: PASS! },
    maxRedirects: 0,
  });
  expect([200, 302]).toContain(res.status());

  await page.goto("/recherche?q=paris");
  await page.getByRole("link").first().click();
  await expect(page).toHaveURL(/\/entreprise\//);

  // Au moins un champ gated ne doit plus afficher le paywall
  await expect(page.getByText(/réservé aux membres/)).toHaveCount(0);
});
```

- [ ] **Step 2: Lancer** (avec identifiants si disponibles)

Run: `cd web && E2E_USERNAME=... E2E_PASSWORD=... npm run e2e -- fiche-authed`
Expected: PASS (ou SKIPPED si identifiants absents).

> Note : en dev, Next (`:3000`) et FastAPI (`:8090`) sont sur des origines différentes, donc le cookie posé sur `:8090` n'est pas automatiquement envoyé par le navigateur vers `:3000`. Ce test valide le **chemin serveur** (login API OK) ; la validation navigateur complète du déblocage se fait en préprod derrière le reverse proxy (même domaine), comme spécifié. Le déblocage lui-même est déjà couvert unitairement par `freemium.test.ts` (Task 5) et `auth.test.ts` (Task 6).

- [ ] **Step 3: Commit**

```bash
cd .. && git add web/e2e/fiche-authed.spec.ts && git commit -m "test(web): E2E fiche authentifiée (déblocage des champs gated)"
```

---

### Task 10: Sitemap (fiches à contenu) + robots

**Files:**
- Create: `web/app/robots.ts`
- Create: `web/app/sitemap.ts`
- Test: `web/e2e/seo.spec.ts`

**Interfaces:**
- Consumes: `searchCompanies` avec `only_with_fiche: true` (Task 4), `toSlug` (Task 3).
- Produces: `/robots.txt` et `/sitemap.xml` (limité aux entreprises ayant une fiche éditoriale — option a).

- [ ] **Step 1: Implémenter robots**

Create `web/app/robots.ts` :
```ts
import type { MetadataRoute } from "next";

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";

export default function robots(): MetadataRoute.Robots {
  return {
    rules: { userAgent: "*", allow: "/", disallow: ["/login"] },
    sitemap: `${SITE}/sitemap.xml`,
  };
}
```

- [ ] **Step 2: Implémenter sitemap (fiches à contenu, borné)**

Create `web/app/sitemap.ts` :
```ts
import type { MetadataRoute } from "next";
import { searchCompanies } from "@/lib/api";
import { toSlug } from "@/lib/slug";

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3000";

export const revalidate = 86400;

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base: MetadataRoute.Sitemap = [
    { url: `${SITE}/`, changeFrequency: "weekly", priority: 1 },
    { url: `${SITE}/recherche`, changeFrequency: "weekly", priority: 0.8 },
  ];
  // Option (a) : uniquement les entreprises avec fiche éditoriale.
  const data = await searchCompanies({ only_with_fiche: true, page: 1, per_page: 1000 });
  const fiches: MetadataRoute.Sitemap = data.results.map((c) => ({
    url: `${SITE}/entreprise/${toSlug(c.title)}`,
    changeFrequency: "monthly",
    priority: 0.6,
  }));
  return [...base, ...fiches];
}
```

- [ ] **Step 3: E2E d'échec**

Create `web/e2e/seo.spec.ts` :
```ts
import { test, expect } from "@playwright/test";

test("robots.txt référence le sitemap", async ({ request }) => {
  const res = await request.get("/robots.txt");
  expect(res.ok()).toBeTruthy();
  expect(await res.text()).toContain("Sitemap:");
});

test("sitemap.xml liste des URLs d'entreprise", async ({ request }) => {
  const res = await request.get("/sitemap.xml");
  expect(res.ok()).toBeTruthy();
  const body = await res.text();
  expect(body).toContain("<urlset");
  expect(body).toContain("/recherche");
});
```

- [ ] **Step 4: Lancer l'E2E → succès**

Run: `cd web && npm run e2e -- seo`
Expected: PASS.

- [ ] **Step 5: Ajouter la variable de site + commit**

Ajouter à `web/.env.local` :
```
NEXT_PUBLIC_SITE_URL=http://localhost:3000
```

```bash
cd .. && git add web/app/robots.ts web/app/sitemap.ts web/e2e/seo.spec.ts && git commit -m "feat(web): robots + sitemap (fiches à contenu)"
```

---

### Task 11: Page d'accueil `/` + finitions

**Files:**
- Modify: `web/app/page.tsx`
- Modify: `web/app/layout.tsx`
- Test: `web/e2e/home.spec.ts`

**Interfaces:**
- Consumes: `SearchBar` (Task 7).
- Produces: accueil statique avec barre de recherche ; métadonnées globales du site.

- [ ] **Step 1: E2E d'échec**

Create `web/e2e/home.spec.ts` :
```ts
import { test, expect } from "@playwright/test";

test("l'accueil affiche la barre de recherche", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("searchbox")).toBeVisible();
  await page.getByRole("searchbox").fill("boulangerie");
  await page.getByRole("button", { name: /rechercher/i }).click();
  await expect(page).toHaveURL(/\/recherche\?q=boulangerie/);
});
```

- [ ] **Step 2: Lancer → échec** (l'accueil par défaut de create-next-app n'a pas de searchbox)

Run: `cd web && npm run e2e -- home`
Expected: FAIL.

- [ ] **Step 3: Remplacer l'accueil**

Replace `web/app/page.tsx` avec :
```tsx
import { SearchBar } from "@/components/SearchBar";

export default function Home() {
  return (
    <main className="mx-auto flex max-w-3xl flex-col items-center gap-6 p-10 text-center">
      <h1 className="text-3xl font-bold">Annuaire des entreprises françaises</h1>
      <p className="text-gray-600">Recherchez parmi des millions d'entreprises.</p>
      <SearchBar />
    </main>
  );
}
```

- [ ] **Step 4: Métadonnées globales**

Dans `web/app/layout.tsx`, remplacer l'export `metadata` par :
```tsx
export const metadata = {
  title: { default: "Annuaire des entreprises françaises", template: "%s" },
  description: "Recherchez parmi des millions d'entreprises françaises.",
};
```

- [ ] **Step 5: Lancer → succès**

Run: `cd web && npm run e2e -- home`
Expected: PASS.

- [ ] **Step 6: Suite complète + typecheck + lint**

Run: `cd web && npm run test && npx tsc --noEmit && npm run lint && npm run e2e`
Expected: tout PASS (E2E authentifié SKIPPED si pas d'identifiants).

- [ ] **Step 7: Commit**

```bash
cd .. && git add web/app/page.tsx web/app/layout.tsx web/e2e/home.spec.ts && git commit -m "feat(web): page d'accueil + métadonnées globales"
```

---

## Notes de déploiement (hors code — pour la préprod)

Reverse proxy (Nginx/Caddy) sur le même domaine :
- `/` et le reste → Next.js (`next start`, port 3000)
- `/api/` et `/static/` → FastAPI (port 8090)

Cela met front et API sur la **même origine** → le cookie de session FastAPI est transmis par le navigateur, ce qui active le déblocage des champs gated côté fiche (validé en préprod, cf. Task 9).

Variables d'environnement de prod (`web/.env.production` ou équivalent) :
- `API_URL` = URL interne de FastAPI (ex. `http://127.0.0.1:8090`)
- `NEXT_PUBLIC_SITE_URL` = URL publique du site (pour sitemap/robots/canonical)
