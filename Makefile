PACKAGES := packages/pii-shield packages/ris-client

.PHONY: install test qa up down

install:
	@for p in $(PACKAGES); do [ -f $$p/composer.json ] && (cd $$p && composer install) || true; done
	@[ -f app/composer.json ] && (cd app && composer install) || true

test:
	@for p in $(PACKAGES); do [ -f $$p/phpunit.xml.dist ] && (cd $$p && vendor/bin/phpunit) || true; done
	@[ -f app/phpunit.xml.dist ] && (cd app && bin/phpunit) || true

qa:
	@echo "TODO (day 0): wire php-cs-fixer @Symfony and phpstan level 8 per package"

up:
	docker compose up -d --build

down:
	docker compose down
