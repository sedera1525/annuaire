import {
  searchResponseSchema, companySchema, companySlugSchema,
  categoriesSchema, citiesSchema,
  type SearchResponse, type Company, type CompanySlug,
  type CategoryStat, type CityStat,
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

export async function getCategories(): Promise<CategoryStat[]> {
  const res = await fetch(`${API_URL}/api/categories`, { next: { revalidate: 86400 } });
  if (!res.ok) return [];
  return categoriesSchema.parse(await res.json());
}

export async function getCities(): Promise<CityStat[]> {
  const res = await fetch(`${API_URL}/api/cities`, { next: { revalidate: 86400 } });
  if (!res.ok) return [];
  return citiesSchema.parse(await res.json());
}

export async function getCompanyCount(): Promise<number | null> {
  const res = await fetch(`${API_URL}/api/status`, { next: { revalidate: 3600 } });
  if (!res.ok) return null;
  const data = await res.json();
  return typeof data?.rows === "number" ? data.rows : null;
}

export async function getCompany(title: string, cookie?: string): Promise<Company | null> {
  const res = await fetch(`${API_URL}/api/company/${encodeURIComponent(title)}`, {
    headers: headers(cookie),
    next: { revalidate: 86400 },
  });
  if (!res.ok) return null;
  return companySchema.parse(await res.json());
}
