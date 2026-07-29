---
title: "Tenir les frontières d'un monolithe Laravel avec des tests d'archi Pest"
description: "Empêcher en CI qu'un module Laravel en importe un autre par la porte de derrière, avec les tests d'architecture Pest — et savoir quand passer à Deptrac."
pubDate: 2026-07-29
tags: ["laravel", "tests"]
---

Un monolithe Laravel qui grossit finit toujours par le même symptôme : un jour, dans le module `Catalog`, quelqu'un écrit `use App\Modules\Billing\Actions\FacturerCommande;` et appelle directement une classe qui appartenait à la facturation. Ça compile, ça passe les tests, ça part en prod. Le couplage ne se voit qu'en review — quand on le voit —, et six mois plus tard les deux modules ne se démontent plus l'un sans l'autre. Sur une plateforme métier interne, j'ai fini par poser un garde-fou automatique en CI, léger, avant même de penser à découper le projet en vrais packages.

## La convention de dossiers ne tient pas toute seule

Ranger le code par domaine — `app/Modules/Catalog`, `app/Modules/Billing`, `app/Modules/Shared` — est un bon début. Mais un arbre de dossiers ne fait respecter aucune règle : c'est un *gentleman's agreement*. Rien ne casse quand on le viole. PHP se moque de savoir dans quel sous-dossier vit la classe qu'on importe ; un `use` traverse une frontière de module exactement comme il traverse un espace de noms quelconque.

La structure minimale que je vise ressemble à ceci, pour que les règles restent lisibles :

```
app/
  Modules/
    Catalog/
      Models/
      Actions/
      Contracts/
    Billing/
      Models/
      Actions/
      Contracts/
    Shared/
```

`Contracts/` porte les interfaces publiques d'un module — la seule surface qu'un autre module a le droit de connaître. Tout le reste (les modèles, les Actions concrètes) est censé rester privé. « Censé », justement : il manque le mécanisme qui transforme cette intention en barrière. Ce mécanisme, Pest le fournit déjà, sans nouvelle dépendance à installer.

## La barrière légère : un test d'architecture Pest

Pest expose une API d'assertions sur l'architecture via la fonction `arch()`. On ne teste plus un comportement, mais une propriété statique du code : qui a le droit d'importer quoi. Le fichier `tests/Arch/ModuleBoundariesTest.php` centralise ces règles et s'exécute en CI comme n'importe quel test.

La règle la plus directe interdit à un module d'en toucher un autre :

```php
<?php

arch('le catalogue ne connaît pas la facturation')
    ->expect('App\Modules\Catalog')
    ->not->toUse('App\Modules\Billing');

arch('la facturation ne connaît pas le catalogue')
    ->expect('App\Modules\Billing')
    ->not->toUse('App\Modules\Catalog');
```

`toUse()` inspecte les dépendances (les `use`, les type-hints, les instanciations) de tout le code sous `App\Modules\Catalog`. La forme `not->toUse()` fait échouer le test dès qu'un fichier du catalogue référence quoi que ce soit sous `App\Modules\Billing`. C'est l'import par la porte de derrière, arrêté net au build.

Pour une frontière plus stricte, on renverse la logique avec `toOnlyUse()` : au lieu d'énumérer les interdits, on déclare la liste blanche des dépendances autorisées. Un module ne peut alors s'appuyer que sur lui-même et sur le socle partagé :

```php
arch('le catalogue reste dans son périmètre')
    ->expect('App\Modules\Catalog')
    ->toOnlyUse([
        'App\Modules\Catalog',
        'App\Modules\Shared',
    ])
    ->ignoring([
        'Illuminate',
        'App\Models',
    ]);
```

`ignoring()` est indispensable : sans lui, chaque `Illuminate\Support\Collection` ou classe Eloquent hors module ferait échouer la règle. On exclut donc le framework et les espaces de noms neutres pour ne surveiller que ce qui compte — les dépendances inter-modules. C'est aussi la première source de faux sentiment de sécurité : une liste `ignoring()` trop large finit par tout autoriser sans qu'on s'en aperçoive.

Ces trois règles suffisent à couvrir l'essentiel. Elles tournent dans le même `pest` que le reste, échouent en CI sur le premier import interdit, et n'ajoutent aucune brique à la stack.

## Par où les modules ont le droit de se parler

Interdire l'import direct ne veut pas dire que les modules s'ignorent — une commande du catalogue doit bien finir par être facturée. Le principe, c'est de ne laisser passer que des surfaces publiques et stables, jamais une classe concrète. Trois canaux tiennent la route :

- **Un contrat public + DTO.** Le module `Billing` expose une interface dans son `Contracts/`, le catalogue la reçoit par injection. Il dépend de l'abstraction, résolue par le container, pas de l'implémentation.

```php
namespace App\Modules\Billing\Contracts;

use App\Modules\Shared\Data\CommandeData;

interface Facturier
{
    public function facturer(CommandeData $commande): void;
}
```

- **Un event Laravel.** Le catalogue émet `CommandeValidee` et ne sait rien de qui écoute. La facturation branche un listener de son côté. Le couplage tombe à zéro dans le sens de l'émission.
- **La résolution par le container.** On tape sur l'interface, jamais sur la classe : `app(Facturier::class)`. L'implémentation concrète reste privée à son module.

Le point commun : la dépendance ne porte que sur `Contracts/` et sur les DTO de `Shared/`, exactement ce que la liste blanche de `toOnlyUse()` autorise. La règle d'archi et la règle d'architecture disent alors la même chose.

## Quand la barrière légère ne suffit plus

Soyons honnête sur les limites. Les tests d'archi Pest raisonnent par espace de noms : ils voient très bien « le catalogue importe la facturation », mais ils peinent à exprimer des directions de dépendance fines. « Le domaine peut dépendre du partagé, jamais l'inverse, et la couche HTTP ne parle qu'au domaine » devient vite un empilement de règles `not->toUse()` difficile à relire. C'est le moment de sortir l'outil dédié.

[Deptrac](https://github.com/qossmic/deptrac) modélise le code en *layers* et n'autorise les dépendances qu'entre couches déclarées. Un `deptrac.yaml` décrit les frontières une fois pour toutes :

```yaml
deptrac:
  paths:
    - ./app/Modules
  layers:
    - name: Catalog
      collectors:
        - type: directory
          value: app/Modules/Catalog/.*
    - name: Billing
      collectors:
        - type: directory
          value: app/Modules/Billing/.*
    - name: Shared
      collectors:
        - type: directory
          value: app/Modules/Shared/.*
  ruleset:
    Catalog:
      - Shared
    Billing:
      - Shared
    Shared: ~
```

Ici, `Catalog` et `Billing` ne peuvent dépendre que de `Shared`, et `Shared` de personne. Toute autre dépendance est une violation. Le check tourne dans son propre job CI :

```bash
vendor/bin/deptrac analyse --fail-on-uncovered
```

`--fail-on-uncovered` fait aussi échouer le build sur une classe qui n'appartient à aucune couche — la faille par laquelle un nouveau dossier échappe silencieusement aux règles. Deptrac coûte une dépendance et un fichier de config à maintenir, mais il pilote finement ce que Pest ne fait que dégrossir.

## Ne pas sur-découper un projet qui ne le mérite pas

La vraie erreur serait de lire tout ça comme une invitation à modulariser un projet de trois entités. Sur une petite app, ces frontières sont de la sur-ingénierie pure : on paie le coût cognitif d'une architecture en modules sans avoir le problème qu'elle résout. Une poignée de règles `arch()` vertes en CI est le bon point de départ, très en amont d'une restructuration en packages Composer ou en *path repositories*.

L'ordre que je recommande :

1. Poser trois ou quatre règles `arch()` sur les frontières qui existent déjà, même informelles. C'est gratuit et immédiat.
2. Les regarder rougir en CI la première fois qu'un import traverse une frontière. La barrière fait son travail, on nettoie.
3. Ne durcir — passer à `toOnlyUse()`, puis à Deptrac, puis à un vrai découpage physique — que lorsqu'une même frontière est franchie plus d'une fois. Une violation isolée se corrige à la main ; une violation récurrente signale une frontière réelle qui mérite un outil dédié.

On monte les marches quand le couplage le prouve, pas par anticipation. Comme pour le [mutation testing](/blog/mutation-testing-pest-laravel/), l'outil ne remplace pas le jugement : il rend visible, en CI, une règle qu'on tenait jusque-là de tête et qu'on finissait fatalement par oublier.

## Ce qu'il faut retenir

Un dossier par module est une intention, pas une frontière — rien ne casse quand on la viole. Les tests d'architecture Pest transforment cette intention en barrière au coût le plus bas possible :

- Commencez par quelques `arch()->expect(...)->not->toUse(...)` dans `tests/Arch/`, exécutés avec le reste de la suite. Aucune dépendance à ajouter.
- Faites communiquer les modules par contrats publics, DTO et events — jamais par import d'une classe concrète.
- Passez à `toOnlyUse()` puis à Deptrac quand vous voulez piloter les *directions* de dépendance, pas seulement les interdire.
- Ne modularisez pas un projet qui ne souffre pas encore : durcissez le jour où une frontière est franchie deux fois, pas avant.

Écrivez la première règle rouge sur une frontière que vous croyez déjà respectée. Le test qui échoue est souvent la preuve que la porte de derrière était grande ouverte depuis des mois.
