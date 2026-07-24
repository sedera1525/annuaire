import Link from "next/link";

export function Paywall({ label }: { label: string }) {
  return (
    <div className="rounded-md border border-dashed border-gray-300 bg-gray-50 p-3">
      <p className="text-sm text-gray-500">{label} — réservé aux membres</p>
      <Link href="/login" className="text-sm font-medium text-blue-600 underline">
        Se connecter pour voir
      </Link>
    </div>
  );
}
