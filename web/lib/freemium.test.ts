import { describe, it, expect } from "vitest";
import { gateCompany, GATED_FIELDS } from "@/lib/freemium";
import { companySchema } from "@/lib/types";

const full = companySchema.parse({
  title: "ACME", city: "Paris", category: "Café",
  phone: "0102030405", url: "http://acme.fr", domain: "acme.fr",
  emails: ["x@acme.fr"], contacts: [{ type: "Mail", value: "x@acme.fr" }],
});

describe("gateCompany", () => {
  it("visiteur non authentifié : champs gated masqués", () => {
    const { company, hidden } = gateCompany(full, false);
    expect(company.phone).toBe("");
    expect(company.emails).toEqual([]);
    expect(company.url).toBe("");
    expect(hidden).toEqual([...GATED_FIELDS]);
    expect(company.title).toBe("ACME"); // champ public intact
  });

  it("utilisateur authentifié : rien n'est masqué", () => {
    const { company, hidden } = gateCompany(full, true);
    expect(company.phone).toBe("0102030405");
    expect(hidden).toEqual([]);
  });
});
