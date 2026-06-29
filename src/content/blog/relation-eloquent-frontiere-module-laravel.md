---
title: "La relation Eloquent qui perce une frontière de module"
description: "Dans un monolithe Laravel modulaire, un belongsTo vers un autre module couple les deux en silence. Garder l'ID brut et résoudre via un contrat, avec ses contreparties."
pubDate: 2026-06-29
tags: ["laravel", "php"]
---

Un monolithe Laravel découpé en modules promet une chose : pouvoir faire évoluer le module `Order` sans toucher au module `User`, et inversement. Sur une plateforme métier interne, j'ai vu cette promesse tomber à cause d'une seule ligne — une relation Eloquent parfaitement banale. Le genre de ligne qu'on écrit sans réfléchir, parce que c'est exactement ce que montre la doc, et qui soude deux modules censés rester indépendants.

## Le symptôme : un `belongsTo` qui traverse la frontière

Voici la commande, dans son module, qui veut connaître son client :

```php
namespace Modules\Order\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\User\Models\User;

class Order extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Ça marche, c'est lisible, et `$order->user->name` fonctionne du premier coup. Le problème n'est pas dans le comportement, il est dans la première ligne d'`use` : le module `Order` importe `Modules\User\Models\User`, c'est-à-dire le **modèle interne** d'un autre module. La frontière qu'on croyait avoir tracée vient d'être percée. `Order` ne dépend plus d'une idée abstraite d'« utilisateur », il dépend de la classe Eloquent concrète de `User`, de ses colonnes, de ses casts, de ses relations.

Et ce couplage est silencieux. Aucun test ne tombe, aucune alerte ne se déclenche. Il se révèle plus tard, le jour où on veut faire bouger les choses.

## Pourquoi c'est un vrai problème

Tant que les deux modules vivent côte à côte sans jamais changer, ce couplage est invisible. Les ennuis commencent dès qu'on veut faire évoluer l'un des deux.

Renommer une colonne de `User`, déplacer son modèle, ou simplement le remanier en profondeur : chaque modification peut casser le module `Order`, qui pointe directement sur cette classe. Pire, le sens de la dépendance est contre-intuitif. C'est `Order` qui se brise quand `User` change, alors que rien dans le code d'`Order` ne le laisse deviner.

L'effet boule de neige suit vite. Les tests du module `Order` ne peuvent plus tourner sans le schéma et les factories de `User`. Extraire un jour le module `User` vers un service séparé devient un chantier, parce qu'il faut d'abord débusquer toutes les relations Eloquent qui le traversent. Et comme la première a été facile à écrire, il y en a rarement une seule : les `belongsTo` et `hasMany` inter-modules se multiplient, jusqu'à ce que la frontière ne soit plus qu'un trait sur un schéma d'architecture.

## L'alternative : ID brut plus contrat

L'idée tient en deux temps. D'abord, on garde l'identifiant comme une simple colonne — `user_id` est un `int`, pas une relation Eloquent traversante. Ensuite, quand on a besoin de la donnée du client, on la demande au module propriétaire à travers un **contrat** qu'il expose, jamais en attrapant son modèle.

Le module `User` publie une interface et un petit objet de lecture. Ce sont les seules choses que les autres modules ont le droit de connaître de lui :

```php
namespace Modules\User\Contracts;

final readonly class UserData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {}
}
```

```php
namespace Modules\User\Contracts;

interface UserDirectory
{
    public function find(int $id): ?UserData;

    /**
     * @param  int[]  $ids
     * @return array<int, UserData>
     */
    public function findMany(array $ids): array;
}
```

L'implémentation, elle, reste cachée à l'intérieur du module `User` — libre à lui d'utiliser Eloquent, un cache, ou un appel réseau le jour où il devient un service distant :

```php
namespace Modules\User\Services;

use Modules\User\Contracts\UserData;
use Modules\User\Contracts\UserDirectory;
use Modules\User\Models\User;

final class EloquentUserDirectory implements UserDirectory
{
    public function find(int $id): ?UserData
    {
        $user = User::find($id);

        return $user ? $this->toData($user) : null;
    }

    public function findMany(array $ids): array
    {
        return User::whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => $this->toData($user)])
            ->all();
    }

    private function toData(User $user): UserData
    {
        return new UserData($user->id, $user->name, $user->email);
    }
}
```

Le module `User` lie l'interface à son implémentation dans son propre service provider :

```php
$this->app->bind(UserDirectory::class, EloquentUserDirectory::class);
```

Côté `Order`, on n'importe plus jamais `User`. On injecte le contrat, et on résout le client à partir de l'ID brut :

```php
namespace Modules\Order\Services;

use Modules\Order\Models\Order;
use Modules\User\Contracts\UserDirectory;

final class OrderPresenter
{
    public function __construct(private UserDirectory $users) {}

    public function present(Order $order): array
    {
        $customer = $this->users->find($order->user_id);

        return [
            'reference' => $order->reference,
            'client'    => $customer?->name ?? 'Compte supprimé',
        ];
    }
}
```

La seule chose que `Order` connaît désormais de `User`, c'est `Modules\User\Contracts` — une interface et un DTO, deux types stables qu'on s'engage à ne pas casser. Le modèle interne peut être remanié librement : tant que le contrat tient, `Order` ne s'en aperçoit pas.

## La contrepartie assumée : adieu l'eager loading

Soyons honnête, ce découplage a un prix, et il n'est pas symbolique. En coupant la relation Eloquent, on perd le `with('user')` et tout le chargement anticipé qui va avec. Or c'est précisément ce qui protège du N+1.

Le piège est facile à tomber dedans. Sur une liste de commandes, appeler `find()` dans une boucle, c'est une requête par ligne :

```php
// À ne pas faire : une requête par commande
foreach ($orders as $order) {
    $client = $this->users->find($order->user_id); // N+1 garanti
}
```

La parade tient dans le contrat lui-même : on a prévu `findMany()` justement pour ça. On charge tous les clients en une requête, indexés par ID, puis on pioche dedans :

```php
use Illuminate\Support\Collection;

public function presentList(Collection $orders): array
{
    $clients = $this->users->findMany(
        $orders->pluck('user_id')->unique()->values()->all()
    );

    return $orders->map(fn (Order $order) => [
        'reference' => $order->reference,
        'client'    => $clients[$order->user_id]->name ?? 'Compte supprimé',
    ])->all();
}
```

On retrouve deux requêtes au lieu d'une jointure, mais on garde la maîtrise des accès, et le N+1 est écarté. Ce n'est pas gratuit : c'est du code que l'eager loading nous offrait pour rien. C'est le coût réel du découplage, et il faut l'accepter en connaissance de cause plutôt que le découvrir en production.

## Quand le couplage direct reste acceptable

Tout ça serait une erreur sur un projet qui n'a pas de vraie frontière. Le `belongsTo` traversant n'est pas un mal en soi — il devient un problème quand il franchit une frontière qu'on a l'intention de défendre.

Quelques cas où je garde la relation Eloquent directe sans état d'âme :

- **Le petit projet sans modules réels.** Si `Order` et `User` vivent dans le même espace de noms applicatif et qu'aucune extraction n'est prévue, abstraire une frontière inexistante n'est que de la cérémonie.
- **Des modules destinés à rester soudés.** Certains découpages sont organisationnels, pas architecturaux. Si deux modules évolueront toujours ensemble et ne seront jamais séparés, le contrat n'achète rien.
- **La règle de trois.** Tant qu'un seul module lit les données d'un autre, la relation directe documente le besoin clairement. C'est quand un troisième consommateur apparaît que le contrat commence à payer.

Le contrat n'est pas gratuit : une interface, un DTO, une implémentation, une liaison dans le container. On ne paie ce coût que là où la frontière compte vraiment.

## Ce qu'il faut retenir

Une relation `belongsTo` vers le modèle d'un autre module est une dépendance déguisée en commodité. Elle ne coûte rien à écrire et beaucoup à défaire.

Le critère pour trancher tient en une question, à se poser au moment d'écrire la relation : **si je devais extraire ce module demain, cette relation me bloquerait-elle ?**

- Si la réponse est non — pas de frontière à défendre, modules soudés —, gardez le `belongsTo`, il est lisible et suffisant.
- Si la réponse est oui, gardez l'ID brut en colonne et résolvez la donnée via un contrat exposé par le module propriétaire. Vous perdez l'eager loading, vous le compensez par un chargement par lot d'IDs, et vous gagnez une frontière qui tient vraiment.

Une frontière de module ne vaut que si on refuse de la percer, même quand Eloquent rend ça facile.
