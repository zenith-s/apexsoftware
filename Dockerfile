FROM php:8.2-apache

# Gerekli PHP eklentilerini kur (PDO SQLite vs.)
RUN docker-php-ext-install pdo pdo_mysql

# Apache mod_rewrite aktif et
RUN a2enmod rewrite

# Proje dosyalarını apache dizinine kopyala
COPY . /var/www/html/

# İzinleri ayarla
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
