FROM php:8.2-apache

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable required Apache modules
RUN a2enmod rewrite headers

# Disable ALL other MPMs and enable ONLY prefork
RUN a2dismod mpm_event mpm_worker mpm_itk 2>/dev/null || true \
    && rm -f /etc/apache2/mods-enabled/mpm_event.* \
    && rm -f /etc/apache2/mods-enabled/mpm_worker.* \
    && rm -f /etc/apache2/mods-enabled/mpm_itk.* \
    && a2enmod mpm_prefork

# Set working directory
WORKDIR /var/www/html

# Copy website
COPY . /var/www/html/

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Make Apache listen on Railway's port
RUN sed -i 's/Listen 80/Listen 80/' /etc/apache2/ports.conf

EXPOSE 80

# Verify Apache configuration during build
RUN apache2ctl -t

# Start Apache
CMD ["apache2-foreground"]