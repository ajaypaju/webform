COMPOSE     := docker compose
COMPOSE_DEV := docker compose -f compose.yaml -f compose.dev.yaml
export UID  := $(shell id -u)
export GID  := $(shell id -g)

.PHONY: up dev key down reset logs sh test test-php test-js tenant reload

## up: build, generate APP_KEY, start everything and wait until healthy (the reviewer command)
up: .env
	$(COMPOSE) build
	$(MAKE) key
	$(COMPOSE) up --build -d --wait

## dev: same as up, with the working tree bind-mounted into api/ingest
dev: .env
	$(COMPOSE_DEV) build
	$(MAKE) key
	$(COMPOSE_DEV) up --build -d --wait

.env:
	cp .env.example .env

## key: fill APP_KEY in .env if empty, via a one-off container running as the host user
key: .env
	@if grep -q '^APP_KEY=$$' .env; then \
		docker run --rm --user "$(UID):$(GID)" -v "$(CURDIR)/.env:/app/.env" webform-app php artisan key:generate --force --ansi; \
	fi

down:
	$(COMPOSE) down

## reset: down and delete volumes (postgres data, redpanda data), so the next up re-initializes roles and topics
reset:
	$(COMPOSE) down -v

logs:
	$(COMPOSE) logs -f --tail=100

sh:
	$(COMPOSE) exec api bash

## test: the container env would shadow phpunit.xml (Laravel reads $_SERVER first), so pass the test values explicitly
test: test-php test-js

## test-php: runs as the owner role (migrations, truncation); the role passwords come from the container's own env
test-php:
	$(COMPOSE) exec api sh -c 'APP_ENV=testing DB_DATABASE=webform_test CACHE_STORE=array DB_USERNAME=webform_owner DB_PASSWORD=$$DB_OWNER_PASSWORD php artisan test'

## test-js: cross-engine regex check (tests/js), no npm dependencies
test-js:
	docker run --rm -v "$(CURDIR):/app:ro" -w /app node:22-alpine node --test tests/js/*.test.mjs

## tenant: create a tenant and print its API key once, as the owner role (usage: make tenant name="Acme")
tenant:
	$(COMPOSE) run --rm --no-deps migrate php artisan tenants:create "$(name)"

reload:
	$(COMPOSE) exec api php artisan octane:reload
	$(COMPOSE) exec ingest php artisan octane:reload
