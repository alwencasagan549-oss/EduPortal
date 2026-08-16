FROM php:8.2-apache

# Install PostgreSQL client libraries then PHP extensions
RUN apt-get update && apt-get install -y libpq-dev libzip-dev && docker-php-ext-install pdo_pgsql pgsql zip opcache

# Enable Apache modules
RUN a2enmod rewrite headers deflate expires

# PHP INI settings
RUN { \
        echo "memory_limit = 256M"; \
        echo "upload_max_filesize = 10M"; \
        echo "post_max_size = 10M"; \
        echo "max_execution_time = 30"; \
        echo "session.gc_maxlifetime = 1440"; \
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
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Copy remaining project files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]
