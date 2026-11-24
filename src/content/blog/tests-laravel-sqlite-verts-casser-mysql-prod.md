---
title: "Tests Laravel verts sur SQLite, cassés en prod sous MySQL"
description: "laravel new configure les tests sur SQLite alors que la prod tourne sous MySQL. Catalogue des divergences qui mordent, et comment rejouer la suite sur le bon moteur."
pubDate: 2026-11-06
tags: ["laravel", "tests"]
---

La suite est verte, la couverture est bonne, on déploie — et c'est en production que le bug sort, sur une contrainte d'unicité qui se déclenche là où elle ne s'était jamais déclenchée en local. La cause est presque toujours la même : les tests tournent sur SQLite, la prod sur MySQL, et les deux moteurs ne se comportent pas pareil. Le pire, c'est que le vert ne mentait pas : il validait un comportement SQLite qui n'existe tout simplement pas sous MySQL.

## Le piège par défaut

Quand on crée une application avec `laravel new`, le `phpunit.xml` généré oriente les tests vers SQLite en mémoire :

```xml
<php>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

Le choix se défend : une base en mémoire est rapide, ne laisse rien derrière elle, et `RefreshDatabase` repart d'un schéma propre à chaque test. Le souci, c'est que la prod, elle, tourne sous MySQL 8. On teste donc un moteur qu'on ne déploiera jamais. Tant que le code reste dans le périmètre commun aux deux moteurs, tout va bien. Dès qu'il s'appuie sur une spécificité de MySQL — et il y en a plus qu'on ne croit —, la suite valide une réalité fictive.

## Catalogue des divergences qui mordent

Voici les écarts que j'ai vus passer en vrai, du plus fréquent au plus sournois.

**L'unicité sensible à la casse.** C'est le cas d'école. SQLite compare les chaînes `TEXT` octet par octet : `contact@example.com` et `Contact@example.com` sont deux valeurs distinctes. MySQL, avec une collation par défaut comme `utf8mb4_unicode_ci` ou `utf8mb4_0900_ai_ci`, compare sans tenir compte de la casse : les deux entrent en collision sur un index unique. Un test qui crée deux utilisateurs ne différant que par la casse passe sous SQLite et explose sous MySQL.

**Le mode strict.** MySQL 8 tourne en mode strict par défaut. Une valeur trop longue pour sa colonne, un entier hors plage, une date invalide : MySQL rejette avec une erreur. SQLite, lui, est permissif — son typage est dynamique, il range à peu près n'importe quoi dans n'importe quelle colonne. Même famille de problème avec `ONLY_FULL_GROUP_BY` : un `GROUP BY` qui sélectionne une colonne non agrégée et non groupée passe sous SQLite mais est refusé net par MySQL.

**Les requêtes JSON.** Les deux moteurs savent stocker du JSON, mais pas l'interroger pareil. Passer un tableau de valeurs à `whereJsonContains()` n'est supporté que sous MariaDB, MySQL et PostgreSQL, pas sous SQLite ; certains chemins d'accès JSON renvoient des résultats différents. Un test qui exerce une recherche dans une colonne JSON peut être vert faute d'exécuter réellement le bon chemin.

**Les types de clés étrangères.** MySQL exige que la clé étrangère et la clé référencée aient exactement le même type : une `foreignUuid` en `char(36)` pointant vers un `bigint` échoue à la création de la contrainte (le fameux `errno 150`). SQLite ne vérifie pas la correspondance des types. Une migration bancale passe donc les tests et casse au premier `migrate` sur MySQL.

**Le SQL brut spécifique au moteur.** `NOW()`, `REGEXP`, ou `CONCAT()` sur de vieilles versions de SQLite : ces fonctions existent d'un côté, pas de l'autre, ou ne portent pas le même nom. SQLite concatène avec `||` et formate les dates avec `datetime()`. Un `whereRaw('created_at >= NOW() - INTERVAL 1 DAY')` fonctionne sous MySQL et échoue sous SQLite — et l'inverse arrive tout autant.

## Un test qui ment

Rien ne vaut un exemple concret. Prenons une migration on ne peut plus banale :

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('email')->unique();
    $table->timestamps();
});
```

Et un test Pest qui vérifie que deux comptes peuvent coexister :

```php
use App\Models\User;

it('stocke deux emails qui ne diffèrent que par la casse', function () {
    User::factory()->create(['email' => 'contact@example.com']);
    User::factory()->create(['email' => 'Contact@example.com']);

    expect(User::count())->toBe(2);
});
```

Sous SQLite, les deux chaînes sont distinctes : l'index unique les laisse passer, on a bien deux lignes, le test est vert. Sous MySQL en collation insensible à la casse, le second `create()` percute l'index unique et lève une `QueryException`. Le test, lui, aurait raison d'échouer : il documente une hypothèse fausse. Mais tant qu'il tourne sur SQLite, personne ne le sait — jusqu'au jour où un utilisateur s'inscrit avec une casse légèrement différente et déclenche l'erreur 500 en production.

Le bug n'est pas dans le test, il est dans l'écart entre le moteur testé et le moteur déployé. Le seul moyen de le voir, c'est de faire tourner ce test sur MySQL.

## Rejouer la suite sur le bon moteur

La stratégie que j'applique tient en une idée : garder SQLite si on y tient pour la boucle locale rapide, mais ne jamais considérer une suite comme fiable tant qu'elle n'a pas tourné au moins une fois sur le moteur de production.

Le plus simple est un job de CI dédié qui démarre un vrai MySQL, de la même version que la prod, et rejoue la suite dessus. En GitHub Actions, un service container fait l'affaire :

```yaml
jobs:
  tests-mysql:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: testing
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -proot"
          --health-interval=5s
          --health-timeout=3s
          --health-retries=10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.3"
      - run: composer install --no-interaction --prefer-dist
      - run: php artisan test
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_PORT: 3306
          DB_DATABASE: testing
          DB_USERNAME: root
          DB_PASSWORD: root
```

Le healthcheck sur `mysqladmin ping` évite de lancer les tests avant que MySQL accepte réellement les connexions — le même piège que pour un `docker compose up`. Les variables d'environnement surchargent le `DB_CONNECTION` du `phpunit.xml` : la suite tourne alors sur le vrai moteur, avec `RefreshDatabase` qui migre et nettoie une vraie base MySQL entre les tests.

Deux réflexes complètent le dispositif, côté code cette fois :

- **Privilégier le query builder au SQL brut.** `whereDate()`, `where('created_at', '>=', now()->subDay())` ou les helpers de dates d'Eloquent génèrent le SQL adapté au moteur actif. Le `whereRaw` moteur-spécifique est à réserver aux cas où on n'a pas le choix — et à couvrir par un test qui tourne sur MySQL.
- **Au minimum, tester les migrations et les contraintes sur MySQL.** Même si on ne bascule pas toute la suite, un sous-ensemble ciblé sur les migrations, les index uniques et les clés étrangères attrape l'essentiel des divergences structurelles.

## Le coût, et quand SQLite reste acceptable

Soyons honnêtes : MySQL est plus lent que SQLite en mémoire. `RefreshDatabase` rejoue les migrations, les tables vivent sur disque, chaque test paie ce prix. Sur une grosse suite, la différence se sent, et c'est le vrai argument en faveur de SQLite pour la boucle de développement locale — celle qu'on relance en boucle toute la journée.

Le compromis que je trouve raisonnable : SQLite en local pour la vitesse, MySQL en CI comme garde-fou avant toute fusion. On ne paie la lenteur qu'une fois par push, pas à chaque sauvegarde de fichier. Et si le projet n'emploie aucune spécificité MySQL — pas de JSON, pas de SQL brut, pas de dépendance à la collation ou au mode strict —, rester sur SQLite partout est un choix défendable. À condition d'en être sûr, et de le réévaluer dès que le schéma se complexifie.

## Ce qu'il faut retenir

Une suite verte sur SQLite prouve que le code marche sur SQLite. Rien de plus. Tant que la prod tourne sous MySQL, c'est là que la vérité se joue.

- `laravel new` configure les tests sur SQLite en mémoire : c'est rapide, mais ce n'est pas le moteur de production.
- Les divergences qui mordent : unicité sensible à la casse via la collation, mode strict et `ONLY_FULL_GROUP_BY`, requêtes JSON, types de clés étrangères, fonctions SQL brutes.
- La parade : un job de CI qui rejoue la suite sur un vrai MySQL de la même version que la prod, et du query builder plutôt que du SQL brut moteur-spécifique.
- Le compromis tenable : SQLite en local pour la vitesse, MySQL en CI comme filet — en acceptant la suite plus lente à cet endroit.

Si votre suite n'a jamais vu MySQL, lancez-la une fois dessus. Comme pour le mutation testing, le résultat est souvent une douche froide : quelques tests rouges qui attendaient sagement de tomber en production.
