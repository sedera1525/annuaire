import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { SearchAutocomplete } from "@/components/SearchAutocomplete";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

const categories = [{ category: "Restaurant", count: 84475 }, { category: "Coiffeur", count: 50 }];
const cities = [{ city: "Paris", count: 244018 }, { city: "Lyon", count: 500 }];

function stubFetch() {
  vi.stubGlobal("fetch", vi.fn(async (input: string | URL) => {
    const url = String(input);
    if (url.includes("/api/categories")) return { ok: true, json: async () => categories } as Response;
    if (url.includes("/api/cities")) return { ok: true, json: async () => cities } as Response;
    if (url.includes("/api/search")) return { ok: true, json: async () => ({
      results: [{ title: "KaraFun Paris" }], total: 1, page: 1, per_page: 6, pages: 1, elapsed: 0,
    }) } as Response;
    return { ok: false, json: async () => ({}) } as Response;
  }));
}

beforeEach(() => { push.mockReset(); stubFetch(); });
afterEach(() => vi.unstubAllGlobals());

describe("SearchAutocomplete", () => {
  it("au focus (champ vide) affiche les exemples préchargés", async () => {
    render(<SearchAutocomplete debounceMs={0} />);
    await userEvent.click(screen.getByRole("combobox"));
    await waitFor(() => expect(screen.getByText("Restaurant")).toBeTruthy());
    expect(screen.getByText("Paris")).toBeTruthy();
  });

  it("à la frappe affiche des suggestions d'entreprises", async () => {
    render(<SearchAutocomplete debounceMs={0} />);
    await userEvent.type(screen.getByRole("combobox"), "kara");
    await waitFor(() => expect(screen.getByText("KaraFun Paris")).toBeTruthy());
  });

  it("Entrée sans sélection lance une recherche", async () => {
    render(<SearchAutocomplete debounceMs={0} />);
    await userEvent.type(screen.getByRole("combobox"), "boulangerie{Enter}");
    await waitFor(() => expect(push).toHaveBeenCalledWith("/recherche?q=boulangerie"));
  });
});
