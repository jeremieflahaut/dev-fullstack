---
title: "Bienvenue sur le blog"
description: "Un énième blog de dev ? Oui — mais en français, orienté Laravel et fullstack, et construit comme un projet à part entière."
pubDate: 2026-06-10
tags: ["meta"]
---

Bonjour ! Je suis Jérémie, développeur backend. Je travaille principalement avec **Laravel**, et il m'arrive de bricoler des side projects — des stacks Docker, des petites apps Laravel, et maintenant ce site.

## Pourquoi un blog (de plus)

Trois raisons, honnêtement :

1. **Documenter pour ne pas oublier.** Combien de fois ai-je résolu un problème… puis recherché la même solution six mois plus tard ? Écrire, c'est se constituer une mémoire externe.
2. **Apprendre en expliquant.** On croit comprendre un sujet jusqu'au moment où il faut l'expliquer simplement. Rédiger force à combler les trous.
3. **Écrire en français.** Les ressources techniques de qualité en français ne sont pas si nombreuses. Si un article peut faire gagner une heure à un dev francophone, c'est gagné.

## Ce que vous trouverez ici

Des notes concrètes et reproductibles, tirées de mon quotidien : Laravel et son écosystème, Docker pour le dev et la prod, un peu de JavaScript, et tout ce qui gravite autour du métier de dev backend.

Pas de calendrier de publication intenable — je publierai quand j'aurai quelque chose d'utile à partager.

## Comment ce site est construit

Ce site est lui-même un petit projet : un site **statique** généré avec [Astro](https://astro.build), stylé avec Tailwind CSS, développé en local dans Docker et déployé gratuitement sur **GitHub Pages** à chaque push.

Les articles sont de simples fichiers markdown, transformés en HTML au moment du build :

```bash
❯ make build
# astro build → dist/ → GitHub Pages
```

Zéro base de données, zéro serveur à maintenir, zéro coût d'hébergement.

À bientôt pour le premier vrai article !
