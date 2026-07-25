import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { FullDetailsCTA } from "@/components/FullDetailsCTA";

describe("FullDetailsCTA", () => {
  it("bouton unique vers /login", () => {
    render(<FullDetailsCTA />);
    const link = screen.getByRole("link", { name: /voir les détails complets/i });
    expect(link.getAttribute("href")).toBe("/login");
  });
});
