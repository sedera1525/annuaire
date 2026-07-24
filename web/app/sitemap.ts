import type { MetadataRoute } from "next";
import { searchCompanies } from "@/lib/api";
import { toSlug } from "@/lib/slug";

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? "http://localhost:3100";

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
