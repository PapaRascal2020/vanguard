#!/bin/bash

# Function to check the last command status
check_status() {
    if [ $? -ne 0 ]; then
        echo "Error: $1 failed"
        exit 1
    fi
}

# Wait for the database to be ready
until pg_isready -h ${DB_HOST} -U ${DB_USERNAME} -d ${DB_DATABASE}
do
    echo "Waiting for database to be ready..."
    sleep 2
done

# Copy .env file if not exists
if [ ! -f .env ]; then
    cp .env.example.docker .env
    check_status "Copying .env file"
fi

# Update environment variables
sed -i "s#APP_ENV=.*#APP_ENV=${APP_ENV:-production}#" .env
sed -i "s#APP_DEBUG=.*#APP_DEBUG=${APP_DEBUG:-false}#" .env
sed -i "s#APP_URL=.*#APP_URL=${APP_URL:-http://localhost}#" .env
sed -i "s#LOG_LEVEL=.*#LOG_LEVEL=${LOG_LEVEL:-error}#" .env
sed -i "s#DB_CONNECTION=.*#DB_CONNECTION=${DB_CONNECTION:-pgsql}#" .env
sed -i "s#DB_HOST=.*#DB_HOST=${DB_HOST:-postgres}#" .env
sed -i "s#DB_PORT=.*#DB_PORT=${DB_PORT:-5432}#" .env
sed -i "s#DB_DATABASE=.*#DB_DATABASE=${DB_DATABASE:-vanguard}#" .env
sed -i "s#DB_USERNAME=.*#DB_USERNAME=${DB_USERNAME:-postgres}#" .env
sed -i "s#DB_PASSWORD=.*#DB_PASSWORD=${DB_PASSWORD}#" .env
sed -i "s#APP_KEY=.*#APP_KEY=${APP_KEY}#" .env
sed -i "s#SSH_PASSPHRASE=.*#SSH_PASSPHRASE=${SSH_PASSPHRASE}#" .env

# Update REVERB keys
sed -i "s#REVERB_APP_KEY=.*#REVERB_APP_KEY=${REVERB_APP_KEY:-default_reverb_app_key}#" .env
sed -i "s#REVERB_APP_SECRET=.*#REVERB_APP_SECRET=${REVERB_APP_SECRET:-default_reverb_app_secret}#" .env
sed -i "s#VITE_REVERB_APP_KEY=.*#VITE_REVERB_APP_KEY=\"${REVERB_APP_KEY:-default_reverb_app_key}\"#" .env

# Forcefully set Redis values
sed -i '/^### CACHE ###/,/^$/c\
### CACHE ###\
REDIS_HOST='"${REDIS_HOST:-redis}"'\
REDIS_PORT='"${REDIS_PORT:-6379}"'\
REDIS_PASSWORD='"${REDIS_PASSWORD:-null}"'\
' .env

check_status "Updating .env file"

# Install Composer dependencies
echo "Installing Composer dependencies..."
composer install --no-interaction --no-plugins --no-scripts --optimize-autoloader --no-dev
check_status "Composer install"

# Run database migrations
echo "Running database migrations..."
php artisan migrate --force
check_status "Database migration"

# Install npm dependencies
echo "Installing npm dependencies..."
npm ci --only=production
check_status "npm install"

# Build front-end assets
echo "Building front-end assets..."
npm run build
check_status "npm run build"

# Clear and cache config
php artisan config:clear
php artisan config:cache
check_status "Config caching"

# Clear and cache routes
php artisan route:clear
php artisan route:cache
check_status "Route caching"

# Clear and cache views
php artisan view:clear
php artisan view:cache
check_status "View caching"

echo "All setup tasks completed successfully!"

# Start PHP-FPM
php-fpm
