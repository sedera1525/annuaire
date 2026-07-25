import { test, expect } from "@playwright/test";

test("focus affiche des exemples, la frappe filtre, le clic navigue", async ({ page }) => {
  await page.goto("/");
  const box = page.getByRole("combobox");
  await box.click();
  // exemples préchargés (une catégorie très courante)
  await expect(page.getByRole("option").first()).toBeVisible();

  await box.fill("restau");
  // cible explicitement la suggestion de type "catégorie" (les entreprises peuvent aussi matcher)
  const catOption = page.locator('li[role="option"][data-kind="category"]').first();
  await expect(catOption).toBeVisible();
  await catOption.click();
  await expect(page).toHaveURL(/\/recherche\?categorie=/);
});
