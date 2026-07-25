import { test, expect } from "@playwright/test";

const USER = process.env.E2E_USERNAME;
const PASS = process.env.E2E_PASSWORD;

test("fiche connecté : téléphone/site débloqués (pas de paywall)", async ({ page, context }) => {
  test.skip(!USER || !PASS, "E2E_USERNAME / E2E_PASSWORD non fournis");

  // Connexion via le formulaire FastAPI, servi sur le même domaine en prod ;
  // en dev, on passe par l'API directement puis on injecte le cookie.
  const res = await context.request.post("http://localhost:8090/login", {
    form: { username: USER!, password: PASS! },
    maxRedirects: 0,
  });
  expect([200, 302]).toContain(res.status());

  await page.goto("/recherche?q=paris");
  await page.getByRole("link").first().click();
  await expect(page).toHaveURL(/\/entreprise\//);

  // Connecté : pas de CTA « Voir les détails complets »
  await expect(page.getByRole("link", { name: /voir les détails complets/i })).toHaveCount(0);
});
