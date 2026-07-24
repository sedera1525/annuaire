import { describe, it, expect } from "vitest";
import { toSlug } from "@/lib/slug";

describe("toSlug", () => {
  it("minuscule + tirets", () => {
    expect(toSlug("Café de la Paix")).toBe("cafe-de-la-paix");
  });
  it("gère & et espaces multiples", () => {
    expect(toSlug("JACK & JONES")).toBe("jack-jones");
  });
  it("supprime les tirets de bord", () => {
    expect(toSlug("  --Hello--  ")).toBe("hello");
  });
});
