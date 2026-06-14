---
title: "Queues Laravel avec Redis et Horizon en prod : ce que la doc ne dit pas"
description: "Horizon en production, c'est plus que lancer une commande. Voici les pièges — retry_after, redémarrage au déploiement, éviction Redis — qui font perdre des jobs."
pubDate: 2026-06-13
tags: ["laravel", "redis"]
---

Mettre Horizon en route tient en une commande : `php artisan horizon`, et les jobs partent. La documentation officielle est excellente pour démarrer. Mais entre « ça tourne en local » et « ça tient en production sous charge », il y a une poignée de détails que la doc effleure à peine — et chacun se paie en jobs exécutés deux fois, en code mort qui continue de tourner, ou en jobs qui disparaissent sans laisser de trace. Voici ceux qui m'ont coûté le plus de temps.

## `retry_after` : le piège qui exécute vos jobs deux fois

C'est de loin l'erreur la plus sournoise, parce qu'elle ne lève aucune exception. Dans `config/queue.php`, la connexion Redis a un paramètre `retry_after` :

```php
// config/queue.php
'redis' => [
    'driver' => 'redis',
    'connection' => 'default',
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => 90,
    'block_for' => null,
],
```

`retry_after` dit à Laravel : « si un job est réservé depuis plus de 90 secondes sans être terminé, considère-le comme bloqué et remets-le dans la file ». En parallèle, chaque job peut définir son propre `$timeout`, qui est le délai au bout duquel le worker **tue** le process.

Le piège, c'est quand `retry_after` est **plus petit** que le `$timeout` d'un job. Imaginez un job d'export qui met 120 secondes :

```php
class GenererExport implements ShouldQueue
{
    public int $timeout = 110;

    public function handle(): void
    {
        // 120 s de traitement réel…
    }
}
```

Avec `retry_after = 90`, voici ce qui se passe : au bout de 90 secondes, Laravel juge le job perdu et le relance sur un autre worker — alors que le premier est toujours en train de tourner. Vous avez maintenant **deux exécutions simultanées** du même export. Aucune erreur, aucun log d'alerte : juste deux fichiers générés, deux emails envoyés, ou pire, deux débits.

La règle est simple et non négociable : **`retry_after` doit être strictement supérieur au `$timeout` le plus long de vos jobs.** Si votre job le plus lent peut prendre 110 secondes, mettez `retry_after` à 120 au minimum. Le `$timeout`, lui, reste en dessous pour que le worker reprenne la main proprement.

## Horizon ne recharge pas votre code

En développement, vous modifiez un job, vous rafraîchissez, ça marche. En production, vous déployez une correction… et le bug persiste. Pourquoi ? Parce qu'un worker Horizon est un process PHP **long-vivant** : il charge le framework une fois au démarrage, puis traite des milliers de jobs sans jamais relire vos fichiers. Votre nouveau code est sur le disque, mais le process en mémoire exécute toujours l'ancien.

La solution est de signaler aux workers de s'arrêter en douceur après leur job courant — Horizon est alors relancé par son superviseur avec le code à jour :

```bash
php artisan horizon:terminate
```

Cette commande doit faire partie de **chaque déploiement**, après avoir mis à jour le code. Un script de déploiement minimal ressemble à ça :

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan horizon:terminate   # ← à ne jamais oublier
```

`horizon:terminate` est gracieux : il laisse les jobs en cours se terminer avant de couper. Encore faut-il que quelque chose relance Horizon derrière — ce qui m'amène au point suivant.

## Horizon a besoin d'un superviseur, et `horizon` n'en est pas un

`php artisan horizon` lance un process maître qui pilote des workers, mais ce process maître peut lui-même mourir : un `horizon:terminate`, une exception fatale, un `OOM kill` du noyau. S'il meurt, plus rien ne consomme la file. Il faut donc un vrai gestionnaire de process qui relance Horizon automatiquement. Le choix classique est Supervisor :

```ini
; /etc/supervisor/conf.d/horizon.conf
[program:horizon]
process_name=%(program_name)s
command=php /var/www/app/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/horizon.log
stopwaitsecs=3600
```

Le `stopwaitsecs=3600` est important : il laisse à Supervisor jusqu'à une heure pour que les jobs en cours se terminent avant un arrêt forcé. Trop court, et vous coupez des jobs longs en plein milieu à chaque redémarrage.

## Redis n'est pas une base de données : configurez l'éviction

Voici le piège qui fait disparaître des jobs sans aucune trace. Par défaut, beaucoup d'installations Redis — surtout celles partagées avec le cache — tournent avec une politique d'éviction comme `allkeys-lru` : quand la mémoire est pleine, Redis **supprime les clés les moins récemment utilisées** pour faire de la place.

Le problème : vos jobs en attente *sont* des clés Redis. Sous pression mémoire, Redis peut donc évincer une file entière de jobs en attente. Ils ne partent pas dans `failed_jobs`, ils ne lèvent pas d'exception : ils n'existent tout simplement plus.

La parade tient en deux décisions :

1. Utilisez une **instance Redis dédiée aux queues**, séparée de celle du cache.
2. Sur cette instance, forcez la politique `noeviction` :

```conf
# redis.conf de l'instance dédiée aux queues
maxmemory-policy noeviction
```

Avec `noeviction`, Redis refuse les nouvelles écritures plutôt que de jeter des données existantes quand la mémoire est pleine. Vous obtenez une erreur visible au moment de pousser un job — bien préférable à une perte silencieuse. Mélanger cache et queues sur le même Redis est commode au début, mais c'est exactement le genre de raccourci qui se retourne contre vous en prod.

## La mémoire des workers ne fait que monter

Un worker traitant des milliers de jobs accumule fatalement de la mémoire — objets non libérés, instances singleton qui grossissent, fuites dans une dépendance. C'est inhérent à un process PHP qui ne meurt jamais. Plutôt que de chasser chaque fuite, on borne la durée de vie des workers. Dans `config/horizon.php` :

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'maxProcesses' => 10,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
        ],
    ],
],
```

Le paramètre `memory` (128 Mo ici) fait redémarrer un worker dès qu'il dépasse ce seuil, après son job courant. C'est un garde-fou, pas une excuse pour ignorer une vraie fuite, mais ça évite qu'un worker grignote tout la RAM de la machine en pleine nuit.

Quelques mots sur `balance` au passage, parce que la doc reste discrète sur le choix : `auto` ajuste dynamiquement le nombre de workers par file selon la charge, en montant jusqu'à `maxProcesses`. C'est le bon défaut dans la majorité des cas. `simple` répartit les process de façon fixe entre les files, et `false` désactive l'équilibrage. Si vous avez une file « urgente » et une file « lente » que vous voulez isoler, déclarez plutôt **deux superviseurs** avec chacun son `maxProcesses`, plutôt que de tout entasser dans un seul.

## Les jobs échoués vivent ailleurs que dans Redis

Dernier point qui surprend : même avec Redis comme driver de queue, les jobs définitivement échoués sont écrits dans une table de base de données, `failed_jobs`. C'est voulu — on ne veut surtout pas que la trace d'un échec soit, elle aussi, vulnérable à une éviction Redis. Assurez-vous donc que la migration existe :

```bash
php artisan queue:failed-table
php artisan migrate --force
```

Et, comme cette table grossit indéfiniment si personne ne la nettoie, planifiez une purge des échecs anciens dans `routes/console.php` :

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=168')->daily();
```

Côté données Horizon (métriques, jobs récents), c'est l'inverse : tout vit dans Redis et expire selon les clés `trim` de `config/horizon.php`. Les deux mécanismes coexistent sans se marcher dessus, mais il faut savoir lequel surveille quoi.

## Ce qu'il faut retenir

Horizon en production, ce n'est pas une commande, c'est une checklist :

- **`retry_after` > `$timeout` le plus long**, sinon vos jobs s'exécutent en double.
- **`horizon:terminate` à chaque déploiement**, sinon vos workers tournent avec le vieux code.
- **Un superviseur** (Supervisor ou équivalent) qui relance Horizon, avec un `stopwaitsecs` généreux.
- **Une instance Redis dédiée en `noeviction`**, sinon vos jobs peuvent disparaître silencieusement.
- **Un seuil `memory`** pour borner les fuites, et un nettoyage planifié de `failed_jobs`.

Aucun de ces points n'est compliqué pris isolément. Ce qui coûte cher, c'est de les découvrir un par un, en production, le jour où la charge monte. Faites-en une liste de vérification de déploiement une bonne fois, et Horizon devient ce qu'il promet d'être : un système de queues qu'on oublie parce qu'il fait son travail.
