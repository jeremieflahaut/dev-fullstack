---
title: "Faire respecter les frontières de modules d'un monolithe Laravel avec Deptrac"
description: "Une frontière de module ne tient que si une machine la vérifie. Voici comment Deptrac interdit les imports croisés et casse le build en CI à la première dérive."
pubDate: 2026-06-24
tags: ["laravel", "php"]
---

Le monolithe avait commencé proprement : un dossier par domaine, `app/Modules/Commandes`, `app/Modules/Facturation`, `app/Modules/Catalogue`. Six mois plus tard, un `use App\Modules\Facturation\Internal\LigneFactureRepository;` traînait au beau milieu d'un service du module Commandes. Personne ne l'avait décidé : c'était juste plus rapide ce jour-là. La frontière entre les deux modules existait dans nos têtes et dans le README — nulle part dans le code. Et une frontière que rien ne vérifie n'est pas une frontière, c'est un vœu pieux.

## Une arborescence ne se défend pas toute seule

La plupart des articles sur le « monolithe modulaire » s'arrêtent à la structure de dossiers : un répertoire par module, des sous-dossiers `Models`, `Services`, `Http`. C'est nécessaire, mais ça ne contraint rien. PHP se moque de votre intention : tant que la classe est autoloadée, un `use` vers les entrailles d'un autre module compile et tourne sans broncher.

Le problème, c'est que cette dette est invisible. Aucun test ne rougit, aucun linter classique ne s'en plaint — `phpcs` vérifie le style, pas les dépendances entre couches. La dérive ne se voit qu'à la relecture, et seulement si le relecteur connaît la règle. Multipliez par une équipe et quelques mois, et les modules finissent soudés par mille petits imports qu'on n'ose plus défaire.

Pour que la frontière tienne, il faut qu'une machine la vérifie à chaque commit. C'est exactement le travail de [Deptrac](https://github.com/deptrac/deptrac) : un analyseur statique qui découpe le code en couches et refuse toute dépendance que vous n'avez pas explicitement autorisée.

## Installer Deptrac

Deptrac est un outil de développement, jamais une dépendance de production. Il demande PHP 8.1 ou plus :

```bash
composer require --dev deptrac/deptrac
```

Le binaire arrive dans `vendor/bin/deptrac`. Tout se pilote ensuite depuis un seul fichier de configuration, `deptrac.yaml`, à la racine du projet.

## Des couches alignées sur les modules

Le principe de Deptrac tient en trois notions : des **paths** (où chercher le code), des **layers** (des regroupements logiques de classes) et un **ruleset** (qui a le droit de dépendre de qui). La règle d'or à garder en tête : **par défaut, tout est interdit entre couches**. On ne déclare pas les interdictions, on déclare les seules dépendances tolérées ; le reste est refusé d'office.

L'astuce pour notre problème, c'est de ne pas traiter un module comme une couche unique, mais d'en isoler la façade publique de ses internes. Un module expose un dossier `Api` — ses contrats, ses DTO, les seuls points d'entrée légitimes — et garde tout le reste pour lui.

```yaml
deptrac:
  paths:
    - ./app/Modules

  layers:
    - name: Commandes
      collectors:
        - type: directory
          value: app/Modules/Commandes/.*

    # La façade publique de Facturation : ce que les autres modules ont le droit d'appeler.
    - name: FacturationApi
      collectors:
        - type: directory
          value: app/Modules/Facturation/Api/.*

    # Tout Facturation SAUF sa façade : les internes, interdits de l'extérieur.
    - name: FacturationInterne
      collectors:
        - type: bool
          must:
            - type: directory
              value: app/Modules/Facturation/.*
          must_not:
            - type: directory
              value: app/Modules/Facturation/Api/.*

  ruleset:
    Commandes:
      - FacturationApi
    FacturationInterne:
      - FacturationApi
    FacturationApi: ~
```

Le collecteur `bool` est ce qui rend la séparation propre : la couche `FacturationInterne` rassemble tout ce qui est sous `app/Modules/Facturation` **et** hors de `Api`. Le `ruleset` autorise ensuite `Commandes` à dépendre de `FacturationApi`, et rien d'autre. La ligne `FacturationApi: ~` déclare que la façade ne dépend d'aucune couche listée — un contrat public n'a pas à connaître quoi que ce soit en amont.

## Autoriser le contrat, interdire les internes

C'est maintenant que la règle mord. Reprenons l'import fautif du début : un service de Commandes qui pioche directement dans un repository interne de Facturation.

```php
namespace App\Modules\Commandes\Services;

use App\Modules\Facturation\Internal\LigneFactureRepository; // ❌ frontière franchie

class ValidationCommande
{
    public function __construct(
        private LigneFactureRepository $lignes,
    ) {}
}
```

On lance l'analyse :

```bash
vendor/bin/deptrac analyse
```

Et Deptrac refuse :

```
App\Modules\Commandes\Services\ValidationCommande::8 must not depend on App\Modules\Facturation\Internal\LigneFactureRepository (Commandes on FacturationInterne)

Violations: 1
```

La sortie se lit de gauche à droite : telle classe de `Commandes`, à telle ligne, dépend d'une classe rangée dans `FacturationInterne`, ce qui n'est pas autorisé. Le correctif n'est pas de bidouiller la config, mais de passer par la façade — une interface dans `FacturationApi` qui expose juste ce dont Commandes a besoin, et qu'une implémentation interne réalise. La dépendance redevient légale parce qu'elle passe par le contrat, pas par-dessus.

## Adopter Deptrac sans tout casser : la baseline

Sur un monolithe existant, la première analyse remonte rarement une violation : elle en remonte trente. Les corriger toutes avant de pouvoir merger quoi que ce soit, c'est le meilleur moyen de ne jamais adopter l'outil.

La parade est la **baseline** : on photographie les violations actuelles, on les gèle comme dette connue, et on interdit seulement les *nouvelles*.

```bash
vendor/bin/deptrac analyse --formatter=baseline
```

Cette commande génère un fichier `deptrac.baseline.yaml` contenant la liste des violations existantes sous une clé `skip_violations`. Ce fichier ne se charge pas tout seul, il faut l'importer dans `deptrac.yaml` via une section `imports` (`imports: [deptrac.baseline.yaml]`). Une fois en place, les violations gelées ne font plus échouer le build, mais toute nouvelle dépendance croisée, elle, est rejetée. La dette ne grossit plus, et on la résorbe au rythme où on retouche les fichiers concernés — chaque ligne supprimée de la baseline est un petit progrès mesurable.

## La frontière devient réelle en CI

Tant que Deptrac tourne sur le poste d'un dev consciencieux, la frontière reste optionnelle. Elle ne devient une règle qu'au moment où le build casse sans elle. Une étape de CI suffit ; voici un job GitHub Actions :

```yaml
name: Architecture

on: [pull_request]

jobs:
  deptrac:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - run: composer install --no-interaction --prefer-dist

      - name: Vérifier les frontières de modules
        run: vendor/bin/deptrac analyse --no-progress --fail-on-uncovered
```

`deptrac analyse` renvoie un code de sortie non nul dès qu'une violation hors baseline apparaît : la pull request passe au rouge. L'option `--fail-on-uncovered` ajoute une exigence utile : faire échouer aussi le build si du code n'est rattaché à aucune couche, ce qui arrive typiquement quand on crée un module et qu'on oublie de l'ajouter à `deptrac.yaml`. Sans ça, un nouveau module pourrait naître entièrement hors radar.

À partir de là, la frontière est réelle. Le `use` interdit du début compile peut-être toujours, mais il ne *merge* plus.

## Ce que Deptrac ne voit pas

Vendre Deptrac comme un garde-fou total serait malhonnête. C'est un analyseur **statique de namespaces** : il lit les `use` et les références de types, point. Plusieurs choses lui échappent.

Les **résolutions dynamiques** passent sous le radar. Un `app(LigneFactureRepository::class)`, une façade Laravel, un `resolve()` avec une chaîne construite à la volée : Deptrac ne suit pas le conteneur de services à l'exécution. On peut donc franchir une frontière sans qu'il s'en aperçoive, simplement en résolvant la dépendance dynamiquement. La discipline reste nécessaire ; l'outil n'attrape que ce qui est écrit en clair dans le code.

Et puis tout cela peut être de l'**over-engineering**. Sur une petite app, un projet solo, ou des modules dont les contours bougent encore à chaque sprint, poser des frontières rigides revient à se ligoter avant de savoir où l'on va. Deptrac prend tout son sens quand les modules sont stabilisés, que l'équipe est à plusieurs et que la dérive a déjà commencé à coûter. Avant ça, c'est une cérémonie pour rien.

## Ce qu'il faut retenir

Une frontière de module décrite dans un README n'engage personne ; une frontière vérifiée par une machine, à chaque pull request, en est vraiment une.

- Découpez chaque module en une façade publique (`Api`) et des internes, et n'autorisez les autres modules qu'à dépendre de la façade — le `ruleset` de Deptrac interdit le reste par défaut.
- Sur l'existant, démarrez avec une baseline pour geler la dette et n'interdire que les nouvelles violations.
- Branchez `deptrac analyse --fail-on-uncovered` en CI : c'est le seul endroit où la règle devient contraignante.
- Restez lucide sur ses angles morts (résolutions dynamiques, façades) et n'imposez ces frontières qu'à des modules stabilisés.

Commencez petit : deux ou trois couches grossières, une baseline, une étape de CI. Mieux vaut une frontière imparfaite mais vérifiée qu'une cartographie exhaustive que personne ne fait respecter. Les règles grandiront avec l'application — et le jour où quelqu'un retentera le `use` interdit, c'est le build qui dira non, pas vous en relecture.
