FROM php:8.2-apache

# Install PostgreSQL client libraries then PHP extensions.
# ca-certificates is required explicitly: the transactional mail transport
# verifies TLS against api.brevo.com over HTTPS, and without a trust store
# every send fails with "unable to get local issuer certificate". The base
# image may carry one transitively, which is not something to rely on for a
# hard dependency.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install pdo_pgsql pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers deflate expires
COPY pwa-apache.conf /etc/apache2/conf-available/eduportal-pwa.conf
RUN a2enconf eduportal-pwa

# PHP INI settings
RUN { \
        echo "memory_limit = 256M"; \
        # The application caps assignment uploads at 9MB so that the multipart
        # envelope still fits inside post_max_size. Setting post_max_size to the
        # same 10M as the old upload_max_filesize meant any large file silently
        # discarded $_POST and $_FILES, and the user saw "Please select a file".
        echo "upload_max_filesize = 9M"; \
        echo "post_max_size = 12M"; \
        echo "max_execution_time = 30"; \
        # Assignment drafts live in $_SESSION. A 1440s GC lifetime deleted them
        # mid-edit, losing unsaved teacher and student work.
        echo "session.gc_maxlifetime = 86400"; \
        echo "session.use_strict_mode = 1"; \
        echo "session.cookie_httponly = 1"; \
        echo "session.cookie_samesite = Lax"; \
        echo "expose_php = Off"; \
    } > /usr/local/etc/php/conf.d/zz-eduportal.ini

# OPcache configuration
RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.interned_strings_buffer=8"; \
        echo "opcache.max_accelerated_files=10000"; \
        echo "opcache.validate_timestamps=0"; \
        echo "opcache.revalidate_freq=0"; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

# Set document root
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies (after files are available)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress \
    && test -f vendor/aws/aws-sdk-php/src/S3/S3Client.php

# Copy remaining project files
COPY . /var/www/html/
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# Copy entrypoint script and make executable
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set permissions
# uploads/ is excluded from the build context, so `COPY .` no longer creates
# it implicitly. Create it explicitly with the right ownership; PHP reads and
# writes these files directly, and HTTP access to the directory is denied by
# pwa-apache.conf and uploads/.htaccess.
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/ \
    && chmod 0750 /var/www/html/uploads

EXPOSE 80

CMD ["entrypoint.sh"]
