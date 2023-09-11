#!/bin/bash
set -e

echo "Deploying application ..."

# Enter maintanance mode
docker exec dev_fullstack_web php artisan down
    # Update codebase
    git pull origin master

    # Install dependencies based on lock file
    docker exec dev_fullstack_web composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

    # Migrate database
    docker exec dev_fullstack_web php artisan migrate --force

    # Clear cache
    docker exec dev_fullstack_web php artisan config:clear
    docker exec dev_fullstack_web php artisan route:clear

# Exit maintenance mode
docker exec dev_fullstack_web php artisan up

echo "🚀 Application deployed!"
