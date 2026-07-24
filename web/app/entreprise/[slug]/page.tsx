import { notFound } from "next/navigation";
import { cookies } from "next/headers";
import { getCompanyBySlug, getCompany } from "@/lib/api";
import { gateCompany } from "@/lib/freemium";
import { isAuthenticated } from "@/lib/auth";
import { Paywall } from "@/components/Paywall";
import { CompanyJsonLd } from "@/components/CompanyJsonLd";
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
    description: company.snippet || `${company.title}, ${company.category} à ${company.city}.`,
    alternates: { canonical: `/entreprise/${slug}` },
    openGraph: { title: company.title, description: company.snippet || "" },
  };
}

export default async function FichePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const raw = await resolve(slug);
  if (!raw) notFound();

  const cookieHeader = (await cookies()).toString();
  const authed = await isAuthenticated(cookieHeader || undefined);
  const { company } = gateCompany(raw, authed);

  return (
    <main className="mx-auto max-w-3xl p-6">
      <CompanyJsonLd company={raw} />
      <h1 className="text-2xl font-bold">{company.title}</h1>
      <p className="text-gray-600">
        {[company.category, company.address_full || company.city].filter(Boolean).join(" · ")}
      </p>
      {company.rating_votes > 0 && (
        <p className="mt-1 text-amber-600">★ {company.rating_value.toFixed(1)} ({company.rating_votes} avis)</p>
      )}
      {company.snippet && <p className="mt-4">{company.snippet}</p>}

      <section className="mt-6 grid gap-3">
        <div>
          <span className="text-sm font-medium">Téléphone : </span>
          {company.phone ? <span>{company.phone}</span> : <Paywall label="Téléphone" />}
        </div>
        <div>
          <span className="text-sm font-medium">Site web : </span>
          {company.url ? (
            <a className="text-blue-600 underline" href={company.url}>{company.url}</a>
          ) : <Paywall label="Site web" />}
        </div>
        <div>
          <span className="text-sm font-medium">Emails : </span>
          {company.emails.length > 0 ? (
            <span>{company.emails.join(", ")}</span>
          ) : <Paywall label="Emails" />}
        </div>
      </section>
    </main>
  );
}
