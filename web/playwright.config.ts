import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  timeout: 30_000,
  // Un seul dev-server Next partagé (compilation à la demande) → exécution série
  // pour éviter la contention et les timeouts de navigation.
  workers: 1,
  use: { baseURL: "http://localhost:3100" },
  webServer: {
    command: "npm run dev",
    url: "http://localhost:3100",
    reuseExistingServer: true,
    timeout: 60_000,
  },
});
