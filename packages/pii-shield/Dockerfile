# pii-shield as a standalone HTTP service (/v1, see docs/openapi.yaml).
#   docker build -t pii-shield packages/pii-shield
#   docker run -p 8080:8080 -e PII_SERVICE_TOKEN=change-me pii-shield
# Service mode: only /v1, the demo page is not included. Without PII_SERVICE_TOKEN every request gets 503.

# Build: runtime dependencies of the server only (demo/composer.json), no dev tools.
FROM php:8.4-cli-alpine AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache unzip
WORKDIR /srv/pii-shield
COPY . .
RUN composer install --working-dir=demo --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader \
 && rm -rf tests .php-cs-fixer.dist.php phpstan.dist.neon phpunit.dist.xml

# Runtime: Alpine (libsodium and pdo_sqlite included)
FROM php:8.4-cli-alpine
COPY --from=build /srv/pii-shield /srv/pii-shield
WORKDIR /srv/pii-shield/demo
RUN mkdir -p var && chown -R www-data:www-data var
USER www-data
ENV PHP_CLI_SERVER_WORKERS=4 \
    PII_SERVICE_ONLY=1 \
    PII_SERVICE_VAULT_STORE=filesystem \
    PII_SERVICE_LEARNED_STORE=file
EXPOSE 8080
HEALTHCHECK CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/v1/health", false, stream_context_create(["http" => ["header" => "Host: localhost"]])) ? 0 : 1);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
