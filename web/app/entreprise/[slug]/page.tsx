import Link from "next/link";
import { notFound } from "next/navigation";
import { getCompanyBySlug, getCompany } from "@/lib/api";
import { CompanyJsonLd } from "@/components/CompanyJsonLd";
import type { Company } from "@/lib/types";
import type { Metadata } from "next";

export const revalidate = 86400; // ISR : re-rendu au plus une fois/jour

async function resolve(slug: string) {
  const ref = await getCompanyBySlug(slug);
  if (!ref) return null;
  return getCompany(ref.title);
}

export async function generateMetadata(
  { params }: { params: Promise<{ slug: string }> },
): Promise<Metadata> {
  const { slug } = await params;
  const company = await resolve(slug);
  if (!company) return { title: "Entreprise introuvable" };
  const loc = [company.city, company.zip_code].filter(Boolean).join(" ");
  return {
    title: `${company.title}${loc ? ` — ${loc}` : ""} | Annuaire`,
    description: company.snippet || company.description?.slice(0, 155) ||
      `${company.title}, ${company.category} à ${company.city}.`,
    alternates: { canonical: `/entreprise/${slug}` },
    openGraph: {
      title: company.title,
      description: company.snippet || company.description?.slice(0, 155) || "",
      images: company.main_image || company.logo ? [company.main_image || company.logo] : undefined,
    },
  };
}

function phones(c: Company): string[] {
  const list = c.phones_extra.length > 0 ? c.phones_extra : c.phone ? [c.phone] : [];
  return Array.from(new Set(list.filter(Boolean)));
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5 border-t border-[var(--border)] py-3 sm:flex-row sm:gap-4">
      <dt className="w-40 shrink-0 text-sm font-medium text-[var(--muted)]">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

export default async function FichePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const c = await resolve(slug);
  if (!c) notFound();

  const tels = phones(c);
  const address = c.address_full || [c.addr_street, c.zip_code, c.city].filter(Boolean).join(", ");
  const mapUrl = typeof c.latitude === "number" && typeof c.longitude === "number"
    ? `https://www.google.com/maps/search/?api=1&query=${c.latitude},${c.longitude}`
    : null;

  return (
    <main className="mx-auto max-w-3xl px-6 py-10">
      <CompanyJsonLd company={c} />

      <Link href="/recherche" className="text-sm text-[var(--muted)] hover:text-[var(--accent)]">
        ← Retour à la recherche
      </Link>

      <header className="mt-5 flex items-start gap-4">
        {c.logo && (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={c.logo}
            alt=""
            className="h-16 w-16 shrink-0 rounded-xl border border-[var(--border)] object-cover"
          />
        )}
        <div className="min-w-0">
          <h1 className="font-[family-name:var(--font-display)] text-3xl font-semibold tracking-tight sm:text-4xl">
            {c.title}
          </h1>
          <p className="mt-1 text-[var(--muted)]">
            {[c.category, c.city].filter(Boolean).join(" · ")}
            {c.is_claimed ? <span className="ml-2 text-[var(--accent)]">✓ Fiche vérifiée</span> : null}
          </p>
          {c.rating_votes > 0 && (
            <p className="mt-1 text-sm text-amber-500">
              ★ {c.rating_value.toFixed(1)}{" "}
              <span className="text-[var(--muted)]">({c.rating_votes.toLocaleString("fr-FR")} avis)</span>
            </p>
          )}
        </div>
      </header>

      {c.description && (
        <p className="mt-6 whitespace-pre-line leading-relaxed text-[var(--foreground)]/90">
          {c.description}
        </p>
      )}

      <dl className="mt-8">
        {tels.length > 0 && (
          <Row label="Téléphone">
            <div className="flex flex-col gap-1">
              {tels.map((t) => (
                <a key={t} href={`tel:${t}`} className="text-[var(--accent)] hover:underline">{t}</a>
              ))}
            </div>
          </Row>
        )}
        {c.url && (
          <Row label="Site web">
            <a href={c.url} target="_blank" rel="noopener noreferrer" className="break-all text-[var(--accent)] hover:underline">
              {c.domain || c.url}
            </a>
          </Row>
        )}
        {c.emails.length > 0 && (
          <Row label="Email">
            <div className="flex flex-col gap-1">
              {c.emails.map((e) => (
                <a key={e} href={`mailto:${e}`} className="break-all text-[var(--accent)] hover:underline">{e}</a>
              ))}
            </div>
          </Row>
        )}
        {address && (
          <Row label="Adresse">
            <span>{address}</span>
            {mapUrl && (
              <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="ml-2 text-[var(--accent)] hover:underline">
                Voir sur la carte ↗
              </a>
            )}
          </Row>
        )}
        {c.region && <Row label="Région">{c.region}</Row>}
        {c.total_photos > 0 && <Row label="Photos">{c.total_photos.toLocaleString("fr-FR")}</Row>}
      </dl>
    </main>
  );
}
