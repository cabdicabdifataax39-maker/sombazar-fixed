FROM php:8.2-fpm-alpine

# Install dependencies
RUN apk add --no-cache \
    nginx \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    libzip-dev \
    zip unzip \
    dcron

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring gd zip

# Nginx config
RUN mkdir -p /run/nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copy project
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Cron job — her saat çalışır
RUN echo "0 * * * * php /var/www/html/api/cron.php >> /var/log/sombazar_cron.log 2>&1" | crontab -

EXPOSE 80

CMD ["sh", "-c", "crond && php-fpm -D && nginx -g 'daemon off;'"]
