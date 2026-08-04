FROM php:8.2-apache

# curl extension is required by config.php's decart_request() helper
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

# App code
COPY . /var/www/html/

# Writable dirs the app creates at runtime for temp reference images + rate limiting
RUN mkdir -p /var/www/html/tmp_references /var/www/html/tmp_ratelimit \
    && chown -R www-data:www-data /var/www/html/tmp_references /var/www/html/tmp_ratelimit

# Configure Apache to listen on whatever $PORT the platform gives us at
# container start (Railway assigns this dynamically; Render can use a fixed
# one). See docker-entrypoint.sh.
RUN chmod +x /var/www/html/docker-entrypoint.sh
EXPOSE 10000
ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
