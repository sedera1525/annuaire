export function SearchBar({ defaultQuery = "" }: { defaultQuery?: string }) {
  return (
    <form action="/recherche" method="get" className="flex gap-2 w-full max-w-2xl">
      <input
        type="search"
        name="q"
        defaultValue={defaultQuery}
        placeholder="Rechercher une entreprise…"
        className="flex-1 rounded-md border border-gray-300 px-4 py-2"
        aria-label="Rechercher une entreprise"
      />
      <button type="submit" className="rounded-md bg-blue-600 px-4 py-2 text-white">
        Rechercher
      </button>
    </form>
  );
}
