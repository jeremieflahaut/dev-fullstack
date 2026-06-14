---
title: "Un commit, une intention : en finir avec les commits fourre-tout"
description: "Pourquoi un commit doit porter une seule intention, comment découper proprement avec git, et comment je délègue cette discipline à une skill Claude Code."
pubDate: 2026-06-14
tags: ["git", "ia"]
---

Vous ouvrez `git blame` sur une ligne qui vous intrigue, et vous tombez sur un commit intitulé « fix bug + refacto + WIP ». Impossible de savoir laquelle des trois modifications explique le comportement que vous traquez. Un `git revert` annulerait les deux autres au passage. Le problème n'est pas la ligne : c'est le commit, qui mélange plusieurs intentions.

## Le commit fourre-tout se paie plus tard

La valeur d'un commit, c'est de pouvoir être manipulé seul. Un commit qui mélange un correctif, un refactoring et une coquille casse tout ce qui fait la force de git :

- **`git revert`** : annuler le correctif sans défaire le refactoring devient impossible, car les trois sont soudés.
- **`git bisect`** : quand le commit fautif contient à la fois un fix et un refacto, vous savez *où* ça casse, mais pas *laquelle des deux moitiés* est en cause.
- **`git blame` et la revue** : on lit un diff qui raconte trois histoires en même temps. Personne ne relit ça correctement.

Le coût est différé, donc invisible au moment où on commite. Il revient toujours, en général un soir de production.

## La règle : une intention par commit

L'unité d'un commit, ce n'est pas le fichier, mais **l'intention**, le changement logique cohérent.

Une intention, c'est un commit, même si elle touche dix fichiers. Une fonctionnalité = son implémentation, son câblage et ses tests = **un seul** commit, parce que le code et son test doivent rester checkout-ables ensemble. Beaucoup de fichiers n'est pas un problème ; beaucoup d'intentions dans un même commit, si.

Le test mental tient en une phrase : **si vous ne pouvez pas résumer le commit sans utiliser « et », c'est qu'il en contient plusieurs.** « Corrige l'arrondi des montants » passe. « Corrige l'arrondi *et* renomme le service » est à découper en deux.

L'erreur inverse existe aussi : le commit par fichier. Découper une seule intention en un commit par fichier casse l'atomicité tout autant. Un commit isolé doit pouvoir tourner sans casser le reste.

## Découper proprement

D'abord, **inspecter** tout ce qui a bougé, fichiers non suivis compris, car un nouveau fichier fait presque toujours partie intégrante d'une intention :

```bash
git status
git diff
```

Ensuite, **stager précisément**, fichier par fichier. On bannit `git add -A` et `git add .`, qui ramassent tout en vrac et ruinent le découpage :

```bash
# Au lieu d'un seul commit fourre-tout…
git commit -am "fix login + refacto cache + typo readme"

# …on isole chaque intention :
git add src/Auth/LoginController.php
git commit -m "fix(auth): rejette les identifiants vides"

git add src/Cache/RedisStore.php
git commit -m "refactor(cache): extrait la clé dans une méthode dédiée"

git add README.md
git commit -m "docs(readme): corrige une coquille dans l'installation"
```

Reste le cas tordu : **un fichier qui porte deux intentions** à la fois. Stager le fichier entier mélangerait les deux. La parade, c'est le staging par bloc, qui vous laisse choisir les portions à inclure :

```bash
git add -p src/Service/Invoice.php
```

git vous présente chaque *hunk* et vous répondez `y` ou `n`. Vous committez la première intention, puis vous recommencez pour la seconde.

## Un message qui dit l'intention

Un bon découpage mérite un bon message. J'utilise les commits conventionnels :

```
type(scope): sujet à l'impératif, en minuscule

Le pourquoi : le problème que ce commit résout. Pas le « quoi »,
déjà visible dans le diff.
```

Le `type` (`fix`, `feat`, `refactor`, `docs`, `chore`…) et le `scope` situent le changement d'un coup d'œil dans l'historique. Le sujet reste court et descriptif. Le corps, lui, n'est utile que s'il explique une décision : pourquoi cette approche, quel piège on évite. Inutile de paraphraser le diff.

Deux détails qui comptent : pas de numéro de ticket dans le message (sa place est dans la pull request), et surtout pas de bruit du type « WIP » ou « update », qui ne décrivent aucune intention.

## Je délègue la discipline à une skill Claude Code

Tenir cette rigueur à chaque commit, c'est fastidieux, et c'est exactement le genre de tâche que je préfère déléguer. Je m'appuie sur une skill Claude Code dédiée. Son déroulé est simple : elle inspecte l'arbre de travail, classe les changements par intention, me **propose un découpage** (pour chaque commit, sa liste de fichiers et son message), attend ma validation, puis commite en stageant précisément.

Ce qu'elle m'apporte vraiment, c'est moins la frappe en moins que la discipline systématique : elle n'oublie jamais un fichier non suivi (l'angle mort classique), elle refuse le `git add -A`, et elle calque la langue des messages sur l'historique du dépôt.

Honnêtement, elle ne remplace pas le jugement. Sur un fichier qui porte deux intentions, c'est toujours à moi de trancher avec le `git add -p` vu plus haut. Et elle a ses partis pris : par défaut, elle préfère créer une branche plutôt que committer directement sur `main`, pratique sur un dépôt d'équipe mais à contourner sur un petit projet en solo. L'outil applique une règle ; à moi de savoir quand elle ne s'applique pas.

## Le réflexe à garder

Pas besoin d'outillage pour bien commencer. Avant chaque commit, posez-vous une seule question : **puis-je le résumer en une phrase, sans « et » ?** Si oui, il est atomique. Sinon, découpez. Votre vous-du-futur, un `git bisect` à 23 h, vous remerciera.
