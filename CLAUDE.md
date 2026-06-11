# CLAUDE.md

Site perso statique + blog de Jérémie (Astro 6 + Tailwind v4 + TypeScript strict), déployé sur GitHub Pages → https://www.dev-fullstack.net. Pousser sur `main` = publier.

## Règle n°1 — contenu public

Avant de créer ou modifier **tout contenu public** — articles `src/content/blog/`, pages `src/pages/`, descriptions/meta, RSS — lire `docs/redaction.md` et appliquer ses règles éditoriales, typographiques et de confidentialité (non négociables). Cela vaut aussi pour les retouches demandées via un commentaire `@claude` sur une pull request.

## Commandes

- Dev local 100 % Docker : `make init` puis `make up` → http://localhost:4321 (aucun Node sur l'hôte).
- Sur un runner CI : `npm ci`, `npx astro check`, `npm run build`.
- Les articles sont du markdown dans `src/content/blog/` — schéma du frontmatter dans `src/content.config.ts`, nom de fichier = URL (kebab-case sans accents).

## Pièges

- Le site est volontairement en `noindex` (flag `NOINDEX` dans `src/layouts/BaseLayout.astro` + ligne `Sitemap` commentée dans `public/robots.txt`) : ne retirer les deux qu'ensemble, et uniquement sur demande explicite.
- Astro 6 : `src/content.config.ts` (pas `src/content/config.ts`), `post.id` (pas `slug`), `render(post)` importé depuis `astro:content`, zod via `astro/zod`.
- Commits : conventionnels, en français, avec scope ; jamais de trailer `Co-Authored-By`.
