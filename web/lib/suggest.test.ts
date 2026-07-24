import { describe, it, expect } from "vitest";
import { filterByLabel } from "@/lib/suggest";

const items = [
  { label: "Restaurant", count: 84475 },
  { label: "Café", count: 100 },
  { label: "Coiffeur", count: 50 },
];

describe("filterByLabel", () => {
  it("q vide → renvoie les premiers (borné)", () => {
    expect(filterByLabel(items, "", 2).map((i) => i.label)).toEqual(["Restaurant", "Café"]);
  });
  it("filtre insensible à la casse et aux accents", () => {
    expect(filterByLabel(items, "cafe", 5).map((i) => i.label)).toEqual(["Café"]);
  });
  it("respecte la limite", () => {
    expect(filterByLabel(items, "c", 1)).toHaveLength(1);
  });
});
