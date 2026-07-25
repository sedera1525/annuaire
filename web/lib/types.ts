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
}).loose();
export type Company = z.infer<typeof companySchema>;

// /api/categories et /api/cities (stats avec volumes)
export const categoryStatSchema = z.object({
  category: z.string(),
  count: z.number().nullish().transform((v) => v ?? 0),
});
export type CategoryStat = z.infer<typeof categoryStatSchema>;
export const categoriesSchema = z.array(categoryStatSchema);

export const cityStatSchema = z.object({
  city: z.string(),
  count: z.number().nullish().transform((v) => v ?? 0),
});
export type CityStat = z.infer<typeof cityStatSchema>;
export const citiesSchema = z.array(cityStatSchema);

// /api/company/by-slug/{slug}
export const companySlugSchema = z.object({
  title: z.string(),
  city: z.string().nullish().transform((v) => v ?? ""),
  category: z.string().nullish().transform((v) => v ?? ""),
  zip_code: z.string().nullish().transform((v) => v ?? ""),
});
export type CompanySlug = z.infer<typeof companySlugSchema>;
