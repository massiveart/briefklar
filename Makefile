PACKAGES := packages/pii-shield packages/ris-client

.PHONY: install test qa cs-check cs-fix phpstan up down

install:
	@set -e; for p in $(PACKAGES); do if [ -f $$p/composer.json ]; then (cd $$p && composer install); fi; done
	@set -e; if [ -f app/composer.json ]; then (cd app && composer install); fi

test:
	@set -e; for p in $(PACKAGES); do if [ -f $$p/phpunit.xml.dist ]; then (cd $$p && vendor/bin/phpunit); fi; done
	@set -e; if [ -f app/phpunit.xml.dist ]; then (cd app && bin/phpunit); fi

qa: cs-check phpstan

cs-check:
	@set -e; for p in $(PACKAGES); do if [ -f $$p/.php-cs-fixer.dist.php ]; then echo "== cs-check $$p"; (cd $$p && vendor/bin/php-cs-fixer check --diff); fi; done

cs-fix:
	@set -e; for p in $(PACKAGES); do if [ -f $$p/.php-cs-fixer.dist.php ]; then (cd $$p && vendor/bin/php-cs-fixer fix); fi; done

phpstan:
	@set -e; for p in $(PACKAGES); do if [ -f $$p/phpstan.dist.neon ]; then echo "== phpstan $$p"; (cd $$p && vendor/bin/phpstan analyse --no-progress); fi; done

up:
	docker compose up -d --build

down:
	docker compose down
