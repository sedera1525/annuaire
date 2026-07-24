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
