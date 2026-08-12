---
title: "GitHub Actions : le piège de concurrency sur un déploiement"
description: "cancel-in-progress: false ne garantit pas que tous vos déploiements passent : la file ne garde qu'un run en attente. Comment sérialiser vraiment."
pubDate: 2026-08-12
tags: ["ci", "git"]
---

Vous poussez un correctif sur `main`, puis un second trente secondes plus tard parce que vous avez oublié une ligne. GitHub Actions lance deux déploiements. Le premier se fait annuler en plein milieu, ou reste bloqué en `queued` pour toujours. Le réflexe, c'est d'ajouter un bloc `concurrency` avec `cancel-in-progress: false` en pensant « comme ça, les deux passeront à la suite ». C'est faux, et cette croyance est le piège le plus courant autour de `concurrency`.

## Le modèle mental exact d'un groupe de concurrence

Un groupe de concurrence, ce n'est pas une file d'attente illimitée. C'est un emplacement qui contient **au plus deux runs** : un run *en cours d'exécution*, et un seul run *en attente*. Rien de plus.

Quand un nouveau run arrive dans un groupe déjà occupé, GitHub applique une règle simple : le run en attente actuel est **évincé** au profit du nouveau. Il ne passe pas après — il est purement et simplement annulé, sans jamais avoir tourné.

Déroulons le scénario qui fait mal. Vous poussez huit commits rapprochés sur `main`, chacun déclenche le workflow de déploiement, et tout ce petit monde atterrit dans le même groupe :

- push #1 : démarre, occupe le slot « en cours » ;
- push #2 : se met en attente ;
- push #3 : évince #2, prend sa place en attente ;
- push #4 : évince #3… et ainsi de suite jusqu'à #8.

Résultat : **seuls #1 et #8 s'exécutent**. Les six déploiements intermédiaires sont annulés avant d'avoir commencé. Si votre déploiement était censé jouer une action à chaque commit — pas seulement converger vers le dernier état — vous venez d'en perdre six sans le moindre avertissement rouge.

## `cancel-in-progress: true` : rapide mais dangereux

Le premier réglage annule le run *en cours* dès qu'un nouveau arrive :

```yaml
concurrency:
  group: deploy-${{ github.ref }}
  cancel-in-progress: true
```

C'est parfait quand un déploiement en cours devient inutile à la seconde où un plus récent apparaît — typiquement le déploiement d'un site statique, où seul le dernier build compte. Pourquoi finir un déploiement périmé ?

Le danger arrive quand le run fait des étapes **non idempotentes et non rejouables** en cours de route : une migration de base de données, une publication d'assets, un tag de release, un appel à une API tierce qui facture. Si `cancel-in-progress: true` coupe le job entre deux migrations, vous vous retrouvez avec un schéma à moitié appliqué et aucun run pour finir le travail. L'annulation ne fait pas de rollback : elle envoie un `SIGTERM` et passe à la suite.

## `cancel-in-progress: false` : le piège du « tout passera »

Le second réglage protège le run en cours — il le laisse aller au bout :

```yaml
concurrency:
  group: deploy-${{ github.ref }}
  cancel-in-progress: false
```

C'est là que l'erreur de modèle mental coûte cher. `false` protège bien le run *en cours*, mais **il ne change rien à la règle d'éviction des runs en attente**. Le slot d'attente reste unique. Vos pushes #2 à #7 s'annulent toujours entre eux ; seul le dernier arrivé survit pour passer après le run actif.

Autrement dit, `cancel-in-progress: false` ne veut pas dire « tous mes déploiements s'exécuteront ». Il veut dire « je ne coupe pas celui qui tourne, et je garde le plus récent des suivants ». Si votre besoin, c'est de ne perdre aucun déploiement, ce réglage ne le couvre pas.

## L'heuristique de choix

Le bon réglage ne se copie-colle pas : il découle d'une seule question — **votre déploiement est-il idempotent ?**

**Cas 1 — déploiement idempotent, « seul le dernier état compte ».** Un site statique, un build reproductible, une image qu'on republie : rejouer le déploiement donne exactement le même résultat, et sauter les états intermédiaires est sans conséquence. Ici, `cancel-in-progress: true` est le bon choix. On annule le run périmé, on déploie le dernier commit, terminé. Perdre les états intermédiaires n'est pas un bug, c'est le comportement voulu.

**Cas 2 — étapes ordonnées et non rejouables.** Des migrations, une séquence de publication, quoi que ce soit où chaque déploiement *fait quelque chose* qu'on ne peut pas simplement écraser par le suivant. Là, ni `true` (qui risque de couper à mi-chemin) ni `false` (qui perd les intermédiaires) ne conviennent. Il faut sérialiser **strictement** : chaque déploiement va au bout, dans l'ordre, sans qu'aucun ne soit évincé.

La clé, c'est de changer la granularité du groupe :

```yaml
concurrency:
  group: deploy-${{ github.sha }}
  cancel-in-progress: false
```

En passant de `github.ref` à `github.sha`, chaque commit obtient **son propre groupe de concurrence**. Comme un groupe ne contient jamais qu'un seul run par SHA, plus personne n'évince personne : les runs ne se marchent plus dessus et chacun s'exécute jusqu'au bout. Vous ne sérialisez plus « la branche » globalement, vous garantissez qu'un même commit ne se déploie pas deux fois en parallèle, tout en laissant tous les commits passer.

Attention à la contrepartie honnête : avec `github.sha`, les déploiements peuvent alors se **chevaucher** entre commits différents, puisqu'ils sont dans des groupes distincts. Si vos migrations exigent un ordre strict et exclusif, ce n'est pas suffisant — il faut un verrou en amont (une file applicative, un environnement GitHub avec un seul déploiement concurrent, ou un job unique qui traite les commits en série). `concurrency` gère la déduplication, pas l'ordonnancement métier.

## Un workflow complet pour GitHub Pages

Le cas le plus fréquent — celui de ce blog — c'est un site statique déployé sur GitHub Pages à chaque push sur `main`. Déploiement idempotent : `cancel-in-progress: true` convient. Voici un workflow fonctionnel de bout en bout :

```yaml
name: Déploiement Pages

on:
  push:
    branches: [main]

# Site statique : seul le dernier build compte, on annule les précédents.
concurrency:
  group: pages
  cancel-in-progress: true

permissions:
  contents: read
  pages: write        # publier sur GitHub Pages
  id-token: write     # authentifier le déploiement (OIDC)

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 22
      - run: npm ci
      - run: npm run build
      - uses: actions/upload-pages-artifact@v3
        with:
          path: ./dist

  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment:
      name: github-pages
      url: ${{ steps.deployment.outputs.page_url }}
    steps:
      - id: deployment
        uses: actions/deploy-pages@v4
```

Deux points de vigilance sur Pages :

- **Les permissions sont obligatoires.** `pages: write` et `id-token: write` sont requis par `deploy-pages`. Sans elles, le job échoue avec une erreur de permission au moment du déploiement, pas au checkout.
- **Le groupe `pages` peut geler vos déploiements.** GitHub Pages utilise en interne un environnement protégé qui n'accepte qu'un déploiement à la fois. Si un run reste coincé, le suivant attend indéfiniment en `queued`. Quand vous voyez un déploiement bloqué sans raison, cherchez d'abord un run fantôme qui occupe encore le slot avant d'incriminer votre YAML.

## Ce qu'il faut retenir

`concurrency` n'est pas une file d'attente : c'est **un run actif plus un seul run en attente**, et tout nouvel arrivant évince celui qui patientait.

1. **`cancel-in-progress: true`** : pour les déploiements idempotents où seul le dernier état compte. Dangereux si le run fait des étapes non rejouables à mi-chemin.
2. **`cancel-in-progress: false`** : protège le run en cours, mais **ne sauve pas** les runs intermédiaires en attente. Ce n'est pas « tout passera ».
3. **`group: ${{ github.sha }}`** : pour que chaque commit ait son groupe et qu'aucun déploiement ne soit évincé — la bonne base quand les étapes sont ordonnées et non rejouables.

Choisissez le réglage à partir de l'idempotence réelle de votre déploiement, pas par copier-coller depuis un tuto. La question tient en une phrase : si deux déploiements se télescopent, est-ce que rejouer le dernier suffit, ou est-ce que chacun devait vraiment s'exécuter ? La réponse décide de votre bloc `concurrency`.
