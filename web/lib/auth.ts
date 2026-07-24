const API_URL = process.env.API_URL ?? "http://localhost:8090";

export async function isAuthenticated(cookie?: string): Promise<boolean> {
  if (!cookie) return false;
  try {
    const res = await fetch(`${API_URL}/api/fiches/stats`, {
      headers: { cookie },
      cache: "no-store",
    });
    return res.ok;
  } catch {
    return false;
  }
}
