---
title: "Cache stampede avec Laravel et Redis : Cache::lock ou Cache::flexible ?"
description: "Quand une clé chaude expire, la ruée des requêtes concurrentes recalcule en même temps et fait tomber la base. Comparaison honnête du verrou atomique et du stale-while-revalidate."
pubDate: 2026-09-28
tags: ["laravel", "redis"]
---

Une plateforme métier interne affiche un tableau de bord dont les chiffres viennent d'une requête agrégée lourde. Pour tenir la charge, le résultat est mis en cache dans Redis pendant cinq minutes. Tout va bien jusqu'au moment où cette clé expire : d'un coup, toutes les requêtes en cours trouvent le cache vide, et se lancent *toutes en même temps* dans le recalcul coûteux. La base, qui encaissait une requête toutes les cinq minutes, en reçoit soudain plusieurs centaines dans la même seconde. Elle sature, les temps de réponse explosent, et parfois elle tombe. Ce phénomène porte un nom : le *cache stampede* (ou *thundering herd*).

## Pourquoi `Cache::remember` ne vous protège pas

Le réflexe naturel, c'est le cache-aside via `Cache::remember` :

```php
use Illuminate\Support\Facades\Cache;

$stats = Cache::remember('dashboard:stats', 300, function () {
    return $this->requeteAgregeeCouteuse();
});
```

La logique est simple : si la clé existe, on la sert ; sinon, on exécute la *closure* et on stocke son résultat. Le problème est ailleurs : `remember` ne coordonne rien entre les processus. Au moment précis où la clé expire, si trente requêtes arrivent en parallèle, les trente constatent le *miss* au même instant, les trente exécutent la *closure*, et les trente frappent la base. `remember` protège du trafic *entre* les expirations, pas de la ruée *à* l'expiration. C'est exactement là que ça casse, et c'est contre-intuitif parce qu'en local, seul, on ne le voit jamais.

Laravel propose deux réponses à ce problème, avec des philosophies opposées. Voyons-les honnêtement.

## Solution 1 : le verrou atomique avec `Cache::lock`

L'idée : au moment du *miss*, un seul processus obtient le droit de recalculer ; les autres attendent qu'il ait fini, puis lisent la valeur fraîche. C'est ce que permet le verrou atomique, qui repose sur un `SET NX` côté Redis — une opération atomique où un seul client peut poser la clé.

```php
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

public function stats(): array
{
    // Chemin chaud : la valeur est là, aucune contention.
    if (($stats = Cache::get('dashboard:stats')) !== null) {
        return $stats;
    }

    // Miss : un seul processus doit recalculer. Le verrou expire
    // de lui-même au bout de 10 s, garde-fou contre l'oubli de release.
    $lock = Cache::lock('dashboard:stats:lock', 10);

    if ($lock->get()) {
        try {
            $stats = $this->requeteAgregeeCouteuse();
            Cache::put('dashboard:stats', $stats, 300);
        } finally {
            $lock->release();
        }

        return $stats;
    }

    // Verrou déjà pris : quelqu'un recalcule. On attend son résultat
    // jusqu'à 5 s plutôt que de frapper la base à notre tour.
    try {
        $lock->block(5);

        return Cache::get('dashboard:stats') ?? $this->requeteAgregeeCouteuse();
    } catch (LockTimeoutException $e) {
        return $this->requeteAgregeeCouteuse(); // dernier recours
    } finally {
        $lock->release();
    }
}
```

Le mécanisme tient en trois temps : le premier arrivé pose le verrou et recalcule pendant que `block(5)` fait patienter les autres ; dès qu'il a écrit la valeur et relâché le verrou, les processus en attente sortent de `block` et trouvent le cache rempli. La base ne voit qu'*une seule* requête.

Deux pièges à connaître. D'abord, **relâchez toujours le verrou dans un `finally`** : si le recalcul lève une exception et que vous oubliez le `release`, les autres processus attendent pour rien. Le deuxième argument de `lock` (ici 10 secondes) est un filet de sécurité — le verrou s'auto-détruit passé ce délai, même en cas de crash, mais ne comptez pas dessus comme mécanisme normal. Ensuite, **calibrez ce délai au-dessus de votre pire temps de recalcul** : si l'agrégat peut prendre 12 secondes et que le verrou expire à 10, un second processus repartira recalculer alors que le premier n'a pas fini — et vous voilà avec deux exécutions concurrentes, le problème même que vous vouliez éviter.

## Solution 2 : le stale-while-revalidate avec `Cache::flexible`

Depuis Laravel 11, `Cache::flexible` propose une approche radicalement différente : ne faire attendre *personne*. Plutôt qu'un délai de vie unique, on définit deux seuils — une durée pendant laquelle la valeur est « fraîche », et une durée au-delà de laquelle elle est « périmée ».

```php
use Illuminate\Support\Facades\Cache;

$stats = Cache::flexible('dashboard:stats', [300, 3600], function () {
    return $this->requeteAgregeeCouteuse();
});
```

Le tableau `[300, 3600]` se lit ainsi, en secondes : la valeur est servie telle quelle pendant les 300 premières secondes. Entre 300 et 3600 secondes, elle est considérée comme périmée, mais **on la sert quand même** — et Laravel enregistre une *fonction différée* (`defer`) qui recalcule la valeur en tâche de fond, une fois la réponse HTTP renvoyée au client. Personne n'attend : l'utilisateur qui déclenche le rafraîchissement reçoit l'ancienne valeur immédiatement, et le nouveau calcul se fait après coup. Au-delà de 3600 secondes, la valeur est vraiment expirée et le recalcul redevient synchrone.

L'élégance de l'approche, c'est qu'il n'y a plus de « moment d'expiration » où tout le monde se rue en même temps : la transition frais → périmé → recalculé est étalée et invisible pour l'utilisateur. Et pour éviter que la ruée ne se déplace simplement vers la tâche de fond, `flexible` s'appuie sur un verrou interne quand le driver le supporte — c'est le cas de Redis — de sorte qu'un seul rafraîchissement parte réellement, même si dix requêtes tombent dans la fenêtre de péremption.

## Les contreparties, honnêtement

Aucune des deux solutions n'est gratuite.

**`Cache::flexible` sert des données périmées, par conception.** C'est tout l'intérêt, mais aussi sa limite : pendant la fenêtre de péremption, vos utilisateurs voient une valeur qui peut dater de plusieurs minutes. Pour un compteur de vues ou un tableau de bord de tendances, personne ne s'en aperçoit. Pour un solde de compte ou un stock affiché juste avant un paiement, c'est inacceptable.

**Le stale-while-revalidate s'appuie sur un verrou pour qu'un seul rafraîchissement parte.** Concrètement, il faut un driver dont le verrou est partagé entre les serveurs : Redis et Memcached, mais aussi `database` ou `dynamodb`. Le driver `file`, lui, pose un verrou local à la machine : n'espérez pas coordonner plusieurs serveurs avec un cache fichier en production.

**Le verrou atomique ajoute de la latence pour les perdants.** Les processus qui n'obtiennent pas le verrou attendent — c'est le prix de la fraîcheur garantie. Sous très forte concurrence, `block` peut faire s'empiler des requêtes en attente ; il faut dimensionner le délai avec soin.

**Et parfois, aucune des deux n'est nécessaire.** Avant de sortir l'artillerie, posez-vous la question : est-ce que le recalcul est réellement coûteux, et la clé réellement chaude ? Si l'agrégat prend 80 ms et que la clé est lue dix fois par minute, une ruée de dix requêtes ne fera pas tomber quoi que ce soit. Parfois, la vraie réponse est simplement d'allonger le TTL, ou de pré-calculer la valeur dans une tâche planifiée qui écrit le cache toutes les cinq minutes — la ruée n'existe plus puisque la clé n'expire jamais côté lecteurs.

## Comment choisir

L'heuristique tient en une phrase : **`Cache::flexible` pour la lecture massive tolérante à la fraîcheur, `Cache::lock` quand la valeur doit être à la fois fraîche et unique.**

- Un tableau de bord, une page d'accueil, un fil d'actualité, un compteur d'affichage — tout ce qui est lu énormément et où une donnée vieille de quelques minutes ne porte pas à conséquence : `Cache::flexible`. Personne n'attend, la base est protégée, et le code tient en trois lignes.
- Un calcul dont le résultat doit être exact au moment où on le lit, ou une ressource qu'on ne peut recalculer qu'une seule fois à la fois (génération d'un document, initialisation d'un état partagé) : `Cache::lock`. On accepte que quelques requêtes patientent, en échange d'une valeur toujours à jour et d'un unique recalcul.

## Ce qu'il faut retenir

Le *cache stampede* n'est pas un bug de votre code : c'est une propriété du cache-aside naïf qui ne se révèle que sous concurrence, en production, au pire moment. `Cache::remember` ne vous en protège pas.

- **`Cache::lock`** sérialise le recalcul : un seul processus travaille, les autres attendent puis lisent la valeur fraîche. Relâchez le verrou dans un `finally`, et dimensionnez son délai d'expiration au-dessus de votre pire temps de calcul.
- **`Cache::flexible`** (Laravel 11+) sert la valeur périmée et recalcule en arrière-plan : personne n'attend, au prix d'une fraîcheur relâchée. Réservé aux drivers à verrou partagé, Redis en tête.
- **Avant les deux**, vérifiez qu'une simple augmentation de TTL ou un pré-calcul planifié ne suffit pas : la meilleure protection contre la ruée reste une clé qui n'expire jamais pour les lecteurs.
