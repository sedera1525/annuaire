import { searchCompanies } from "@/lib/api";
import { CompanyCard } from "@/components/CompanyCard";
import { Pagination } from "@/components/Pagination";
import { SearchAutocomplete } from "@/components/SearchAutocomplete";
import type { Metadata } from "next";

export const dynamic = "force-dynamic";

type SP = Record<string, string | undefined>;

function headingFor(sp: SP): { eyebrow: string; title: string } {
  if (sp.categorie) return { eyebrow: "Catégorie", title: sp.categorie };
  if (sp.ville) return { eyebrow: "Ville", title: `Entreprises à ${sp.ville}` };
  if (sp.q) return { eyebrow: "Recherche", title: `« ${sp.q} »` };
  return { eyebrow: "Annuaire", title: "Toutes les entreprises" };
}

export async function generateMetadata(
  { searchParams }: { searchParams: Promise<SP> },
): Promise<Metadata> {
  const sp = await searchParams;
  const { title } = headingFor(sp);
  return {
    title: `${title} — Annuaire`,
    description: "Recherchez parmi des millions d'entreprises françaises.",
  };
}

export default async function RecherchePage({ searchParams }: { searchParams: Promise<SP> }) {
  const sp = await searchParams;
  const q = sp.q ?? "";
  const city = sp.ville ?? "";
  const category = sp.categorie ?? "";
  const page = Math.max(1, Number(sp.page ?? "1") || 1);

  const data = await searchCompanies({ q, city, category, page, per_page: 20, sort_by: "rating" });
  const { eyebrow, title } = headingFor(sp);

  const makeHref = (p: number) => {
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (city) params.set("ville", city);
    if (category) params.set("categorie", category);
    params.set("page", String(p));
    return `/recherche?${params.toString()}`;
  };

  return (
    <main className="mx-auto max-w-5xl px-6 py-10">
      <header className="border-b border-[var(--border)] pb-8">
        <p className="text-[0.7rem] font-semibold uppercase tracking-[0.2em] text-[var(--muted)]">
          {eyebrow}
        </p>
        <h1 className="mt-2 font-[family-name:var(--font-display)] text-3xl font-semibold tracking-tight sm:text-4xl">
          {title}
        </h1>
        <p className="mt-2 text-sm text-[var(--muted)]">
          {data.total.toLocaleString("fr-FR")} résultat{data.total > 1 ? "s" : ""} · {data.elapsed}s
        </p>
        <div className="mt-6">
          <SearchAutocomplete defaultQuery={q} />
        </div>
      </header>

      {data.results.length > 0 ? (
        <div className="mt-8 grid gap-4 sm:grid-cols-2">
          {data.results.map((c) => <CompanyCard key={c.title} company={c} />)}
        </div>
      ) : (
        <p className="mt-20 text-center text-[var(--muted)]">
          Aucune entreprise ne correspond à cette recherche.
        </p>
      )}

      <Pagination page={data.page} pages={data.pages} makeHref={makeHref} />
    </main>
  );
}
