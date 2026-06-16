---
title: "filter() puis toArray() : le piège des clés qui casse un $in Mongo"
description: "filter() et unique() conservent les clés d'origine. Derrière un toArray(), ça donne un tableau à trous que le driver Mongo sérialise en document — et $in explose."
pubDate: 2026-06-14
tags: ["laravel", "mongodb"]
---

Le code passait tous les tests, la requête tournait en local, et en prod elle renvoyait `$in needs an array`. Un message laconique du driver MongoDB, pour une requête dont le `$in` recevait pourtant — j'en étais sûr — un tableau. Le coupable n'était ni Mongo ni le driver : c'était une `Collection` Laravel filtrée juste avant, et la façon dont elle conserve ses clés. Une ligne manquait, `->values()`, et tout est rentré dans l'ordre.

## Une Collection qui conserve ses clés

Le point de départ, c'est un comportement parfaitement documenté de `Collection`, mais qu'on oublie vite : `filter()`, `unique()`, `reject()` et leurs cousines **préservent les clés d'origine**. Elles n'en fabriquent pas de nouvelles, elles se contentent de retirer des entrées.

Prenons une collection d'identifiants et retirons-en quelques-uns :

```php
use Illuminate\Support\Collection;

$ids = collect([10, 20, 30, 40, 50]);

$filtres = $ids->filter(fn (int $id) => $id !== 30);

$filtres->all();
// [0 => 10, 1 => 20, 3 => 40, 4 => 50]
```

La clé `2` a disparu avec la valeur `30`, mais les autres clés n'ont pas bougé. Le tableau résultant a **un trou** : il saute de `1` à `3`. Tant qu'on reste dans l'univers des collections, ça ne se voit pas — on itère, on `map`, on `sum`, les clés ne dérangent personne. Le problème surgit au moment où ce tableau quitte PHP.

`unique()` produit exactement le même genre de résultat :

```php
$ids = collect([10, 20, 20, 30, 30, 30]);

$ids->unique()->all();
// [0 => 10, 1 => 20, 3 => 30]
```

Là aussi, les doublons partent avec leur position, et il reste un tableau à clés non contiguës.

## Comment PHP voit ce tableau

PHP n'a qu'un seul type `array`, qui sert à la fois de liste indexée et de tableau associatif. La frontière entre les deux est purement conventionnelle : un tableau est considéré comme une « liste » si ses clés sont les entiers `0, 1, 2, …` sans trou ni réordonnancement. Depuis PHP 8.1, la fonction `array_is_list()` formalise ce test :

```php
array_is_list([10, 20, 40]);          // true
array_is_list([0 => 10, 1 => 20, 3 => 40]); // false, il manque la clé 2
```

Notre tableau filtré tombe dans le second cas. Pour n'importe quelle bibliothèque qui sérialise du PHP vers un format où liste et objet sont deux choses distinctes — JSON, BSON, YAML — cette distinction devient soudain capitale. `json_encode()` le montre bien :

```php
json_encode([10, 20, 40]);             // "[10,20,40]"        → un tableau JSON
json_encode([0 => 10, 1 => 20, 3 => 40]); // "{\"0\":10,\"1\":20,\"3\":40}" → un objet JSON
```

Le second n'est plus un tableau : c'est un objet, avec des clés `"0"`, `"1"`, `"3"`. Et c'est précisément ce que le driver MongoDB fait de son côté, en BSON.

## Le crash côté Mongo

L'opérateur `$in` attend une liste de valeurs. En BSON, une liste, c'est un *array* ; un tableau PHP à clés non contiguës, lui, est encodé en *document* BSON (l'équivalent d'un objet). Le serveur reçoit donc un document là où il attendait un array, et refuse net : `$in needs an array`.

Voici le scénario complet, tel qu'on le rencontre avec [`mongodb/laravel-mongodb`](https://www.mongodb.com/docs/drivers/php/laravel-mongodb/) :

```php
use App\Models\Commande; // modèle MongoDB

// Des identifiants venus d'ailleurs, qu'on nettoie avant la requête
$ids = collect([101, 102, 103, 104])
    ->filter(fn (int $id) => $this->estAutorise($id)); // retire le 102

// $ids->all() === [0 => 101, 2 => 103, 3 => 104]  ← trou en clé 1

Commande::whereIn('_id', $ids->toArray())->get();
// MongoDB\Driver\Exception\ServerException : $in needs an array
```

Le `toArray()` transmet fidèlement les clés `[0, 2, 3]`. Le driver voit un tableau qui n'est pas une liste, l'encode en document, et le `$in` reçoit `{ "0": 101, "2": 103, "3": 104 }` au lieu de `[101, 103, 104]`.

Ce qui rend le bug pénible, c'est son intermittence. Si le `filter()` ne retire **rien** — cas le plus fréquent en développement, avec des jeux de données propres — les clés restent `0, 1, 2, 3`, le tableau est une liste valide, et tout fonctionne. Le crash n'apparaît que le jour où le filtre élimine vraiment un élément. C'est-à-dire, typiquement, en production sur des données réelles.

## La correction : intercaler `->values()`

La méthode `values()` d'une `Collection` réindexe les entrées sur `0, 1, 2, …` en jetant les anciennes clés. C'est exactement le chaînon manquant entre `filter()` et `toArray()` :

```php
$ids = collect([101, 102, 103, 104])
    ->filter(fn (int $id) => $this->estAutorise($id))
    ->values(); // ← réindexe : [0 => 101, 1 => 103, 2 => 104]

Commande::whereIn('_id', $ids->toArray())->get(); // BSON array, $in content
```

Après `values()`, `array_is_list($ids->toArray())` renvoie `true`, le driver encode une vraie liste BSON, et `$in` reçoit ce qu'il attend. La règle est simple : **dès qu'une chaîne de Collection contient `filter()`, `unique()`, `reject()` ou `where()` et qu'elle se termine par `toArray()` vers du JSON ou du BSON, terminez par `values()`.**

L'équivalent en PHP pur, sans Collection, c'est `array_values()` — la même idée :

```php
$ids = array_values(array_filter($source, $estAutorise));
```

## Là où ça se cache aussi

Le `whereIn` est le cas le plus visible, mais le même piège se tend partout où un tableau filtré finit sérialisé. Quelques endroits à surveiller :

- **Une API JSON.** Un contrôleur qui renvoie `response()->json($collection->filter(...))` produira un objet `{"0": …, "2": …}` au lieu d'un tableau dès qu'un élément est filtré. Le front, qui attendait un tableau à itérer, reçoit un objet et casse — souvent loin de la cause.
- **Un `$push` ou un sous-document Mongo.** Écrire un tableau à trous dans un champ de document le transforme en sous-objet aux clés numériques. À la relecture, ce n'est plus la liste qu'on croyait avoir stockée.
- **Une *resource* Eloquent.** `JsonResource::collection()` part d'une collection ; si elle a été filtrée sans `values()`, la sortie JSON dérape de la même manière.

Le dénominateur commun n'est jamais Mongo ni le front : c'est toujours un tableau PHP qui n'est plus une liste, sérialisé par une couche qui distingue liste et objet. Une fois qu'on a ce réflexe en tête, le symptôme `$in needs an array` se lit immédiatement comme « j'ai filtré sans réindexer ».

## Un garde-fou en test

Comme le bug ne se déclenche que quand le filtre retire effectivement quelque chose, le meilleur garde-fou est un test qui filtre vraiment. Un cas où l'on sait qu'un élément doit disparaître suffit à attraper l'oubli de `values()` :

```php
public function test_les_ids_filtres_forment_une_liste(): void
{
    $ids = collect([1, 2, 3])
        ->reject(fn (int $id) => $id === 2)
        ->values();

    $this->assertTrue(array_is_list($ids->toArray()));
}
```

Sans le `values()` dans le code testé, `array_is_list()` renvoie `false` et le test échoue — bien avant que Mongo ne s'en mêle en production.

## Ce qu'il faut retenir

`$in needs an array` n'accuse pas Mongo : il signale un tableau PHP qui n'est plus une liste. La cause remonte presque toujours à une `Collection` filtrée dont on a oublié de réindexer les clés.

- `filter()`, `unique()`, `reject()`, `where()` **conservent les clés** — le résultat peut avoir des trous.
- Un tableau à clés non contiguës est sérialisé en **objet/document**, pas en liste, par JSON comme par BSON.
- La parade tient en une méthode : `->values()` juste avant `toArray()` (ou `array_values()` en PHP pur).
- Testez avec un filtre qui retire vraiment un élément, et vérifiez `array_is_list()` — sinon le bug se réserve pour la production.

Une seule méthode oubliée, un message d'erreur qui pointe au mauvais endroit, et des heures de débogage. Le jour où vous reverrez `$in needs an array`, commencez par chercher le `filter()` qui précède : il manque probablement un `values()` derrière.
