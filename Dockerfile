FROM php:8.2-apache 
RUN apt-get update && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libzip-dev zip unzip && rm -rf /var/lib/apt/lists/* 
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install pdo pdo_mysql mysqli mbstring gd zip 
RUN rm -f /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_event.load 
RUN a2enmod mpm_prefork rewrite 
COPY . /var/www/html/ 
RUN chown -R www-data:www-data /var/www/html 
EXPOSE 80 
CMD ["apache2-foreground"]
