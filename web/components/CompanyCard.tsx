import Link from "next/link";
import { toSlug } from "@/lib/slug";
import type { CompanySearchResult } from "@/lib/types";

export function CompanyCard({ company }: { company: CompanySearchResult }) {
  return (
    <Link
      href={`/entreprise/${toSlug(company.title)}`}
      className="group block rounded-xl border border-[var(--border)] bg-[var(--card)] p-5 transition hover:-translate-y-0.5 hover:border-[var(--accent)] hover:shadow-[0_14px_32px_-20px_var(--glow)]"
    >
      <h2 className="font-[family-name:var(--font-display)] text-lg font-semibold tracking-tight group-hover:text-[var(--accent)]">
        {company.title}
      </h2>
      <p className="mt-1 text-sm text-[var(--muted)]">
        {[company.category, company.city, company.zip_code].filter(Boolean).join(" · ")}
      </p>
      {company.rating_votes > 0 && (
        <p className="mt-2 text-sm text-amber-500">
          ★ {company.rating_value.toFixed(1)}{" "}
          <span className="text-[var(--muted)]">({company.rating_votes} avis)</span>
        </p>
      )}
    </Link>
  );
}
