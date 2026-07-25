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
  const desc = company.snippet || company.description?.slice(0, 155) ||
    `${company.title}, ${company.category} à ${company.city}.`;
  return {
    title: `${company.title}${loc ? ` — ${loc}` : ""} | Annuaire`,
    description: desc,
    alternates: { canonical: `/entreprise/${slug}` },
    openGraph: {
      title: company.title,
      description: desc,
      images: company.main_image || company.logo ? [company.main_image || company.logo] : undefined,
    },
  };
}

function phones(c: Company): string[] {
  const list = c.phones_extra.length > 0 ? c.phones_extra : c.phone ? [c.phone] : [];
  return Array.from(new Set(list.filter(Boolean)));
}

function Card({ title, children }: { title?: string; children: React.ReactNode }) {
  return (
    <section className="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-6">
      {title && (
        <h2 className="mb-4 text-[0.7rem] font-semibold uppercase tracking-[0.16em] text-[var(--muted)]">
          {title}
        </h2>
      )}
      {children}
    </section>
  );
}

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between gap-4 border-t border-[var(--border)] py-2.5 text-sm first:border-t-0 first:pt-0">
      <span className="text-[var(--muted)]">{label}</span>
      <span className="text-right">{children}</span>
    </div>
  );
}

export default async function FichePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const c = await resolve(slug);
  if (!c) notFound();

  const tels = phones(c);
  const address = c.address_full || [c.addr_street, c.zip_code, c.city].filter(Boolean).join(", ");
  const hasGeo = typeof c.latitude === "number" && typeof c.longitude === "number";
  const mapUrl = hasGeo
    ? `https://www.google.com/maps/search/?api=1&query=${c.latitude},${c.longitude}`
    : null;
  const mapEmbed = hasGeo
    ? `https://maps.google.com/maps?q=${c.latitude},${c.longitude}&z=15&output=embed`
    : null;

  return (
    <main className="mx-auto max-w-5xl px-6 py-8">
      <CompanyJsonLd company={c} />

      <nav className="text-sm text-[var(--muted)]">
        <Link href="/" className="hover:text-[var(--accent)]">Accueil</Link>
        <span className="mx-1.5">/</span>
        <Link href="/recherche" className="hover:text-[var(--accent)]">Recherche</Link>
        <span className="mx-1.5">/</span>
        <span className="text-[var(--foreground)]">{c.title}</span>
      </nav>

      {/* Bannière */}
      <div className="relative mt-4 h-48 overflow-hidden rounded-2xl border border-[var(--border)] sm:h-60">
        {c.main_image ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={c.main_image} alt="" className="h-full w-full object-cover" />
        ) : (
          <div
            className="h-full w-full"
            style={{ background: "radial-gradient(120% 120% at 20% 0%, var(--glow), transparent 60%), var(--card)" }}
          />
        )}
        <div className="absolute inset-0 bg-gradient-to-t from-black/45 to-transparent" />
      </div>

      {/* En-tête */}
      <header className="px-1">
        {c.logo ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={c.logo}
            alt=""
            className="relative z-10 -mt-12 h-24 w-24 rounded-2xl border-2 border-[var(--card)] bg-[var(--card)] object-cover shadow-lg"
          />
        ) : (
          <div className="relative z-10 -mt-12 flex h-24 w-24 items-center justify-center rounded-2xl border-2 border-[var(--card)] bg-[var(--accent)] text-3xl font-bold text-white shadow-lg">
            {c.title.charAt(0)}
          </div>
        )}
        <h1 className="mt-4 font-[family-name:var(--font-display)] text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">
          {c.title}
        </h1>
        <div className="mt-2 flex flex-wrap items-center gap-2">
          {c.category && (
            <span className="rounded-full border border-[var(--border)] bg-[var(--card)] px-3 py-1 text-xs font-medium">
              {c.category}
            </span>
          )}
          {c.rating_votes > 0 && (
            <span className="text-sm text-amber-500">
              ★ {c.rating_value.toFixed(1)}{" "}
              <span className="text-[var(--muted)]">({c.rating_votes.toLocaleString("fr-FR")} avis)</span>
            </span>
          )}
          {c.is_claimed && <span className="text-sm text-[var(--accent)]">✓ Fiche vérifiée</span>}
        </div>
      </header>

      {/* Actions rapides */}
      <div className="mt-5 flex flex-wrap gap-2.5">
        {tels[0] && (
          <a href={`tel:${tels[0]}`} className="inline-flex items-center gap-2 rounded-full bg-[var(--accent)] px-5 py-2.5 text-sm font-medium text-white hover:opacity-90">
            📞 Appeler
          </a>
        )}
        {c.url && (
          <a href={c.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 rounded-full border border-[var(--border)] bg-[var(--card)] px-5 py-2.5 text-sm font-medium hover:border-[var(--accent)]">
            🌐 Site web
          </a>
        )}
        {mapUrl && (
          <a href={mapUrl} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 rounded-full border border-[var(--border)] bg-[var(--card)] px-5 py-2.5 text-sm font-medium hover:border-[var(--accent)]">
            🧭 Itinéraire
          </a>
        )}
        {c.emails[0] && (
          <a href={`mailto:${c.emails[0]}`} className="inline-flex items-center gap-2 rounded-full border border-[var(--border)] bg-[var(--card)] px-5 py-2.5 text-sm font-medium hover:border-[var(--accent)]">
            ✉️ Email
          </a>
        )}
      </div>

      {/* Corps : 2 colonnes */}
      <div className="mt-8 grid gap-5 lg:grid-cols-3">
        <div className="flex flex-col gap-5 lg:col-span-2">
          {c.description && (
            <Card title="À propos">
              <p className="whitespace-pre-line leading-relaxed">{c.description}</p>
            </Card>
          )}
          <Card title="Informations">
            <div className="grid gap-0">
              {address && <InfoRow label="Adresse">{address}</InfoRow>}
              {c.region && <InfoRow label="Région">{c.region}</InfoRow>}
              {c.country_code && <InfoRow label="Pays">{c.country_code}</InfoRow>}
              {c.rating_votes > 0 && (
                <InfoRow label="Note">
                  {c.rating_value.toFixed(1)} / 5 · {c.rating_votes.toLocaleString("fr-FR")} avis
                </InfoRow>
              )}
              {c.total_photos > 0 && <InfoRow label="Photos">{c.total_photos.toLocaleString("fr-FR")}</InfoRow>}
            </div>
          </Card>
        </div>

        <div className="flex flex-col gap-5">
          <Card title="Coordonnées">
            <div className="flex flex-col gap-3 text-sm">
              {tels.length > 0 && (
                <div>
                  <p className="text-[var(--muted)]">Téléphone</p>
                  {tels.map((t) => (
                    <a key={t} href={`tel:${t}`} className="block text-[var(--accent)] hover:underline">{t}</a>
                  ))}
                </div>
              )}
              {c.url && (
                <div>
                  <p className="text-[var(--muted)]">Site web</p>
                  <a href={c.url} target="_blank" rel="noopener noreferrer" className="block break-all text-[var(--accent)] hover:underline">
                    {c.domain || c.url}
                  </a>
                </div>
              )}
              {c.emails.length > 0 && (
                <div>
                  <p className="text-[var(--muted)]">Email</p>
                  {c.emails.map((e) => (
                    <a key={e} href={`mailto:${e}`} className="block break-all text-[var(--accent)] hover:underline">{e}</a>
                  ))}
                </div>
              )}
              {address && (
                <div>
                  <p className="text-[var(--muted)]">Adresse</p>
                  <p>{address}</p>
                </div>
              )}
            </div>
          </Card>

          {mapEmbed && (
            <div className="overflow-hidden rounded-2xl border border-[var(--border)]">
              <iframe
                title="Carte"
                src={mapEmbed}
                loading="lazy"
                className="h-56 w-full"
                referrerPolicy="no-referrer-when-downgrade"
              />
            </div>
          )}
        </div>
      </div>
    </main>
  );
}
