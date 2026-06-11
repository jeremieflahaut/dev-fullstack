# Guide de rédaction des articles

Ce guide est lu par l'agent de rédaction (workflow « Rédaction d'article ») avant chaque article. Il fait foi pour **tout contenu publié** sur le blog : corps de l'article, titre, description, tags.

## Voix et ton

- Français, première personne (« je »), vouvoiement du lecteur.
- Ton direct, concret, pragmatique — un dev backend senior qui partage ses notes, pas un vendeur. Les superlatifs creux (« incroyable », « révolutionnaire ») sont interdits ; l'humour sobre est bienvenu.
- L'auteur est développeur backend senior (Laravel, PHP 8, MySQL/MongoDB/Redis), fullstack à l'occasion sur des side projects.
- Honnêteté technique : si une solution a des limites ou des contreparties, les dire.

## Format du fichier

- Un seul fichier : `src/content/blog/<slug>.md`. Le slug est en kebab-case sans accents (il devient l'URL) et ne doit pas entrer en collision avec un article existant.
- Frontmatter conforme au schéma `src/content.config.ts` :

  ```yaml
  ---
  title: "Titre de l'article"
  description: "Résumé d'environ 150 caractères pour les listings, le RSS et le SEO."
  pubDate: 2026-06-15 # date du jour (commande `date +%F`)
  tags: ["laravel", "docker"] # 1 à 3 tags, en minuscules
  ---
  ```

- Tags : réutiliser en priorité le vocabulaire des articles existants ; n'en créer un nouveau que s'il a vocation à resservir. Jamais de tag `vue`, `nuxt`, `inertia` ni `tailwind`.
- Pas de `draft: true` : la pull request fait office de brouillon.

## Structure d'un article

- 800 à 1500 mots.
- Une intro courte (2 à 4 phrases) qui pose un problème concret — jamais de « Dans cet article, nous allons voir… ».
- Un développement progressif avec des exemples de code **complets et fonctionnels** (pas de pseudo-code), dans des blocs annotés du langage (```php, ```bash, ```yaml…).
- Une conclusion actionnable : ce qu'il faut retenir, ou la prochaine étape.
- Sections en `##` (le `#` est réservé au titre de page) ; pas de table des matières manuelle.
- Ne jamais inventer de benchmarks, de chiffres ou de citations.

## Typographie française

- Espace insécable (U+00A0) avant `:`, `;`, `?`, `!` et à l'intérieur des guillemets « … ».
- Guillemets français « … » pour les citations, points de suspension « … » (un seul caractère).
- Sigles techniques sans points (API, CLI, SQL).

## Règles de confidentialité — non négociables

Elles s'appliquent à tout le contenu, exemples de code compris :

1. Ne jamais nommer l'employeur de l'auteur, ses clients ni des collègues. Le contexte professionnel se résume à « une plateforme métier interne », sans autre détail.
2. Ne rien mentionner du parcours de l'auteur antérieur à 2018.
3. Ne jamais nommer les projets personnels non publiés ; les side projects se décrivent de façon générique (« une petite app Laravel », « une stack Docker réutilisable »).
4. Jamais de numéro de téléphone ni d'adresse email — les contacts publics sont GitHub, LinkedIn et le flux RSS.
5. Ne pas dépeindre l'auteur comme codant le soir ou en permanence : les side projects sont occasionnels (« quand l'envie est là », « il m'arrive de »).
6. Côté frontend, les compétences citées sont JavaScript/TypeScript et Svelte. Ne pas citer Vue, Nuxt ou Inertia. Tailwind n'est mentionné que pour décrire la construction de ce site.
7. Les anecdotes professionnelles restent anonymisées : pas de nom de produit, de client, de chiffre d'affaires ni de volumétrie identifiante.

## Périmètre de l'agent

L'agent crée **son article et rien d'autre** : aucun autre fichier du dépôt (pages, design, config, workflows) n'est modifié. Toute idée qui demanderait de toucher au site lui-même est hors périmètre — le signaler dans la pull request plutôt que de le faire.
