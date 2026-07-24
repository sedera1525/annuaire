export type SuggestionKind = "company" | "category" | "city";

export interface Suggestion {
  kind: SuggestionKind;
  label: string;
  href: string;
  meta?: string;
}

export interface LabeledItem {
  label: string;
  count?: number;
}

function normalize(s: string): string {
  return s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "");
}

export function filterByLabel(items: LabeledItem[], q: string, limit: number): LabeledItem[] {
  const nq = normalize(q.trim());
  const base = nq ? items.filter((it) => normalize(it.label).includes(nq)) : items;
  return base.slice(0, limit);
}
