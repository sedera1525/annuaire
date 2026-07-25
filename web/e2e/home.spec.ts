import { test, expect } from "@playwright/test";

test("l'accueil affiche la barre d'autocomplétion", async ({ page }) => {
  await page.goto("/");
  const box = page.getByRole("combobox");
  await expect(box).toBeVisible();
  await box.pressSequentially("boulangerie");
  await box.press("Enter");
  await expect(page).toHaveURL(/\/recherche\?q=boulangerie/);
});

test("l'accueil affiche des puces catégories et villes cliquables", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  await expect(page.locator('a[href^="/recherche?categorie="]').first()).toBeVisible();
  await expect(page.locator('a[href^="/recherche?ville="]').first()).toBeVisible();
});
