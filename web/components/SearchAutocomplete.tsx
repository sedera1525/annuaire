"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { toSlug } from "@/lib/slug";
import { filterByLabel, type LabeledItem, type Suggestion } from "@/lib/suggest";

const LIMIT = 6;

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
    if (!q) { setCompanies([]); return; }
    const id = setTimeout(async () => {
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
    <div ref={boxRef} className="relative w-full max-w-2xl">
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
        className="w-full rounded-full border border-gray-300 px-5 py-3 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
      />
      {open && suggestions.length > 0 && (
        <ul
          id="autocomplete-list"
          role="listbox"
          className="absolute z-10 mt-2 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg"
        >
          {suggestions.map((s, i) => (
            <li
              key={`${s.kind}-${s.label}`}
              role="option"
              data-kind={s.kind}
              aria-selected={i === active}
              onMouseDown={(e) => { e.preventDefault(); go(s); }}
              onMouseEnter={() => setActive(i)}
              className={`flex cursor-pointer items-center justify-between px-4 py-2.5 ${i === active ? "bg-blue-50" : ""}`}
            >
              <span className="flex items-center gap-2">
                <span aria-hidden>{icon(s.kind)}</span>
                <span>{s.label}</span>
              </span>
              {s.meta && <span className="text-xs text-gray-400">{s.meta}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
