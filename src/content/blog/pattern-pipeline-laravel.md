---
title: "Le pattern Pipeline de Laravel pour orchestrer un processus métier"
description: "Refactorer une méthode de service truffée de if imbriqués en une chaîne de pipes à responsabilité unique avec Illuminate\\Pipeline — et savoir quand s'en passer."
pubDate: 2026-06-26
tags: ["laravel", "php"]
---

Une méthode `traiter()` dans un service, et au fil des évolutions elle gonfle : une validation de stock, un code promo à résoudre, un total à plafonner, une réservation à poser. Chaque règle ajoute son `if`, parfois imbriqué dans le précédent, avec des `return` d'échec disséminés. La méthode finit à soixante lignes, impossible à lire d'un coup d'œil, et chaque nouvelle étape oblige à relire tout le reste pour ne rien casser. Le pattern Pipeline — fourni dans le cœur de Laravel via `Illuminate\Pipeline` — répond exactement à ce problème : faire passer un objet à travers une suite d'étapes indépendantes, chacune libre d'interrompre la chaîne.

## Le point de départ : des `if` qui s'empilent

Voici le genre de méthode dont on parle, dans une petite app Laravel qui traite une commande :

```php
public function traiter(Order $order): Resultat
{
    if (! $this->stock->disponiblePour($order)) {
        return Resultat::echec('Stock insuffisant');
    }

    if ($order->code_promo) {
        $promo = $this->promos->resoudre($order->code_promo);

        if ($promo === null || $promo->estExpire()) {
            return Resultat::echec('Code promo invalide');
        }

        $order->remise = $promo->montant;
    }

    $order->total = max(0, $order->montant_brut - $order->remise);

    $this->stock->reserver($order);

    return Resultat::succes($order);
}
```

Rien n'est faux ici, mais tout est mélangé : la validation, le calcul et l'effet de bord cohabitent, les chemins d'échec sont éparpillés, et tester « le cas du code promo expiré » suppose de monter toute la machinerie autour. Ajouter une étape, c'est rouvrir cette méthode et espérer ne pas déplacer un `return` au mauvais endroit.

## Ce qu'est un pipe

Un *pipe* est une classe à responsabilité unique avec une méthode `handle($passager, Closure $next)`. Elle reçoit le passager — l'objet qui traverse la chaîne —, fait son travail, puis appelle `$next($passager)` pour passer la main à l'étape suivante. Si elle décide d'arrêter, elle renvoie sans appeler `$next`.

Le passager est un simple objet qui transporte l'état d'une étape à l'autre :

```php
namespace App\Checkout;

use App\Models\Order;

class Panier
{
    public function __construct(
        public Order $order,
        public bool $arrete = false,
        public ?string $raison = null,
    ) {}

    public function arreter(string $raison): self
    {
        $this->arrete = true;
        $this->raison = $raison;

        return $this;
    }
}
```

Chaque règle devient alors un pipe isolé :

```php
namespace App\Checkout\Pipes;

use App\Checkout\Panier;
use App\Repositories\StockRepository;
use Closure;

class VerifierStock
{
    public function __construct(private StockRepository $stock) {}

    public function handle(Panier $panier, Closure $next): Panier
    {
        if (! $this->stock->disponiblePour($panier->order)) {
            return $panier->arreter('Stock insuffisant');
        }

        return $next($panier);
    }
}
```

Notez le constructeur : comme la pipeline résout chaque pipe via le container, l'injection de dépendances fonctionne normalement. `StockRepository` arrive tout seul.

Les autres étapes suivent le même moule. `AppliquerCodePromo` résout et valide le code, `CalculerTotaux` plafonne le total, `ReserverStock` pose la réservation :

```php
class AppliquerCodePromo
{
    public function __construct(private PromoRepository $promos) {}

    public function handle(Panier $panier, Closure $next): Panier
    {
        if (! $panier->order->code_promo) {
            return $next($panier);
        }

        $promo = $this->promos->resoudre($panier->order->code_promo);

        if ($promo === null || $promo->estExpire()) {
            return $panier->arreter('Code promo invalide');
        }

        $panier->order->remise = $promo->montant;

        return $next($panier);
    }
}
```

## Assembler la chaîne

C'est `Illuminate\Pipeline\Pipeline` qui orchestre le tout, avec trois méthodes : `send()` injecte le passager, `through()` liste les pipes dans l'ordre d'exécution, `then()` reçoit le résultat final une fois la chaîne traversée.

```php
use Illuminate\Pipeline\Pipeline;
use App\Checkout\Pipes\{VerifierStock, AppliquerCodePromo, CalculerTotaux, ReserverStock};

public function traiter(Order $order): Resultat
{
    $panier = app(Pipeline::class)
        ->send(new Panier($order))
        ->through([
            VerifierStock::class,
            AppliquerCodePromo::class,
            CalculerTotaux::class,
            ReserverStock::class,
        ])
        ->then(fn (Panier $panier) => $panier);

    return $panier->arrete
        ? Resultat::echec($panier->raison)
        : Resultat::succes($panier->order);
}
```

La méthode de service redevient lisible : elle décrit *quoi* enchaîner, pas *comment* chaque règle s'applique. L'ordre de la liste est l'ordre d'exécution — vérifier le stock avant d'appliquer une promo a du sens, l'inverse beaucoup moins. Réordonner le processus, c'est déplacer une ligne.

## Court-circuiter la chaîne

Le mécanisme tient en une phrase : un pipe qui n'appelle pas `$next()` interrompt la chaîne. Dans `VerifierStock`, si le stock manque, on renvoie le panier marqué `arrete` sans propager. Les étapes suivantes ne s'exécutent jamais, et `then()` reçoit directement ce panier-là.

C'est le gros avantage sur une suite linéaire : pas besoin d'un drapeau testé à chaque étape ni d'un `return` qui fait sauter le reste de la méthode. Chaque pipe décide localement s'il laisse passer ou s'il bloque, sans rien savoir de ses voisins. Attention toutefois à respecter le contrat : un pipe qui oublie d'appeler `$next()` *et* ne veut pas arrêter casse silencieusement la chaîne. La règle est simple — soit on arrête explicitement, soit on termine par `return $next($panier)`.

Par défaut, la pipeline appelle la méthode `handle`. Si vous préférez une autre convention, `via()` la change pour toute la chaîne :

```php
->via('executer')   // appellera executer() au lieu de handle()
```

## Tester un pipe isolément

C'est là que le découpage paye vraiment. Un pipe n'a pas besoin de la pipeline pour être testé : c'est une classe ordinaire dont on appelle `handle()` avec une closure `$next` factice. On vérifie deux choses — l'effet du pipe, et s'il a propagé ou non.

```php
use App\Checkout\Panier;
use App\Checkout\Pipes\VerifierStock;
use App\Repositories\StockRepository;

it('arrête la chaîne quand le stock est insuffisant', function () {
    $stock = Mockery::mock(StockRepository::class);
    $stock->shouldReceive('disponiblePour')->once()->andReturnFalse();

    $suivantAppele = false;
    $panier = new Panier(Order::factory()->make());

    $resultat = (new VerifierStock($stock))->handle($panier, function ($p) use (&$suivantAppele) {
        $suivantAppele = true;

        return $p;
    });

    expect($suivantAppele)->toBeFalse();
    expect($resultat->arrete)->toBeTrue();
    expect($resultat->raison)->toBe('Stock insuffisant');
});
```

Un test ciblé par étape, sans monter toute la commande : c'est exactement ce que la grosse méthode initiale rendait pénible.

## Quand s'en passer

Voilà la partie honnête, parce que sortir la pipeline pour tout serait de l'abstraction gratuite. Pour deux ou trois étapes sans branchement ni court-circuit, une suite d'appels reste plus claire et plus directe :

```php
$this->valider($order);
$this->calculer($order);
$this->enregistrer($order);
```

Trois classes de pipes, un objet passager et une `Pipeline` pour ça, c'est de la cérémonie qui masque l'essentiel. La pipeline gagne quand le processus a **quatre étapes ou plus**, que **plusieurs peuvent interrompre** la chaîne, que **l'ordre est susceptible de changer**, ou qu'on veut **tester chaque étape isolément**. En dessous, la méthode linéaire est le bon choix.

Deux confusions à écarter au passage. Les **middlewares HTTP** suivent la même mécanique `handle($request, $next)`, mais ils opèrent sur le cycle requête/réponse du framework, pas sur votre logique métier. Les **jobs chaînés** (`Bus::chain`) servent à enchaîner des traitements *asynchrones* sur la file, avec des frontières de persistance entre eux. La pipeline, elle, est synchrone et en mémoire : elle structure une opération logique unique, le temps d'un appel.

## Ce qu'il faut retenir

Le pattern Pipeline transforme une méthode truffée de `if` en une chaîne de pipes à responsabilité unique : chacun fait une chose, peut court-circuiter en n'appelant pas `$next()`, se résout via le container et se teste seul. La méthode de service ne décrit plus que l'ordre des étapes.

Le critère de décision tient en une question : **vos étapes sont-elles assez nombreuses, réordonnables ou interruptibles pour justifier le découpage ?** Si oui, `Illuminate\Pipeline` vous donne une structure claire pour le coût de quelques classes. Si non — deux ou trois appels sans branchement —, une simple suite de méthodes reste la réponse la plus honnête.
