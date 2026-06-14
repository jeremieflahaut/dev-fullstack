# Guide de relecture factuelle des articles

Ce guide est lu par l'agent de relecture (workflow « Relecture d'article ») après chaque rédaction, avant la fusion de la pull request. Il complète `docs/redaction.md` : la rédaction écrit, la relecture vérifie. La relecture est **indépendante** du rédacteur — on ne se fie jamais à sa propre mémoire, on confronte chaque affirmation à une source.

## Mission

Relire l'article modifié par la pull request, vérifier que **tout ce qui y est affirmé est vrai**, corriger les inexactitudes, et signaler ce qui reste douteux dans un rapport. La correction est automatique : les passages inexacts sont réécrits puis commités sur la branche.

L'article à relire est le fichier `src/content/blog/<slug>.md` modifié par rapport à `origin/main` (`git diff --name-only origin/main...HEAD`).

## Ce qu'on vérifie

1. **Affirmations factuelles et techniques** (le cœur) : numéros de version, options et flags de CLI, comportements et valeurs par défaut, standards et RFC, dates, attributions et citations, chiffres et ordres de grandeur. Chaque affirmation se vérifie contre la **documentation officielle** (`WebFetch` sur la source de référence) ou, à défaut, une autorité reconnue (`WebSearch`). Une affirmation invérifiable n'est pas « vraie par défaut ».
2. **Validité des liens** : chaque lien externe doit répondre (statut ≈ 200) et pointer vers une source réellement pertinente pour ce qu'il prétend appuyer (`WebFetch`).
3. **Re-contrôle de confidentialité** : en filet de sécurité, repasser l'article au crible des règles de confidentialité « non négociables » de [`docs/redaction.md`](redaction.md), qui en est la source de vérité. Toute violation se corrige selon ce que ce guide impose.
4. **Cohérence interne** : contradictions d'une section à l'autre, et exemples de code conformes à ce que le texte en dit (commandes, sorties, noms d'options annoncés vs réels).

## Comment corriger

- N'éditer **que** le fichier de l'article (`src/content/blog/<slug>.md`). Aucun autre fichier du dépôt.
- Corrections **minimales et ciblées** : on rectifie l'affirmation fausse, on ne réécrit pas l'article.
- Respecter `docs/redaction.md` : voix à la première personne, ton pragmatique, et la typographie française (espace insécable U+00A0 avant `:`, `;`, `?`, `!` et dans les guillemets « … », guillemets français, points de suspension « … » en un seul caractère).
- **Ne jamais inventer** un chiffre, une version ou une source pour « corriger ». Si une affirmation est fausse, centrale, et qu'on ne peut pas la rectifier sans fabriquer une donnée, la retirer ou la reformuler prudemment, et le signaler dans le rapport.
- Une affirmation douteuse mais pas clairement fausse n'est pas touchée : on la liste en « à vérifier » dans le rapport et on laisse l'humain trancher.

## Périmètre et commit

- L'agent ne modifie que l'article — aucun autre fichier (pages, design, config, workflows, ce guide).
- S'il y a des corrections : un commit unique, conventionnel et en français, par exemple `fix(blog): corrige les inexactitudes relevées en relecture`, dont le corps liste brièvement les corrections. Jamais de trailer `Co-Authored-By`.
- S'il n'y a rien à corriger : pas de commit.
- **Ne pas pousser** la branche : le workflow s'en charge après avoir vérifié le build.

## Rapport

Écrire un compte-rendu Markdown dans `/tmp/relecture-report.md` (hors du dépôt, pour ne pas déclencher le garde-fou). Concis et scannable :

- Un tableau « verdict par affirmation » : affirmation | verdict (✅ confirmé / ⚠️ à vérifier / ❌ inexact) | source.
- La liste des corrections appliquées (avec, idéalement, le commit).
- Les points restants à trancher par l'humain (les « ⚠️ à vérifier »).
- Une synthèse d'une ligne (nombre d'inexactitudes corrigées, de points en suspens).

## Prudence côté web

Ne rechercher que des **faits techniques publics**. Ne jamais coller dans une requête web un contenu potentiellement sensible — il ne devrait pas y en avoir dans un article conforme à `docs/redaction.md`, mais c'est une garantie de principe.
