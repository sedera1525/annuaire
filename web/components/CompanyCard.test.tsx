import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { CompanyCard } from "@/components/CompanyCard";
import { companySearchResultSchema } from "@/lib/types";

describe("CompanyCard", () => {
  it("affiche le nom et lie vers /entreprise/<slug>", () => {
    const c = companySearchResultSchema.parse({ title: "Café de la Paix", city: "Paris" });
    render(<CompanyCard company={c} />);
    expect(screen.getByText("Café de la Paix")).toBeTruthy();
    const link = screen.getByRole("link");
    expect(link.getAttribute("href")).toBe("/entreprise/cafe-de-la-paix");
  });
});
