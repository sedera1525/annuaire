import { SearchAutocomplete } from "@/components/SearchAutocomplete";

export default function Home() {
  return (
    <main className="mx-auto flex max-w-3xl flex-col items-center gap-6 p-10 text-center">
      <h1 className="text-3xl font-bold">Annuaire des entreprises françaises</h1>
      <p className="text-gray-600">Recherchez parmi des millions d&apos;entreprises.</p>
      <SearchAutocomplete />
    </main>
  );
}
