import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { searchCompanies, getCompanyBySlug, getCompany, getCategories, getCities } from "@/lib/api";

const okJson = (body: unknown) =>
  ({ ok: true, status: 200, json: async () => body }) as Response;

beforeEach(() => { vi.restoreAllMocks(); });
afterEach(() => { vi.unstubAllGlobals(); });

describe("searchCompanies", () => {
  it("construit la query et parse la réponse", async () => {
    let calledUrl = "";
    const fetchMock = vi.fn(async (input: string | URL) => {
      calledUrl = String(input);
      return okJson({ results: [{ title: "ACME" }], total: 1, page: 1, per_page: 50, pages: 1, elapsed: 0 });
    });
    vi.stubGlobal("fetch", fetchMock);

    const res = await searchCompanies({ q: "acme", page: 2, per_page: 50 });

    expect(res.results[0].title).toBe("ACME");
    expect(calledUrl).toContain("/api/search");
    expect(calledUrl).toContain("q=acme");
    expect(calledUrl).toContain("page=2");
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

describe("getCategories / getCities", () => {
  it("parse les catégories", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => okJson([{ category: "Restaurant", count: 84475 }])));
    const cats = await getCategories();
    expect(cats[0]).toEqual({ category: "Restaurant", count: 84475 });
  });
  it("renvoie [] si l'API échoue", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({ ok: false, status: 500 }) as Response));
    expect(await getCities()).toEqual([]);
  });
});
