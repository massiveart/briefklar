# pii-shield as a standalone HTTP service (/v1, see docs/openapi.yaml).
#   docker build -t pii-shield packages/pii-shield
#   docker run -p 8080:8080 -e PII_SERVICE_TOKEN=change-me pii-shield
# Service mode: only /v1, the demo page is not included. Without PII_SERVICE_TOKEN every request gets 503.
FROM php:8.4-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*

WORKDIR /srv
COPY composer.json ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize && mkdir -p demo/var && chown -R www-data:www-data demo/var

USER www-data
ENV PHP_CLI_SERVER_WORKERS=4 \
    PII_SERVICE_ONLY=1 \
    PII_SERVICE_VAULT_STORE=filesystem \
    PII_SERVICE_LEARNED_STORE=file
EXPOSE 8080
HEALTHCHECK CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/v1/health", false, stream_context_create(["http" => ["header" => "Host: localhost"]])) ? 0 : 1);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "demo/public", "demo/public/index.php"]
