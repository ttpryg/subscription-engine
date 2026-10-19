FROM php:8.1-cli

# Install system deps and PHP extensions commonly needed
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip \
    && rm -rf /var/lib/apt/lists/*

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Set user and permissions safely (uid 1000 matches the typical host user)
RUN useradd -o -u 1000 -g www-data -m developer \
    && mkdir -p /home/developer/.cache/composer \
    && chown -R developer:www-data /var/www/html /home/developer

# Set git safe directory to avoid permission issues
RUN git config --global --add safe.directory /var/www/html

ENV COMPOSER_HOME=/home/developer/.cache/composer

USER developer

CMD ["sleep", "infinity"]
