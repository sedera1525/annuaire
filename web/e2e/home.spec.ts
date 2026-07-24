import { test, expect } from "@playwright/test";

test("l'accueil affiche la barre de recherche", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("searchbox")).toBeVisible();
  await page.getByRole("searchbox").fill("boulangerie");
  await page.getByRole("button", { name: /rechercher/i }).click();
  await expect(page).toHaveURL(/\/recherche\?q=boulangerie/);
});
