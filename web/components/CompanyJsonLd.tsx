import type { Company } from "@/lib/types";

export function CompanyJsonLd({ company }: { company: Company }) {
  const data: Record<string, unknown> = {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    name: company.title,
    telephone: company.phone || undefined,
    url: company.url || undefined,
    image: company.logo || company.main_image || undefined,
    description: company.description || company.snippet || undefined,
    address: {
      "@type": "PostalAddress",
      streetAddress: company.addr_street || undefined,
      addressLocality: company.city || undefined,
      postalCode: company.zip_code || undefined,
      addressCountry: company.country_code || "FR",
    },
  };
  if (typeof company.latitude === "number" && typeof company.longitude === "number") {
    data.geo = {
      "@type": "GeoCoordinates",
      latitude: company.latitude,
      longitude: company.longitude,
    };
  }
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
