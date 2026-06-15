---
title: "Tester le temps dans Laravel : geler l'horloge pour des tests déterministes"
description: "Un test qui passe le matin et casse en CI le soir ? Le coupable, c'est souvent l'horloge. Geler et voyager dans le temps avec travel, freezeTime et freezeSecond."
pubDate: 2026-06-15
tags: ["laravel", "php", "pest"]
---

Vous écrivez un test : un token doit expirer 60 minutes après sa création. En local, à 10 h du matin, il passe au vert. Trois semaines plus tard, l'intégration continue le rejette — sans qu'une seule ligne de code métier ait bougé. Le coupable n'est pas votre logique : c'est l'horloge. Tant qu'un test lit l'heure réelle, son résultat dépend du moment où il tourne, du fuseau du runner et des microsecondes qui séparent deux appels à `now()`. Laravel fournit tout le nécessaire pour neutraliser cette source d'instabilité.

## Pourquoi un test daté est instable

Le problème de fond, c'est que l'heure « vraie » est une entrée cachée du test. Prenez ce calcul d'expiration, tout ce qu'il y a de banal :

```php
class Token extends Model
{
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
```

Un test naïf crée un token censé expirer dans une heure, puis vérifie qu'il n'est pas expiré :

```php
it('considère un token valide pendant 60 minutes', function () {
    $token = Token::factory()->create([
        'expires_at' => now()->addMinutes(60),
    ]);

    expect($token->isExpired())->toBeFalse();
});
```

Tant que la machine est rapide, ça marche. Mais le `now()` du test et le `isPast()` du modèle sont deux lectures distinctes de l'horloge, séparées par tout le travail de la factory et de l'insertion en base. Sur un runner CI chargé, ces deux instants peuvent diverger de plusieurs secondes. Pire : dès qu'on teste une bascule (« le token devient invalide *après* 60 minutes »), on ne peut pas raisonnablement attendre une heure dans un test. Il faut pouvoir décider de l'heure.

## `now()`, pas `new DateTime()`

La première règle, c'est de toujours passer par l'horloge de Laravel. Le helper `now()` — comme `Carbon::now()` et `today()` — lit une horloge que le framework sait remplacer pendant les tests. À l'inverse, `new DateTime()` ou `time()` interrogent directement l'OS et restent imperméables à toute manipulation.

```php
// Testable : Laravel peut figer cette horloge
$expiresAt = now()->addMinutes(60);

// Non testable : lit l'heure système, impossible à geler
$expiresAt = (new DateTime())->modify('+60 minutes');
```

C'est une contrainte légère mais structurante : si une portion de code instancie ses propres dates, elle deviendra un angle mort dès qu'on voudra la tester. Centraliser sur `now()` est le prérequis de tout ce qui suit.

## Geler l'instant avec `freezeTime()`

`freezeTime()` arrête l'horloge sur l'instant présent pour toute la durée du test. Chaque appel à `now()` renvoie alors exactement la même valeur, peu importe le temps réel écoulé entre deux lignes :

```php
use Illuminate\Support\Carbon;

it('considère un token valide pendant 60 minutes', function () {
    $this->freezeTime();

    $token = Token::factory()->create([
        'expires_at' => now()->addMinutes(60),
    ]);

    expect($token->isExpired())->toBeFalse();
});
```

Désormais, le `now()` de la factory et le `now()` implicite derrière `isPast()` lisent le même instant. L'écart qui faisait vaciller le test a disparu. Historiquement, `freezeTime()` est d'ailleurs un raccourci pour `travelTo(now())` — geler le temps, c'est voyager jusqu'à maintenant et y rester.

## Le piège des microsecondes : `freezeSecond()`

Voici le piège que la documentation officielle survole, et qui produit les échecs les plus déroutants. Carbon mesure le temps à la microseconde près. Mais une colonne `TIMESTAMP` ou `DATETIME` de MySQL, par défaut, ne stocke que la seconde — la fraction de seconde est arrondie à la seconde la plus proche à l'écriture.

Conséquence : vous figez le temps à `10:30:00.123456`, vous insérez une ligne, et au moment de la relire la base vous renvoie `10:30:00.000000`. La comparaison `created_at == now()` échoue alors qu'à la seconde près, tout est correct.

```php
it('horodate la ligne à l’instant gelé', function () {
    $this->freezeTime();

    $token = Token::factory()->create();

    // Échoue : now() porte les microsecondes, created_at relu les a perdues
    expect($token->fresh()->created_at->eq(now()))->toBeTrue();
});
```

La parade, c'est `freezeSecond()`, qui gèle le temps en remettant les microsecondes à zéro. L'instant figé est alors aligné sur ce que la base sait réellement stocker :

```php
it('horodate la ligne à l’instant gelé', function () {
    $this->freezeSecond();

    $token = Token::factory()->create();

    // Passe : les deux côtés sont à la seconde pleine
    expect($token->fresh()->created_at->eq(now()))->toBeTrue();
});
```

Règle pratique : dès qu'un test compare une date relue depuis la base à un `now()` figé, utilisez `freezeSecond()` plutôt que `freezeTime()`. Pour les calculs qui restent en mémoire, `freezeTime()` suffit.

## Voyager dans le temps avec `travel()` et `travelTo()`

Geler l'instant règle le déterminisme, mais ne teste pas les bascules. Pour vérifier qu'une règle change d'état au franchissement d'un seuil, on avance l'horloge. `travelTo()` se place à une date absolue, `travel()` applique un décalage relatif via une API fluide :

```php
it('expire le token après 60 minutes', function () {
    $this->freezeTime();

    $token = Token::factory()->create([
        'expires_at' => now()->addMinutes(60),
    ]);

    expect($token->isExpired())->toBeFalse();

    // On saute 61 minutes plus tard
    $this->travel(61)->minutes();

    expect($token->fresh()->isExpired())->toBeTrue();
});
```

Le même mécanisme couvre une fin de période d'essai à 14 jours, une fenêtre d'éligibilité ou un TTL de cache — on se place juste avant le seuil, on assert, on franchit le seuil, on assert à nouveau. `travel()` accepte `->days()`, `->hours()`, `->seconds()`, et `travelBack()` ramène à l'heure réelle.

`travelTo()` a une variante très commode : passez-lui une closure, et l'horloge n'est décalée que le temps de l'exécuter, puis restaurée automatiquement. Utile pour créer une donnée « dans le passé » sans contaminer le reste du test :

```php
it('liste les tokens créés cette semaine', function () {
    // Un token créé il y a deux semaines, le temps de la closure
    travelTo(now()->subWeeks(2), function () {
        Token::factory()->create();
    });

    // Ici, l'horloge est déjà revenue à maintenant
    Token::factory()->create();

    expect(Token::createdThisWeek()->count())->toBe(1);
});
```

## Restaurer l'horloge entre les tests

Toute manipulation du temps est un état global : si elle fuit d'un test à l'autre, vous remplacez une instabilité par une autre, plus sournoise encore. La bonne nouvelle, c'est que Laravel restaure automatiquement l'horloge après chaque test, à condition d'utiliser ses helpers (`travel`, `travelTo`, `freezeTime`, `freezeSecond`) plutôt que de bricoler `Carbon::setTestNow()` à la main sans nettoyer.

Si vous tenez à `Carbon::setTestNow()` directement, repassez-le à `null` en fin de test pour libérer l'horloge :

```php
afterEach(function () {
    Carbon::setTestNow(); // sans argument : retour à l'heure réelle
});
```

Cet isolement est aussi ce qui rend les tests parallèles sûrs : chaque test gèle sa propre horloge dans son propre processus, sans que le voyage temporel de l'un déborde sur l'autre. Lancer la suite avec `--parallel` ne change donc rien à ces helpers — tant que vous ne laissez pas d'état traîner.

## Ce qu'il faut retenir

Geler le temps, c'est transformer une entrée cachée et incontrôlable en une valeur que vous décidez. C'est l'un des leviers les plus rentables contre les tests instables, ceux qui passent en local et cassent en CI.

Trois réflexes pour s'y retrouver :

1. **`freezeTime()`** quand le test fait des calculs de durée en mémoire et n'a pas besoin que le temps avance.
2. **`freezeSecond()`** dès qu'on compare une date relue depuis la base à `now()` — c'est lui qui désamorce le piège des fractions de seconde arrondies par MySQL.
3. **`travel()` / `travelTo()`** pour franchir un seuil et vérifier qu'une règle bascule ; la variante closure de `travelTo()` restaure l'horloge toute seule.

Le tout repose sur une discipline simple en amont : lire l'heure avec `now()`, jamais avec `new DateTime()`. À ce prix, une règle métier datée devient aussi déterministe que n'importe quel autre test.
