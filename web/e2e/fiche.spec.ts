import { test, expect } from "@playwright/test";

test("fiche publique : données publiques + bouton détails complets", async ({ page }) => {
  await page.goto("/recherche?q=paris");
  await page.getByRole("link").first().click();
  await expect(page).toHaveURL(/\/entreprise\//);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  // Un seul bouton « Voir les détails complets », plus de « Se connecter pour voir »
  await expect(page.getByRole("link", { name: /voir les détails complets/i })).toBeVisible();
  await expect(page.getByText(/se connecter pour voir/i)).toHaveCount(0);
  // Preuve SEO : JSON-LD présent
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(1);
});
