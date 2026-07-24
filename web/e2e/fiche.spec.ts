import { test, expect } from "@playwright/test";

test("fiche publique : données publiques + paywall visible", async ({ page }) => {
  await page.goto("/recherche?q=paris");
  const firstCard = page.getByRole("link").first();
  await firstCard.click();
  await expect(page).toHaveURL(/\/entreprise\//);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  // Champ gated → paywall pour un visiteur non connecté
  await expect(page.getByText(/réservé aux membres/).first()).toBeVisible();
  // Preuve SEO : JSON-LD présent
  const jsonLd = page.locator('script[type="application/ld+json"]');
  await expect(jsonLd).toHaveCount(1);
});
