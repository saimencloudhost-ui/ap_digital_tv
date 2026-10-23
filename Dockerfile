FROM php:8.2-apache

# Install ekstensi PDO MySQL dan atur timezone Asia/Jakarta
RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite headers

# Konfigurasi php.ini untuk mendukung upload file video berukuran besar (hingga 256MB)
RUN { \
    echo "upload_max_filesize = 256M"; \
    echo "post_max_size = 260M"; \
    echo "memory_limit = 256M"; \
    echo "max_execution_time = 300"; \
    echo "max_input_time = 300"; \
    echo "date.timezone = Asia/Jakarta"; \
} > /usr/local/etc/php/conf.d/ap-digital-tv.ini

WORKDIR /var/www/html

# Salin seluruh file aplikasi ke dalam container
COPY . /var/www/html/

# Pastikan folder uploads tersedia dan memiliki izin tulis untuk Apache (www-data)
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 775 /var/www/html/uploads

EXPOSE 80
