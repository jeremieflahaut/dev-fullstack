---
title: "Un environnement Laravel propre avec Docker Compose"
description: "PHP qui diverge entre projets, « ça marche chez moi », vendor/ appartenant à root… Comment je structure mes environnements de dev Laravel avec Docker."
pubDate: 2026-06-05
tags: ["laravel", "docker"]
---

Quand on jongle entre plusieurs projets Laravel — un en PHP 8.1, l'autre en 8.2 — installer PHP sur sa machine devient vite ingérable. Ma règle depuis quelques années : **rien sur l'hôte, tout dans Docker**.

## La stack minimale

Un service PHP-FPM, un Nginx devant, et c'est tout (SQLite suffit souvent en dev) :

```yaml
services:
  php:
    build:
      context: ./backend
      target: dev
    container_name: monapp_php
    volumes:
      - ./backend/app:/var/www/html

  nginx:
    image: nginx:alpine
    container_name: monapp_nginx
    depends_on:
      - php
    ports:
      - "8080:80"
```

Deux détails qui comptent :

- **`container_name` explicite** — sinon, deux projets nommés `app` entrent en collision.
- **Une cible `dev` dans le Dockerfile** — la toolchain (Xdebug, Composer) ne va pas en prod.

## Le piège classique : les permissions

Par défaut, tout ce que le conteneur écrit dans le bind-mount (`vendor/`, `storage/`, la base SQLite…) appartient à `root`. La solution : exécuter les commandes avec l'UID/GID de l'hôte, encapsulé dans un Makefile :

```makefile
UID := $(shell id -u)
GID := $(shell id -g)

init: up
	docker compose exec -u $(UID):$(GID) php composer install
	docker compose exec -u $(UID):$(GID) php php artisan migrate
```

Plus jamais de `sudo chown -R` honteux.

## Artisan sans PHP local

Toutes les commandes passent par le conteneur :

```bash
❯ docker compose exec php php artisan make:model Position -mf
❯ docker compose exec php composer test
```

Un alias shell (`alias art='docker compose exec php php artisan'`) et on ne sent plus la différence avec un PHP local.

## Ce que ça change au quotidien

```php
// Le même code tourne à l'identique partout :
// PHP 8.2 dans le conteneur, peu importe la machine.
Route::get('/sante', fn () => response()->json(['ok' => true]));
```

Onboarder quelqu'un sur le projet tient en deux commandes : `git clone`, puis `make init`. L'environnement **est** dans le repo — versionné, reproductible, jetable.

C'est exactement l'approche que j'utilise sur mes side projects, et… pour ce site lui-même.
