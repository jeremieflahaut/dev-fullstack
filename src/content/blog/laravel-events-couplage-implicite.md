---
title: "Les events Laravel, ou le couplage qu'on ne voit plus"
description: "Quand les events, listeners et model observers troquent un couplage explicite contre un couplage implicite intraçable — et une grille pour choisir entre event et appel direct."
pubDate: 2026-07-03
tags: ["laravel", "php"]
---

Un bug remonte : après un paiement, le client ne reçoit pas sa facture une fois sur dix. Je pars du contrôleur, propre, trois lignes. Il émet un event `CommandePayee`. Je cherche qui l'écoute : quatre listeners, dans quatre fichiers, plus un model observer qui se réveille au passage. L'un est en file, un autre dépend d'un `updated_at` posé par le premier, et l'ordre entre eux n'est écrit nulle part. Le flux « quand une commande est payée, voici ce qui arrive » n'existe plus à un seul endroit — il faut le reconstituer à la main. C'est exactement le coût que les tutos oublient de facturer.

## Le découplage n'efface pas le couplage, il le cache

L'argument de vente des events est séduisant : au lieu que `PaiementController` appelle directement le service de facturation, il émet un signal, et qui veut réagit. Le contrôleur ne « connaît » plus la facturation. Découplé, donc mieux.

Sauf que la dépendance n'a pas disparu : facturer reste une conséquence obligatoire du paiement. On a seulement rendu ce lien invisible. Avec un appel direct, la question « qu'est-ce qui se passe quand on paie ? » se répond en lisant la méthode. Avec un event, elle se répond en cherchant dans tout le projet qui s'abonne — un `grep` sur un nom de classe, en espérant qu'aucun abonnement ne soit enregistré dynamiquement. Le couplage explicite est une flèche qu'on suit ; le couplage implicite est une flèche qu'on devine.

Ce n'est pas un problème quand la réaction est réellement optionnelle. Ça en devient un dès qu'on planque une règle métier obligatoire derrière un mécanisme conçu pour l'accessoire.

## Trois symptômes que je paie régulièrement

**Les listeners en file échouent en silence.** Mettre un listener sur la queue est trivial : on implémente `ShouldQueue`. L'ennui, c'est que le contrôleur, lui, a déjà renvoyé son `200`. Si le listener plante, l'utilisateur n'en saura rien.

```php
namespace App\Listeners;

use App\Events\CommandePayee;
use Illuminate\Contracts\Queue\ShouldQueue;

class EnvoyerFacture implements ShouldQueue
{
    public function handle(CommandePayee $event): void
    {
        // Si le service tiers lève une exception, l'échec part
        // dans failed_jobs — pas sous les yeux de l'appelant.
        $this->facturation->emettrePour($event->order);
    }
}
```

L'échec atterrit dans `failed_jobs`, à condition qu'on surveille cette table. Le paiement, lui, est passé. On a créé une incohérence différée que personne ne voit avant le ticket de support.

**L'ordre d'exécution n'est pas un contrat.** Quand plusieurs listeners écoutent le même event, leur ordre dépend de l'enregistrement — et devient franchement hasardeux dès que certains sont synchrones et d'autres en file. Si `GenererFacture` doit tourner avant `NotifierClient`, aucune signature ne l'impose. Le jour où quelqu'un ajoute un listener au milieu, ou bascule l'un des deux sur la queue, la dépendance implicite casse sans un seul test rouge.

**Les model observers cachent des écritures.** C'est la version la plus sournoise. Un observer sur `created` ou `saved` déclenche des effets à chaque fois que le modèle est persisté — y compris dans un seeder, un import de masse, un test de factory.

```php
namespace App\Observers;

use App\Models\Order;

class OrderObserver
{
    public function created(Order $order): void
    {
        // Se déclenche AUSSI dans Order::factory()->create()
        // et dans une commande d'import. Surprise garantie.
        LigneComptable::depuis($order);
        $this->notifications->prevenirEntrepot($order);
    }
}
```

On lit `Order::create()` dans une Action et on ne soupçonne pas la ligne comptable ni la notification entrepôt qui partent derrière. J'ai déjà passé une bonne heure à comprendre pourquoi une factory de test créait des enregistrements comptables fantômes. La réponse était dans un observer que personne n'avait relu depuis des mois.

## Le coût de test qu'on découvre trop tard

Un event bien placé se teste très bien. Un event mal placé rend le test indirect et fragile. Côté émission, on ne teste plus un résultat mais une intention :

```php
use App\Events\CommandePayee;
use Illuminate\Support\Facades\Event;

it('émet CommandePayee après un paiement', function () {
    Event::fake();

    $this->postJson('/commandes/42/payer')->assertOk();

    Event::assertDispatched(CommandePayee::class);
});
```

Ce test vérifie qu'on a crié, pas que quelqu'un a répondu. La facture est-elle bien générée ? Il faut un second test, sur le listener cette fois. La logique d'un seul parcours métier se retrouve éclatée en autant de tests qu'il y a d'abonnés, reliés par la seule confiance qu'ils s'appellent dans le bon ordre.

Comparez avec une [Action](/blog/pattern-action-laravel/) qui orchestre explicitement : un test, une assertion sur l'état final.

```php
it('génère la facture après paiement', function () {
    $order = Order::factory()->create();

    app(PayerCommande::class)->handle($order);

    expect($order->fresh()->facture)->not->toBeNull();
});
```

Un flux, un test, un résultat observable. Pas d'`Event::fake`, pas d'assertion sur un intermédiaire.

## La grille de décision

À force de me faire piéger, j'ai fini par me poser toujours les mêmes questions avant de sortir un event. Un event se justifie quand **les quatre** sont vraies :

1. **La réaction est vraiment optionnelle.** Le parcours principal reste correct même si personne n'écoute. Logguer une connexion, oui. Générer la facture d'une commande payée, non.
2. **Le lien traverse une frontière de module.** L'émetteur n'a aucune raison de connaître l'abonné — deux contextes métier distincts qui communiquent, plutôt que deux étapes du même processus.
3. **Il y a, ou il y aura, plusieurs abonnés.** Un event pour un seul listener, c'est un appel direct déguisé, avec une indirection en prime.
4. **On vise une extensibilité réelle** : broadcasting, hook exposé à un package, intégration tierce branchable sans toucher au cœur.

Si l'une manque, l'appel explicite — une Action, une méthode de service — est plus lisible et surtout traçable. La règle courte : **event pour l'accessoire et l'ouvert ; appel direct pour l'obligatoire et le séquentiel.**

## L'exemple charnière : « commande payée »

Voici le même flux des deux façons. En events, le contrôleur émet et se lave les mains :

```php
public function payer(Order $order): JsonResponse
{
    $this->paiements->encaisser($order);

    CommandePayee::dispatch($order); // qui écoute ? mystère.

    return response()->json($order);
}
```

Pour savoir ce qui arrive ensuite — facture, décrément de stock, notification — il faut ouvrir `EventServiceProvider`, lister les listeners, vérifier lesquels sont en file, deviner leur ordre. Le parcours est réparti, l'ordre non garanti, les échecs silencieux.

Orchestré explicitement dans une Action, le même flux se lit de haut en bas :

```php
class PayerCommande
{
    public function __construct(
        private GenererFacture $facture,
        private DecrementerStock $stock,
    ) {}

    public function handle(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->paiements->encaisser($order);
            $this->facture->generer($order);
            $this->stock->decrementerPour($order);

            return $order;
        });
    }
}
```

L'ordre est le code, la transaction garantit la cohérence, et un échec remonte à l'appelant au lieu de se perdre. Si, ensuite, une intégration analytics veut réagir *sans* faire partie du parcours obligatoire, alors — et seulement alors — un event `CommandePayee` émis après le `commit` est le bon outil pour elle. Le métier reste explicite ; l'accessoire passe par l'event.

## Quand les events sont vraiment le bon choix

Le tableau serait malhonnête sans l'envers. Les events brillent là où ils ont été pensés. Le **broadcasting** temps réel vers le front en est l'usage canonique : émettre `CommandePayee` sur un canal WebSocket est exactement ce qu'il faut. La **communication inter-modules** d'un monolithe modulaire aussi : un module `Facturation` qui réagit à un event du module `Commandes`, sans dépendance de code directe, c'est le découplage qui rend service — la frontière est réelle. Enfin, tout ce qui est **transversal et non métier** — journaliser, invalider un cache, poser un flag d'audit — vit très bien dans un listener ou un observer léger, précisément parce que sa présence ou son absence ne change rien au parcours principal.

Le fil conducteur : l'event est excellent quand l'émetteur n'a aucune raison légitime de connaître l'abonné. Il devient un piège quand on l'emploie pour masquer une dépendance qui, elle, est bien réelle.

## Ce qu'il faut retenir

Un event ne supprime pas un couplage, il le rend invisible — ce qui est un progrès pour l'accessoire et une régression pour l'obligatoire. Avant d'en écrire un, je me pose la question franche : **est-ce que je découple deux choses qui n'ont pas à se connaître, ou est-ce que je cache une dépendance dont j'aurai besoin de suivre le fil dans six mois ?**

- Réaction optionnelle, frontière de module, plusieurs abonnés, extensibilité réelle → **event**.
- Étape obligatoire d'un même parcours, ordre qui compte, effet à garder traçable → **appel explicite**.

Le découplage n'est pas gratuit. Il se paie en lisibilité et en traçabilité, et cette facture-là arrive toujours le jour du bug, jamais le jour où on écrit le code.
