FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive
ENV APACHE_RUN_USER=www-data
ENV APACHE_RUN_GROUP=www-data
ENV APACHE_LOG_DIR=/var/log/apache2
ENV APACHE_RUN_DIR=/var/run/apache2
ENV APACHE_LOCK_DIR=/var/lock/apache2
ENV APACHE_PID_FILE=/var/run/apache2/apache2.pid

RUN apt-get update && apt-get install -y \
    apache2 \
    php8.1 \
    php8.1-mysql \
    php8.1-gd \
    php8.1-mbstring \
    php8.1-zip \
    php8.1-exif \
    php8.1-xml \
    php8.1-curl \
    libapache2-mod-php8.1 \
    curl \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Enable only what we need - prefork is default with mod_php
RUN a2enmod rewrite php8.1 && \
    a2dismod mpm_event mpm_worker 2>/dev/null || true && \
    a2enmod mpm_prefork

# Apache virtual host config
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

RUN echo 'ServerName localhost' >> /etc/apache2/apache2.conf

# PHP configuration
RUN echo "upload_max_filesize=10M\npost_max_size=10M\nmemory_limit=256M\nmax_execution_time=300" \
    > /etc/php/8.1/apache2/conf.d/somabazar.ini

WORKDIR /var/www/html

COPY . .

RUN mkdir -p uploads/listings uploads/avatars uploads/stores && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 uploads

EXPOSE 80

CMD ["apache2ctl", "-D", "FOREGROUND"]
