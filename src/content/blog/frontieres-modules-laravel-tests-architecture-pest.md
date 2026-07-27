---
title: "Garder honnête un monolithe modulaire Laravel avec les tests arch() de Pest"
description: "Un module qui appelle en direct l'interne d'un autre, et la frontière meurt en silence. Comment la verrouiller avec les tests d'architecture Pest, en CI."
pubDate: 2026-07-27
tags: ["laravel", "tests"]
---

Le couplage ne s'annonce jamais. Sur une plateforme métier interne, un module de facturation avait besoin du prix d'un article. La classe qui le calculait existait déjà dans le module catalogue — alors on l'a instanciée en direct. Une ligne, un `new`, la fonctionnalité livrée. Ça compilait, la suite était verte, le ticket était fermé. La frontière entre les deux modules venait de mourir, et personne ne l'a vu passer.

Six mois plus tard, on ne peut plus toucher au calcul de prix du catalogue sans casser la facturation, parce qu'un lien qu'on croyait interne est devenu une dépendance publique de fait. La leçon n'est pas « il fallait mieux ranger le code » : c'était rangé. C'est que rien, dans le projet, n'empêchait ce lien d'exister. Une frontière qu'aucun outil ne défend n'est pas une frontière, c'est une intention.

## Des modules, sans package ni Clean Architecture

Le décor est volontairement minimal. Le code est organisé par modules métier, un dossier et un namespace chacun, sans package externe ni couches hexagonales :

```
app/
  Modules/
    Catalogue/
      Contracts/        // ce que les autres modules ont le droit d'utiliser
      Internal/         // tout le reste : privé au module
      Http/
    Facturation/
      Contracts/
      Internal/
      Http/
```

La convention tient en une phrase : `Internal` est privé au module, `Contracts` est sa surface publique. Un module peut dépendre des `Contracts` d'un autre ; il ne doit jamais toucher à son `Internal`. C'est une décision d'équipe, pas une contrainte du framework — et c'est précisément le problème. Rien dans PHP ne connaît cette règle. Il faut la rendre exécutable.

## La frontière comme contrat exécutable

Pest 3 embarque les tests d'architecture. On y décrit des règles sur les namespaces et leurs usages, et Pest les vérifie en analysant le code. La règle qui aurait attrapé notre incident tient en trois lignes :

```php
arch('un module ne touche jamais l’interne d’un autre')
    ->expect('App\Modules\Catalogue\Internal')
    ->toOnlyBeUsedIn('App\Modules\Catalogue');
```

Traduction : tout ce qui vit dans `Catalogue\Internal` ne peut être utilisé que depuis l'intérieur du catalogue. Le `new \App\Modules\Catalogue\Internal\PrixCalculator()` glissé dans le contrôleur de facturation fait immédiatement passer ce test au rouge. Le couplage ne compile plus « sans que personne ne le voie » : il devient une erreur nommée.

On peut poser la règle dans l'autre sens, côté consommateur, pour être explicite sur ce qu'un module a le droit d'ignorer :

```php
arch('la facturation ne dépend pas de l’interne du catalogue')
    ->expect('App\Modules\Facturation')
    ->not->toUse('App\Modules\Catalogue\Internal');
```

Et on peut serrer une couche précise. La couche HTTP d'un module n'a aucune raison de connaître un autre module — elle parle à son propre domaine, qui, lui, dialogue avec l'extérieur :

```php
arch('la couche Http de la facturation ignore les autres modules')
    ->expect('App\Modules\Facturation\Http')
    ->not->toUse([
        'App\Modules\Catalogue',
        'App\Modules\Stock',
    ]);
```

Ces règles ne sont pas de la documentation qui se périme. Elles s'exécutent, elles échouent, elles se corrigent.

## Autoriser les points de passage légitimes

Interdire tout dialogue entre modules serait absurde : la facturation a réellement besoin d'un prix. L'enjeu n'est pas de supprimer le couplage, mais de le rendre **visible et voulu**. On ouvre donc une porte, une seule : les `Contracts`.

Concrètement, le catalogue expose une interface et la lie dans le container. La facturation dépend de l'interface, jamais de l'implémentation :

```php
namespace App\Modules\Catalogue\Contracts;

interface FournitLePrix
{
    public function prixTtc(int $articleId): int;
}
```

```php
namespace App\Modules\Facturation\Internal;

use App\Modules\Catalogue\Contracts\FournitLePrix;

final class GenerateurFacture
{
    public function __construct(private FournitLePrix $prix) {}

    public function pour(int $articleId): int
    {
        return $this->prix->prixTtc($articleId);
    }
}
```

La règle d'architecture reflète cette porte : l'interne du catalogue reste privé, mais ses contrats sont ouverts à tous. La clause `ignoring()` exempte explicitement la surface publique :

```php
arch('le catalogue n’est accessible que par ses contrats')
    ->expect('App\Modules\Catalogue')
    ->toOnlyBeUsedIn('App\Modules\Catalogue')
    ->ignoring('App\Modules\Catalogue\Contracts');
```

Le couplage résiduel — la facturation connaît `FournitLePrix` — est désormais un choix inscrit dans le test, pas un accident. Pour les échanges qui n'appellent pas de retour, un événement de domaine joue le même rôle de sas : le catalogue émet, la facturation écoute, sans qu'aucune des deux ne cite l'`Internal` de l'autre.

## Brancher la frontière dans la CI

Un test d'architecture dans un dépôt que personne ne lance ne vaut pas mieux qu'un commentaire. Sa valeur vient de la CI. Comme n'importe quel test Pest, il tourne avec la suite :

```bash
./vendor/bin/pest
```

Dans le workflow d'intégration continue, la commande est déjà là :

```yaml
- name: Tests
  run: ./vendor/bin/pest --ci
```

À partir de ce moment, la mécanique s'inverse. La pull request qui réintroduit un `new Catalogue\Internal\...` dans la facturation ne passe plus le vert obligatoire : le build casse, avec le nom de la règle violée en clair. La frontière ne peut plus se dégrader en douce entre deux revues, parce que la dégrader, c'est désormais rendre la CI rouge. L'architecture cesse d'être un principe qu'on rappelle en réunion pour devenir une contrainte qu'on ne peut plus contourner par accident.

## Ce que arch() ne voit pas

Vendre ces tests comme une garantie totale serait malhonnête. Ils analysent les namespaces et les usages statiques — les `use`, les instanciations, les appels typés. Ils ne comprennent ni la sémantique ni l'intention.

Le trou le plus large : le couplage qui passe par le container ou par des chaînes. Un `app(PrixCalculator::class)` avec le nom de la classe interne en dur, ou une résolution montée depuis une string de configuration, échappe à l'analyse statique. `arch()` ne les voit pas, et la frontière peut fuir par là. La règle attrape le couplage franc et honnête, pas le couplage dynamique déguisé.

Il faut aussi assumer les faux négatifs dans l'autre sens : une règle trop large peut passer au vert alors que le design est douteux, simplement parce qu'aucun namespace interdit n'est cité. Le test dit « la frontière déclarée est respectée », pas « l'architecture est bonne ».

Enfin, la lucidité sur le contexte. Sur un petit projet tenu par une seule personne, verrouiller des frontières entre modules est du sur-engineering : on paie une cérémonie pour protéger une découpe qui n'existe pas encore. Ces règles paient quand le monolithe grossit, que plusieurs domaines cohabitent et que plusieurs mains touchent au code — le moment exact où le couplage discret devient cher à défaire. Pour un outillage plus poussé, orienté graphe de dépendances, [deptrac ou Arkitect](https://laravel-france.com/posts/laravel-arkitect) vont plus loin ; mais pour commencer, Pest a l'avantage d'être déjà là, dans la suite que la CI lance de toute façon.

## Ce qu'il faut retenir

Une frontière entre modules ne tient pas parce qu'on l'a décidée, mais parce que quelque chose la défend à chaque commit. Les tests d'architecture Pest transforment cette décision en contrat exécutable, et la CI en fait une contrainte qu'on ne franchit plus par distraction.

Le bon point de départ n'est pas d'écrire dix règles d'un coup. C'est d'en écrire **une seule**, sur la frontière qui fait déjà mal — celle où vous savez qu'un module tripote l'interne d'un autre. La rendre verte, souvent en extrayant un contrat. Puis l'ajouter à la CI. À partir de là, cette frontière-là ne peut plus régresser en silence, et vous ajouterez les suivantes une par une, au rythme où le monolithe vous montre où il se fissure.
