FROM php:8.2-apache

RUN apt-get update && apt-get install -y     libpng-dev     libjpeg-dev     libfreetype6-dev     libzip-dev     zip     unzip     && docker-php-ext-configure gd --with-freetype --with-jpeg     && docker-php-ext-install gd pdo pdo_mysql mbstring zip     && a2enmod rewrite     && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html     && find /var/www/html -type d -exec chmod 755 {} \;     && find /var/www/html -type f -exec chmod 644 {} \;

RUN echo '<Directory /var/www/html>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>' > /etc/apache2/conf-available/app.conf     && a2enconf app

EXPOSE 80