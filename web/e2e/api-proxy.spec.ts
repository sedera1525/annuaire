import { test, expect } from "@playwright/test";

test("/api/categories est proxifié vers FastAPI", async ({ request }) => {
  const res = await request.get("/api/categories");
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  expect(Array.isArray(body)).toBeTruthy();
  expect(body[0]).toHaveProperty("category");
});
