import { test, expect } from "@playwright/test";

test("l'accueil affiche la barre d'autocomplétion", async ({ page }) => {
  await page.goto("/");
  const box = page.getByRole("combobox");
  await expect(box).toBeVisible();
  await box.pressSequentially("boulangerie");
  await box.press("Enter");
  await expect(page).toHaveURL(/\/recherche\?q=boulangerie/);
});
