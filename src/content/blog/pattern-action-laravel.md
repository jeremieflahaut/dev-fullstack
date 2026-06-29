---
title: "Le pattern Action en Laravel, vu depuis la prod"
description: "Ce que les tutos survolent sur les Actions Laravel : le couplage à la Request, les transactions multi-effets, la composition d'Actions et l'organisation des dossiers."
pubDate: 2026-06-29
tags: ["laravel", "php"]
---

Le pattern Action a fini par s'imposer dans beaucoup de projets Laravel : une classe, une méthode `handle()`, une intention métier. Les présentations du sujet — celle de Nuno Maduro est limpide — donnent une base saine. Mais entre le tuto et la prod, quelques détails font toute la différence : ils ne se voient pas sur un exemple jouet, et se paient cher six mois plus tard. Voici les quatre que j'aurais aimé qu'on me dise plus tôt, glanés sur une plateforme métier interne.

## Une Action n'est pas un contrôleur déguisé

Le premier réflexe, qu'on voit dans bien des exemples, est de passer la `Request` directement à l'Action :

```php
class CreerCommande
{
    public function handle(Request $request): Order
    {
        $order = Order::create([
            'client_id' => $request->input('client_id'),
            'montant' => $request->input('montant'),
        ]);

        return $order;
    }
}
```

Ça marche le jour où on l'écrit. Le problème arrive quand on veut rejouer la même logique ailleurs : une commande Artisan d'import, un job de file, un test. Tous doivent alors fabriquer une fausse `Request` pour appeler l'Action, ce qui est absurde — il n'y a pas de requête HTTP dans un cron de nuit. L'Action est devenue couplée au transport HTTP alors qu'elle décrit une règle métier qui n'en dépend pas.

Le correctif tient en une discipline simple : l'Action ne reçoit jamais la `Request`, mais seulement des données déjà validées et des ressources typées.

```php
class CreerCommande
{
    public function handle(int $clientId, int $montant): Order
    {
        return Order::create([
            'client_id' => $clientId,
            'montant' => $montant,
        ]);
    }
}
```

Le contrôleur garde la responsabilité du HTTP — valider, puis déléguer :

```php
class OrderController
{
    public function store(StoreOrderRequest $request, CreerCommande $action): JsonResponse
    {
        $valide = $request->validated();

        $order = $action->handle(
            clientId: (int) $valide['client_id'],
            montant: (int) $valide['montant'],
        );

        return response()->json($order, 201);
    }
}
```

La même Action devient appelable depuis n'importe où :

```php
$action->handle(clientId: 42, montant: 1500);
```

Plus de `Request` à simuler dans les tests, plus de couplage au cycle requête/réponse. Le contrôleur fait son métier — traduire du HTTP —, l'Action fait le sien.

## Plusieurs effets ? Une transaction

Une Action de tuto fait souvent une seule chose : un `create`, un `update`. En prod, une intention métier en touche plusieurs : créer la commande, décrémenter le stock, écrire une ligne de journal. Et là, la question n'est plus « est-ce que ça marche ? » mais « qu'est-ce qui se passe si la deuxième écriture échoue après la première ? ».

Sans transaction, on se retrouve avec une commande créée mais un stock non décrémenté : une base incohérente, le genre de bug qu'on découvre par un ticket de support trois semaines plus tard. Dès qu'une Action enchaîne plusieurs écritures, je les enveloppe dans `DB::transaction()` :

```php
use Illuminate\Support\Facades\DB;

class CreerCommande
{
    public function handle(int $clientId, int $montant): Order
    {
        return DB::transaction(function () use ($clientId, $montant) {
            $order = Order::create([
                'client_id' => $clientId,
                'montant' => $montant,
            ]);

            $this->stock->decrementerPour($order);

            LigneJournal::enregistrer($order, 'creation');

            return $order;
        });
    }
}
```

Si une instruction de la closure lève une exception, Laravel annule l'ensemble : aucune des écritures n'est conservée. La base reste cohérente, et l'exception remonte normalement au contrôleur. C'est le genre de filet qui ne sert qu'une fois sur cent — mais cette fois-là, il évite des heures de réparation manuelle. La contrepartie à garder en tête : une transaction n'annule que ce qui touche la base. Un email envoyé ou un appel à une API tierce au milieu de la closure, eux, ne se rejouent pas en arrière. Ces effets-là, on les sort de la transaction, typiquement après le `commit`.

## Composer des Actions : le vrai point fort

C'est l'aspect le plus sous-estimé, et pourtant celui qui rentabilise le pattern. Comme une Action est une classe résolue par le container, elle peut en injecter une autre dans son constructeur. On compose des intentions métier comme des briques.

Prenons une inscription : créer le compte, puis lui ouvrir un abonnement d'essai. Deux Actions distinctes, chacune utilisable seule, et une troisième qui les orchestre :

```php
class InscrireUtilisateur
{
    public function __construct(
        private CreerCompte $creerCompte,
        private DemarrerEssai $demarrerEssai,
    ) {}

    public function handle(string $email, string $nom): User
    {
        return DB::transaction(function () use ($email, $nom) {
            $user = $this->creerCompte->handle($email, $nom);

            $this->demarrerEssai->handle($user);

            return $user;
        });
    }
}
```

`CreerCompte` reste appelable seule dans un import en masse, `DemarrerEssai` se déclenche aussi depuis un back-office, et `InscrireUtilisateur` encapsule le parcours complet. Chaque brique se teste isolément, et l'orchestratrice se teste en moquant ses dépendances. Aucune duplication, aucune logique qui se balade entre un contrôleur et un service.

Une réserve, quand même : composer ne veut pas dire empiler. Une Action qui en injecte cinq autres et les enchaîne sur quinze lignes redevient le service fourre-tout qu'on cherchait à fuir. Au-delà de trois ou quatre étapes interdépendantes, un [pattern Pipeline](/blog/pattern-pipeline-laravel/) exprime souvent mieux l'enchaînement.

## Les choix qui ne valent pas la dette

Deux débats reviennent autour des Actions, et mon avis penche vers la sobriété dans les deux cas.

**`array` ou DTO en entrée ?** La tentation est de créer un *Data Transfer Object* typé pour chaque Action, au nom de la rigueur. En pratique, le DTO systématique est de la sur-ingénierie : pour une Action à deux paramètres, c'est une classe de plus à maintenir pour zéro gain. Des arguments nommés et typés suffisent — c'est explicite et l'IDE complète. Je ne sors le DTO que quand le même jeu de données circule entre plusieurs Actions, ou qu'il porte une logique de transformation. Sinon, la signature de méthode est la documentation la plus honnête.

**Un dossier `Actions/` à plat ?** Sur un petit projet, ranger toutes les Actions dans `app/Actions/` est parfait : on les voit toutes d'un coup d'œil. C'est là que je m'écarte du conseil habituel : passé quelques dizaines de classes, le dossier plat devient un mur de noms qu'on parcourt sans rien trouver. Je regroupe alors par domaine métier, ce qui rapproche les Actions des modèles et services qu'elles manipulent :

```
app/
  Orders/
    Actions/
      CreerCommande.php
      AnnulerCommande.php
  Billing/
    Actions/
      DemarrerEssai.php
```

La règle n'est pas « plat » contre « par domaine » dans l'absolu, mais « plat tant que c'est lisible, par domaine dès que ça ne l'est plus ».

## Action explicite plutôt qu'observer implicite

Un dernier point, plus discutable. Laravel offre les *events* et les *observers* pour réagir à un changement de modèle — décrémenter le stock dès qu'une commande est créée, par exemple. C'est élégant sur le papier, mais ça déplace la logique métier dans un mécanisme implicite, déclenché à distance. Six mois plus tard, on lit `Order::create()` dans une Action sans soupçonner les trois observers qui se réveillent derrière.

Je préfère l'appel explicite : si créer une commande doit décrémenter le stock, l'Action `CreerCommande` appelle elle-même `$this->stock->decrementerPour()`. Le parcours se lit de haut en bas, sans saut invisible. Les observers gardent leur place pour le transversal et le non-métier — toucher un `updated_at`, invalider un cache — mais l'enchaînement métier, lui, reste écrit noir sur blanc dans l'Action.

## Ce qu'il faut retenir

Le pattern Action tient ses promesses en prod à condition de respecter quelques règles que les exemples laissent de côté :

1. **Ne passez jamais la `Request`** à une Action — seulement des données validées et des ressources typées. Le HTTP reste l'affaire du contrôleur.
2. **Enveloppez les effets multiples** dans `DB::transaction()`, en gardant hors transaction ce qui ne se rejoue pas en arrière (emails, API tierces).
3. **Composez les Actions** par injection au constructeur : c'est le vrai levier de réutilisation, tant qu'on ne les empile pas.
4. **Restez sobre** sur les DTO et le rangement : un `array` typé suffit souvent, et le dossier plat ne tient que tant qu'il reste lisible.

Une Action, au fond, c'est un verbe métier rendu testable et réutilisable. Tout ce qui l'éloigne de cette définition — un couplage HTTP, un observer caché, un DTO gratuit — est à interroger avant de l'écrire.
