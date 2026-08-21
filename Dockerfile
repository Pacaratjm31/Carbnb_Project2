FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql
RUN a2dismod mpm_prefork mpm_worker mpm_event || true; \
	a2enmod mpm_event

COPY . /var/www/html/
