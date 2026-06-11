# Wrappers compose, dans l'esprit de pea/ : tout passe par le conteneur node,
# aucun Node requis sur l'hôte. UID/GID exportés => fichiers créés appartenant à l'hôte.
HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID HOST_GID

.PHONY: init up down restart logs sh build preview check

init: ## Installe les dépendances (npm install dans le conteneur)
	docker compose run --rm node npm install

up: ## Démarre le serveur de dev → http://localhost:4321
	docker compose up -d

down:
	docker compose down

restart: down up

logs:
	docker compose logs -f node

sh: ## Shell dans le conteneur
	docker compose run --rm node sh

build: ## Build de prod dans ./dist
	docker compose run --rm node npm run build

preview: ## Sert ./dist sur http://localhost:4321
	docker compose run --rm --service-ports node npm run preview

check: ## Vérification des types (astro check)
	docker compose run --rm node npx astro check
