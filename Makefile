PACKAGES := packages/pii-shield packages/ris-client

# PHP, Composer and Tesseract run inside the app container by default, no host PHP needed.
# Use `make <target> DOCKER=0` to run with a local PHP 8.4 instead.
DOCKER ?= 1
ifeq ($(DOCKER),1)
RUN := docker compose run --rm --no-deps -w /srv app
else
RUN :=
endif

.PHONY: build install test qa cs-check cs-fix phpstan demo up down sh composer console

build:
	docker compose build app

install:
	@$(RUN) sh -c 'set -e; for p in $(PACKAGES); do if [ -f $$p/composer.json ]; then echo "== composer install $$p"; (cd $$p && composer install); fi; done; \
		if [ -f app/composer.json ]; then echo "== composer install app"; (cd app && composer install); fi'

test:
	@$(RUN) sh -c 'set -e; for p in $(PACKAGES); do if [ -f $$p/phpunit.dist.xml ] || [ -f $$p/phpunit.xml.dist ]; then (cd $$p && vendor/bin/phpunit); fi; done; \
		if [ -f app/phpunit.dist.xml ] || [ -f app/phpunit.xml.dist ]; then (cd app && bin/phpunit); fi'

qa: cs-check phpstan

cs-check:
	@$(RUN) sh -c 'set -e; for p in $(PACKAGES); do if [ -f $$p/.php-cs-fixer.dist.php ]; then echo "== cs-check $$p"; (cd $$p && vendor/bin/php-cs-fixer check --diff); fi; done; \
		if [ -f app/.php-cs-fixer.dist.php ]; then echo "== cs-check app"; (cd app && vendor/bin/php-cs-fixer check --diff); fi'

cs-fix:
	@$(RUN) sh -c 'set -e; for p in $(PACKAGES); do if [ -f $$p/.php-cs-fixer.dist.php ]; then (cd $$p && vendor/bin/php-cs-fixer fix); fi; done; \
		if [ -f app/.php-cs-fixer.dist.php ]; then (cd app && vendor/bin/php-cs-fixer fix); fi'

phpstan:
	@$(RUN) sh -c 'set -e; for p in $(PACKAGES); do if [ -f $$p/phpstan.dist.neon ]; then echo "== phpstan $$p"; (cd $$p && vendor/bin/phpstan analyse --no-progress); fi; done; \
		if [ -f app/phpstan.dist.neon ]; then echo "== phpstan app"; (cd app && bin/console cache:warmup --env=test -q && vendor/bin/phpstan analyse --no-progress); fi'

# Interactive shell in the app container (PHP, Composer, Tesseract available).
sh:
	docker compose run --rm --no-deps app bash

# Examples: make composer c="require foo/bar"   make console c="debug:container"
composer:
	@$(RUN) sh -c 'cd app && composer $(c)'

console:
	@$(RUN) sh -c 'cd app && bin/console $(c)'

# pii-shield demo on http://127.0.0.1:8088, in Docker (DOCKER=0: local PHP). See packages/pii-shield/Makefile.
demo:
	@$(MAKE) -C packages/pii-shield demo

up:
	docker compose up -d --build

down:
	docker compose down
