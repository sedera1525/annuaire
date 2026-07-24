import { describe, it, expect } from "vitest";
import { ping } from "@/lib/smoke";

describe("smoke", () => {
  it("returns pong", () => {
    expect(ping()).toBe("pong");
  });
});
