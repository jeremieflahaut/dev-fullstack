# dev-fullstack.net

Site perso + blog de Jérémie Flahaut, développeur web fullstack — [www.dev-fullstack.net](https://www.dev-fullstack.net).

Site **statique** : les articles sont rédigés en markdown et transformés en HTML au build, puis déployés automatiquement sur GitHub Pages. Zéro serveur, zéro base de données, zéro coût d'hébergement.

## Stack

- [Astro 6](https://astro.build) + TypeScript strict — content collections pour le blog
- [Tailwind CSS v4](https://tailwindcss.com) — design « atelier terminal », dark/light
- Fonts auto-hébergées via l'API Fonts d'Astro (Bricolage Grotesque, Instrument Sans, JetBrains Mono)
- Dev local 100 % Docker (`node:24-alpine`) — aucun Node requis sur la machine
- Déploiement : GitHub Actions → GitHub Pages, à chaque push sur `main`

## Démarrer en local

Prérequis : Docker.

```bash
make init   # npm install (dans le conteneur)
make up     # serveur de dev → http://localhost:4321
```

| Commande | Effet |
|---|---|
| `make up` / `make down` | démarre / arrête le serveur de dev (HMR) |
| `make logs` | logs du conteneur |
| `make sh` | shell dans le conteneur |
| `make check` | vérification des types (`astro check`) |
| `make build` | build de prod dans `dist/` |
| `make preview` | sert `dist/` sur http://localhost:4321 |

## Écrire un article

Créer un fichier markdown dans `src/content/blog/` — le nom du fichier devient l'URL (kebab-case, sans accents) :

```markdown
---
title: "Titre de l'article"
description: "Résumé affiché dans les listings, le RSS et les meta SEO."
pubDate: 2026-06-15
tags: ["laravel", "docker"]
draft: false
---

Contenu en markdown. Les blocs de code sont colorés (Shiki, thème clair/sombre).
```

- `draft: true` → l'article reste visible en dev mais est **exclu du build de prod**.
- Un fichier préfixé par `_` est totalement ignoré.
- `updatedDate: 2026-07-01` (optionnel) affiche une mention de mise à jour.
- Le temps de lecture est calculé automatiquement.

**Publier = pousser sur `main`.** Le workflow `.github/workflows/deploy.yml` reconstruit et déploie le site.

## Faire rédiger un article par l'agent IA

Le dépôt embarque un agent de rédaction (Claude) piloté par GitHub Actions :

1. **Actions → « Rédaction d'article » → Run workflow** : saisir l'idée, et des notes optionnelles (angle, liens, contraintes).
2. L'agent lit le guide éditorial [`docs/redaction.md`](docs/redaction.md), rédige l'article dans `src/content/blog/`, puis le workflow vérifie que le site build et ouvre une **pull request**.
3. **Relecture factuelle automatique** : dès la PR ouverte, un second agent (avec accès web) vérifie chaque affirmation contre des sources, suivant [`docs/relecture.md`](docs/relecture.md) — exactitude technique, validité des liens, confidentialité, cohérence. Il corrige les inexactitudes (commit poussé sur la branche) et poste un rapport sur la PR. Aucune action manuelle.
4. Pour demander une retouche, commenter `@claude raccourcis l'introduction` : l'agent pousse la correction sur la branche (réservé au propriétaire du dépôt). Relecture relançable à la main : **Actions → « Relecture d'article »**, en saisissant la branche `article/<slug>`.
5. **Merger la PR = publier** — le déploiement part automatiquement.

Côté plomberie :

- Workflows : `redaction-article.yml` (idée → article → PR → relecture), `relecture-article.yml` (vérification factuelle + corrections, auto ou manuelle), `claude.yml` (retouches `@claude`), `ci.yml` (build de contrôle sur les PR).
- Prérequis (une fois) : secret `CLAUDE_CODE_OAUTH_TOKEN` (généré avec `claude setup-token`, abonnement Pro/Max) et GitHub App Claude installée sur le dépôt.
- ⚠️ Dépôt public : l'idée saisie dans le formulaire est visible dans l'historique des runs — n'y mettre que ce qui peut finir public.

## Structure

```
src/
├── components/      # Header, Footer, PostCard
├── content/blog/    # les articles (markdown)
├── content.config.ts# schéma de la collection (zod)
├── layouts/         # BaseLayout (SEO, fonts, thème)
├── lib/posts.ts     # tri + filtrage des drafts
├── pages/           # routes (/, /blog, /blog/[id], /a-propos, 404, rss.xml)
└── styles/          # global.css (tokens, Tailwind v4)
scripts/             # generate-og.mjs (image Open Graph + apple-touch-icon)
```

## Notes

- Le site est ouvert à l'indexation : sitemap déclaré dans `public/robots.txt` (`/sitemap-index.xml`, généré par `@astrojs/sitemap`).
- Le domaine custom est configuré dans les settings GitHub Pages (le fichier `public/CNAME` n'est qu'informatif avec un déploiement via Actions).
- Régénérer l'image OG / les icônes : voir l'en-tête de `scripts/generate-og.mjs`.
