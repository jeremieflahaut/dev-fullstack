---
title: "Healthcheck Docker pour PHP-FPM : pourquoi et comment le faire bien"
description: "PHP-FPM ne parle pas HTTP : un simple curl ne suffit pas pour vérifier qu'il tourne. Voici comment écrire un healthcheck fiable avec cgi-fcgi."
pubDate: 2026-06-11
tags: ["docker", "php"]
---

Un conteneur PHP-FPM démarre, Docker lui attribue l'état `running`, et le reverse proxy commence à lui envoyer des requêtes — avant même que les workers FPM soient prêts. Résultat : des 502 au démarrage, voire en production après un redémarrage à chaud. La solution, c'est le `HEALTHCHECK`, mais encore faut-il savoir comment l'écrire pour FPM.

## Pourquoi FPM n'est pas comme les autres

La majorité des healthchecks Docker se résument à un `curl http://localhost/ping`. Ça marche pour Nginx, pour une API Node.js, pour n'importe quel service HTTP. Pas pour PHP-FPM.

FPM n'écoute pas en HTTP. Il parle le protocole **FastCGI**, sur le port 9000 par défaut (ou sur un socket Unix). Il n'y a rien à `curl` directement — le conteneur peut écouter sur ce port sans que ça signifie qu'il est capable de traiter une requête PHP.

Par conséquent, `HEALTHCHECK CMD curl -f http://localhost:9000/` renvoie une erreur de protocole, pas un 200. Le healthcheck échoue en permanence, et Docker finit par marquer le conteneur `unhealthy` — ce qui ne reflète pas du tout la réalité.

## La solution : la page de ping FPM

PHP-FPM expose nativement deux endpoints internes dans sa config de pool :

- `ping.path` : répond simplement `pong` (configurable via `ping.response`)
- `pm.status_path` : retourne des métriques détaillées sur le gestionnaire de processus

Le ping est ce qu'on veut pour un healthcheck : léger, rapide, sans charge PHP.

### Activer le ping dans la config du pool

Dans votre fichier de configuration de pool (par défaut `www.conf`) :

```ini
; www.conf
[www]
ping.path = /ping
ping.response = pong
```

Si vous utilisez une image officielle `php:8.x-fpm`, le fichier se trouve dans `/usr/local/etc/php-fpm.d/www.conf`. Vous pouvez soit le modifier directement dans l'image, soit l'inclure via `COPY` dans votre Dockerfile.

### Envoyer une requête FastCGI depuis le conteneur

Pour interroger FPM en FastCGI depuis un script shell, l'outil de référence est `cgi-fcgi`, fourni par le paquet `libfcgi-bin` sur Debian/Ubuntu ou `fcgi` sur Alpine.

La commande ressemble à ça :

```bash
SCRIPT_NAME=/ping \
SCRIPT_FILENAME=/ping \
REQUEST_METHOD=GET \
cgi-fcgi -bind -connect 127.0.0.1:9000
```

Elle envoie une requête FastCGI synthétique à FPM, qui répond `pong` avec un code 200. Si FPM ne répond pas — processus mort, port non ouvert, workers saturés au point de ne plus accepter de connexions — la commande échoue et retourne un code non nul.

## Le HEALTHCHECK dans le Dockerfile

Voici un Dockerfile minimal qui regroupe tout :

```dockerfile
FROM php:8.3-fpm

# Installer cgi-fcgi pour le healthcheck
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfcgi-bin \
    && rm -rf /var/lib/apt/lists/*

# Activer le ping FPM dans le pool www
RUN echo "ping.path = /ping" >> /usr/local/etc/php-fpm.d/www.conf \
 && echo "ping.response = pong" >> /usr/local/etc/php-fpm.d/www.conf

HEALTHCHECK --interval=10s --timeout=5s --start-period=20s --retries=3 \
  CMD SCRIPT_NAME=/ping \
      SCRIPT_FILENAME=/ping \
      REQUEST_METHOD=GET \
      cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1
```

Quelques mots sur les paramètres :

- `--start-period=20s` : Docker ne comptabilise pas les échecs pendant ce délai. C'est le temps qu'on accorde à FPM pour charger ses extensions et initialiser ses workers — à ajuster selon la taille de votre application.
- `--interval=10s` : fréquence des vérifications. Toutes les 10 secondes, c'est raisonnable en production ; en développement, on peut monter à 30s pour réduire le bruit.
- `--timeout=5s` : si FPM ne répond pas dans ce délai, la vérification est marquée échouée.
- `--retries=3` : trois échecs consécutifs avant de passer à `unhealthy`.

## Intégration avec Docker Compose

Un healthcheck n'a de valeur que si quelque chose en tient compte. Dans Docker Compose, la directive `depends_on` accepte une condition `service_healthy` qui bloque le démarrage du service dépendant jusqu'à ce que le healthcheck soit vert :

```yaml
services:
  php:
    build: .
    # le HEALTHCHECK est défini dans le Dockerfile

  nginx:
    image: nginx:alpine
    depends_on:
      php:
        condition: service_healthy
    ports:
      - "80:80"
```

Avec cette configuration, Nginx ne démarre pas tant que FPM n'a pas répondu `pong` au moins une fois dans les délais. Les 502 au démarrage disparaissent.

## Ce que ce healthcheck surveille — et ce qu'il ne surveille pas

Soyons honnêtes sur ce que ce check vérifie réellement.

**Ce qu'il détecte :**
- FPM est en cours d'exécution et écoute sur le port 9000
- Le gestionnaire de processus est capable d'accepter de nouvelles connexions
- La configuration FPM est valide (sinon FPM ne démarre pas du tout)

**Ce qu'il ne détecte pas :**
- Une base de données inaccessible
- Une extension PHP mal configurée qui plante uniquement sur certains chemins
- Une saturation mémoire progressive qui n'affecte pas encore le ping

Ce n'est pas un test end-to-end. Pour détecter une dépendance défaillante — MySQL, Redis… — il faut soit un healthcheck dédié sur ces services, soit un endpoint de santé applicatif qui les interroge. Mais ça appartient à l'application, pas au conteneur FPM.

## En résumé

Un healthcheck FPM bien écrit, c'est trois choses :

1. **`ping.path` activé** dans la config du pool — sans ça, FPM ne sait pas quoi répondre.
2. **`cgi-fcgi` installé dans l'image** — le seul outil qui parle FastCGI depuis un shell.
3. **`--start-period` calibré** sur le temps réel de démarrage de votre app — trop court, vous marquez le conteneur `unhealthy` avant même qu'il soit prêt ; trop long, vous perdez l'intérêt du check.

Ajouter ces quelques lignes dans un Dockerfile évite les 502 intempestifs et donne à votre orchestrateur une information fiable sur l'état du processus. C'est peu d'effort pour un gain concret.
