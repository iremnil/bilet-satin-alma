FROM php:8.2-apache

# pdo_sqlite derlemek için gereken paketler
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsqlite3-dev pkg-config; \
    docker-php-ext-configure pdo_sqlite --with-pdo-sqlite=/usr; \
    docker-php-ext-install -j"$(nproc)" pdo_sqlite; \
    rm -rf /var/lib/apt/lists/*

# (isteğe bağlı) .htaccess vb. için rewrite
RUN a2enmod rewrite
