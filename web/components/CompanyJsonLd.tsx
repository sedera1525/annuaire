import type { Company } from "@/lib/types";

export function CompanyJsonLd({ company }: { company: Company }) {
  const data: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    name: company.title,
    address: {
      "@type": "PostalAddress",
      streetAddress: company.addr_street || undefined,
      addressLocality: company.city || undefined,
      postalCode: company.zip_code || undefined,
      addressCountry: "FR",
    },
  };
  if (company.rating_votes > 0) {
    data.aggregateRating = {
      "@type": "AggregateRating",
      ratingValue: company.rating_value,
      reviewCount: company.rating_votes,
    };
  }
  return (
    <script
      type="application/ld+json"
      dangerouslySetInnerHTML={{ __html: JSON.stringify(data) }}
    />
  );
}
