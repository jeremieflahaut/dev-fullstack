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

/**
 * Identité de l'auteur du site, source unique réutilisée dans tous les schémas
 * schema.org (Person, WebSite, author des articles). Les `sameAs` relient le
 * site à de vraies identités : c'est le signal E-E-A-T attendu par Google,
 * d'autant plus utile pour du contenu rédigé par IA.
 */
export const AUTHOR = {
  name: 'Jérémie Flahaut',
  jobTitle: 'Développeur backend senior',
  /** Chemin de la page auteur, à résoudre via `new URL(path, Astro.site)`. */
  path: '/a-propos/',
  sameAs: [
    'https://github.com/jeremieflahaut',
    'https://www.linkedin.com/in/jeremie-flahaut-developpeur-fullstack-php-javascript',
  ],
  knowsAbout: ['Laravel', 'PHP', 'MySQL', 'Redis', 'API REST', 'Docker', 'GitHub Actions'],
} as const;

export const SITE = {
  name: 'dev-fullstack',
  description:
    "Blog d'un développeur backend : Laravel, PHP, Docker — notes pratiques et retours d'expérience, en français.",
} as const;

/**
 * Nœud Person de l'auteur, à embarquer dans un autre schéma (pas de `@context`).
 * Utilisé tel quel comme `author` des articles ; enrichi sur la page « à propos ».
 */
export function buildPerson(site: URL | undefined) {
  return {
    '@type': 'Person',
    name: AUTHOR.name,
    url: new URL(AUTHOR.path, site).href,
    sameAs: [...AUTHOR.sameAs],
  };
}

/**
 * Schéma WebSite pour la page d'accueil : aide Google à identifier le nom du
 * site et l'entité qui le publie.
 */
export function buildWebSite(site: URL | undefined) {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    name: SITE.name,
    url: new URL('/', site).href,
    description: SITE.description,
    inLanguage: 'fr-FR',
    author: buildPerson(site),
  };
}

/**
 * Schéma ProfilePage pour la page « à propos » : déclare la page comme la fiche
 * de l'auteur (Person enrichi de son métier et de ses domaines).
 */
export function buildProfilePage(site: URL | undefined) {
  return {
    '@context': 'https://schema.org',
    '@type': 'ProfilePage',
    mainEntity: {
      ...buildPerson(site),
      jobTitle: AUTHOR.jobTitle,
      knowsAbout: [...AUTHOR.knowsAbout],
    },
  };
}
