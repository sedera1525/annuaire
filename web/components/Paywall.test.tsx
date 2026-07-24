import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { Paywall } from "@/components/Paywall";

describe("Paywall", () => {
  it("affiche un CTA de connexion", () => {
    render(<Paywall label="Téléphone" />);
    expect(screen.getByText(/Téléphone/)).toBeTruthy();
    expect(screen.getByRole("link", { name: /connecter/i }).getAttribute("href")).toBe("/login");
  });
});
