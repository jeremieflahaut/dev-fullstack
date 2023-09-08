#!/bin/bash
set -e

echo "Deploying application ..."

# Enter maintanance mode
sudo docker exec dev_fullstack_web php artisan down
    # Update codebase
    git pull origin master

    # Install dependencies based on lock file
    sudo docker exec dev_fullstack_web  composer install --no-interaction --prefer-dist --optimize-autoloader

    # Migrate database
    sudo docker exec dev_fullstack_web  php artisan migrate --force

    # Clear cache
    sudo docker exec dev_fullstack_web  php artisan optimize

# Exit maintenance mode
sudo docker exec dev_fullstack_web php artisan up

echo "🚀 Application deployed!"
