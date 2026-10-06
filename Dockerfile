
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install mysqli gd

COPY apache-limits.conf /etc/apache2/conf-available/apache-limits.conf
RUN a2enconf apache-limits

COPY docker-entrypoint-check.sh /usr/local/bin/docker-entrypoint-check.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-check.sh
CMD ["/usr/local/bin/docker-entrypoint-check.sh"]
