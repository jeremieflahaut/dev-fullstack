---
title: "Les enums PHP dans Laravel : caster proprement vos statuts"
description: "Fini les constantes de classe et les 'pending' en dur partout. Comment caster vos statuts avec les enums PHP 8.1, les valider, et éviter les pièges côté base."
pubDate: 2026-06-13
tags: ["laravel", "php"]
---

Un champ `status` dans une table, et très vite la chaîne `'pending'` se retrouve copiée-collée dans un contrôleur, une condition Blade, un scope Eloquent et trois tests. Le jour où ce statut devient `'awaiting_payment'`, vous partez à la pêche aux occurrences — et vous en oubliez toujours une. Les enums de PHP 8.1 règlent ce problème à la racine : un type unique, vérifié par le compilateur, que Laravel sait caster directement depuis Eloquent.

## Le réflexe d'avant : des constantes et des chaînes en dur

Pendant des années, on a modélisé un statut comme ça :

```php
class Order
{
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_CANCELLED = 'cancelled';
}

if ($order->status === Order::STATUS_PAID) {
    // …
}
```

C'est déjà mieux que `'paid'` en dur, mais ça reste fragile. Rien n'empêche d'affecter `$order->status = 'payed'` (avec une faute), aucune autocomplétion ne liste les valeurs possibles, et la logique métier attachée à chaque statut — un libellé, une couleur, une transition autorisée — finit éparpillée dans des `match` ou des tableaux associatifs sans cohésion.

## Un enum *backed*, le bon outil

PHP 8.1 introduit les enums. Pour un statut stocké en base, on veut un *backed enum* : chaque cas porte une valeur scalaire, ici une chaîne, qui sera la valeur écrite en colonne.

```php
namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
```

L'intérêt immédiat : `OrderStatus::Paid` est un objet typé, pas une chaîne. Une méthode qui attend `OrderStatus` refusera n'importe quoi d'autre, et l'IDE complète les trois cas. On récupère la valeur stockée avec `->value`, et on remonte d'une valeur vers le cas avec `from()` (qui lève une `ValueError` si la valeur est inconnue) ou `tryFrom()` (qui renvoie `null`).

```php
OrderStatus::Paid->value;        // 'paid'
OrderStatus::from('paid');       // OrderStatus::Paid
OrderStatus::tryFrom('payed');   // null, pas d'exception
OrderStatus::cases();            // [Pending, Paid, Cancelled]
```

On peut aussi rattacher le comportement métier directement à l'enum, là où il a du sens :

```php
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Paid => 'Payée',
            self::Cancelled => 'Annulée',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Paid || $this === self::Cancelled;
    }
}
```

Le `match` sur `$this` n'a pas de branche `default` : si vous ajoutez un cas sans traiter son libellé, PHP lève une `\UnhandledMatchError`. C'est exactement ce qu'on veut — une erreur bruyante plutôt qu'un statut silencieusement sans libellé.

## Caster le statut dans Eloquent

Depuis Laravel 9, le cast d'enum est natif. On déclare le mapping dans le modèle :

```php
namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $casts = [
        'status' => OrderStatus::class,
    ];
}
```

À partir de là, Eloquent fait la traduction dans les deux sens. En lecture, `$order->status` renvoie une instance de `OrderStatus`. En écriture, on affecte l'enum et Laravel stocke `->value` :

```php
$order = Order::find(1);

$order->status;              // OrderStatus::Paid (un objet)
$order->status->label();     // 'Payée'

$order->status = OrderStatus::Cancelled;
$order->save();              // écrit 'cancelled' en base
```

Les requêtes acceptent aussi bien l'enum que sa valeur — le *query builder* sérialise l'enum automatiquement dans le `where` :

```php
Order::where('status', OrderStatus::Pending)->get();
```

Sur les versions récentes de Laravel, la déclaration des casts passe volontiers par une méthode plutôt que par la propriété, surtout dès qu'on référence des classes d'enums :

```php
protected function casts(): array
{
    return [
        'status' => OrderStatus::class,
    ];
}
```

La méthode `casts()` (Laravel 11+) remplace avantageusement la propriété `$casts` et se prête mieux à la composition.

## Valider une entrée utilisateur

Laisser un enum entrer dans le modèle suppose que la valeur soit valide. Pour les données venues d'une requête HTTP, la règle `Enum` fait le travail :

```php
use Illuminate\Validation\Rules\Enum;
use App\Enums\OrderStatus;

$request->validate([
    'status' => ['required', new Enum(OrderStatus::class)],
]);
```

La validation échoue si `status` ne correspond à aucune valeur de l'enum. Ensuite, dans le contrôleur, il faut convertir la chaîne validée en instance — la validation ne le fait pas pour vous :

```php
$order->status = OrderStatus::from($request->validated('status'));
$order->save();
```

Comme la valeur a déjà passé la règle `Enum`, le `from()` ne lèvera pas d'exception ici. Si vous préférez restreindre l'entrée à un sous-ensemble de cas — par exemple interdire qu'une API publique passe une commande directement à `Paid` — utilisez `Rule::enum(...)->only([...])` sur Laravel 11+ :

```php
use Illuminate\Validation\Rule;

'status' => [Rule::enum(OrderStatus::class)->only([OrderStatus::Pending, OrderStatus::Cancelled])],
```

## Les pièges côté base de données

C'est là que les ennuis arrivent, parce que la base ne connaît rien des enums PHP. Quelques points à surveiller.

**La colonne reste une simple chaîne.** Le plus robuste est une colonne `string` (ou `varchar`) classique. La migration ne change pas par rapport à du texte libre :

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->string('status')->default('pending');
    $table->timestamps();
});
```

Notez le `default('pending')` : c'est une **chaîne**, pas l'enum. Une migration ne sait pas instancier votre enum, et de toute façon le défaut est appliqué par le moteur SQL, pas par PHP. Si vous tenez à éviter le littéral, écrivez `OrderStatus::Pending->value` — le résultat est la même chaîne `'pending'`, mais l'intention est explicite et la faute de frappe impossible.

**Méfiez-vous du type `enum` SQL.** MySQL propose un type colonne `ENUM('pending', 'paid', …)`, repris par `$table->enum('status', [...])` dans les migrations Laravel. C'est tentant, mais ça duplique la liste des valeurs entre PHP et le schéma : ajouter un cas oblige à une migration `ALTER TABLE`, souvent verrouillante sur une grosse table, et les deux définitions finissent par diverger. Une colonne `string` laisse la source de vérité unique côté PHP. Si vous voulez une garantie au niveau base, une contrainte `CHECK` est plus souple à faire évoluer qu'un type `ENUM`.

**Une valeur orpheline en base fait planter la lecture.** C'est le piège le plus vicieux. Si une ligne contient `'archived'` — issue d'un ancien déploiement, d'un script SQL manuel, d'un import — et que ce cas n'existe pas dans l'enum, Eloquent appelle `from()` en interne et lève une `ValueError` au moment de l'hydratation du modèle. Pas à l'usage du statut : dès le `find()`. Et comme c'est une `ValueError` et non une exception Laravel, elle ne passe pas par vos `try/catch` habituels sur les exceptions de validation.

Conséquences pratiques :

- Quand vous **retirez** un cas d'un enum, vérifiez d'abord qu'aucune ligne ne le porte encore, et prévoyez une migration de données pour réécrire les valeurs orphelines.
- Quand vous **renommez** une valeur, la migration de données est obligatoire — l'ancienne valeur en base ne se mappe plus sur rien.

```php
// Migration de données avant de retirer le cas 'archived'
DB::table('orders')
    ->where('status', 'archived')
    ->update(['status' => OrderStatus::Cancelled->value]);
```

**Les seeders et factories doivent passer par l'enum.** Une factory qui écrit `'status' => 'pending'` fonctionne, mais vous reperdez la sécurité de typage. Préférez `fake()->randomElement(OrderStatus::cases())` : vous testez ainsi tous les cas réels, et un cas ajouté entre automatiquement dans le jeu de test.

```php
public function definition(): array
{
    return [
        'status' => fake()->randomElement(OrderStatus::cases()),
    ];
}
```

## Ce qu'il faut retenir

Un statut, c'est un type, pas une chaîne qui se balade. Les enums *backed* de PHP 8.1, castés dans Eloquent, vous donnent l'autocomplétion, la vérification au build, et un endroit unique où ranger le libellé et les règles de transition.

Trois réflexes pour que ça tienne en production :

1. **Colonne `string`, source de vérité côté PHP** — évitez le type `ENUM` SQL qui fige la liste dans le schéma.
2. **Validez avant de caster** avec la règle `Enum`, puis convertissez explicitement via `from()`.
3. **Aucune valeur orpheline en base** — toute suppression ou renommage de cas s'accompagne d'une migration de données, sous peine de `ValueError` à la lecture.

Le coût d'entrée est faible : un fichier d'enum, une ligne de cast. Le gain, lui, court sur toute la durée de vie du projet.
