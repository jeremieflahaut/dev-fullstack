---
title: "Une rétro de mes sessions d'agent, sans halluciner le bilan"
description: "Construire une rétrospective de ses sessions Claude Code sans inventer le bilan : un extracteur déterministe, une synthèse LLM cadrée, une validation humaine."
pubDate: 2026-07-09
tags: ["ia"]
---

En fin de semaine, je veux savoir ce qui a coincé dans mes sessions d'agent de code : où j'ai repris la main, quelles consignes j'ai répétées, quels réflexes il me manque. Le geste naïf est de demander à l'agent lui-même : « fais-moi une rétro de la semaine ». La réponse est fluide, structurée, plausible — et en partie inventée. Le modèle comble les trous et me rend un bilan que je ne peux pas vérifier. La raison est mécanique : je lui demande un jugement (« qu'est-ce qui a foiré ? ») sans lui donner de quoi l'appuyer.

## Pourquoi ne pas demander la rétro à l'agent

« Ce qui a foiré cette semaine » n'est pas un fait qu'on récupère avec un `grep` : c'est un jugement. Un LLM à qui on demande un jugement sans matière factuelle produit un texte vraisemblable — d'où des frictions qui sonnent juste mais que rien n'atteste.

La règle que j'applique à ce que j'automatise avec un agent : ne boucler en autonomie que sur du **déterministe** — une suite de tests, un build, un linter, des verdicts reproductibles. Une rétro n'entre pas dans ce cadre, puisque son livrable est subjectif. Plutôt que d'y renoncer, je repousse le déterministe le plus loin possible et je borde ce qui reste. D'où une architecture en trois couches : un extracteur factuel, une synthèse cadrée, une validation humaine.

## Couche 1 — un extracteur déterministe

La matière première existe : mes transcripts de sessions, stockés localement. Un script Python, `extract_frictions.py`, les balaie sur une fenêtre glissante et n'émet qu'un JSON distillé. Aucun LLM ici, juste de la lecture de texte et des règles fixes — donc reproductible et auditable.

```bash
python3 extract_frictions.py --days 7 > frictions.json
```

Trois principes gouvernent ce script :

- **Tout est local et en lecture seule.** Il lit les transcripts sur ma machine, ne les envoie nulle part, n'en modifie aucun.
- **Il n'émet jamais le transcript brut.** Question de confidentialité : la sortie est une distillation, pas une copie. Ce qui part vers la couche suivante est déjà réduit à l'essentiel.
- **Il ne capture que du factuel**, sous trois signaux :

1. les **corrections** : un recadrage en langage naturel (« non, pas comme ça », « je t'avais dit de… ») accompagné d'un court extrait de ce que l'agent faisait juste avant ;
2. les **erreurs d'outil** (`tool_errors`), agrégées par outil et par signature d'erreur, pour voir ce qui casse en boucle ;
3. les **rejets** (`rejections`) : les actions que j'ai bloquées au moment de la demande de permission.

La détection d'une correction reste volontairement bête — quelques marqueurs, aucun modèle :

```python
import json, pathlib

PUSHBACKS = ("non", "pas comme ça", "je t'avais dit", "arrête", "plutôt")

def corrections(transcript: pathlib.Path):
    tours = [json.loads(l) for l in transcript.read_text().splitlines() if l]
    for precedent, tour in zip(tours, tours[1:]):
        if tour["role"] != "user":
            continue
        texte = tour["content"].lower()
        if any(marqueur in texte for marqueur in PUSHBACKS):
            yield {
                "signal": "correction",
                "message": tour["content"][:200],
                "faisait_avant": precedent["content"][:200],
            }
```

Le résultat est un JSON compact et vérifiable — chaque entrée pointe vers un moment réel de la session :

```json
{
  "fenetre_jours": 7,
  "corrections": [
    {
      "signal": "correction",
      "message": "non, pas de add -A, stage fichier par fichier",
      "faisait_avant": "git add -A && git commit -m ..."
    }
  ],
  "tool_errors": [
    { "outil": "Bash", "signature": "make: command not found", "occurrences": 2 }
  ],
  "rejections": [
    { "action": "suppression de src/legacy/", "compte": 1 }
  ]
}
```

À ce stade, aucune interprétation : juste des faits que je pourrais retrouver à la main dans mes transcripts.

## Couche 2 — une synthèse LLM cadrée

Vient seulement maintenant le modèle. On lui passe le JSON distillé, pas les transcripts, et son travail est précis : **regrouper en thèmes récurrents** plutôt que réciter la liste brute. Quatre corrections autour du staging git et deux erreurs `make: command not found` deviennent deux thèmes — « l'agent ne connaît pas mes conventions de commit », « l'environnement n'a pas `make` » — au lieu de six lignes isolées.

Reste un point à border : le **caveat de précision**. L'extracteur est calibré pour privilégier le rappel sur la précision — mieux vaut sur-capturer que rater une friction. Conséquence : dans mes sessions d'apprentissage, où je pose des questions ouvertes ou joue le candide, mes messages *ressemblent* à des corrections sans en être. « Non mais pourquoi tu fais ça ? » est une question, pas un reproche.

Ce tri, l'extracteur ne peut pas le faire : il n'a pas le contexte. C'est au modèle de l'exercer, avec une consigne explicite — distinguer une vraie friction d'une interaction socratique, et écarter les faux positifs plutôt que de gonfler le bilan. Le factuel reproductible reste en bas, le jugement contextuel au-dessus.

## Couche 3 — la gate humaine

La synthèse ne déclenche rien toute seule : elle propose, je dispose. Chaque friction confirmée se traduit en un **correctif typé**, lisible et réversible, à prendre ou à laisser :

- une **mémoire** (un fait à retenir sur mes préférences) ;
- une **règle dans `CLAUDE.md`** (une convention de projet) ;
- un **ajustement de skill ou d'agent** ;
- un **hook** (pour automatiser un garde-fou) ;
- une **pré-approbation de permission** (pour ne plus être sollicité sur une action sûre) ;
- un **manque d'environnement** à combler (`make` absent du conteneur, par exemple).

Concrètement, la couche 2 me rend une liste de ce genre :

```json
[
  {
    "theme": "conventions de commit non respectées",
    "correctif": "regle-claude-md",
    "proposition": "Interdire `git add -A`; stager fichier par fichier.",
    "preuve": "4 corrections cette semaine sur le staging"
  },
  {
    "theme": "make indisponible",
    "correctif": "gap-environnement",
    "proposition": "Ajouter make à l'image Docker de dev.",
    "preuve": "tool_errors: make: command not found x2"
  }
]
```

Je lis, je valide l'un, je jette l'autre. Chaque correctif est rattaché à sa preuve, donc je peux remonter au fait qui le motive. Rien ne s'applique sans mon accord, et tout ce qui s'applique est un fichier que je peux relire et annuler.

## Contexte versionné plutôt que fine-tune

Je ne fine-tune pas Claude Code : je n'y ai pas accès, et ce n'est pas l'objectif. La sortie d'une rétro n'est pas un jeu de poids modifié, c'est du **contexte versionné** — une ligne dans `CLAUDE.md`, une mémoire, un hook, une permission. Des fichiers texte dans un dépôt git, avec trois propriétés concrètes :

- **versionné** : chaque correctif est un commit ; je vois quand et pourquoi une règle est arrivée ;
- **lisible** : je relis la règle en clair au lieu de deviner ce qu'un jeu de poids a « appris » ;
- **réversible** : une règle qui gêne, c'est un `git revert`, pas un ré-entraînement.

Pour un livrable subjectif comme une rétro, ces trois propriétés rendent le résultat plus simple à corriger qu'un comportement appris que je ne peux qu'observer.

La skill qui orchestre tout ça est publique : dans le dépôt `cc-dev-kit`, sous `plugins/meta-workflow/skills/retro/SKILL.md`. L'extracteur y est en Python de la bibliothèque standard, sans dépendance.

## Ce qu'il faut retenir

Automatiser une rétro de ses sessions d'agent est possible sans halluciner le bilan, à condition de ne pas demander au LLM ce qu'il ne peut pas savoir.

- **Repoussez le déterministe dans un extracteur** : local, lecture seule, jamais le transcript brut, seulement des signaux factuels (corrections, erreurs d'outil, rejets).
- **Cadrez la synthèse** : le modèle regroupe en thèmes et exerce le jugement que l'extracteur ne peut pas — d'où le caveat de précision, qui lui fait écarter les fausses frictions des sessions socratiques.
- **Gardez une gate humaine** : des correctifs typés, chacun rattaché à sa preuve, que vous validez un par un.
- **Visez du contexte versionné, pas des poids** : versionné, lisible, réversible — donc plus simple à corriger qu'un fine-tune.

Le fait en bas, reproductible ; le jugement au milieu, encadré ; la décision en haut, humaine. C'est ce découpage qui garde le bilan honnête.
