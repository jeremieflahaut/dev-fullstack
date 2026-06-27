---
title: "Obsidian lit, l'agent écrit : répartir les rôles sur un second cerveau markdown"
description: "Sur une base de connaissance markdown pilotée par git : Obsidian pour lire et naviguer, un agent IA pour produire et ranger, sans jamais polluer le savoir durable."
pubDate: 2026-06-27
tags: ["ia", "git"]
---

Un second cerveau en markdown, c'est séduisant sur le papier : des notes en texte brut, versionnées dans git, que je relis et relie au fil de l'eau. En pratique, le mien pourrissait. Les captures s'empilaient sans jamais être rangées, la veille restait à l'état d'onglets, et l'idée de tout synthétiser à la main me décourageait. J'ai voulu confier le rangement à un agent IA — et j'ai vite compris que le laisser écrire où bon lui semble revenait à troquer un fouillis contre un autre, en pire : un savoir dont je ne savais plus distinguer ce qui venait de moi de ce qu'une machine avait inventé. La règle qui a tout débloqué tient en une phrase : l'agent ne touche jamais au savoir durable.

## Deux rôles, deux outils

Le tournant a été d'arrêter de voir Obsidian et l'agent comme deux façons de faire la même chose. Ce sont deux métiers distincts sur la même base de fichiers.

**Obsidian, c'est l'outil de l'humain qui lit.** Le graphe, les backlinks, la recherche, la navigation de note en note : tout ce qui sert à *retrouver* et à *relire*. Je n'y tape presque plus rien. C'est ma fenêtre de lecture sur le savoir, pas mon clavier d'écriture.

**L'agent, c'est l'outil qui produit, range et synthétise.** Il tourne dans le dépôt git de la base, en ligne de commande. Il digère l'inbox, propose des regroupements, rédige des brouillons de synthèse, repère les notes orphelines. Tout ce qui est fastidieux et mécanique tombe de son côté.

La frontière est nette : l'un *consomme* le savoir, l'autre *l'usine*. Et surtout, ils n'écrivent pas au même endroit.

## Comment la base est alimentée

La matière première arrive de trois canaux, tous déversés dans une même boîte de réception :

- les **captures rapides** : une idée, un extrait, une phrase prise à la volée depuis le téléphone ou l'éditeur ;
- la **veille automatique** : un petit script qui dépose, en markdown, les liens et résumés de ce que je marque comme intéressant dans la semaine ;
- les **transcriptions** : notes de réunion ou de réflexion dictées, converties en texte.

Tout ça est brut, redondant, mal formulé. C'est exactement le genre de magma que l'agent sait débroussailler — et que je n'ai aucune envie de trier à la main.

## Le garde-fou : l'agent n'écrit jamais dans le durable

Le risque d'une base pilotée par une IA, ce n'est pas qu'elle range mal. C'est qu'elle *contamine* : une reformulation qui change le sens, une affirmation inventée glissée au milieu de mes notes, un fait approximatif qui prend l'autorité du reste. Une fois mélangé au savoir validé, c'est indétectable.

La parade tient à la structure des dossiers. L'arborescence sépare physiquement la zone de travail de l'agent du savoir durable :

```bash
cerveau/
├── 00-inbox/        # captures brutes, jetables — l'agent pioche ici
├── 10-brouillons/   # zone de travail de l'agent : il écrit ici, et nulle part ailleurs
├── 20-durable/      # le savoir validé — lecture seule pour l'agent
└── AGENTS.md        # les règles que l'agent relit avant chaque tâche
```

Et ces règles sont écrites noir sur blanc dans un fichier que l'agent relit au début de chaque tâche :

```markdown
## Règles non négociables
- Tu écris uniquement dans `10-brouillons/`.
- Tu ne crées, ne modifies ni ne déplaces jamais un fichier de `20-durable/`.
- Tout brouillon porte des marqueurs sur ce qui reste à valider.
- Pour toute affirmation externe (version, API, date, chiffre), tu poses un
  marqueur de vérification au lieu de l'affirmer toi-même.
- La promotion d'un brouillon vers `20-durable/` est un acte humain. Jamais le tien.
```

À partir de là, le flux est calqué sur une pull request, étape pour étape :

1. **L'agent produit un brouillon relié.** Il consolide des captures dans `10-brouillons/`, tisse les liens vers le durable avec des wikilinks, et balise tout ce qui reste incertain avec des marqueurs de travail.

   ```markdown
   ---
   source: 00-inbox/2026-06-25-capture-pipeline.md
   statut: brouillon
   cree-par: agent
   ---

   # Pipeline Laravel — notes à consolider

   Le pattern fait passer un objet à travers une suite d'étapes indépendantes,
   chacune libre d'interrompre la chaîne. Voir [[illuminate-pipeline]].

   > [!todo] À compléter : ajouter mon retour sur le seuil des quatre étapes.
   > [!verify] À vérifier : `Illuminate\Pipeline` est-il dans le cœur depuis Laravel 5 ?
   ```

2. **Je relis à froid.** Pas dans la foulée : plus tard, dans Obsidian, en lecteur. Les marqueurs `[!todo]` et `[!verify]` ressortent visuellement et me disent exactement où porter mon attention. C'est moi qui tranche, complète, corrige.

3. **Go explicite.** Quand le brouillon me convient, et seulement là, il passe dans le durable. Cette promotion, c'est moi qui la fais — l'agent n'a pas les droits dessus.

   ```bash
   # je valide, je nettoie les marqueurs, puis je promeus moi-même
   git mv 10-brouillons/pipeline-laravel.md 20-durable/patterns/pipeline-laravel.md
   $EDITOR 20-durable/patterns/pipeline-laravel.md   # je retire les [!todo] / [!verify]
   git commit -m "note: consolide le pattern pipeline"
   ```

Rien n'entre dans le savoir durable sans cette relecture humaine. L'agent prépare le terrain ; la décision reste de mon côté, exactement comme une PR qui attend une revue avant d'être mergée.

## La relecture factuelle, ciblée

L'agent a un second rôle utile, mais je l'ai volontairement bridé. Quand il consolide des notes, il peut signaler les erreurs factuelles — et uniquement les **affirmations externes et vérifiables** : un numéro de version, une signature d'API, une date, un chiffre. Pour celles-là, il pose un marqueur `[!verify]` plutôt que d'affirmer lui-même, et me laisse confirmer.

Ce qu'il ne fait **jamais**, c'est réécrire le vécu. Mon retour d'expérience, mon opinion sur un pattern, la raison pour laquelle un choix m'a coûté cher : ça, c'est de la matière personnelle, pas une donnée à corriger. Un agent qui « améliore » une anecdote la transforme en texte générique et lui retire sa seule valeur. La consigne est donc explicite : tu signales un fait douteux, tu ne touches pas à une expérience racontée.

## Pourquoi markdown + git, et pas une appli

Le substrat n'est pas un détail. Du markdown dans un dépôt git, ça donne trois propriétés que je n'avais avec aucun outil « tout-en-un » :

- **Zéro lock-in.** Ce sont des fichiers texte. Obsidian, l'agent, mon éditeur, `grep` : tout le monde lit le même format, et je peux changer d'outil sans migration.
- **Tout est diffable.** Avant d'accepter quoi que ce soit, je vois précisément ce que l'agent a changé. Un `git diff` sur un brouillon, et l'ajout suspect saute aux yeux.

  ```bash
  git diff --stat 10-brouillons/
  ```

- **Tout est réversible.** Si une consolidation part de travers, `git revert` ou `git checkout` annulent proprement. Le filet de sécurité est intégré : aucune modification n'est jamais perdue ni définitive.

Cette traçabilité est ce qui rend la délégation supportable. Je laisse une machine manipuler mes notes parce que je peux, à tout instant, voir et défaire ce qu'elle a fait.

## Ce qu'il faut retenir

Confier sa base de connaissance à un agent IA ne marche que si les rôles sont tranchés : l'humain lit et navigue, l'agent produit et range, et le savoir durable reste sanctuarisé.

- **Séparez les zones physiquement** : un dossier de travail pour l'agent, un dossier durable en lecture seule, et des règles écrites qu'il relit à chaque tâche.
- **Calquez le flux sur une pull request** : brouillon relié avec marqueurs de travail → relecture humaine à froid → promotion manuelle. Rien n'entre dans le durable sans votre go.
- **Bridez la relecture factuelle** : l'agent signale les faits externes vérifiables, il ne réécrit jamais le vécu.
- **Tenez-vous au couple markdown + git** : pas de lock-in, tout est diffable et réversible — c'est ce qui rend la délégation réversible donc acceptable.

L'IA structure, relie et extrait ; elle n'invente pas à votre place. La prochaine fois que vous laisserez un agent toucher à vos notes, donnez-lui un bac à sable et gardez la clé du coffre.
