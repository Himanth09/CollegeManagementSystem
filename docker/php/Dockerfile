# Local development image: PHP 8.3 + Apache with the extensions the installer checks for.
FROM php:8.3-apache

RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers expires deflate \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Show PHP errors in the container log, not in pages.
RUN { echo 'display_errors=Off'; echo 'log_errors=On'; echo 'upload_max_filesize=4M'; echo 'post_max_size=8M'; } \
    > /usr/local/etc/php/conf.d/cms.ini
