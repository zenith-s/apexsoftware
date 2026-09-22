FROM php:8.2-apache

# MySQL (PDO) bağlantısı için gerekli eklentileri yüklüyoruz
RUN docker-php-ext-install pdo pdo_mysql

# Proje dosyalarını Apache dizinine kopyalıyoruz
COPY . /var/www/html/
