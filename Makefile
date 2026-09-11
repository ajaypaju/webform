COMPOSE     := docker compose
COMPOSE_DEV := docker compose -f compose.yaml -f compose.dev.yaml
export UID  := $(shell id -u)
export GID  := $(shell id -g)

.PHONY: up dev key down logs sh test reload

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

logs:
	$(COMPOSE) logs -f --tail=100

sh:
	$(COMPOSE) exec api bash

## test: the container env would shadow phpunit.xml (Laravel reads $_SERVER first), so pass the test values explicitly
test:
	$(COMPOSE) exec -e APP_ENV=testing -e DB_DATABASE=webform_test -e CACHE_STORE=array api php artisan test

reload:
	$(COMPOSE) exec api php artisan octane:reload
	$(COMPOSE) exec ingest php artisan octane:reload
