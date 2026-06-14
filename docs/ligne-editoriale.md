# Ligne éditoriale du blog

Ce guide est lu par l'agent d'idéation (workflow « Idée d'article ») avant de proposer un sujet. Son objectif : **élargir la couverture du blog plutôt que creuser un coin déjà traité**. Il ne concerne que le **choix du sujet** ; les règles d'écriture, de typographie, de confidentialité et de tags restent dans `docs/redaction.md`, qui fait foi.

## Les piliers

Le blog couvre le terrain réel de l'auteur : un développeur backend senior (Laravel, PHP 8, MySQL/MongoDB/Redis), fullstack à l'occasion sur des side projects, côté front en TypeScript et Svelte. Chaque article appartient à **un** pilier principal.

1. **Backend Laravel / PHP** — langage, framework, patterns applicatifs (enums, value objects, events/listeners, validation, conception d'API). Le cœur, mais déjà bien servi : à ne pas sur-alimenter.
2. **Données & stockage** — MySQL, MongoDB, Redis : modélisation, requêtes, index, perfs, choix d'un store, migrations.
3. **Infra, conteneurs & CI/CD** — Docker, Docker Compose, GitHub Actions, déploiement, healthchecks, observabilité légère.
4. **Tests & qualité** — Pest/PHPUnit, organisation d'une suite, tests d'intégration, fixtures, ce qui mérite (ou non) un test, dette technique.
5. **Architecture & conception** — découpage d'une app, frontières de modules, jobs/queues vus sous l'angle archi, quand (ne pas) introduire une abstraction.
6. **Frontend léger** — TypeScript au quotidien, Svelte, consommation d'API côté client, ergonomie d'un petit front. (Jamais Vue, Nuxt, Inertia ; Tailwind seulement pour parler de la construction de ce site — cf. `redaction.md`.)
7. **Méthode & outillage dev** — git, workflow de commit, revue, l'IA dans le quotidien de dev, automatisation, productivité.
8. **Retours d'expérience** — leçons tirées de side projects ou de la construction de ce blog et de son pipeline d'articles, toujours anonymisées (cf. confidentialité). Pas de journal intime : un enseignement réutilisable par le lecteur.

## Règle de diversification (non négociable)

1. Classer **chaque** article déjà publié dans son pilier, puis compter par pilier.
2. Identifier le ou les piliers **dominants** (les plus servis) et les piliers **vides ou à un seul article**.
3. Choisir le nouveau sujet dans un pilier **sous-couvert** (vide en priorité). Tant qu'il reste un pilier vide, **ne pas** proposer dans le pilier dominant.
4. **Exception** : si le workflow reçoit un thème explicite en entrée, il prime — l'idée vise ce thème, quel que soit le pilier.

La diversité porte sur le **sujet**, pas sur les tags : un article d'un nouveau pilier réutilise le plus souvent un tag existant (un sujet « Tests » sur du Laravel reste taggé `laravel`). L'objectif n'est pas la nouveauté gratuite : le sujet doit rester ancré dans le vécu de l'auteur et utile au lecteur. Mais à pertinence égale, **on choisit toujours le pilier le moins couvert**.
