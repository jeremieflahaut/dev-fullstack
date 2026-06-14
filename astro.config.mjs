import { readdirSync, readFileSync } from 'node:fs';
import { defineConfig, fontProviders } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';
import { remarkReadingTime } from './plugins/remark-reading-time.mjs';

const SITE = 'https://www.dev-fullstack.net';

// Date de dernière modif par URL d'article, lue dans le frontmatter (updatedDate
// sinon pubDate) pour alimenter le <lastmod> du sitemap. Lecture au build, donc
// pas de dépendance à la collection Astro ici.
const blogDir = new URL('./src/content/blog/', import.meta.url);
const articleLastmod = new Map();
for (const file of readdirSync(blogDir)) {
  if (!file.endsWith('.md') || file.startsWith('_')) continue;
  const raw = readFileSync(new URL(file, blogDir), 'utf8');
  const pub = raw.match(/^pubDate:\s*(.+)$/m)?.[1]?.trim();
  const upd = raw.match(/^updatedDate:\s*(.+)$/m)?.[1]?.trim();
  const date = upd || pub;
  if (date) {
    const slug = file.replace(/\.md$/, '');
    articleLastmod.set(`${SITE}/blog/${slug}/`, new Date(date).toISOString());
  }
}

export default defineConfig({
  site: SITE,
  integrations: [
    sitemap({
      // Priorités par type de page + lastmod réel sur les articles : aide Google
      // à prioriser le crawl. (priority/changefreq sont des indications, lastmod
      // est le signal réellement exploité.)
      serialize(item) {
        if (item.url === `${SITE}/`) {
          item.priority = 1.0;
          item.changefreq = 'weekly';
        } else if (articleLastmod.has(item.url)) {
          item.priority = 0.8;
          item.changefreq = 'monthly';
          item.lastmod = articleLastmod.get(item.url);
        } else if (item.url === `${SITE}/a-propos/`) {
          item.priority = 0.7;
          item.changefreq = 'monthly';
        } else if (item.url.includes('/blog/tags/')) {
          item.priority = 0.4;
          item.changefreq = 'weekly';
        } else if (item.url.includes('/blog')) {
          item.priority = 0.6;
          item.changefreq = 'weekly';
        } else {
          item.priority = 0.5;
        }
        return item;
      },
    }),
  ],
  vite: {
    plugins: [tailwindcss()],
  },
  markdown: {
    remarkPlugins: [remarkReadingTime],
    shikiConfig: {
      themes: { light: 'vitesse-light', dark: 'vitesse-dark' },
      wrap: true,
    },
  },
  fonts: [
    {
      provider: fontProviders.fontsource(),
      name: 'Bricolage Grotesque',
      cssVariable: '--font-bricolage',
      weights: ['200 800'],
      styles: ['normal'],
      subsets: ['latin', 'latin-ext'],
      fallbacks: ['sans-serif'],
    },
    {
      provider: fontProviders.fontsource(),
      name: 'Instrument Sans',
      cssVariable: '--font-instrument',
      weights: ['400 700'],
      styles: ['normal', 'italic'],
      subsets: ['latin', 'latin-ext'],
      fallbacks: ['sans-serif'],
    },
    {
      provider: fontProviders.fontsource(),
      name: 'JetBrains Mono',
      cssVariable: '--font-jbmono',
      weights: ['100 800'],
      styles: ['normal'],
      subsets: ['latin', 'latin-ext'],
      fallbacks: ['monospace'],
    },
  ],
});
