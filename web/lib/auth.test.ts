import { describe, it, expect, vi, afterEach } from "vitest";
import { isAuthenticated } from "@/lib/auth";

afterEach(() => vi.unstubAllGlobals());

describe("isAuthenticated", () => {
  it("false sans cookie (aucun appel réseau)", async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
    expect(await isAuthenticated(undefined)).toBe(false);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("true si la sonde renvoie 200", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({ ok: true, status: 200 }) as Response));
    expect(await isAuthenticated("session=abc")).toBe(true);
  });

  it("false si la sonde renvoie 401", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => ({ ok: false, status: 401 }) as Response));
    expect(await isAuthenticated("session=abc")).toBe(false);
  });
});
