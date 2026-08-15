FROM php:8.2-apache

# Install PostgreSQL extensions (PDO + pgsql)
RUN docker-php-ext-install pdo_pgsql pgsql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set document root
ENV APACHE_DOCUMENT_ROOT /var/www/html

# Copy project files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80

CMD ["apache2-foreground"]
