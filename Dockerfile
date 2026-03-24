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
    dcron \
    curl

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring gd zip

# Nginx config
RUN mkdir -p /run/nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf

# PHP config
RUN echo "upload_max_filesize=10M" > /usr/local/etc/php/conf.d/somabazar.ini && \
    echo "post_max_size=10M" >> /usr/local/etc/php/conf.d/somabazar.ini && \
    echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/somabazar.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/somabazar.ini

# Copy project
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Create upload dirs
RUN mkdir -p /var/www/html/uploads/listings /var/www/html/uploads/avatars /var/www/html/uploads/stores && \
    chown -R www-data:www-data /var/www/html/uploads

# Cron job
RUN echo "0 * * * * php /var/www/html/api/cron.php >> /var/log/sombazar_cron.log 2>&1" | crontab -

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/api/health.php || exit 1

CMD ["sh", "-c", "crond && php-fpm -D && nginx -g 'daemon off;'"]
