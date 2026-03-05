FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    zip unzip \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql mysqli mbstring gd zip

# Fix Apache MPM - disable all then enable only prefork
RUN cd /etc/apache2/mods-enabled && \
    rm -f mpm_event.conf mpm_event.load mpm_worker.conf mpm_worker.load 2>/dev/null ; \
    ln -sf ../mods-available/mpm_prefork.conf mpm_prefork.conf 2>/dev/null ; \
    ln -sf ../mods-available/mpm_prefork.load mpm_prefork.load 2>/dev/null ; \
    a2enmod rewrite

# Copy project files
COPY . /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# Apache AllowOverride
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

EXPOSE 80
CMD ["apache2-foreground"]
