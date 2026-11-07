FROM php:8.2-apache
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY . /var/www/html
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && printf '\n<Directory ${APACHE_DOCUMENT_ROOT}>\n    Options FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\nDirectoryIndex index.php index.html\n' >> /etc/apache2/apache2.conf \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html/src /var/www/html/public -type f -name '*.php' -exec php -l {} \; \
    && apache2ctl configtest
EXPOSE 80
