---
title: "Deptrac : faire respecter l'architecture d'un monolithe Laravel en CI"
description: "Une convention d'archi dans un README ne tient jamais. Voici comment Deptrac transforme les frontières d'un monolithe modulaire Laravel en règle bloquante en CI."
pubDate: 2026-06-22
tags: ["laravel", "architecture"]
---

La scène est connue. On documente une belle architecture dans le README : des couches `Domain`, `Application`, `Infrastructure`, des modules métier qui ne se parlent que par des contrats. Trois sprints plus tard, sous la pression d'une deadline, un controller appelle directement le model d'un autre module « juste pour cette fois ». Personne ne le voit en revue, c'est une ligne sur deux cents. Six mois après, les frontières n'existent plus que sur le papier. Une convention qui n'est pas vérifiée par une machine n'est pas une règle : c'est un vœu pieux. Voici comment je la rends exécutable et bloquante avec [Deptrac](https://deptrac.github.io/deptrac/).

## D'abord, regarder le graphe qu'on a vraiment

Avant de poser la moindre règle, il faut voir l'existant. On part d'une structure de monolithe modulaire classique, un dossier par module métier, et à l'intérieur les couches :

```
app/
├─ Catalog/
│  ├─ Domain/
│  ├─ Application/
│  ├─ Infrastructure/
│  └─ Public/          ← contrats, DTO et events exposés aux autres modules
└─ Billing/
   ├─ Domain/
   ├─ Application/
   ├─ Infrastructure/
   └─ Public/
```

On installe Deptrac et on lui décrit ces couches sans encore interdire quoi que ce soit. Le but est de générer le graphe réel des dépendances pour constater l'écart avec ce qu'on croyait avoir :

```bash
composer require --dev deptrac/deptrac
vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml --formatter=mermaidjs --output=architecture.md
```

Le formatteur `mermaidjs` produit un diagramme que GitHub affiche directement dans une issue ou une PR. C'est souvent une douche froide salutaire : on découvre les flèches qu'on aurait juré inexistantes. À partir de là, on transforme l'intention en contrainte.

## Définir les couches et leurs droits

Deptrac fonctionne en deux temps : on déclare des *layers* via des *collectors* (ici, par répertoire), puis on écrit le *ruleset*, c'est-à-dire la liste des dépendances autorisées pour chaque couche. Tout ce qui n'y figure pas devient une violation.

```yaml
# deptrac.layers.yaml
deptrac:
  paths:
    - ./app
  layers:
    - name: Domain
      collectors:
        - type: directory
          value: app/[^/]+/Domain/.*
    - name: Application
      collectors:
        - type: directory
          value: app/[^/]+/Application/.*
    - name: Infrastructure
      collectors:
        - type: directory
          value: app/[^/]+/Infrastructure/.*
  ruleset:
    Domain: ~
    Application:
      - Domain
    Infrastructure:
      - Domain
      - Application
```

La lecture est limpide. `Domain: ~` signifie « le domaine ne dépend de rien » — c'est le cœur métier, il ignore tout du framework et de la base de données. `Application` orchestre le domaine, donc il a le droit de le voir. `Infrastructure` (repositories Eloquent, clients HTTP, jobs) peut s'appuyer sur les deux couches du dessus. La dépendance interdite la plus courante — un service `Domain` qui importe un model Eloquent rangé en `Infrastructure` — devient mécaniquement une violation, parce que `Domain` n'autorise rien.

## Isoler les modules entre eux

Le découpage en couches ne dit rien des frontières horizontales : rien n'empêche encore `Billing\Domain` d'appeler `Catalog\Domain`. C'est précisément là que les monolithes modulaires se transforment en plat de spaghetti. Je gère cette dimension dans un second fichier, dédié à l'isolation des modules :

```yaml
# deptrac.modules.yaml
deptrac:
  paths:
    - ./app
  layers:
    - name: Catalog
      collectors:
        - type: directory
          value: app/Catalog/(?!Public/).*
    - name: CatalogPublic
      collectors:
        - type: directory
          value: app/Catalog/Public/.*
    - name: Billing
      collectors:
        - type: directory
          value: app/Billing/(?!Public/).*
    - name: BillingPublic
      collectors:
        - type: directory
          value: app/Billing/Public/.*
  ruleset:
    Catalog:
      - CatalogPublic
    Billing:
      - BillingPublic
      - CatalogPublic
    CatalogPublic: ~
    BillingPublic: ~
```

L'astuce tient dans le lookahead négatif `(?!Public/)` : il sépare les *internals* d'un module de sa façade publique. La règle qui en découle est exactement celle qu'on voulait écrire dans le README : `Billing` a le droit de consommer `CatalogPublic` (les contrats et events que le Catalog expose volontairement), mais jamais `Catalog` tout court. Toucher à `Catalog\Domain` ou `Catalog\Infrastructure` depuis Billing devient une violation. Et comme `Catalog` n'a pas `Billing` dans ses dépendances autorisées, le couplage est interdit dans les deux sens. Les modules ne communiquent plus que par la porte d'entrée prévue.

Deux fichiers valent mieux qu'un seul ici : les deux dimensions (couches verticales, modules horizontaux) se raisonnent et se font évoluer séparément.

## Lire une violation et comprendre le code de sortie

Quand quelqu'un franchit une frontière, le rapport console est sans ambiguïté :

```
------ ----------------------------------------------------------------
 Line   Billing
------ ----------------------------------------------------------------
 23     App\Billing\Domain\Invoice must not depend on
        App\Catalog\Domain\Product (Billing on Catalog)
------ ----------------------------------------------------------------

 [ERROR] found 1 violations
```

Le point décisif pour la suite est invisible à l'écran : en présence de violations, `deptrac analyse` se termine avec un **code de sortie différent de zéro**. C'est tout ce dont la CI a besoin pour transformer un avertissement poli en barrière.

## Rendre la règle bloquante en CI

Un rapport que personne ne lit ne vaut rien. La règle ne devient une règle que le jour où elle casse la pull request. Voici le job GitHub Actions qui s'en charge :

```yaml
# .github/workflows/architecture.yml
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
          coverage: none
      - run: composer install --no-interaction --prefer-dist --no-progress
      - name: Vérifier les couches
        run: vendor/bin/deptrac analyse --config-file=deptrac.layers.yaml --fail-on-uncovered
      - name: Vérifier l'isolation des modules
        run: vendor/bin/deptrac analyse --config-file=deptrac.modules.yaml --formatter=github-actions
```

Comme chaque étape échoue au moindre code de sortie non nul, une frontière franchie fait virer la PR au rouge — impossible de merger sans la corriger ou amender la règle en connaissance de cause. Deux options méritent l'attention. `--fail-on-uncovered` fait échouer le build si une classe n'appartient à aucune couche : sans lui, on croit l'archi sous contrôle alors que des pans entiers du code échappent silencieusement à l'analyse. Et `--formatter=github-actions` transforme chaque violation en annotation directement posée sur la ligne fautive du diff, là où le relecteur la verra.

## L'alternative : Laravel Arkitect

Deptrac n'est pas seul. [Laravel Arkitect](https://github.com/smortexa/laravel-arkitect) enveloppe PHPArkitect pour le rendre idiomatique côté Laravel et exécutable dans la foulée des tests. La différence d'approche est nette : là où Deptrac décrit l'architecture en YAML, ici les règles s'écrivent en PHP, avec une API fluide. Sous le capot, c'est la grammaire expressive de PHPArkitect :

```php
<?php
// phparkitect.php

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {
    $classSet = ClassSet::fromDir(__DIR__ . '/app');

    $isolationDesModules = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Billing'))
        ->should(new NotDependsOnTheseNamespaces(
            'App\Catalog\Domain',
            'App\Catalog\Infrastructure',
        ))
        ->because('un module ne touche que les contrats publics d\'un autre');

    $config->add($classSet, $isolationDesModules);
};
```

Le choix entre les deux est affaire de goût et de besoin. Deptrac brille pour cartographier des couches et générer des graphes : sa vision « par layers » colle parfaitement à un monolithe modulaire, et le diagramme Mermaid est un argument à lui seul. PHPArkitect / Laravel Arkitect, avec ses règles écrites en PHP, est plus à l'aise pour des contraintes fines et conventionnelles — « tout controller doit finir par `Controller` », « aucune classe `Domain` ne doit être `final` oubliée ». Les deux retournent un code de sortie exploitable en CI ; rien n'interdit de les combiner, mais commencer avec un seul évite de disperser les règles.

## Les contreparties, parce qu'il y en a

Vendre cet outillage comme indolore serait malhonnête. Au premier `analyse` sur un code existant, le rapport déborde — des dizaines de violations, dont une partie sont des faux positifs liés à un découpage encore flou. La tentation est d'écrire un ruleset parfait d'un coup ; c'est l'erreur. Mieux vaut **commencer par une ou deux règles qui font mal** — l'isolation des modules, l'interdiction faite au domaine de connaître l'infrastructure — et les rendre bloquantes immédiatement, quitte à laisser le reste en jachère. Une règle verte et respectée vaut mieux que dix règles affichées et contournées.

L'autre coût est la maintenance. Le ruleset est du code : il vit, il se refactore avec l'application, et un découpage qui change oblige à le mettre à jour. Deptrac propose une *baseline* pour figer les violations existantes et n'interdire que les nouvelles — pratique pour brancher l'outil sur un projet déjà installé sans bloquer toute l'équipe le premier jour. C'est, à mon sens, la bonne porte d'entrée : on gèle la dette, on stoppe l'hémorragie, on résorbe ensuite.

## Ce qu'il faut retenir

Une frontière d'architecture n'existe que si une machine la vérifie. Le reste est de la documentation que la prochaine deadline effacera.

- Décrivez vos couches et l'isolation de vos modules dans des fichiers Deptrac séparés ; le `ruleset` rend explicite ce qui a le droit de dépendre de quoi.
- Exposez une façade `Public/` par module et interdisez l'accès aux internals : c'est la seule frontière horizontale qui tienne.
- Branchez `deptrac analyse` dans un job GitHub Actions : son code de sortie non nul casse la PR, et c'est précisément le but.
- Démarrez avec une *baseline* et deux règles qui comptent, plutôt qu'un ruleset parfait que personne ne respectera.

Lancez l'analyse une fois sur un projet que vous croyez propre. Le graphe qui s'affiche dit la vérité — et c'est rarement celle du README.
