import Link from "next/link";
import { getCategories, getCities, getCompanyCount } from "@/lib/api";
import { pickRandom } from "@/lib/random";
import { SearchAutocomplete } from "@/components/SearchAutocomplete";

export const dynamic = "force-dynamic"; // reshuffle des exemples à chaque visite

const fmt = (n: number) => n.toLocaleString("fr-FR");

export default async function Home() {
  const [categories, cities, total] = await Promise.all([
    getCategories(),
    getCities(),
    getCompanyCount(),
  ]);
  const catPicks = pickRandom(categories, 10);
  const cityPicks = pickRandom(cities, 8);

  return (
    <main className="hero-bg flex min-h-[100dvh] flex-col items-center justify-center px-6 py-20">
      <div className="w-full max-w-3xl text-center">
        <p
          className="fade-up text-[0.7rem] font-semibold uppercase tracking-[0.24em] text-[var(--muted)]"
          style={{ animationDelay: "0ms" }}
        >
          Annuaire des entreprises françaises
        </p>

        <h1
          className="fade-up mt-6 font-[family-name:var(--font-display)] text-5xl font-semibold leading-[1.03] tracking-tight sm:text-6xl"
          style={{ animationDelay: "80ms" }}
        >
          Trouvez n’importe quelle
          <br />
          <em className="italic text-[var(--accent)]">entreprise</em> en France
        </h1>

        <p
          className="fade-up mx-auto mt-6 max-w-xl text-lg text-[var(--muted)]"
          style={{ animationDelay: "160ms" }}
        >
          {total ? `${fmt(total)} sociétés référencées.` : "Des millions de sociétés référencées."}{" "}
          Recherchez par nom, catégorie ou ville.
        </p>

        <div
          className="fade-up mt-9 flex justify-center"
          style={{ animationDelay: "240ms" }}
        >
          <SearchAutocomplete />
        </div>

        {catPicks.length > 0 && (
          <section className="fade-up mt-14" style={{ animationDelay: "340ms" }}>
            <h2 className="text-[0.7rem] font-semibold uppercase tracking-[0.18em] text-[var(--muted)]">
              Explorez par catégorie
            </h2>
            <div className="mt-4 flex flex-wrap justify-center gap-2.5">
              {catPicks.map((c) => (
                <Link
                  key={c.category}
                  href={`/recherche?categorie=${encodeURIComponent(c.category)}`}
                  className="chip"
                >
                  <span>{c.category}</span>
                  <span className="chip-count">{fmt(c.count)}</span>
                </Link>
              ))}
            </div>
          </section>
        )}

        {cityPicks.length > 0 && (
          <section className="fade-up mt-8" style={{ animationDelay: "420ms" }}>
            <h2 className="text-[0.7rem] font-semibold uppercase tracking-[0.18em] text-[var(--muted)]">
              Grandes villes
            </h2>
            <div className="mt-4 flex flex-wrap justify-center gap-2.5">
              {cityPicks.map((c) => (
                <Link
                  key={c.city}
                  href={`/recherche?ville=${encodeURIComponent(c.city)}`}
                  className="chip"
                >
                  <span aria-hidden>📍</span>
                  <span>{c.city}</span>
                </Link>
              ))}
            </div>
          </section>
        )}
      </div>
    </main>
  );
}
