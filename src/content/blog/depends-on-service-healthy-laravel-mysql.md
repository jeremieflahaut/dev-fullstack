---
title: "depends_on ne suffit pas : démarrer Laravel et MySQL dans le bon ordre"
description: "service_healthy ne garantit pas que MySQL accepte les connexions, et ne joue qu'au démarrage. Healthcheck, conteneur de migration et reconnexion applicative."
pubDate: 2026-10-16
tags: ["docker", "laravel"]
---

`docker compose up`, et l'application plante aussitôt : `SQLSTATE[HY000] [2002] Connection refused`. On ajoute `depends_on` sur le service MySQL, on relance, même erreur. On passe à `condition: service_healthy`, ça va mieux… jusqu'au premier `docker compose restart mysql` en pleine journée, où tout retombe. Le problème n'est pas que `depends_on` est cassé : c'est qu'il ne fait pas ce qu'on croit.

## « Conteneur démarré » n'est pas « service prêt »

La confusion de départ tient en une phrase : `depends_on` sans condition attend que le **conteneur** soit démarré, pas que le **service** à l'intérieur soit prêt à travailler.

```yaml
services:
  app:
    build: .
    depends_on:
      - mysql   # ← n'attend QUE le lancement du conteneur mysql
  mysql:
    image: mysql:8.4
```

Avec cette configuration, Docker lance le conteneur MySQL, constate qu'il tourne (`running`), et démarre immédiatement `app`. Sauf qu'un conteneur MySQL qui vient de naître met plusieurs secondes — parfois beaucoup plus au tout premier boot — avant d'initialiser ses fichiers de données, de créer la base et d'ouvrir son socket aux connexions. Pendant ce laps de temps, le conteneur est bien `running`, mais le serveur MySQL refuse encore les connexions. Laravel démarre, essaie de se connecter, échoue.

## Les trois conditions de depends_on

La forme longue de `depends_on` accepte une `condition`, et c'est là que tout se joue. Il en existe trois :

- `service_started` : le comportement par défaut. Le conteneur est lancé, point.
- `service_healthy` : le conteneur a un healthcheck et celui-ci est passé au vert.
- `service_completed_successfully` : le conteneur s'est terminé avec un code de sortie `0`. Parfait pour un conteneur « one-shot » comme un job de migration.

`service_healthy` est la bonne réponse pour MySQL, mais à une condition non négociable : encore faut-il qu'un healthcheck existe et qu'il teste la bonne chose.

## Le healthcheck va sur MySQL, pas sur l'application

L'erreur classique, c'est de poser un healthcheck sur le service applicatif. C'est l'inverse : le healthcheck doit vivre sur le service dont les autres dépendent, c'est-à-dire MySQL. Et il ne doit pas se contenter de vérifier que le port 3306 est ouvert — un port ouvert ne veut pas dire que le serveur accepte une authentification. L'outil juste, c'est `mysqladmin ping` (ou `mariadb-admin ping` sur une image MariaDB) :

```yaml
services:
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: app
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-uroot", "-proot"]
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 30s
```

Un détail qui a son importance : testez sur `127.0.0.1` et non `localhost`. Avec `localhost`, le client MySQL tente une connexion par socket Unix, qui peut répondre avant que le serveur accepte réellement les connexions réseau TCP — celles qu'utilisera votre application. `127.0.0.1` force le TCP et teste donc le même chemin que Laravel.

## start_period : le piège du premier boot

Le `start_period` mérite qu'on s'y arrête, parce qu'un healthcheck trop agressif fait plus de mal que pas de healthcheck du tout. Au tout premier démarrage, l'image MySQL ne fait pas que lancer le serveur : elle initialise le répertoire de données, crée la base définie par `MYSQL_DATABASE`, applique le mot de passe root. Cette initialisation peut prendre vingt à trente secondes, pendant lesquelles `mysqladmin ping` échoue légitimement.

Sans `start_period`, chacun de ces échecs compte dans le quota `retries`. Avec `retries: 10` et `interval: 5s`, on tolère environ cinquante secondes — mais si l'init déborde, Docker marque le conteneur `unhealthy`, et tous les services en `service_healthy` restent bloqués. Le `start_period` règle ça : pendant cette fenêtre, les échecs du healthcheck **ne sont pas comptabilisés**. Le conteneur a le temps de s'initialiser sans jamais basculer `unhealthy`, et dès que le premier ping réussit, il passe `healthy` immédiatement.

## Le cas Laravel : sortir les migrations de l'app

Reste un piège spécifique à Laravel. Même avec un MySQL sain au démarrage, où faites-vous tourner `php artisan migrate` ? Si c'est dans l'entrypoint du conteneur applicatif, deux services (`app` et `queue`, par exemple) peuvent lancer les migrations en parallèle, ou le faire à chaque redémarrage. La solution propre, c'est un conteneur de migration dédié, qui dépend de MySQL en `service_healthy`, et dont l'application dépend en `service_completed_successfully` :

```yaml
services:
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: app
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-uroot", "-proot"]
      interval: 5s
      timeout: 3s
      retries: 10
      start_period: 30s

  migrate:
    build: .
    command: php artisan migrate --force
    depends_on:
      mysql:
        condition: service_healthy
    restart: "no"

  app:
    build: .
    depends_on:
      migrate:
        condition: service_completed_successfully
    ports:
      - "8000:8000"
```

La chaîne est maintenant déterministe : MySQL devient `healthy`, `migrate` s'exécute une seule fois et sort avec le code `0`, puis `app` démarre sur un schéma à jour. Le `restart: "no"` est là pour éviter que Compose relance un conteneur qui, par nature, est censé se terminer.

## Honnêteté technique : depends_on ne joue qu'au démarrage

Voici le point que la plupart des tutoriels passent sous silence, et c'est le plus important. `depends_on` ne gouverne par défaut que l'**ordre de démarrage initial**. Concrètement :

- Si MySQL redémarre en cours de vie — un `restart`, un `OOM kill`, une mise à jour —, Compose ne redémarre pas `app` pour autant par défaut, et ne réattend pas que MySQL redevienne `healthy`. L'option `restart: true` dans `depends_on` couvre un `docker compose restart` ou une mise à jour explicites, mais pas un crash relancé par la politique de redémarrage du conteneur.
- `depends_on` peut être court-circuité : `docker compose up --no-deps app` démarre `app` sans lancer ni attendre `mysql`, et il n'est pas honoré de la même façon en mode Swarm.
- En production, votre base vit sur un serveur géré, pas dans le même Compose. `depends_on` ne s'applique tout simplement pas.

La conclusion est inévitable : **il faut quand même une reconnexion côté application.** Un démarrage ordonné règle le premier boot, pas la résilience. Laravel sait reprendre une connexion perdue entre deux requêtes, mais un worker de queue long-vivant qui perd MySQL doit échouer proprement et réessayer. Vous pouvez borner les tentatives initiales sur une connexion fragile :

```php
// config/database.php
'mysql' => [
    'driver' => 'mysql',
    // …
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::ATTR_TIMEOUT => 5,
    ]) : [],
],
```

Et côté queue, laissez les jobs retomber dans la file plutôt que de les perdre : un `tries` supérieur à 1 et un `backoff` donnent à MySQL le temps de revenir sans sacrifier le travail en cours.

## Et wait-for-it.sh dans tout ça ?

Avant que les healthchecks natifs n'existent, on bricolait l'attente avec un script comme `wait-for-it.sh` ou `dockerize`, qui bouclaient sur l'ouverture d'un port avant de lancer la vraie commande. Ça fonctionne encore, mais ça teste l'ouverture d'un port TCP — pas que MySQL accepte une authentification. Un `mysqladmin ping` dans un healthcheck couplé à `service_healthy` fait le même travail, en mieux, et de façon déclarative dans le fichier Compose. Sauf contrainte particulière, ces scripts ne servent plus à grand-chose aujourd'hui.

## Ce qu'il faut retenir

Un démarrage propre de Laravel + MySQL en Compose, c'est une poignée de décisions :

1. `depends_on` nu attend le conteneur, pas le service : il faut `condition: service_healthy`.
2. Le healthcheck va sur MySQL (`mysqladmin ping` sur `127.0.0.1`), pas sur l'application.
3. Un `start_period` calibré évite de marquer MySQL `unhealthy` pendant l'init du premier boot.
4. Les migrations vivent dans un conteneur dédié, enchaîné via `service_completed_successfully`.
5. `depends_on` ne joue qu'au démarrage : la reconnexion applicative reste indispensable pour tout le reste.

Le bloc Compose plus haut est à copier tel quel pour démarrer. Mais gardez le point 5 en tête : ordonner le boot vous débarrasse des `Connection refused` du matin, pas des coupures de MySQL à 15 h. Pour celles-là, c'est l'application qui doit savoir attendre et réessayer.
