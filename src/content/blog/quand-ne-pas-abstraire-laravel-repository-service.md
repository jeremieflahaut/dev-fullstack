---
title: "Repository, Service, interface : quand ne PAS abstraire en Laravel"
description: "Le coût caché de l'abstraction prématurée en Laravel, et une heuristique simple pour savoir quand une couche Repository ou Service se justifie — et quand elle ne sert à rien."
pubDate: 2026-08-03
tags: ["laravel", "php"]
---

Un jour ou l'autre, sur un projet Laravel, quelqu'un ouvre une pull request qui enveloppe `User::find()` derrière une `UserRepositoryInterface`, son implémentation `EloquentUserRepository`, et un binding dans un service provider. La justification tient en une phrase : « comme ça, si on change d'ORM, on n'aura qu'une implémentation à réécrire ». On ne change jamais d'ORM. On garde en revanche l'interface, l'implémentation, le binding et l'indirection — pour toujours. Voici comment je décide, en prod, si une abstraction mérite d'exister.

## Le réflexe « au cas où »

La plupart des tutos francophones et anglophones enseignent à *mettre en place* une couche Repository ou Service. Le message implicite : un code sérieux abstrait ses accès aux données derrière une interface. Alors on le fait, par mimétisme, dès le premier CRUD, avant même d'avoir un second cas d'usage.

Le problème, c'est que l'abstraction résout un besoin qu'on n'a pas encore. Une interface n'a de sens que si elle a plusieurs implémentations, réelles ou anticipées avec certitude. Une interface avec une seule implémentation n'abstrait rien : c'est un doublon de signature qu'il faut maintenir en parallèle du vrai code. Le fameux « second ORM » n'arrive jamais, et le jour où il arriverait, l'interface écrite au premier CRUD ne collerait de toute façon pas aux contraintes réelles de la migration.

## Le coût caché

Cette abstraction « au cas où » n'est pas gratuite, elle est juste payée plus tard, en petites coupures.

D'abord l'indirection. Pour comprendre ce que fait `store()`, on ouvre le contrôleur, qui appelle une interface, qu'il faut résoudre mentalement vers son implémentation, où l'on trouve enfin le `Model::create()` qu'on cherchait. Trois sauts de navigation IDE pour une ligne de logique. Multiplié par chaque méthode, ça use.

Ensuite les tests. Une couche Repository pousse à moquer le repository dans les tests du service. On vérifie alors que « le service appelle bien `save()` sur un mock » — pas que la commande est réellement persistée. Le test valide le câblage, pas le comportement. Le jour où la vraie requête Eloquent a un bug, le test au vert ne le voit pas, parce qu'il n'a jamais touché la base.

Enfin le code mort. L'interface, l'implémentation et le binding existent pour un second implémenteur qui ne viendra pas. En attendant, chaque refactoring doit les traverser. C'est de la surface de maintenance pure, sans contrepartie.

## Eloquent est déjà une couche d'accès

L'argument central pour un Repository — « isoler l'accès aux données » — oublie qu'Eloquent *est* cette isolation. C'est une implémentation d'Active Record : le modèle expose déjà une API stable (`find`, `where`, `create`) par-dessus le SQL. Empiler une interface Repository revient à abstraire une abstraction.

Et Laravel fournit déjà tout ce qu'un Repository prétend apporter :

- l'accès aux données ? le modèle Eloquent ;
- l'injection et la substituabilité ? le service container, qui résout n'importe quelle classe et permet de la remplacer dans un test ;
- l'encapsulation d'une intention métier ? une [Action](/blog/pattern-action-laravel/), déjà présentée ici.

Autrement dit, la brique que le Repository voudrait ajouter est, neuf fois sur dix, déjà dans le framework.

## Avant / après : l'abstraction qui n'apporte rien

Voici le cas typique. On veut créer un article de blog. La version directe :

```php
class PostController
{
    public function store(StorePostRequest $request): JsonResponse
    {
        $post = Post::create($request->validated());

        return response()->json($post, 201);
    }
}
```

Trois lignes, lisibles de haut en bas. Maintenant la version « propre » telle qu'on la voit dans les tutos :

```php
interface PostRepositoryInterface
{
    public function create(array $data): Post;
}

class EloquentPostRepository implements PostRepositoryInterface
{
    public function create(array $data): Post
    {
        return Post::create($data);
    }
}

// AppServiceProvider::register()
$this->app->bind(PostRepositoryInterface::class, EloquentPostRepository::class);

class PostController
{
    public function __construct(
        private PostRepositoryInterface $posts,
    ) {}

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->posts->create($request->validated());

        return response()->json($post, 201);
    }
}
```

Trois fichiers, un binding, une indirection — pour finir par appeler `Post::create()`, exactement comme avant. On a déplacé la même ligne derrière une porte qu'on doit désormais ouvrir à chaque lecture. L'interface ne protège de rien : il n'y a pas de second implémenteur, pas de frontière de test qu'on ne pouvait pas franchir autrement (le container permet déjà de substituer le modèle dans un test), pas de bounded context. C'est de l'abstraction prématurée à l'état pur.

## Le cas où l'abstraction se justifie

Renversons l'exemple. Cette fois, on encaisse un paiement via un prestataire externe, et on sait qu'on peut en changer — c'est un vrai besoin, pas une hypothèse de tuto.

```php
interface PasserellePaiement
{
    public function debiter(int $montantCentimes, string $token): Paiement;
}

class PasserelleStripe implements PasserellePaiement
{
    public function debiter(int $montantCentimes, string $token): Paiement
    {
        // appel au SDK du prestataire, mapping de la réponse…
    }
}
```

Ici l'interface gagne sa place, pour trois raisons cumulées :

1. **un vrai second implémenteur** est plausible — changer de prestataire de paiement est une décision business courante, pas une divination ;
2. **une frontière de test réelle** : on ne veut appeler aucune API tierce dans la suite de tests, donc une `PasserelleFake` qui renvoie un paiement en dur est indispensable, et elle exige un contrat commun ;
3. **une frontière technique assumée** : le code externe (SDK, HTTP, format de réponse du prestataire) est isolé du reste de l'application, qui ne parle qu'en `Paiement`.

La différence avec le Repository sur `Post` n'est pas le pattern — c'est le contexte. Une interface se justifie quand elle sépare *votre* code d'un code que vous ne maîtrisez pas ou que vous allez réellement remplacer. Elle ne se justifie pas pour redécorer un accès Eloquent que vous maîtrisez déjà.

## Une heuristique de décision

Avant d'ajouter une interface, une couche Repository ou un Service, je vérifie qu'au moins un de ces trois points est vrai **aujourd'hui** :

- **(a) un second implémenteur ou un provider externe** que je vais réellement substituer — un prestataire de paiement, d'e-mail, de stockage de fichiers ;
- **(b) une frontière de test** que je ne peux pas franchir autrement — typiquement un appel réseau que je dois remplacer par un faux ;
- **(c) une frontière de module** assumée, quand je découpe vers un monolithe modulaire et que je veux qu'un contexte ne parle à un autre que par un contrat explicite.

Si aucun n'est vrai, je n'abstrais pas. J'appelle Eloquent directement, quitte à passer par une Action quand il y a une vraie intention métier. Et j'attends que la douleur arrive : le jour où un second cas concret se présente, le refactoring est mécanique et guidé par un besoin réel, pas par une supposition. Extraire une interface à partir de deux implémentations existantes prend dix minutes ; deviner la bonne interface à partir de zéro cas est un pari qu'on perd presque toujours.

## Ne confondez pas Action et Repository

Une objection revient : « mais alors, où mettre la logique métier ? ». Pas dans un Repository — ce n'est pas son rôle. Une couche Repository encapsule un *accès aux données* (« récupère les commandes payées de ce client »). Une Action encapsule une *intention métier* (« annuler cette commande et rembourser le client »). Les deux ne jouent pas au même étage.

En pratique, une Action appelle directement Eloquent pour ses lectures et écritures, et n'a pas besoin d'un Repository intercalé. Elle porte le verbe métier, orchestre éventuellement d'autres Actions, et enveloppe ses effets multiples dans une transaction. C'est là que vit la logique — pas dans une interface d'accès aux données qui ne fait que relayer des appels au modèle.

## Ce qu'il faut retenir

L'abstraction n'est pas une vertu par défaut : c'est un outil qui a un coût, et qu'on paie même quand il ne sert à rien.

1. **Une interface avec une seule implémentation n'abstrait rien** — c'est un doublon à maintenir, une indirection à traverser, et des tests qui vérifient des mocks au lieu du comportement réel.
2. **Eloquent est déjà votre couche d'accès aux données** ; le container gère la substitution en test, et les Actions portent l'intention métier. Le Repository « au cas où » fait souvent doublon avec ce que le framework offre déjà.
3. **Abstrayez sur un besoin réel, pas sur une hypothèse** : un vrai second implémenteur, une frontière de test infranchissable autrement, ou une frontière de module assumée. Sinon, attendez le deuxième cas concret — extraire une interface a posteriori coûte dix minutes.

Le bon moment pour tracer une frontière n'est pas le premier CRUD : c'est quand vous découpez vers un monolithe modulaire, ou quand un provider externe entre réellement dans le tableau. Avant ça, `Post::create()` dans le contrôleur n'est pas de la dette technique — c'est du code honnête.
