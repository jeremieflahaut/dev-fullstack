export interface BreadcrumbItem {
  name: string;
  /** URL absolue de l'étape. */
  url: string;
}

/**
 * Construit un schéma schema.org BreadcrumbList (fil d'Ariane) pour le JSON-LD.
 * Les URLs passées doivent être absolues (cf. `new URL(path, Astro.site).href`).
 */
export function buildBreadcrumb(items: BreadcrumbItem[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: item.url,
    })),
  };
}
