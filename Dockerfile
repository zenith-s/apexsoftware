FROM php:8.2-apache

# Gerekli uzantıları kur ve etkinleştir
RUN docker-php-ext-install pdo pdo_mysql

# Apache Rewrite modülünü aç
RUN a2enmod rewrite

# Çalışma dizinini ayarla
WORKDIR /var/www/html

# Dosyaları kopyala
COPY . /var/www/html/

# İzinleri ver
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
