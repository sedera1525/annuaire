import type { Company } from "@/lib/types";

export const GATED_FIELDS = ["phone", "url", "domain", "emails", "contacts"] as const;

export function gateCompany(
  company: Company,
  isAuthenticated: boolean,
): { company: Company; hidden: string[] } {
  if (isAuthenticated) return { company, hidden: [] };

  const masked: Company = { ...company };
  masked.phone = "";
  masked.url = "";
  masked.domain = "";
  masked.emails = [];
  masked.contacts = null;
  return { company: masked, hidden: [...GATED_FIELDS] };
}
