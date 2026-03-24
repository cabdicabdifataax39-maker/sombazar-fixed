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

# Completely replace Apache ports and main config to avoid MPM conflicts
# Remove ALL enabled mods first, then selectively enable what we need
RUN cd /etc/apache2/mods-enabled && \
    rm -f mpm_event.load mpm_event.conf mpm_worker.load mpm_worker.conf \
          mpm_prefork.load mpm_prefork.conf && \
    ln -s ../mods-available/mpm_prefork.load mpm_prefork.load && \
    ln -s ../mods-available/mpm_prefork.conf mpm_prefork.conf && \
    ln -s ../mods-available/rewrite.load rewrite.load

# Write clean Apache virtual host config
RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        Options Indexes FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# PHP configuration
RUN echo "upload_max_filesize=10M" > /usr/local/etc/php/conf.d/somabazar.ini && \
    echo "post_max_size=10M" >> /usr/local/etc/php/conf.d/somabazar.ini && \
    echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/somabazar.ini && \
    echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/somabazar.ini

WORKDIR /var/www/html

COPY . .

RUN mkdir -p uploads/listings uploads/avatars uploads/stores && \
    chown -R www-data:www-data uploads && \
    chmod -R 755 uploads

EXPOSE 80
