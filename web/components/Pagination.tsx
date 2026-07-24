import Link from "next/link";

export function Pagination({ page, pages, makeHref }: {
  page: number; pages: number; makeHref: (p: number) => string;
}) {
  return (
    <nav className="flex items-center gap-4 mt-6" aria-label="Pagination">
      {page > 1 && <Link className="underline" href={makeHref(page - 1)}>← Précédent</Link>}
      <span className="text-sm text-gray-600">Page {page} / {pages}</span>
      {page < pages && <Link className="underline" href={makeHref(page + 1)}>Suivant →</Link>}
    </nav>
  );
}
