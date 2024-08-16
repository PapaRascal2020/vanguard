#!/bin/bash

# Function to check the last command status
check_status() {
    if [ $? -ne 0 ]; then
        echo "Error: $1 failed"
        exit 1
    fi
}

# Function to generate a secure random passphrase
generate_secure_passphrase() {
    tr -dc 'A-Za-z0-9!"#$%&'\''()*+,-./:;<=>?@[\]^_`{|}~' </dev/urandom | head -c 32
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
sed -i "s#DB_PASSWORD=.*#DB_PASSWORD=${DB_PASSWORD:-password}#" .env
sed -i "s#MAIL_MAILER=.*#MAIL_MAILER=${MAIL_MAILER:-smtp}#" .env
sed -i "s#MAIL_HOST=.*#MAIL_HOST=${MAIL_HOST:-mailhog}#" .env
sed -i "s#MAIL_PORT=.*#MAIL_PORT=${MAIL_PORT:-1025}#" .env
sed -i "s#MAIL_USERNAME=.*#MAIL_USERNAME=${MAIL_USERNAME:-null}#" .env
sed -i "s#MAIL_PASSWORD=.*#MAIL_PASSWORD=${MAIL_PASSWORD:-null}#" .env
sed -i "s#MAIL_FROM_ADDRESS=.*#MAIL_FROM_ADDRESS=${MAIL_FROM_ADDRESS:-hello@vanguard.test}#" .env
sed -i "s#HORIZON_TOKEN=.*#HORIZON_TOKEN=${HORIZON_TOKEN}#" .env
sed -i "s#FLARE_KEY=.*#FLARE_KEY=${FLARE_KEY:-hPSaRi0s1xCBddUYHsFjjUwTDVHAKn5m}#" .env
sed -i "s#ADMIN_EMAIL_ADDRESSES=.*#ADMIN_EMAIL_ADDRESSES=${ADMIN_EMAIL_ADDRESSES}#" .env
sed -i "s#ENABLE_DEVICE_AUTH_ENDPOINT=.*#ENABLE_DEVICE_AUTH_ENDPOINT=${ENABLE_DEVICE_AUTH_ENDPOINT:-false}#" .env
sed -i "s#USER_REGISTRATION_ENABLED=.*#USER_REGISTRATION_ENABLED=${USER_REGISTRATION_ENABLED:-true}#" .env

# Generate and set a secure SSH_PASSPHRASE if not set or if it's the default value
if [ -z "${SSH_PASSPHRASE}" ] || [ "${SSH_PASSPHRASE}" = "123456789" ]; then
    SSH_PASSPHRASE=$(generate_secure_passphrase)
fi
sed -i "s#SSH_PASSPHRASE=.*#SSH_PASSPHRASE=${SSH_PASSPHRASE}#" .env

# Forcefully set Redis values
sed -i '/^### CACHE ###/,/^$/c\
### CACHE ###\
REDIS_HOST='"${REDIS_HOST:-redis}"'\
REDIS_PORT='"${REDIS_PORT:-6379}"'\
REDIS_PASSWORD='"${REDIS_PASSWORD:-null}"'\
' .env

# Add essential Reverb environment variables
sed -i "s#REVERB_APP_ID=.*#REVERB_APP_ID=${REVERB_APP_ID:-your-app-id}#" .env
sed -i "s#REVERB_APP_KEY=.*#REVERB_APP_KEY=${REVERB_APP_KEY:-set-a-key-here}#" .env
sed -i "s#REVERB_APP_SECRET=.*#REVERB_APP_SECRET=${REVERB_APP_SECRET:-set-a-secret}#" .env
sed -i "s#REVERB_HOST=.*#REVERB_HOST=${REVERB_HOST:-localhost}#" .env
sed -i "s#BROADCAST_CONNECTION=.*#BROADCAST_CONNECTION=reverb#" .env

check_status "Updating .env file"

# Always generate a new application key
php artisan key:generate --force
check_status "Generating application key"

# Generate ssh key
php artisan vanguard:generate-ssh-key
check_status "Generating SSH key"

# Install Composer dependencies
echo "Installing Composer dependencies..."
composer install --no-interaction --no-plugins --no-scripts
check_status "Composer install"

# Run database migrations
echo "Running database migrations..."
php artisan migrate --force
check_status "Database migration"

# Install npm dependencies
echo "Installing npm dependencies..."
npm ci
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
