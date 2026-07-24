import { searchCompanies } from "@/lib/api";
import { CompanyCard } from "@/components/CompanyCard";
import { Pagination } from "@/components/Pagination";
import { SearchBar } from "@/components/SearchBar";
import type { Metadata } from "next";

export const dynamic = "force-dynamic";

type SP = Record<string, string | undefined>;

export async function generateMetadata(
  { searchParams }: { searchParams: Promise<SP> },
): Promise<Metadata> {
  const sp = await searchParams;
  return {
    title: sp.q ? `Recherche : ${sp.q} — Annuaire` : "Recherche d'entreprises — Annuaire",
    description: "Recherchez parmi des millions d'entreprises françaises.",
  };
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
