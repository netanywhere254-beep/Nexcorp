FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev libonig-dev unzip && docker-php-ext-install pdo_mysql intl mbstring opcache zip && a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/nexora-entrypoint
RUN chmod +x /usr/local/bin/nexora-entrypoint

ENTRYPOINT ["nexora-entrypoint"]
CMD ["apache2-foreground"]
