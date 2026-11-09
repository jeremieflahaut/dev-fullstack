---
title: "Deadlocks InnoDB sous Laravel : comprendre le SQLSTATE[40001] avant de relancer"
description: "Sous charge, Laravel remonte des SQLSTATE[40001] « deadlock found ». Comment reproduire un deadlock InnoDB, le lire, puis le désamorcer durablement — sans dégainer FOR UPDATE partout."
pubDate: 2026-11-09
tags: ["laravel", "mysql"]
---

Tout va bien jusqu'au jour où la charge monte. Deux jobs partent en parallèle, deux requêtes touchent les mêmes lignes, et Laravel se met à cracher des `SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock`. Le réflexe est de relancer la transaction et de passer à autre chose. C'est une rustine : un deadlock n'est pas un aléa, c'est le symptôme d'un ordre de verrouillage incohérent. Voici comment je le reproduis, le lis, puis l'élimine — plutôt que de le relancer en boucle.

## Deadlock n'est pas lock wait timeout

Les deux erreurs se ressemblent de loin mais n'ont rien à voir. Un **lock wait timeout** (`1205`), c'est une transaction qui attend un verrou trop longtemps et abandonne au bout de `innodb_lock_wait_timeout` secondes (50 par défaut). Personne n'est fautif en soi : une transaction tient le verrou trop longtemps, l'autre patiente puis renonce.

Un **deadlock** (`1213`), lui, est une impasse mutuelle : la transaction A tient le verrou X et veut Y, pendant que la transaction B tient Y et veut X. Aucune des deux ne peut avancer. InnoDB détecte le cycle immédiatement et sacrifie l'une des deux — la « victime » — en annulant sa transaction, pour que l'autre passe. C'est une bonne nouvelle : InnoDB fait le travail de détection pour vous, et il le fait vite. La victime remonte côté Laravel sous la forme d'une `Illuminate\Database\QueryException` enveloppant le code `1213`.

La distinction compte parce que le remède diffère : un timeout se traite souvent en raccourcissant la transaction qui tient le verrou ; un deadlock se traite en **imposant un ordre de verrouillage stable**.

## Reproduire le cas minimal

Rien ne vaut un deadlock qu'on déclenche à la demande. Prenons une table de comptes et deux virements croisés : l'un débite le compte 1 puis crédite le 2, l'autre débite le 2 puis crédite le 1.

```sql
CREATE TABLE comptes (
    id INT PRIMARY KEY,
    solde INT NOT NULL
) ENGINE=InnoDB;

INSERT INTO comptes (id, solde) VALUES (1, 1000), (2, 1000);
```

Ouvrez deux sessions MySQL. Dans la première :

```sql
-- Session A
START TRANSACTION;
UPDATE comptes SET solde = solde - 100 WHERE id = 1; -- verrouille la ligne 1
```

Dans la seconde, avant que A ne continue :

```sql
-- Session B
START TRANSACTION;
UPDATE comptes SET solde = solde - 100 WHERE id = 2; -- verrouille la ligne 2
```

Chaque session tient maintenant une ligne. Demandez-leur de verrouiller l'autre, dans l'ordre inverse :

```sql
-- Session A (s'ajoute à sa transaction en cours)
UPDATE comptes SET solde = solde + 100 WHERE id = 2; -- attend le verrou de B
```

```sql
-- Session B
UPDATE comptes SET solde = solde + 100 WHERE id = 1; -- ferme le cycle
```

À l'instant où B réclame la ligne 1, InnoDB voit le cycle et tue l'une des deux :

```
ERROR 1213 (40001): Deadlock found when trying to get lock;
try restarting transaction
```

Le même scénario, en Laravel, se produit dès que deux jobs traitent des virements dans des sens opposés — exactement le genre de concurrence qu'une queue parallèle rend quotidienne.

## Lire le diagnostic

Une fois le deadlock produit, MySQL garde le dernier en mémoire. On le lit avec :

```sql
SHOW ENGINE INNODB STATUS\G
```

Dans la sortie, la section `LATEST DETECTED DEADLOCK` détaille les deux transactions, les verrous que chacune tient (`HOLDS THE LOCK(S)`) et celui qu'elle attend (`WAITING FOR THIS LOCK TO BE GRANTED`), ainsi que la requête exacte de chaque côté. C'est là qu'on identifie quelles lignes et quel index sont en jeu — l'information qui dira si le verrou était ciblé ou bien trop large.

Problème : cette commande ne retient que le **dernier** deadlock. En production, les cas se chevauchent et vous en perdez. Activez donc leur journalisation continue :

```sql
SET GLOBAL innodb_print_all_deadlocks = ON;
```

Chaque deadlock atterrit désormais dans le log d'erreurs MySQL, horodaté. Pour le rendre permanent, ajoutez `innodb_print_all_deadlocks = 1` au fichier de configuration ; le surcoût est négligeable et le gain en visibilité, considérable.

## Le piège Laravel : verrouiller tard et dans le désordre

En Eloquent, le scénario typique ressemble à ça :

```php
$compte = Compte::where('id', $id)->first(); // SELECT sans verrou
// ... logique métier ...
$compte->solde -= 100;
$compte->save(); // UPDATE : c'est ici seulement qu'un verrou est posé
```

Le verrou n'arrive qu'au `save()`, après coup. Entre le `first()` et le `save()`, deux jobs peuvent lire la même valeur, puis réclamer les verrous dans n'importe quel ordre. La parade tient en deux règles : **verrouiller tôt** et **dans un ordre stable**. On charge les lignes avec `lockForUpdate()`, triées par clé primaire, pour que toutes les transactions acquièrent les verrous dans la même séquence :

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($idSource, $idDest, $montant) {
    // Toujours verrouiller dans l'ordre croissant des id : l'ordre
    // identique pour toutes les transactions brise les cycles.
    $ids = collect([$idSource, $idDest])->sort()->values();

    $comptes = Compte::whereIn('id', $ids)
        ->orderBy('id')
        ->lockForUpdate()
        ->get()
        ->keyBy('id');

    $comptes[$idSource]->decrement('solde', $montant);
    $comptes[$idDest]->increment('solde', $montant);
});
```

Le `orderBy('id')` combiné au `lockForUpdate()` garantit que la session A et la session B demandent la ligne 1 avant la ligne 2, toujours. Le cycle de l'exemple précédent devient impossible : les deux transactions font la queue sur la même première ligne, l'une passe, l'autre attend — un simple lock wait, plus un deadlock.

## Un index pour réduire la portée des verrous

InnoDB ne verrouille pas « des lignes » dans l'absolu : il verrouille des **entrées d'index**. Si votre `UPDATE ... WHERE statut = 'en_attente'` s'appuie sur une colonne sans index, MySQL fait un balayage complet et pose un verrou sur chaque ligne examinée — bien au-delà de celles réellement modifiées. La surface de contention explose, et avec elle la probabilité de deadlock.

```sql
-- Sans index sur statut, cet UPDATE verrouille toute la table le temps du scan
UPDATE commandes SET statut = 'traitee' WHERE statut = 'en_attente';

-- L'index restreint le verrou aux seules lignes concernées
CREATE INDEX idx_commandes_statut ON commandes (statut);
```

L'index ne sert pas qu'à accélérer la lecture : il **limite le périmètre des verrous**. C'est souvent le correctif le plus rentable, car il réduit les deadlocks sans toucher au code applicatif. Vérifiez avec `EXPLAIN` que vos `UPDATE` et `SELECT ... FOR UPDATE` ciblent bien un index, et non un `type: ALL`.

## Gérer la reprise proprement

Même avec un ordre stable, un deadlock résiduel reste possible sous forte concurrence. Laravel prévoit le cas : le second argument de `DB::transaction()` est un nombre de tentatives. Sur un deadlock, Laravel annule et rejoue automatiquement le callback :

```php
DB::transaction(function () use ($idSource, $idDest, $montant) {
    // ... le virement verrouillé dans l'ordre ...
}, attempts: 3);
```

Une condition impérative : le callback doit être **idempotent**. S'il est rejoué, il doit produire le même résultat sans double effet. Un `increment('solde', ...)` relancé sur une transaction annulée est sûr, car l'annulation a tout défait avant le nouvel essai. En revanche, ne mettez jamais dans la transaction un effet de bord non transactionnel — envoi d'email, appel HTTP, dispatch de job — car lui ne sera pas annulé, et un retry l'exécutera deux fois.

Si vous voulez un contrôle plus fin (backoff, métrique), un retry maison ciblé sur le seul code `1213` fait l'affaire :

```php
use Illuminate\Database\QueryException;

$essais = 0;
while (true) {
    try {
        DB::transaction($callback);
        break;
    } catch (QueryException $e) {
        // getCode() renvoie le SQLSTATE : 40001 = deadlock (code MySQL 1213).
        // On ne rejoue que celui-là.
        if ($e->getCode() === '40001' && ++$essais < 3) {
            usleep(random_int(50, 200) * 1000); // backoff aléatoire
            continue;
        }
        throw $e;
    }
}
```

Le `usleep` avec un délai aléatoire évite que les deux transactions victimes ne se relancent à la milliseconde près et ne se redéadlockent en rythme.

## FOR UPDATE n'est pas une baguette magique

La tentation, après un deadlock, est de parsemer le code de `lockForUpdate()`. Mauvaise idée : chaque verrou pessimiste **sérialise** les accès et augmente la contention. Vous échangez des deadlocks contre des lock wait timeouts et une latence qui grimpe. Quelques garde-fous que je m'impose :

- **Des transactions courtes.** Une transaction ne doit contenir que des requêtes SQL, jamais un appel réseau, un calcul lourd ou un `dispatch()`. Chaque milliseconde de verrou tenu est une occasion de collision.
- **Verrouiller le strict nécessaire**, dans un ordre stable, plutôt que « tout, au cas où ».
- **Préférer un UPDATE atomique** (`decrement`, `WHERE solde >= ?`) à un lire-puis-écrire quand c'est possible : pas de fenêtre entre lecture et écriture, donc pas de verrou à tenir.

Le lien avec la concurrence des queues est direct — paralléliser des jobs multiplie mécaniquement les accès simultanés — mais l'angle reste ici 100 % transactionnel : c'est au niveau d'InnoDB que le problème se résout.

## Ce qu'il faut retenir

Un `SQLSTATE[40001]` n'est pas un caprice de MySQL, c'est un diagnostic. Avant de relancer bêtement :

- **Distinguez `1213` (deadlock) de `1205` (lock wait timeout)** : le remède n'est pas le même.
- **Lisez le deadlock** dans `SHOW ENGINE INNODB STATUS`, et activez `innodb_print_all_deadlocks` pour ne plus en perdre.
- **Imposez un ordre de verrouillage stable** (`orderBy('id')->lockForUpdate()`) : c'est la cause racine de la plupart des cycles.
- **Indexez les colonnes filtrées** par vos `UPDATE` et `SELECT ... FOR UPDATE` pour réduire la portée des verrous.
- **Gardez les transactions courtes et idempotentes**, et ne rejouez que le code `1213`, avec un léger backoff aléatoire.

Le retry reste utile en dernier recours, mais il traite le symptôme. Les quatre autres points traitent la cause — et c'est là que les deadlocks disparaissent pour de bon.
