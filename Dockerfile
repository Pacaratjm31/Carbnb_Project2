FROM php:8.2-apache

# Install PHP extensions required by the application
RUN docker-php-ext-install pdo pdo_mysql

# Use the PHP Apache image's default MPM
RUN a2enmod rewrite headers

# Copy the application into Apache's document root
COPY . /var/www/html/

# Make sure Apache owns the application files
RUN chown -R www-data:www-data /var/www/html

# Apache listens on port 80 inside the container
EXPOSE 80