---
title: "Le piège de la sur-ingénierie d'un side project : le produit tourne avant l'infra"
description: "Comment un dev backend senior monte la « bonne » fondation d'un side project et se retrouve avec un outillage parfait mais aucun produit — et la règle qui m'a aidé à inverser l'ordre."
pubDate: 2026-10-14
tags: ["docker", "architecture"]
---

Il m'arrive de lancer une petite app Laravel pour moi, quand l'envie est là. Et plusieurs fois, le scénario a été le même : trois week-ends plus tard, j'avais une stack Docker réutilisable, une CI GitHub Actions au vert, un `app/` découpé par domaine et une suite de tests — mais pas un seul écran que j'aurais pu montrer à quelqu'un. L'outillage était impeccable. Le produit n'existait pas. Voici le biais que ça révèle, et la règle simple qui m'a fait inverser l'ordre.

## Le réflexe de la « bonne » fondation

Au travail, poser une base propre avant d'écrire une ligne de métier est une bonne décision : le code va vivre des années, être lu par d'autres, passer en prod. L'investissement initial se rentabilise. Le problème, c'est que ce réflexe est tellement ancré qu'il se déclenche tout seul sur un projet perso, où le contexte n'a rien à voir.

Alors je commence par là où je suis à l'aise. Un `compose.yaml` qui monte PHP-FPM, Nginx, MySQL et Redis. Un `Makefile` pour tout lancer d'une commande. Une CI qui enchaîne `phpstan`, `pint` et `pest`. Un découpage par domaine, parce que je sais que ça [tient plus longtemps qu'un découpage par type](/blog/organiser-code-laravel-par-domaine/). Chaque brique est justifiable isolément. L'ensemble a un défaut rédhibitoire : il ne répond à aucune question que l'app est censée résoudre.

Le piège est confortable parce qu'il ressemble à du travail sérieux. Configurer un healthcheck, c'est concret, borné, gratifiant — on voit le conteneur passer `healthy`. Écrire la première vraie fonctionnalité, c'est flou, ça demande de trancher des choix produit qu'on n'a pas envie de faire. On repousse donc le flou en se réfugiant dans l'infra, et on appelle ça « bien faire les choses ».

## Le symptôme : des semaines d'outillage pour zéro écran

Le signal qui aurait dû m'alerter est mesurable. Après plusieurs sessions, la bonne question n'est pas « est-ce que mon pipeline est propre ? » mais « qu'est-ce qu'un utilisateur peut faire avec ce que j'ai livré ? ». Et la réponse était : rien. Pas de formulaire, pas de liste, pas une action qui parte d'un clic et finisse en ligne en base.

Le coût n'est pas que du temps perdu. C'est la motivation qui s'érode. Sur un side project, le seul carburant, c'est de voir la chose avancer. Tant qu'on polit l'outillage, la ligne d'arrivée ne bouge pas — elle recule, même, parce que chaque brique d'infra ajoute une surface à maintenir avant d'avoir produit la moindre valeur. Au bout de quelques week-ends sans rien de visible, l'enthousiasme retombe, et le projet rejoint le cimetière des dépôts à un seul contributeur et zéro release.

C'est le paradoxe du dev solo : plus on est compétent pour monter une fondation solide, plus on est exposé à ce biais, parce qu'on sait exactement quelle serait la « bonne » base — et qu'on a les moyens de la construire avant d'en avoir besoin.

## La bascule : le produit tourne avant l'infra

La règle qui m'a sorti de là tient en une phrase : **le produit tourne avant l'infra**. Concrètement, tant qu'une fonctionnalité de bout en bout — une donnée qui entre, qui est persistée, qui ressort à l'écran — ne tourne pas, je m'interdis de toucher à l'outillage au-delà du strict minimum pour la faire tourner.

Ça change l'ordre des opérations. On n'attaque plus par la stack, on attaque par le plus petit parcours utilisateur qui a du sens, et on ne monte en infra que lorsqu'une douleur réelle le réclame. C'est le même raisonnement que [n'abstraire que sur un besoin présent](/blog/quand-ne-pas-abstraire-laravel-repository-service/), appliqué cette fois à l'infrastructure : YAGNI ne concerne pas que le code, il concerne aussi le `compose.yaml`.

Deux garde-fous rendent la règle opérationnelle :

- **Une v1 écrite noir sur blanc, et surtout une liste de ce que la v1 ne fera PAS.** C'est cette seconde liste qui protège. « Pas d'authentification tierce, pas de file d'attente, pas de cache, pas de multi-tenant » : l'écrire noir sur blanc coupe court à l'ajout réflexe de la brique d'infra correspondante.
- **Du time-box plutôt que du feature-box.** Au lieu de « je livrerai quand ce sera propre », je me fixe « ce que j'aurai mis en ligne d'ici deux week-ends ». Une contrainte de temps force à trancher ce qui compte ; une contrainte de périmètre, elle, s'étire indéfiniment, et l'infra s'engouffre dans l'étirement.

## Ce qui vaut quand même le coup dès le départ

Inverser l'ordre ne veut pas dire tout faire en vrac. Il y a un socle minimal qui fait gagner du temps sans jamais détourner du produit, parce qu'il sert dès la première fonctionnalité au lieu d'anticiper un besoin futur. Pour moi, c'est : de quoi lancer l'app en une commande, et une base de données.

Un `compose.yaml` qui tient dans un écran suffit à démarrer :

```yaml
services:
  app:
    image: php:8.4-cli
    working_dir: /app
    volumes:
      - .:/app
    command: php artisan serve --host=0.0.0.0 --port=8000
    ports:
      - "8000:8000"
    depends_on:
      - db
  db:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: app
      MYSQL_ROOT_PASSWORD: root
    ports:
      - "3306:3306"
```

Deux services, un `docker compose up`, et je code une fonctionnalité. Le serveur intégré d'Artisan remplace très bien Nginx et PHP-FPM tant qu'on développe seul en local.

À comparer avec ce vers quoi mon réflexe me poussait dès la première heure :

```yaml
services:
  nginx:
    build: ./docker/nginx
    ports: ["80:80", "443:443"]
    depends_on:
      php: { condition: service_healthy }
  php:
    build: ./docker/php
    healthcheck:
      test: ["CMD", "php-fpm-healthcheck"]
      interval: 10s
    depends_on:
      db: { condition: service_healthy }
      redis: { condition: service_healthy }
  db:
    image: mysql:8.4
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-proot"]
    volumes:
      - dbdata:/var/lib/mysql
  redis:
    image: redis:7
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
  horizon:
    build: ./docker/php
    command: php artisan horizon
    depends_on:
      redis: { condition: service_healthy }
volumes:
  dbdata:
```

Tout y est défendable. Les [healthchecks bien réglés](/blog/healthcheck-docker-php-fpm/) et [Horizon pour les files Redis](/blog/laravel-queues-redis-horizon-en-prod/) ont toute leur place — en prod, ou le jour où une fonctionnalité a réellement besoin d'une file d'attente. Mais au premier week-end, ce fichier est deux Dockerfile, un service de queue et quatre healthchecks à maintenir, pour une app qui n'a pas encore un seul écran. C'est de l'infrastructure prématurée : exactement la même erreur que l'abstraction prématurée, une marche plus haut.

La règle de tri est simple : une brique d'infra entre dans le projet quand une fonctionnalité qui existe déjà en a besoin pour tourner, jamais parce qu'elle y sera sûrement un jour.

## Repérer le biais chez soi

Le biais est difficile à voir de l'intérieur, parce qu'il se déguise en rigueur. Quelques questions me servent de détecteur, en fin de session :

- Est-ce que ma dernière session a rapproché l'app de son premier utilisateur, ou seulement rendu la stack plus belle ?
- Si je devais couper le projet maintenant, qu'est-ce qui marche vraiment, du point de vue de quelqu'un qui n'est pas moi ?
- Cette brique d'infra que je m'apprête à ajouter, est-ce qu'une fonctionnalité existante la réclame, ou est-ce que je l'anticipe ?

Dès que les réponses penchent vers l'outillage, je repose le `compose.yaml` et je retourne au plus petit parcours qui n'est pas encore bout-en-bout.

## Ce qu'il faut retenir

La compétence qui fait monter une bonne fondation au travail se retourne contre le dev solo : elle lui permet de sur-investir l'infra avant d'avoir un produit, et de confondre un outillage soigné avec de l'avancement.

1. **Le produit tourne avant l'infra.** Tant qu'un parcours de bout en bout ne tourne pas, pas d'infra au-delà du minimum pour le faire tourner.
2. **Écrivez la liste de ce que la v1 ne fera pas** autant que celle de ce qu'elle fera : c'est elle qui coupe l'ajout réflexe de briques.
3. **Time-box, pas feature-box** : une échéance force à livrer ; un périmètre s'étire et laisse l'infra combler le vide.
4. **Gardez le socle minimal, reportez le reste** : de quoi lancer en une commande et une base, c'est tout — healthchecks, files, cache, découpage entrent quand une fonctionnalité existante les réclame.

Un side project ne meurt presque jamais d'une fondation trop faible. Il meurt de n'avoir jamais rien mis en ligne pendant qu'on peaufinait la fondation.
