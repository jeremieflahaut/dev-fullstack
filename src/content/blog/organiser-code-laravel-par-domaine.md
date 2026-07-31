---
title: "Organiser son code Laravel par domaine, sans sauter dans le modular monolith"
description: "Quand une app Laravel grossit, la mode pousse vers le modular monolith, le DDD ou l'hexagonal. L'étape intermédiaire — ranger app/ par domaine — tient bien plus longtemps."
pubDate: 2026-07-31
tags: ["laravel", "php", "architecture"]
---

Une app Laravel qui grossit finit toujours par déclencher la même conversation : « il faudrait une vraie architecture ». Et les articles qui remontent en premier vendent tous la même chose — un modular monolith avec un dossier `modules/` à la racine, ou une couche DDD, ou de l'hexagonal avec ses ports et ses adapters. C'est séduisant sur un schéma. En pratique, on saute une étape intermédiaire qui coûte presque rien et qui repousse le besoin de tout ça de plusieurs mois. Cet article défend la retenue : ranger par domaine dans `app/`, et n'ériger une frontière de module que sur des signaux concrets.

## Ranger par type technique tient plus longtemps qu'on ne le dit

Une app Laravel neuve range son code par type technique : `Http/Controllers`, `Models`, `Services`, `Jobs`. C'est la structure par défaut, et on la présente vite comme le problème à résoudre — « regarde, ton dossier `Models` a quarante fichiers, il est temps de moduliser ». Sauf que la taille d'un dossier est un faux signal. Un dossier `Models/` à cinquante entrées se navigue très bien tant que l'IDE saute au fichier par son nom : personne ne scrolle un dossier à la main.

Le vrai signal, c'est le couplage. Il se mesure autrement : combien de dossiers de types différents faut-il ouvrir pour livrer un changement métier ? Tant qu'ajouter une règle de facturation touche un contrôleur, un modèle et un service qui parlent tous de facturation, l'organisation par type tient. Le jour où ce changement oblige à toucher cinq dossiers qui n'ont rien à voir entre eux, et à retenir dans sa tête quels fichiers vont ensemble, c'est le couplage qui parle — pas le nombre de fichiers.

Autrement dit, on peut rester en organisation par type bien plus longtemps que les tutos ne le laissent croire. Ce qui pousse à en sortir, ce n'est pas un compteur, c'est le moment où le code d'un même sujet est éparpillé et qu'on passe son temps à le rassembler mentalement.

## L'étape intermédiaire qu'on saute

Avant le module, avant le package, avant la couche DDD, il y a une étape sous-estimée : regrouper par domaine **à l'intérieur de `app/`**. On garde le même projet, la même config, et on déplace les fichiers pour que chaque domaine ait son dossier avec ce qu'il lui faut dedans.

Voici le point de départ, rangé par type :

```
app/
  Http/
    Controllers/
      InvoiceController.php
      ProductController.php
  Models/
    Invoice.php
    Product.php
  Actions/
    GenererFacture.php
    PublierProduit.php
  Services/
    TaxeService.php
```

Et la même chose regroupée par domaine :

```
app/
  Billing/
    InvoiceController.php
    Invoice.php
    GenererFacture.php
    TaxeService.php
  Catalog/
    ProductController.php
    Product.php
    PublierProduit.php
```

Rien de magique ici : on a juste déplacé des fichiers et changé leur `namespace`. Le point important est le coût de l'opération, et il est quasi nul. Le `composer.json` d'une app Laravel mappe déjà `App\` sur `app/` en PSR-4 :

```json
"autoload": {
    "psr-4": {
        "App\\": "app/"
    }
}
```

Comme le namespace suit l'arborescence, `App\Billing\Invoice` se charge tout seul dès que le fichier est dans `app/Billing/Invoice.php`. Pas de nouvelle entrée d'autoload, pas de `composer dump-autoload` à surveiller, pas de ServiceProvider. On corrige les `namespace` et les `use`, et c'est fini. Le contrôleur reste un contrôleur, le modèle reste un modèle — on n'a rien inventé, on a juste mis côte à côte ce qui parle du même sujet.

Le gain est immédiat : le changement de facturation évoqué plus haut vit maintenant dans un seul dossier. On lit un domaine en ouvrant un répertoire, sans reconstituer le puzzle entre `Controllers`, `Models` et `Services`.

## Les trois signaux qui justifient une vraie frontière

Regrouper par domaine, ce n'est pas encore isoler un module. Un dossier `app/Billing/` reste libre d'appeler n'importe quelle classe de `app/Catalog/` : rien ne l'en empêche, la frontière est visuelle, pas technique. Passer au module isolé — avec un ServiceProvider dédié, une config d'autoload propre, une API publique et un `internal` invisible du reste — a un coût réel. Je ne paie ce coût que sur trois signaux concrets.

**Deux personnes se disputent le même fichier.** Quand un même service devient le point de rendez-vous de plusieurs sujets et que les modifications s'y télescopent en permanence, c'est que deux domaines cohabitent dans un fichier qui devrait être coupé en deux. La frontière sert alors à donner à chacun son terrain.

**Des dépendances cycliques apparaissent.** `Billing` appelle `Catalog`, qui rappelle `Billing`. Tant que les dépendances vont dans un sens, l'organisation par domaine suffit. Le cycle, lui, signale que les responsabilités ont fui d'un côté à l'autre : une frontière explicite force à choisir qui dépend de qui, et souvent à extraire ce qui est réellement partagé.

**On a besoin d'une règle d'accès.** Le jour où l'on veut garantir qu'un domaine ne tape jamais directement dans les tables d'un autre — qu'il passe par une porte d'entrée plutôt que par une requête Eloquent sur le modèle du voisin —, il faut une frontière que le code puisse faire respecter. Un simple dossier ne l'impose pas ; un module avec une API publique, si.

Tant qu'aucun de ces trois signaux n'est là, le module isolé résout un problème qu'on n'a pas encore.

## Ce qu'une frontière ajoute au budget

L'argument de la retenue tient parce qu'une frontière n'est pas gratuite. Ce qu'elle ajoute, dès qu'on la pose :

- **Un ou plusieurs ServiceProvider** à enregistrer, à charger, à tenir à jour quand le module bouge.
- **De la config d'autoload** si le module sort de `app/` : une entrée PSR-4 par module, et le `dump-autoload` qui va avec.
- **Un coût de navigation** : ce qui était à un `cd` de distance devient un aller-retour entre l'API publique d'un module et ses détails cachés.
- **Des tests transverses** plus lourds : dès qu'un scénario traverse deux modules, il faut monter les deux et leurs points de contact.
- **Des migrations éparpillées** si chaque module range les siennes de son côté : pratique pour la clôture d'un module, moins pour avoir une vue d'ensemble du schéma.

Aucun de ces coûts n'est rédhibitoire quand la frontière est justifiée — c'est le prix de l'isolement, et il se rentabilise. Payé trop tôt, en revanche, il n'achète rien : on porte la cérémonie d'un module sans le problème qu'un module résout.

## Poser une frontière légère quand c'est justifié

Quand un des trois signaux se manifeste, la bonne réponse n'est pas forcément le module complet avec package et couches. La version minimale suffit souvent : un seul point d'entrée public par domaine, et tout le reste marqué comme interne.

Concrètement, chaque domaine expose une façade — une Action ou un petit service — que les autres domaines sont seuls autorisés à appeler. Le reste des classes du domaine ne s'appelle plus depuis l'extérieur.

```php
namespace App\Billing;

use App\Catalog\CatalogApi;

class BillingApi
{
    public function __construct(
        private GenererFacture $generer,
        private CatalogApi $catalog,
    ) {}

    public function facturerCommande(int $commandeId): Invoice
    {
        $lignes = $this->catalog->lignesDeCommande($commandeId);

        return $this->generer->handle($lignes);
    }
}
```

`Catalog` n'expose que `CatalogApi` ; `Billing` ne connaît rien de l'intérieur de `Catalog`, pas même ses modèles. Le contrat entre domaines tient en une classe par domaine, et le couplage passe par une porte au lieu de traverser les murs. Les Actions qui font le travail derrière cette façade, et l'enchaînement de leurs étapes, sont des sujets que j'ai traités ailleurs — le [pattern Action](/blog/pattern-action-laravel/) pour la brique unitaire, le [pattern Pipeline](/blog/pattern-pipeline-laravel/) pour l'orchestration — et ils s'assemblent naturellement derrière une API de domaine sans qu'il y ait rien de neuf à réexpliquer.

Cette frontière-là ne coûte presque rien : une classe publique, une convention respectée à la revue de code. Elle donne l'essentiel de l'isolement — un point de contrôle unique entre domaines — sans le ServiceProvider ni le package qu'on ne sortira que si le besoin monte encore.

## Ce qu'il faut retenir

La progression saine d'une app Laravel qui grossit n'est pas « type technique » puis directement « modular monolith ». Il y a une marche intermédiaire, et elle porte l'essentiel du gain pour un coût quasi nul :

1. **Rangez par domaine dans `app/`** dès que le code d'un même sujet est éparpillé. Le PSR-4 est déjà en place, l'opération se résume à déplacer des fichiers et corriger des `namespace`.
2. **Mesurez le couplage, pas la taille des dossiers.** Un dossier plein n'est pas un problème ; un changement métier qui touche cinq dossiers sans lien en est un.
3. **N'extrayez un module que sur signal** : deux personnes qui se disputent un fichier, une dépendance cyclique, un besoin de règle d'accès. Rien de tout ça, pas de module.
4. **Commencez la frontière au plus léger** : une API publique par domaine, le reste interne, avant de sortir ServiceProvider et package.

La règle tient en une phrase : organisez par domaine, et n'érigez un module que le jour où deux domaines se battent pour le même fichier. Tout le reste — les ports, les adapters, le dossier `modules/` au jour un — est une réponse à un problème qu'on n'a peut-être jamais.
