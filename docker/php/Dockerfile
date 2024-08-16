FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libpq-dev \
    postgresql-client \
    gnupg \
    libgmp-dev

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql mbstring exif pcntl bcmath gd zip gmp
RUN pecl install redis && docker-php-ext-enable redis

# Install Node.js 20.4 and npm
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g npm@latest

# Verify Node.js and npm versions
RUN node --version && npm --version

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy composer.json and composer.lock
COPY composer.json composer.lock ./

# Install project dependencies
RUN composer install --prefer-dist --no-scripts --no-dev --no-autoloader && rm -rf /root/.composer

# Copy existing application directory contents
COPY . /var/www

# Generate autoload files
RUN composer dump-autoload --no-scripts --no-dev --optimize

# Copy existing application directory permissions
COPY --chown=www-data:www-data . /var/www

# Install npm dependencies
COPY package.json package-lock.json ./
RUN npm ci --only=production --ignore-scripts

# Copy the startup script
COPY docker/php/startup.sh /usr/local/bin/startup.sh
RUN chmod +x /usr/local/bin/startup.sh

# Install Supervisor
RUN apt-get update && apt-get install -y supervisor

# Copy Supervisor configuration
COPY docker/php/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create necessary directories and set permissions
RUN mkdir -p /var/log/supervisor /var/run/supervisor /var/www/storage/logs \
    && chown -R www-data:www-data /var/log/supervisor /var/run/supervisor /var/www/storage/logs

# Expose port 9000 and start supervisord
EXPOSE 9000
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
