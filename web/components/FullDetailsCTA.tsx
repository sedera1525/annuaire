import Link from "next/link";

export function FullDetailsCTA() {
  return (
    <Link
      href="/login"
      className="inline-flex items-center justify-center rounded-md bg-blue-600 px-5 py-2.5 font-medium text-white hover:bg-blue-700"
    >
      Voir les détails complets
    </Link>
  );
}
