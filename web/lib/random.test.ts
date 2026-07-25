import { describe, it, expect } from "vitest";
import { pickRandom, shuffle } from "@/lib/random";

describe("random", () => {
  it("shuffle conserve tous les éléments", () => {
    expect(shuffle([1, 2, 3, 4]).sort((a, b) => a - b)).toEqual([1, 2, 3, 4]);
  });
  it("pickRandom renvoie n éléments issus du tableau", () => {
    const out = pickRandom([1, 2, 3, 4, 5], 3, () => 0);
    expect(out).toHaveLength(3);
    out.forEach((x) => expect([1, 2, 3, 4, 5]).toContain(x));
  });
  it("pickRandom ne dépasse pas la taille du tableau", () => {
    expect(pickRandom([1, 2], 5)).toHaveLength(2);
  });
});
