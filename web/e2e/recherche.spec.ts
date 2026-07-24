import { test, expect } from "@playwright/test";

test("la recherche affiche des résultats", async ({ page }) => {
  await page.goto("/recherche?q=paris");
  await expect(page.getByRole("link").first()).toBeVisible();
  await expect(page.getByText(/résultats/)).toBeVisible();
});
