---
title: "withoutOverlapping() : la tâche planifiée Laravel qui s'arrête pendant 24 h"
description: "Un crash en pleine exécution laisse un verrou withoutOverlapping() orphelin dont le TTL est de 24 h. La tâche ne repart plus, sans la moindre erreur. Diagnostic et parade."
pubDate: 2026-06-14
tags: ["laravel", "docker"]
---

Une commande planifiée toutes les cinq minutes qui, du jour au lendemain, ne tourne plus. Pas d'exception, pas de ligne dans `failed_jobs`, pas d'alerte : le scheduler s'exécute toujours, les autres tâches partent normalement, mais celle-ci est muette. Et puis, environ vingt-quatre heures plus tard, elle repart toute seule, comme si de rien n'était. Si vous protégez vos tâches avec `withoutOverlapping()`, vous avez déjà rencontré ce fantôme — ou vous le rencontrerez.

## Ce que `withoutOverlapping()` fait vraiment

L'intention est saine. Une tâche qui met parfois plus longtemps que son intervalle ne doit pas se chevaucher avec elle-même. Deux instances d'un même import qui tournent en parallèle, c'est la garantie de doublons ou de corruption. D'où ce garde-fou, dans `routes/console.php` :

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('rapports:generer')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Sous le capot, Laravel ne fait rien de magique : avant de lancer la commande, il pose un **verrou** (un mutex) dans le cache. Si le verrou est déjà présent, l'exécution est purement et simplement sautée. Quand la commande se termine, le verrou est levé. C'est un sémaphore basique, et il fonctionne très bien — tant que la commande se termine.

Le point clé, c'est *où* vit ce verrou : dans votre store de cache. Avec le driver `file`, c'est un fichier dans `storage/framework/cache`. Avec Redis ou Memcached, c'est une clé. Et ce verrou porte une **durée d'expiration**.

## Le scénario qui casse tout

Le verrou n'est levé que si la commande atteint sa fin normale, ou lève une exception que Laravel intercepte. Le bloc ressemble, en simplifiant, à ceci :

```php
// Illuminate\Console\Scheduling\Event (simplifié)
if (! $this->mutex->create($this)) {
    return; // verrou déjà posé → on saute
}

try {
    // exécution de la commande
} finally {
    $this->mutex->forget($this); // levée du verrou
}
```

Le `finally` couvre le cas d'une exception PHP. Mais il ne couvre **pas** le cas où le process est tué sans préavis. Et en production, surtout dans un environnement conteneurisé, le process se fait tuer plus souvent qu'on ne le croit :

- un `SIGKILL` lors d'un redéploiement, quand l'orchestrateur arrête le conteneur en pleine exécution de la tâche ;
- un `OOM kill` du noyau parce que la commande a mangé trop de mémoire ;
- un timeout d'orchestrateur qui ne laisse pas le temps à un arrêt gracieux ;
- une coupure brutale de la machine.

Dans tous ces cas, le `finally` ne s'exécute jamais. Le process disparaît, mais le verrou, lui, reste posé dans le cache. La tâche est désormais **persuadée qu'une instance tourne encore**, alors qu'il n'y a plus rien. À chaque tick suivant, le scheduler trouve le verrou, et saute. Silencieusement.

## Pourquoi vingt-quatre heures, précisément

Reste à comprendre la durée. Un verrou orphelin pourrait, après tout, rester là pour l'éternité. S'il finit par se libérer tout seul au bout d'un jour, c'est à cause d'une valeur par défaut peu connue.

`withoutOverlapping()` accepte un argument : la durée d'expiration du verrou, en **minutes**. Et sa valeur par défaut est 1440 — soit exactement vingt-quatre heures :

```php
// Illuminate\Console\Scheduling\Event
public function withoutOverlapping($expiresAt = 1440)
{
    // ...
}
```

Cette expiration est un filet de sécurité justement prévu pour les verrous orphelins : sans elle, une tâche bloquée le resterait définitivement. Mais 1440 minutes, c'est énorme. Pour une commande qui tourne toutes les cinq minutes et dure quelques secondes, un verrou qui survit une journée entière n'a aucun sens. C'est ce décalage entre la durée réelle de la tâche et le TTL par défaut qui transforme un crash de quelques secondes en panne d'une journée.

## Débloquer immédiatement

Quand le mal est fait et que la tâche est figée, on veut la relancer sans attendre l'expiration. La commande est faite pour ça :

```bash
php artisan schedule:clear-cache
```

Elle purge les verrous `withoutOverlapping()` posés dans le cache. Au tick suivant, le scheduler ne trouve plus rien et relance la commande normalement. Attention toutefois : elle vide ces mutex pour **toutes** les tâches planifiées, pas seulement celle qui vous intéresse. En pratique ce n'est pas un problème — un verrou légitimement actif sera reposé immédiatement par la tâche en cours — mais c'est bon à savoir avant de la lancer en aveugle.

Si vous êtes sur un store partagé et que vous voulez cibler manuellement, le nom de la clé est dérivé d'un hash du nom de la commande, préfixé par `framework/schedule-`. Mais dans l'immense majorité des cas, `schedule:clear-cache` est la bonne réponse.

## Prévenir, en trois décisions

Déverrouiller à la main, c'est traiter le symptôme. Le vrai correctif tient en trois choix de configuration.

### 1. Adapter la durée d'expiration à la tâche

C'est le geste le plus important, et le plus simple. Passez à `withoutOverlapping()` une durée qui correspond à la réalité de votre commande, pas au défaut de 1440 minutes. Si votre tâche ne dépasse jamais quelques minutes, un TTL de 10 minutes suffit amplement :

```php
// routes/console.php
Schedule::command('rapports:generer')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
```

La règle : le TTL doit être **un peu supérieur à la durée maximale réelle** de la tâche, et c'est tout. Avec 10 minutes, un verrou orphelin se résorbe en 10 minutes au pire, au lieu d'une journée. La protection contre le chevauchement reste intacte — elle ne vit que le temps d'une exécution légitime — mais le coût d'un crash devient négligeable.

Trop court serait dangereux dans l'autre sens : si le TTL est plus petit que la durée d'une exécution normale, le verrou expire pendant que la tâche tourne encore, et une seconde instance peut démarrer. On retombe sur le chevauchement qu'on cherchait à éviter. D'où le « un peu supérieur à la durée maximale » : ni le défaut absurde de 24 h, ni un seuil trop ric-rac.

### 2. Choisir un store de cache cohérent

Le verrou vit dans le store de cache **par défaut** de l'application (`config/cache.php`, clé `default`). Ce détail a deux conséquences concrètes.

D'abord, si vous tournez sur plusieurs conteneurs avec le store `file`, chaque conteneur a son propre `storage/framework/cache` local. Le verrou posé sur l'un est invisible pour les autres : `withoutOverlapping()` ne protège alors plus rien entre instances. Pour une protection qui vaille à l'échelle de plusieurs conteneurs, il faut un **store partagé** : Redis ou Memcached.

Ensuite, si ce store partagé est un Redis configuré en éviction (`allkeys-lru` par exemple), la clé de verrou peut être évincée sous pression mémoire — ce qui, ici, joue plutôt en votre faveur puisque ça libère un orphelin, mais peut aussi lever un verrou légitime en pleine exécution. Mieux vaut un comportement prévisible : un store dédié, ou au moins une politique d'éviction maîtrisée.

### 3. Surveiller le fait que la tâche tourne

Le piège de ce bug, c'est son silence. Aucune erreur n'est levée : du point de vue de Laravel, sauter une exécution verrouillée est un comportement *normal*. La seule façon de le détecter, c'est de surveiller non pas les erreurs, mais **l'absence de succès**.

Laravel offre exactement le crochet qu'il faut avec `onSuccess()` — un *heartbeat* vers un service de supervision qui vous alerte quand le signal cesse :

```php
// routes/console.php
Schedule::command('rapports:generer')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onSuccess(function () {
        // ping vers le service de heartbeat
        Http::get(config('services.heartbeat.rapports_url'));
    });
```

Le principe d'un heartbeat est inversé par rapport à une alerte classique : le service attend un signal à intervalle régulier, et c'est *son silence* qui déclenche l'alerte. Si la tâche est verrouillée et ne s'exécute plus, le ping cesse, et vous êtes prévenu en quelques minutes — au lieu de découvrir le problème le lendemain, quand quelqu'un s'étonne qu'un rapport manque.

## Ce qu'il faut retenir

`withoutOverlapping()` est un bon outil avec un défaut piégeux : son TTL par défaut de 24 h transforme n'importe quel crash en panne silencieuse d'une journée. Trois réflexes suffisent à s'en prémunir :

- **Toujours passer une durée explicite** à `withoutOverlapping()`, calée un peu au-dessus de la durée réelle de la tâche — jamais le défaut de 1440 minutes.
- **Mettre le verrou dans un store partagé et prévisible** (Redis dédié) dès que plusieurs conteneurs sont en jeu, sinon la protection ne protège rien.
- **Surveiller le succès, pas l'erreur** : un heartbeat sur `onSuccess()` est le seul moyen d'attraper une panne qui, par nature, ne lève aucune exception.

Et le jour où ça arrive quand même, `php artisan schedule:clear-cache` débloque tout de suite. Mais l'objectif, c'est de ne plus jamais avoir à le taper.
