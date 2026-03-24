FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libzip-dev \
    libonig-dev \
    zip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo pdo_mysql mbstring zip exif

# Enable Apache modules
RUN a2enmod rewrite

# Apache configuration
RUN echo '<Directory /var/www/html>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/somabazar.conf \
    && a2enconf somabazar

# PHP configuration
RUN echo "upload_max_filesize=10M\npost_max_size=10M\nmemory_limit=256M\nmax_execution_time=300" \
    > /usr/local/etc/php/conf.d/somabazar.ini

WORKDIR /var/www/html

COPY . .

RUN mkdir -p uploads/listings uploads/avatars uploads/stores \
    && chown -R www-data:www-data uploads \
    && chmod -R 755 uploads

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/ || exit 1
