---
title: "Mutation testing avec Pest 3 : quand 100 % de couverture ne prouve rien"
description: "Une suite verte à 100 % de couverture peut ne rien vérifier. Le mutation testing de Pest 3 débusque les tests trop mous, et voici comment lire les mutants survivants."
pubDate: 2026-06-17
tags: ["laravel", "tests"]
---

Le rapport de couverture affichait 100 %, la suite était verte, et pourtant un bug évident venait de passer en production sur un calcul de remise. La ligne fautive était bien « couverte » : un test l'exécutait. Il ne vérifiait juste rien de ce qu'elle renvoyait. La couverture mesure les lignes traversées, pas les comportements contrôlés — et c'est précisément l'angle mort que le mutation testing, intégré à Pest 3 depuis 2024, vient éclairer.

## Ce que la couverture ne dit pas

Prenons un petit service métier, le genre qu'on trouve dans n'importe quelle app Laravel :

```php
namespace App\Services;

class RemiseCalculator
{
    public function pourcentage(int $quantite): int
    {
        if ($quantite >= 10) {
            return 20;
        }

        if ($quantite >= 5) {
            return 10;
        }

        return 0;
    }
}
```

Et le test censé le protéger :

```php
use App\Services\RemiseCalculator;

covers(RemiseCalculator::class);

it('calcule une remise', function () {
    expect((new RemiseCalculator)->pourcentage(10))->toBeInt();
});
```

Ce test passe. Mieux : il fait grimper la couverture de la première branche à 100 %. La ligne `return 20` est exécutée, donc « couverte ». Mais `toBeInt()` ne vérifie qu'une chose : que le résultat est un entier. Remplacez `return 20` par `return 0`, ou `>= 10` par `> 10` : le test reste vert. On a un indicateur à 100 % qui ne garantit rien sur la valeur calculée. C'est le piège classique de l'assertion trop molle, et la couverture est structurellement incapable de le voir.

## Le principe : casser le code exprès

Le mutation testing renverse la question. Plutôt que de mesurer ce que les tests exécutent, il vérifie ce qu'ils détectent. Pest prend votre code, y introduit de petites altérations — les *mutants* — puis relance la suite contre chaque version mutée :

- un `>=` devient `>`, un `+` devient `-` ;
- un `return 20` devient `return 0` ou `return 21` ;
- une condition est inversée, un `&&` devient `||`, un appel est supprimé.

La logique est simple. Si une mutation casse un comportement réel, **un test devrait échouer**. C'est le but recherché : on dit que le mutant est *tué*. Si au contraire la suite reste verte malgré le code saboté, c'est que personne ne surveillait ce comportement : le mutant *survit*, et il pointe un trou dans vos assertions.

## Mettre Pest 3 en route

Le mutation testing a besoin de savoir quelles lignes chaque test couvre : il lui faut donc un driver de couverture. **Xdebug 3+ ou PCOV** sont les prérequis ; sans l'un des deux, Pest refusera de démarrer. PCOV est nettement plus rapide pour cet usage, Xdebug plus polyvalent si vous l'avez déjà.

Ensuite, on indique à Pest quel code muter. La fonction `covers()` en tête du fichier de test suffit dans la majorité des cas : Pest ne mute que les classes couvertes par les tests concernés. Pour cibler explicitement une ou plusieurs classes indépendamment de la couverture, Pest 3 fournit aussi `mutates()` :

```php
covers(RemiseCalculator::class);
// ou, pour viser explicitement les classes à muter :
mutates(RemiseCalculator::class);
```

Le lancement tient en une option :

```bash
./vendor/bin/pest --mutate
```

Sur une vraie suite, c'est lent — Pest relance les tests une fois par mutant. L'option `--parallel` répartit le travail sur plusieurs processus et change radicalement le temps d'attente :

```bash
./vendor/bin/pest --mutate --parallel
```

## Lire les mutants survivants

En fin de course, Pest affiche un **Mutation Score Indicator** (MSI) : le pourcentage de mutants tués sur l'ensemble des mutants générés. C'est l'indicateur qui compte, et il n'a rien à voir avec la couverture. Sur notre exemple, la couverture est à 100 % et le MSI s'effondre, parce que la plupart des mutations passent inaperçues.

Pest distingue deux familles de survivants, et la nuance est utile :

- **Untested** — aucun test n'exécute la ligne mutée. C'est un trou de couverture pur.
- **Escaped** — la ligne *est* exécutée, mais aucun test ne bronche quand on la sabote. C'est l'assertion trop molle, le cas le plus instructif.

Notre `toBeInt()` produit exactement des mutants *escaped* : Pest signale qu'en remplaçant `return 20` par `return 0`, ou en changeant la borne `>= 10`, la suite reste verte. La correction ne touche pas le code de production — elle muscle les assertions et teste les bornes :

```php
it('accorde 20 % à partir de 10 unités', function () {
    expect((new RemiseCalculator)->pourcentage(10))->toBe(20);
});

it('accorde 10 % entre 5 et 9 unités', function () {
    expect((new RemiseCalculator)->pourcentage(5))->toBe(10);
});

it('n’accorde aucune remise en deçà de 5 unités', function () {
    expect((new RemiseCalculator)->pourcentage(4))->toBe(0);
});
```

`toBe(20)` tue la mutation `return 0`. Tester `pourcentage(5)` et `pourcentage(4)` — les valeurs juste de part et d'autre de la borne — tue la mutation `>= 5` → `> 5`. Le MSI remonte parce que chaque mutation casse maintenant une assertion précise. C'est tout l'intérêt de l'exercice : il vous force à écrire les tests de bornes que la couverture ne réclamait jamais.

## Brancher ça dans la CI

Le mutation testing trouve sa vraie place en intégration continue, comme garde-fou. Quelques options rendent ça tenable :

```bash
./vendor/bin/pest --mutate --parallel --covered-only --min=80
```

- `--min=80` fait échouer le build si le MSI passe sous 80 %. C'est le seuil qui transforme l'indicateur en barrière.
- `--covered-only` restreint les mutations au code déjà couvert, pour ne pas noyer le rapport sous des lignes que personne ne teste encore.
- `--profile` liste les mutations les plus lentes, pratique pour traquer ce qui plombe le temps de build.

Pest met aussi en cache les résultats de mutation : d'une exécution à l'autre, seules les portions de code modifiées sont remutées, ce qui rend les passages suivants bien plus rapides. Reste qu'il faut être lucide sur le coût : sur une grosse suite, un run complet de mutation testing se compte en minutes, pas en secondes. Beaucoup d'équipes le réservent à un job de nuit ou à un seuil sur le code modifié, plutôt que de le coller sur chaque commit.

## Les limites à assumer

Le mutation testing n'est pas magique, et le vendre comme tel serait malhonnête.

D'abord, **c'est lent par nature** : relancer la suite une fois par mutant multiplie mécaniquement le temps d'exécution. `--parallel` et le cache aident, mais sur des milliers de mutants, ça reste un investissement.

Ensuite, **les mutants équivalents existent**. Une mutation peut produire un code dont le comportement est strictement identique à l'original — par exemple modifier une borne sur un chemin mathématiquement inatteignable. Aucun test ne pourra jamais le tuer, parce qu'il n'y a rien à distinguer. Ces faux positifs gonflent artificiellement le nombre de survivants ; il faut savoir les reconnaître et les ignorer, sans courir après un MSI de 100 % qui n'a pas toujours de sens.

Enfin, **le mutation testing ne crée pas de tests, il juge ceux qui existent**. Sur du code sans aucun test, il n'a rien à muter d'utile. Ce n'est pas un substitut à la couverture, mais une couche au-dessus : la couverture vous dit où vous n'avez pas de tests, le mutation testing vous dit lesquels de vos tests ne servent à rien.

## Ce qu'il faut retenir

Une suite verte à 100 % de couverture peut ne rien vérifier — la couverture compte les lignes exécutées, pas les comportements contrôlés. Pest 3 comble ce trou en sabotant votre code pour voir si vos tests réagissent.

- Lancez `./vendor/bin/pest --mutate --parallel`, avec Xdebug 3+ ou PCOV installé et `covers()` (ou `mutates()`) pour cibler le code.
- Lisez le MSI, pas la couverture : un mutant *escaped* dénonce une assertion trop molle, un mutant *untested* un trou de couverture.
- Corrigez en testant les valeurs exactes et les bornes, là où `toBeInt()` se contentait du vague.
- En CI, posez un seuil avec `--min=` et `--covered-only`, mais acceptez le coût en temps et les mutants équivalents.

Lancez-le une fois sur une classe métier que vous croyez bien testée. Le score qui s'affiche est souvent une douche froide salutaire — et la meilleure façon de découvrir que « 100 % de couverture » ne voulait pas dire ce que vous pensiez.
