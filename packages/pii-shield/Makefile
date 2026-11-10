# pii-shield development. PHP and Composer run in Docker by default (compose.yaml), no host PHP needed.
# With a local PHP 8.4: make <target> DOCKER=0
DOCKER ?= 1
export PII_UID := $(shell id -u)
export PII_GID := $(shell id -g)

ifeq ($(DOCKER),1)
RUN := docker compose run --rm --no-deps php
RUN_STDIO := docker compose run --rm --no-deps -T php
else
RUN :=
RUN_STDIO :=
endif

.PHONY: build install test qa cs-check cs-fix phpstan demo down sh mcp

build:
	docker compose build php

install:
	$(RUN) composer install

test:
	$(RUN) vendor/bin/phpunit

qa: cs-check phpstan

cs-check:
	$(RUN) vendor/bin/php-cs-fixer check --diff

cs-fix:
	$(RUN) vendor/bin/php-cs-fixer fix

phpstan:
	$(RUN) vendor/bin/phpstan analyse --no-progress

# Demo page and APIs on http://127.0.0.1:8088 (Ollama on this machine, Redis in a container).
demo:
ifeq ($(DOCKER),1)
	docker compose up --build demo
else
	APP_ENV=dev PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8088 -t demo/public demo/public/index.php
endif

down:
	docker compose down

# Shell with PHP and Composer.
sh:
	docker compose run --rm --no-deps php sh

# MCP server over STDIO, e.g. for: claude mcp add pii-shield -- make -s -C /path/to/packages/pii-shield mcp
mcp:
	@$(RUN_STDIO) php demo/bin/console mcp:server pii_shield
