---
title: "Diversifier un blog généré par IA : imposer le pilier avant de chercher le sujet"
description: "Un agent qui choisit et rédige ses sujets dérive vers le pilier dominant et finit par cannibaliser ses propres pages. La parade : une étape déterministe en amont."
pubDate: 2026-11-25
tags: ["ia", "seo"]
---

Ce blog est rédigé par un agent IA, et pendant un temps je le laissais choisir ses sujets tout seul. Le résultat avait l'air sain : des articles propres, bien construits, publiés au rythme voulu. Jusqu'à ce que j'ouvre la Search Console et que je tombe sur un symptôme gênant : deux URL du même site se disputaient la même requête. L'agent n'écrivait pas de mauvais articles — il écrivait les mêmes, encore et encore, sous des titres différents. Voici pourquoi, et l'étape déterministe qui a réglé le problème à la racine.

## Le symptôme : deux pages du même site sur une requête

La cannibalisation, en SEO, c'est quand plusieurs pages d'un même site visent la même intention de recherche et se font concurrence au lieu de s'additionner. Google hésite entre elles, en classe une mal, et le signal se dilue.

Sur ce blog, le cas est flagrant. La requête « restructuration du code laravel » positionne **à la fois** un article dédié, aux alentours de la 40ᵉ place, **et** la page racine `/blog/`, bien plus loin. Même scénario sur « réusinage de code laravel » : l'article et le listing se marchent dessus. Deux pages du même domaine sur un seul mot-clé, c'est de l'énergie gaspillée — et c'est mon propre site qui me le montrait.

Ce n'est pas un hasard. C'est la conséquence mécanique d'un agent libre de choisir son sujet.

## Pourquoi un agent libre dérive vers le pilier dominant

Quand je demandais à l'agent « trouve un bon sujet pour le prochain article », il faisait une recherche, regardait ce qui « marchait déjà » sur le blog, et proposait… une variation de ce qui était le plus représenté. Mon pilier le plus fourni — la méthode et l'outillage de dev — comptait quatre articles ; le modèle y revenait sans cesse, parce que c'est là que le terrain semblait le plus porteur.

C'est un biais prévisible. Un LLM à qui on tend un contexte en déduit la continuité la plus probable, et la continuité la plus probable d'un blog qui parle beaucoup d'un thème, c'est encore ce thème. Pire : si je lui soufflais une idée, il la confirmait presque toujours, en trouvant d'excellentes raisons de l'écrire. Le modèle est un bon avocat du sujet qu'on lui présente, pas un arbitre impartial de la diversité du catalogue.

Résultat : le catalogue se creuse là où il est déjà profond, les nouveaux articles frôlent les anciens, et la cannibalisation s'installe. Demander « diversifie, s'il te plaît » n'y change rien — c'est demander au biais de se corriger lui-même.

## Séparer le choix du pilier de la recherche du sujet

La leçon centrale tient en une phrase : **le choix du pilier ne doit pas être un jugement du LLM.**

J'ai découpé ce qui était une seule décision floue en deux étapes distinctes :

1. **Quel pilier ?** — une question de répartition, qui se répond en comptant. Déterministe.
2. **Quel sujet dans ce pilier ?** — une question d'angle et d'actualité, où la recherche web et le modèle sont légitimes.

L'erreur était de confier la première au modèle. Compter des articles et désigner le moins couvert, ça ne demande aucune intelligence — juste une table et une addition. Autant en faire un garde-fou mécanique, exécuté **avant** que l'agent n'ait vu le moindre résultat de recherche.

## L'étape déterministe : compter, puis imposer

J'ai défini huit piliers éditoriaux et je maintiens à la main un classement `slug → pilier`. La classification manuelle est le seul point de friction, mais c'est un fichier que je relis en trente secondes à chaque publication.

Le script, lui, est bête et reproductible. Il charge le classement, compte, et impose le pilier le moins couvert :

```python
import json
import collections
import pathlib

PILIERS = {
    "1-backend": "Backend Laravel/PHP",
    "2-donnees": "Données & stockage",
    "3-infra": "Infra, conteneurs, CI/CD",
    "4-tests": "Tests & qualité",
    "5-archi": "Architecture & conception",
    "6-front": "Frontend léger",
    "7-methode": "Méthode & outillage dev",
    "8-rex": "Retours d'expérience",
}

# Maintenu à la main : un article de fond = un pilier.
classement = json.loads(pathlib.Path("classement.json").read_text())

comptes = collections.Counter({pilier: 0 for pilier in PILIERS})
comptes.update(classement.values())

for pilier, n in sorted(comptes.items(), key=lambda kv: kv[1]):
    print(f"{n:2d}  {PILIERS[pilier]}")

cible = min(comptes, key=comptes.get)
print(f"\nPilier imposé : {PILIERS[cible]} ({comptes[cible]} article(s))")
```

Sur mon catalogue du moment, la sortie est sans appel :

```text
 0  Retours d'expérience
 1  Frontend léger
 2  Données & stockage
 2  Infra, conteneurs, CI/CD
 2  Tests & qualité
 2  Architecture & conception
 3  Backend Laravel/PHP
 4  Méthode & outillage dev

Pilier imposé : Retours d'expérience (0 article(s))
```

L'agent ne choisit plus : il **reçoit** un pilier. Tant qu'un pilier est vide, on ne touche pas au dominant — la règle est dans le script, pas dans le prompt. Et la contrainte est injectée en amont de toute recherche web, pour que le modèle cherche un sujet *à l'intérieur* du pilier imposé au lieu de remettre le choix du pilier en cause. D'ailleurs, cet article-ci est né de cette sortie : « Retours d'expérience » était à zéro, donc c'était lui, point.

Pourquoi déterministe plutôt qu'une consigne de diversité bien tournée ? Parce qu'un garde-fou qui dépend du jugement du modèle est un garde-fou que le modèle peut contourner de bonne foi. `min(comptes, key=comptes.get)` ne se laisse pas convaincre que le pilier dominant mérite « juste un article de plus ».

## Affiner l'angle avec la Search Console

Le pilier est fixé par le comptage ; reste à choisir l'angle **dans** ce pilier. C'est là que la Search Console entre en jeu — et seulement là. Elle ne sert jamais à rechoisir un pilier déjà servi ; elle affine la cible une fois le pilier verrouillé.

J'exporte le rapport Performances (requêtes × pages) et je cherche deux signaux :

- une requête à fortes impressions mais en position supérieure à 10 : une page « presque gagnée », à renforcer ;
- une requête qui rapporte des impressions sans page dédiée : un trou de contenu à combler.

Un script minimal suffit à sortir les premières de l'export CSV :

```python
import csv

def presque_gagnees(export, seuil_impressions=50):
    """Requêtes à fortes impressions coincées au-delà de la 1re page."""
    with open(export, newline="", encoding="utf-8") as f:
        lignes = csv.reader(f)
        next(lignes)  # en-tête : requête, clics, impressions, CTR, position
        for requete, _clics, impressions, _ctr, position in lignes:
            if int(impressions) >= seuil_impressions and float(position) > 10:
                yield requete, int(impressions), round(float(position))

for requete, impressions, position in sorted(
    presque_gagnees("performance.csv"),
    key=lambda t: t[1],
    reverse=True,
):
    print(f"{impressions:4d} impressions · pos ~{position} · {requete}")
```

La donnée raconte aussi l'histoire de ma cannibalisation : les requêtes les plus porteuses de l'export tournent toutes autour du backend et de l'architecture — mes piliers déjà bien garnis. La Search Console *confirme* que ces piliers performent, mais ne peut pas pour autant justifier d'y retourner. Elle étaye le choix d'angle à l'intérieur du pilier imposé ; elle ne vote pas sur le pilier. Cette subordination est volontaire : sans elle, les impressions ramèneraient toujours vers le thème dominant, et on retomberait sur le problème de départ.

## Les limites que je ne cache pas

Ce garde-fou règle un problème précis, pas tous les problèmes — et il a ses angles morts.

- **À faible volume, le signal est bruité.** Avec dix-sept articles, l'écart entre un pilier à quatre et un pilier à deux ne veut pas dire grand-chose statistiquement. Le comptage est utile surtout pour repérer les piliers à **zéro**, nettement moins pour arbitrer entre un pilier à deux et un à trois.
- **La classification est parfois ambiguë.** Un article à cheval sur deux piliers fausse le comptage selon la case qu'on lui attribue. Je tranche à la main, et ce choix reste subjectif.
- **Diversifier ne garantit pas la qualité.** Imposer un pilier vide évite les doublons ; ça ne produit pas un bon article pour autant. Le vrai goulot d'étranglement reste la relecture humaine — l'étape déterministe choisit *de quoi* parler, pas *si c'est bon*.

Autrement dit : le comptage protège de la dérive thématique, pas de la médiocrité. Ces deux filtres sont distincts, et seul le second me demande encore de lire.

## Ce qu'il faut retenir

Un agent libre de choisir **et** de rédiger ses sujets dérive vers le pilier dominant et finit par cannibaliser ses propres pages. La parade n'est pas une meilleure consigne de diversité — c'est de sortir une décision du jugement du modèle.

- **Séparez le choix du pilier de la recherche du sujet.** Le premier est une question de comptage ; le second, une question d'angle.
- **Rendez le choix du pilier déterministe.** Classez, comptez, imposez le pilier le moins couvert avant toute recherche web. Un `min()` ne se laisse pas convaincre.
- **Branchez la Search Console sur l'angle, pas sur le pilier.** Elle affine la cible à l'intérieur du pilier imposé ; elle ne ramène jamais vers un pilier déjà servi.
- **Gardez la relecture humaine.** La mécanique évite les doublons ; elle ne juge pas la qualité. Ce travail-là reste le vôtre.

Un LLM est un excellent rédacteur et un piètre arbitre de sa propre diversité. Donnez-lui le pilier ; laissez-lui le sujet.
