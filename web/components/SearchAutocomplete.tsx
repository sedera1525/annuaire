"use client";

import { Fragment, useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { toSlug } from "@/lib/slug";
import { filterByLabel, type LabeledItem, type Suggestion, type SuggestionKind } from "@/lib/suggest";

const LIMIT = 6;

const GROUP_LABEL: Record<SuggestionKind, string> = {
  company: "Entreprises",
  category: "Catégories",
  city: "Villes",
};

export function SearchAutocomplete({
  defaultQuery = "",
  debounceMs = 250,
}: {
  defaultQuery?: string;
  debounceMs?: number;
}) {
  const router = useRouter();
  const [query, setQuery] = useState(defaultQuery);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [categories, setCategories] = useState<LabeledItem[]>([]);
  const [cities, setCities] = useState<LabeledItem[]>([]);
  const [companies, setCompanies] = useState<Suggestion[]>([]);
  const boxRef = useRef<HTMLDivElement>(null);

  // Préchargement des exemples (catégories + villes)
  useEffect(() => {
    let alive = true;
    (async () => {
      try {
        const [c, v] = await Promise.all([
          fetch("/api/categories").then((r) => (r.ok ? r.json() : [])),
          fetch("/api/cities").then((r) => (r.ok ? r.json() : [])),
        ]);
        if (!alive) return;
        setCategories((c as { category: string; count: number }[]).map((x) => ({ label: x.category, count: x.count })));
        setCities((v as { city: string; count: number }[]).map((x) => ({ label: x.city, count: x.count })));
      } catch {
        /* réseau indisponible : pas d'exemples, pas de crash */
      }
    })();
    return () => { alive = false; };
  }, []);

  // Suggestions d'entreprises (debounce)
  useEffect(() => {
    const q = query.trim();
    const id = setTimeout(async () => {
      if (!q) { setCompanies([]); return; }
      try {
        const res = await fetch(`/api/search?q=${encodeURIComponent(q)}&per_page=${LIMIT}`);
        if (!res.ok) return;
        const data = await res.json();
        const items: { title: string }[] = data.results ?? [];
        setCompanies(items.map((it) => ({
          kind: "company", label: it.title, href: `/entreprise/${toSlug(it.title)}`,
        })));
      } catch {
        /* ignore */
      }
    }, debounceMs);
    return () => clearTimeout(id);
  }, [query, debounceMs]);

  const suggestions: Suggestion[] = useMemo(() => {
    const cat = filterByLabel(categories, query, LIMIT).map<Suggestion>((c) => ({
      kind: "category", label: c.label, href: `/recherche?categorie=${encodeURIComponent(c.label)}`,
      meta: c.count ? c.count.toLocaleString("fr-FR") : undefined,
    }));
    const cit = filterByLabel(cities, query, LIMIT).map<Suggestion>((c) => ({
      kind: "city", label: c.label, href: `/recherche?ville=${encodeURIComponent(c.label)}`,
      meta: c.count ? c.count.toLocaleString("fr-FR") : undefined,
    }));
    return [...companies, ...cat, ...cit];
  }, [companies, categories, cities, query]);

  useEffect(() => {
    function onClick(e: MouseEvent) {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, []);

  function go(s: Suggestion) { setOpen(false); router.push(s.href); }
  function submit() { setOpen(false); router.push(`/recherche?q=${encodeURIComponent(query.trim())}`); }

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === "ArrowDown") { e.preventDefault(); setActive((i) => Math.min(i + 1, suggestions.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive((i) => Math.max(i - 1, -1)); }
    else if (e.key === "Enter") {
      e.preventDefault();
      if (active >= 0 && suggestions[active]) go(suggestions[active]);
      else if (query.trim()) submit();
    } else if (e.key === "Escape") { setOpen(false); }
  }

  const icon = (k: Suggestion["kind"]) => (k === "company" ? "🏢" : k === "category" ? "🏷️" : "📍");

  return (
    <div ref={boxRef} className="relative z-30 w-full max-w-2xl">
      <input
        type="search"
        role="combobox"
        aria-expanded={open}
        aria-controls="autocomplete-list"
        autoComplete="off"
        value={query}
        onChange={(e) => { setQuery(e.target.value); setOpen(true); setActive(-1); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        placeholder="Rechercher une entreprise, une catégorie, une ville…"
        aria-label="Rechercher"
        className="w-full rounded-full border border-[var(--border)] bg-[var(--card)] px-5 py-3 text-[var(--foreground)] shadow-sm outline-none placeholder:text-[var(--muted)] focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--glow)]"
      />
      {open && suggestions.length > 0 && (
        <ul
          id="autocomplete-list"
          role="listbox"
          className="absolute z-50 mt-2 w-full overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] py-1 text-left shadow-2xl"
        >
          {suggestions.map((s, i) => {
            const prev = suggestions[i - 1];
            const showHeader = !prev || prev.kind !== s.kind;
            return (
              <Fragment key={`${s.kind}-${s.label}`}>
                {showHeader && (
                  <li
                    role="presentation"
                    className="px-4 pt-2 pb-1 text-[0.65rem] font-semibold uppercase tracking-[0.14em] text-[var(--muted)]"
                  >
                    {GROUP_LABEL[s.kind]}
                  </li>
                )}
                <li
                  role="option"
                  data-kind={s.kind}
                  aria-selected={i === active}
                  onMouseDown={(e) => { e.preventDefault(); go(s); }}
                  onMouseEnter={() => setActive(i)}
                  className={`flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 ${i === active ? "bg-[var(--glow)]" : ""}`}
                >
                  <span className="flex min-w-0 items-center gap-2.5">
                    <span aria-hidden className="shrink-0">{icon(s.kind)}</span>
                    <span className="truncate">{s.label}</span>
                  </span>
                  {s.meta && <span className="shrink-0 text-xs tabular-nums text-[var(--muted)]">{s.meta}</span>}
                </li>
              </Fragment>
            );
          })}
        </ul>
      )}
    </div>
  );
}
