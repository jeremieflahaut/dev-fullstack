---
title: "Cache stampede avec Redis et Laravel : pourquoi Cache::remember() ne suffit pas"
description: "Quand une clé chaude expire, tous les lecteurs recalculent en même temps et écrasent la base. Voici le single-flight et le TTL jitter — avec les contreparties honnêtes."
pubDate: 2026-09-30
tags: ["laravel", "redis"]
---

Une requête coûteuse mise en cache pour une heure, `Cache::remember()`, et on n'y pense plus. Jusqu'au jour où le monitoring montre un pic de charge MySQL parfaitement périodique, toutes les heures pile, à la seconde près. Ce n'est pas la base qui faiblit : c'est la clé de cache la plus consultée qui expire, et des dizaines de requêtes simultanées qui se ruent toutes en même temps pour la reconstruire. Ce phénomène a un nom — *cache stampede*, ou *dogpile* — et `Cache::remember()` seul n'y change rien.

## Le problème : une clé chaude qui expire sous charge

Prenons une valeur à la fois coûteuse à calculer et très demandée : le tableau de bord d'accueil, agrégé sur plusieurs tables, servi à chaque visiteur connecté.

```php
$stats = Cache::remember('dashboard:stats', now()->addHour(), function () {
    return $this->agregerStatistiquesLourdes(); // 800 ms de requêtes SQL
});
```

Tant que la clé existe, tout va bien : chaque lecteur la trouve en cache et repart en quelques millisecondes. Le problème est l'instant précis de l'expiration. À la seconde où Redis oublie la clé, si le site reçoit 50 requêtes par seconde, ce sont 50 requêtes qui font un *miss* en même temps. Aucune ne trouve la valeur ; toutes lancent `agregerStatistiquesLourdes()` en parallèle. La base encaisse d'un coup 50 fois la requête à 800 ms, alors qu'une seule aurait suffi. C'est le *thundering herd* : le troupeau qui charge la même porte au même moment.

Plus la clé est consultée, plus le stampede est violent — et c'est un cercle vicieux, car ce sont justement les clés les plus chaudes qu'on a le plus intérêt à mettre en cache.

## Pourquoi `Cache::remember()` ne coordonne rien

La subtilité, c'est que `Cache::remember()` n'a rien de défectueux : il fait exactement ce qu'il promet. Sa logique tient en trois lignes : « lis la clé ; si elle existe, rends-la ; sinon, exécute le *callback*, écris le résultat, rends-le ». Ce qu'il ne fait pas — et ne prétend pas faire — c'est se coordonner avec les **autres** process.

Or en production, votre application tourne dans plusieurs process PHP-FPM, souvent sur plusieurs serveurs. Chacun exécute ce « lis, sinon calcule » de son côté, sans savoir qu'un voisin est en train de faire exactement la même chose à la même milliseconde. `Cache::remember()` protège un process contre lui-même dans le temps ; il ne protège pas N process les uns contre les autres au même instant. C'est précisément ce vide de coordination que le stampede vient combler à votre place.

## Le faux bon réflexe : un verrou global qui sérialise tout

La première idée qui vient est de poser un verrou : puisque le souci est que tout le monde entre en même temps, forçons un passage un par un.

```php
// À NE PAS faire : un verrou qui enveloppe toutes les lectures
$stats = Cache::lock('dashboard:stats:lock', 10)->block(5, function () {
    return Cache::remember('dashboard:stats', now()->addHour(), function () {
        return $this->agregerStatistiquesLourdes();
    });
});
```

Sur le papier, ça marche : un seul process reconstruit à la fois. En pratique, c'est un remède pire que le mal, parce que le verrou enveloppe **aussi les lectures qui touchent au but**. Chaque requête, y compris les 99 % qui n'auraient eu qu'à lire une valeur déjà chaude, doit d'abord acquérir le verrou. Vous avez sérialisé tout votre trafic sur cette clé.

Le résultat est contre-intuitif : vous n'avez pas supprimé le pic, vous l'avez déplacé. Le pic de CPU sur la base se transforme en pic de **latence** sur l'application, car les requêtes font désormais la queue derrière le verrou. Sous forte charge, `block(5)` finit même par expirer et lever une `LockTimeoutException` : vous avez troqué une base surchargée mais fonctionnelle contre des erreurs 500. Le verrou n'est pas une mauvaise idée — c'est son placement qui l'est.

## Le single-flight : un seul reconstruit, les autres relisent

La bonne approche s'appelle *single-flight* : on ne verrouille **que la reconstruction**, pas la lecture. Le chemin nominal — la clé est là — reste sans verrou, à pleine vitesse. Le verrou n'entre en jeu qu'au moment du *miss*, et il garantit qu'un seul process reconstruit pendant que les autres attendent brièvement, puis relisent la valeur fraîchement écrite.

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\LockTimeoutException;

function rememberSingleFlight(string $key, int $ttl, Closure $callback): mixed
{
    // Chemin rapide : la clé est chaude, on rend sans jamais toucher au verrou.
    $valeur = Cache::get($key);
    if ($valeur !== null) {
        return $valeur;
    }

    // Miss : on tente d'être celui qui reconstruit.
    $lock = Cache::lock($key . ':rebuild', 10);

    try {
        // On attend jusqu'à 5 s d'obtenir le verrou de reconstruction.
        $lock->block(5);

        // Pendant l'attente, un autre process a pu peupler la clé : on relit.
        $valeur = Cache::get($key);
        if ($valeur !== null) {
            return $valeur;
        }

        // On est le seul reconstructeur : on calcule et on écrit.
        $valeur = $callback();
        Cache::put($key, $valeur, $ttl);

        return $valeur;
    } catch (LockTimeoutException $e) {
        // Verrou jamais obtenu : dernière lecture, sinon on reconstruit
        // plutôt que d'échouer. Dégradation, pas erreur 500.
        return Cache::get($key) ?? $callback();
    } finally {
        $lock->release();
    }
}
```

Le point clé est la **double lecture** : une avant le verrou (le cas courant), une après l'avoir obtenu. Cette seconde lecture est ce qui évite le stampede — le premier process arrivé reconstruit, tous ceux qui attendaient derrière le verrou trouvent la valeur déjà là et repartent sans toucher à la base. Sur les 50 requêtes concurrentes, une seule appelle `agregerStatistiquesLourdes()`.

Ceci repose entièrement sur le fait que `Cache::lock()` est **atomique**. Avec le driver Redis, Laravel l'implémente via un `SET` avec l'option `NX` (« écris seulement si la clé n'existe pas »), qui est atomique côté serveur : deux process ne peuvent pas croire simultanément qu'ils ont le verrou. C'est un prérequis, pas un détail (j'y reviens).

## Le TTL jitter : désynchroniser les expirations

Le single-flight protège une clé. Mais si vous peuplez dix clés au même instant — au démarrage, après un déploiement, ou parce qu'elles partagent le même TTL fixe — elles expireront toutes ensemble, et vous aurez dix stampedes coordonnés. La parade est triviale et vaut pour toutes vos écritures de cache : ajouter un peu d'aléatoire au TTL.

```php
// Au lieu d'un TTL fixe qui synchronise les expirations…
$ttl = 3600 + random_int(0, 300); // 1 h ± jusqu'à 5 min de gigue

Cache::put($key, $valeur, $ttl);
```

Ces quelques minutes de décalage suffisent à étaler les expirations dans le temps au lieu de les empiler sur la même seconde. C'est la mesure au meilleur rapport effet/effort de tout ce billet : une ligne, aucune complexité ajoutée, et elle rend le stampede structurellement moins probable.

## Aller plus loin : l'expiration anticipée probabiliste

Le single-flight laisse subsister une petite fenêtre : à l'expiration, le reconstructeur met 800 ms, pendant lesquelles les autres attendent. Pour une clé vraiment critique, on peut faire en sorte qu'elle ne rate **jamais** — en la rafraîchissant *avant* son expiration, pendant qu'elle est encore servie. C'est l'idée de l'expiration anticipée probabiliste (l'algorithme XFetch) : plus on approche de l'échéance, plus chaque lecteur a de chances de déclencher un rafraîchissement en arrière-plan, sans bloquer.

```php
function rememberAnticipe(string $key, int $ttl, int $fenetre, Closure $callback): mixed
{
    $enveloppe = Cache::get($key);

    if ($enveloppe !== null) {
        $restant = $enveloppe['expiration'] - now()->timestamp;

        // Tirage : plus « restant » est petit, plus on tente de rafraîchir tôt.
        if ($restant > $fenetre * abs(log(mt_rand() / mt_getrandmax()))) {
            return $enveloppe['valeur']; // encore frais, on sert tel quel
        }

        // On tente le verrou SANS bloquer : un seul rafraîchit,
        // les autres continuent de servir la valeur encore valide.
        $lock = Cache::lock($key . ':refresh', 10);
        if (! $lock->get()) {
            return $enveloppe['valeur'];
        }
    } else {
        // Vrai miss : on retombe sur un verrou bloquant, single-flight classique.
        $lock = Cache::lock($key . ':refresh', 10);
        $lock->block(5);
    }

    try {
        $valeur = $callback();
        Cache::put($key, [
            'valeur' => $valeur,
            'expiration' => now()->timestamp + $ttl,
        ], $ttl + 60); // TTL Redis un peu plus long que l'expiration logique
        return $valeur;
    } finally {
        $lock->release();
    }
}
```

C'est efficace, mais soyons honnêtes sur le prix : la valeur est désormais enveloppée dans une structure, la logique tient sur trois fois plus de lignes, et il faut raisonner sur une probabilité au lieu d'un `if`. Cette complexité ne se justifie que pour une poignée de clés à la fois brûlantes et coûteuses à recalculer. Pour tout le reste, single-flight plus jitter suffisent amplement.

## Quand ne pas s'en soucier

La question honnête à se poser avant d'écrire une ligne de ce qui précède : ce cache est-il vraiment sujet au stampede ? Dans beaucoup de cas, non — et y ajouter des verrous serait de la complexité gratuite.

- **Clés froides ou peu consultées.** Si une clé est lue quelques fois par minute, la probabilité que deux *miss* tombent sur la même milliseconde est négligeable. Le stampede est un problème de **concurrence**, pas de cache en général.
- **Recalcul bon marché.** Si reconstruire la valeur coûte 5 ms, laisser 50 requêtes le faire en double n'écroulera personne. Le single-flight se réserve aux valeurs vraiment lourdes.
- **Faible trafic.** Sans concurrence, pas de troupeau. Un site à quelques visiteurs simultanés n'a tout simplement pas le volume pour déclencher le phénomène.

Enfin, un prérequis technique : tout ceci suppose un store partagé qui gère les verrous atomiques. Laravel les prend en charge avec les drivers `redis`, `memcached`, `dynamodb`, `database` et `file` ; le driver `array`, purement en mémoire et propre à chaque process, ne coordonne rien d'un process à l'autre. Vérifiez votre `CACHE_STORE` avant de compter sur `Cache::lock()`.

## Ce qu'il faut retenir

Le cache stampede n'est pas un défaut de `Cache::remember()`, c'est un vide de coordination entre process que la charge vient exposer.

- **`Cache::remember()` seul ne coordonne pas** les process concurrents : à l'expiration d'une clé chaude, ils reconstruisent tous en même temps.
- **Un verrou global est le faux bon réflexe** : il sérialise aussi les lectures et transforme le pic de CPU en pic de latence.
- **Le single-flight** ne verrouille que la reconstruction, avec une double lecture : un seul process recalcule, les autres relisent la valeur fraîche.
- **Le TTL jitter** désynchronise les expirations pour une ligne de code — à faire systématiquement.
- **L'expiration anticipée probabiliste** protège les clés les plus critiques, au prix d'une vraie complexité qu'il faut réserver à ces cas-là.
- **Avant tout ça, demandez-vous si le problème existe** : clé froide, recalcul bon marché ou faible trafic ne justifient aucun verrou.

Le bon réflexe n'est pas d'ajouter des verrous partout, mais de repérer la poignée de clés à la fois chaudes et coûteuses, et de ne protéger que celles-là. Le reste de votre cache n'a besoin de rien d'autre qu'un peu de gigue sur ses TTL.
