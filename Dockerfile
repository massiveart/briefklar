# Verify the image tag before first build; adjust PHP version to what symfony/ai-bundle requires.
FROM dunglas/frankenphp:1-php8.4

RUN apt-get update \
 && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-deu poppler-utils unzip git \
 && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /srv
COPY . /srv
RUN cd app && composer install --no-interaction --prefer-dist || true

ENV SERVER_NAME=:8080
WORKDIR /srv/app
