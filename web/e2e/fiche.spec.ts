import { test, expect } from "@playwright/test";

test("fiche publique : toutes les infos affichées, sans paywall", async ({ page }) => {
  await page.goto("/recherche?q=paris");
  await page.getByRole("link").first().click();
  await expect(page).toHaveURL(/\/entreprise\//);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  // Plus de paywall : les détails sont publics
  await expect(page.getByText(/voir les détails complets/i)).toHaveCount(0);
  // Au moins une coordonnée / info est affichée
  await expect(page.getByText(/Adresse|Téléphone|Site web|Région/).first()).toBeVisible();
  // Preuve SEO : JSON-LD présent
  await expect(page.locator('script[type="application/ld+json"]')).toHaveCount(1);
});
