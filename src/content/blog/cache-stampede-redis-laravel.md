---
title: "Cache stampede sous Laravel : pourquoi Cache::remember() ne protège pas votre base"
description: "Quand une clé de cache populaire expire sous charge, toutes les requêtes recalculent en même temps et martèlent la base. Comment neutraliser le stampede avec un verrou atomique, puis avec Cache::flexible()."
pubDate: 2026-10-12
tags: ["laravel", "redis"]
---

La page tournait sans accroc depuis des semaines, puis un matin la base de données a décroché pendant quelques secondes — pile au moment où le trafic montait. Rien dans le code n'avait changé. Le coupable : une clé de cache très sollicitée venait d'expirer, et toutes les requêtes présentes à cet instant se sont précipitées pour la recalculer en même temps. C'est le *cache stampede*, aussi appelé *dogpile* ou *thundering herd*, et `Cache::remember()` seul ne vous en protège pas.

## Pourquoi `Cache::remember()` laisse passer la rafale

Le code en cause est d'une banalité parfaite. Une requête coûteuse, mise en cache pour dix minutes :

```php
use Illuminate\Support\Facades\Cache;

$stats = Cache::remember('dashboard.stats', now()->addMinutes(10), function () {
    // Agrégation lourde : plusieurs jointures, un GROUP BY sur une grosse table…
    return $this->calculerStatistiquesDashboard();
});
```

`remember()` fait exactement ce qu'on lui demande : il regarde si la clé existe, la renvoie si oui, sinon il exécute la *closure*, stocke le résultat, puis le renvoie. Le problème est invisible tant qu'on raisonne sur **une** requête. Raisonnez sur **cent requêtes simultanées** au moment précis où la clé expire.

À cet instant, les cent requêtes font un *cache miss* en même temps. Aucune ne voit la valeur de l'autre, parce qu'aucune n'a encore fini de la recalculer. Les cent exécutent donc la closure — les cent lancent l'agrégation lourde sur la base. Une requête qui aurait dû tourner **une seule fois** tourne cent fois d'un coup, sur une table déjà sous tension. `Cache::remember()` n'offre aucune coordination entre les requêtes : son expiration est synchrone et chacun est seul face au *miss*. Plus la clé est populaire et coûteuse, plus la rafale fait mal.

Sur une plateforme métier interne, ce genre de clé — le tableau de bord que tout le monde ouvre à 9 h — est exactement le profil à risque : chère à calculer, et consultée par beaucoup de monde en même temps.

## Première parade : un verrou atomique

L'idée est simple : au moment du *miss*, une seule requête doit avoir le droit de recalculer. Les autres attendent qu'elle ait fini, puis récupèrent la valeur fraîche qu'elle vient de poser. Redis (ou Memcached) rend ça possible avec un **verrou atomique** — `Cache::lock()` — car ces drivers garantissent qu'un seul client obtient le verrou à un instant donné. Le driver de cache par fichier ou base ne convient pas ici.

```php
use Illuminate\Support\Facades\Cache;

function statistiquesDashboard(): array
{
    // 1. Chemin rapide : la clé est là, on la sert sans verrou.
    if ($cache = Cache::get('dashboard.stats')) {
        return $cache;
    }

    // 2. Miss : on tente de prendre le verrou, en acceptant d'attendre 10 s.
    $lock = Cache::lock('dashboard.stats.lock', 10);

    try {
        // block(10) attend jusqu'à 10 s que le verrou se libère.
        $lock->block(10);

        // 3. Double-check : pendant l'attente, un autre process a peut-être
        //    déjà rempli la clé. Inutile de recalculer.
        if ($cache = Cache::get('dashboard.stats')) {
            return $cache;
        }

        // 4. On est seul ici : on recalcule et on stocke.
        $stats = calculerStatistiquesDashboard();
        Cache::put('dashboard.stats', $stats, now()->addMinutes(10));

        return $stats;
    } finally {
        // 5. On relâche toujours le verrou, même en cas d'exception.
        optional($lock)->release();
    }
}
```

Trois détails font tout fonctionner. Le **`block(10)`** transforme les requêtes perdantes en patientes : au lieu de recalculer, elles s'endorment jusqu'à dix secondes en attendant que le verrou se libère. Le **double-check** après l'obtention du verrou est indispensable : quand une requête finit enfin par entrer dans la section critique, la valeur est déjà là, posée par la gagnante — elle la lit et repart sans toucher la base. Enfin, le **`release()` dans un `finally`** garantit que le verrou est rendu quoi qu'il arrive.

Reste le TTL du verrou — le `10` passé à `lock()`. C'est un garde-fou : si le process qui détient le verrou meurt (OOM kill, timeout PHP) sans le relâcher, Redis l'expire tout seul au bout de ce délai, et le système repart. Choisissez-le **plus long que le pire temps de recalcul attendu**, sinon le verrou peut sauter pendant qu'un calcul légitime est encore en cours, et la rafale revient. Trop long en revanche, et une requête bloquée attend inutilement si le détenteur a réellement planté. Un ordre de grandeur de quelques secondes au-dessus du calcul le plus lent est un bon point de départ.

Laravel propose une forme condensée, `Cache::lock(...)->get(closure)`, mais écrire le `block` et le double-check à la main rend le mécanisme explicite — et c'est précisément ce qu'on veut comprendre avant de l'abréger.

## Seconde parade : `Cache::flexible()` et le *stale-while-revalidate*

Le verrou fait attendre les requêtes perdantes. Si cette latence vous gêne, Laravel 11 offre une autre stratégie avec `Cache::flexible()`, qui applique le principe du *stale-while-revalidate* : servir une valeur **périmée** pendant qu'un seul process la régénère en arrière-plan.

```php
use Illuminate\Support\Facades\Cache;

$stats = Cache::flexible('dashboard.stats', [300, 600], function () {
    return calculerStatistiquesDashboard();
});
```

Les deux nombres définissent un intervalle en secondes. De `0` à `300` s, la valeur est considérée comme **fraîche** : elle est servie directement. Entre `300` et `600` s, elle est **périmée mais encore acceptable** : la requête reçoit immédiatement l'ancienne valeur, et Laravel déclenche en différé — après l'envoi de la réponse — une régénération unique de la clé. Au-delà de `600` s, la valeur est **expirée** pour de bon et on retombe sur un recalcul synchrone classique.

L'avantage par rapport au verrou : dans la fenêtre périmée, **personne n'attend**. Les requêtes sont servies sans latence avec une donnée légèrement datée, et la base ne voit qu'un seul recalcul en tâche de fond. Pour un tableau de bord dont les chiffres peuvent avoir quelques minutes de retard sans conséquence, c'est le meilleur rapport confort/simplicité.

La contrepartie est dans le mot « périmé » : vous acceptez de servir une valeur qui n'est plus tout à fait à jour. Pour des statistiques d'affichage, aucun souci. Pour une donnée où l'exactitude à la seconde compte — un solde, un stock décrémenté — ce compromis n'est pas acceptable, et le verrou atomique reste le bon outil.

## Honnêteté technique : quand ne rien faire

Ces deux parades ajoutent de la complexité, et toute complexité se paie. Le verrou introduit une latence d'attente sous `block()`, un TTL de plus à régler, et le risque classique d'un verrou mal relâché si on oublie le `finally`. `flexible()` est plus léger à écrire mais vous engage à servir du périmé. Dans les deux cas, vous ajoutez un mécanisme qu'il faudra comprendre et maintenir.

Or **la grande majorité de vos clés de cache ne justifient rien de tout ça**. Une clé consultée quelques fois par minute ne déclenchera jamais de rafale : même si dix requêtes recalculent par mégarde, votre base l'absorbe sans broncher. Protéger ces clés-là, c'est de la complexité gratuite. Le stampede n'est un vrai problème que lorsque **deux conditions sont réunies** : la clé est chaude (beaucoup de requêtes simultanées) **et** son recalcul est coûteux. C'est cette intersection — souvent une poignée de clés dans toute l'application — qu'il faut protéger, et elle seule.

Pour aller plus loin, l'écosystème propose des approches probabilistes — recalculer la clé *avant* son expiration, de façon aléatoire, pour lisser la charge (algorithme *XFetch*). Des packages comme [`laravel-stampede-guard`](https://packagist.org/packages/mohamedtarek/laravel-stampede-guard) en font un repère utile. Mais avant d'ajouter une dépendance, les deux outils livrés avec le framework couvrent déjà l'essentiel.

## Ce qu'il faut retenir

Le cache stampede n'est pas un bug de Laravel : c'est une conséquence logique d'une expiration synchrone sans coordination. `Cache::remember()` fait son travail ; il ne fait simplement pas *celui-là*.

- Le problème ne concerne que les clés **à la fois chaudes et coûteuses** — repérez-les, ignorez le reste.
- **Verrou atomique** (`Cache::lock` + `block` + double-check) quand l'exactitude prime : une seule requête recalcule, les autres attendent la valeur fraîche. Exige Redis ou Memcached, et un TTL de verrou plus long que le calcul.
- **`Cache::flexible()`** quand la latence prime et qu'un peu de périmé est tolérable : on sert l'ancienne valeur, un seul process régénère en différé.
- Pour tout le reste, `Cache::remember()` suffit — n'ajoutez pas de verrou là où il n'y a pas de rafale.

La prochaine fois que votre base décroche pile quand le trafic monte, regardez quelle clé de cache venait d'expirer. Il y a de bonnes chances qu'une poignée de requêtes se soient ruées dessus en même temps — et que la parade tienne en une dizaine de lignes.
