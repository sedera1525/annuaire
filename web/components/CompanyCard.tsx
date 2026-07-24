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
