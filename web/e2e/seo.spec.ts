import { test, expect } from "@playwright/test";

test("robots.txt référence le sitemap", async ({ request }) => {
  const res = await request.get("/robots.txt");
  expect(res.ok()).toBeTruthy();
  expect(await res.text()).toContain("Sitemap:");
});

test("sitemap.xml liste des URLs d'entreprise", async ({ request }) => {
  const res = await request.get("/sitemap.xml");
  expect(res.ok()).toBeTruthy();
  const body = await res.text();
  expect(body).toContain("<urlset");
  expect(body).toContain("/recherche");
});
