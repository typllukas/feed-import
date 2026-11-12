export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

DC = docker compose
PHP = $(DC) exec -T php
NODE = $(DC) run --rm node
ARTISAN = $(PHP) php artisan
WORKERS = importer publisher
KAFKA_GROUPS = $(DC) exec -T kafka /opt/kafka/bin/kafka-consumer-groups.sh --bootstrap-server localhost:9092

.DEFAULT_GOAL := help
.PHONY: help setup assets up down logs shell check pint pint-fix phpstan test js image migrate seed reset worker import consume demo demo-change history scale topics consume-group

help: ## List the available targets
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## --- environment ---

setup: ## Fresh clone: .env, the PHP image, dependencies, the app key, the Kafka topics, both databases with the demo data, the built assets. Drops any data already there
	test -f .env || cp .env.example .env
	$(DC) build
	@# the workers need vendor/, the key and the jobs table, so they start with the rest once all three exist
	$(DC) up -d --wait php
	$(PHP) composer install --no-interaction --no-progress
	grep -q '^APP_KEY=base64:' .env || $(ARTISAN) key:generate --no-interaction
	@$(MAKE) --no-print-directory topics
	@$(MAKE) --no-print-directory reset
	@$(MAKE) --no-print-directory up

up: assets ## Start the containers of a set-up clone and wait until they are healthy
	$(DC) up -d --wait
	@$(DC) ps --format '{{.Service}}\t{{.Status}}'

assets: public/build/manifest.json ## Build the stylesheet and the Vue component into public/build when a source changed

node_modules/.package-lock.json: package-lock.json
	$(NODE) npm ci

ASSET_SOURCES := $(shell find resources/css resources/js -type f ! -name '*.test.ts' ! -name 'test-setup.ts' 2>/dev/null)
public/build/manifest.json: node_modules/.package-lock.json vite.config.ts $(ASSET_SOURCES)
	$(NODE) npm run build

topics: ## Create the Kafka topics with three partitions
	$(DC) exec -T kafka sh -s < docker/kafka/create-topics.sh

down: ## Stop the containers (volumes are kept)
	$(DC) down

logs: ## Tail the logs of php, the workers and kafka
	$(DC) logs -f php $(WORKERS) kafka

shell: ## Shell in the php container
	$(DC) exec php bash

## --- quality ---

check: pint phpstan test js ## The whole check: style, static analysis, tests, the Vue component

pint: ## Check the coding style
	$(PHP) vendor/bin/pint --test

pint-fix: ## Fix the coding style
	$(PHP) vendor/bin/pint

phpstan: ## Static analysis (Larastan, level 10)
	$(PHP) vendor/bin/phpstan analyse --no-progress --memory-limit=512M

test: ## Tests (Pest, against the feed_import_test database)
	$(PHP) vendor/bin/pest

js: node_modules/.package-lock.json ## Type check, lint and test the Vue component
	$(NODE) sh -c 'npm run types:check && npm run lint && npm test'

image: ## Build the production images, feed-import:production (php-fpm and the workers) and feed-import-web:production
	docker build --file docker/php/Dockerfile --target production --tag feed-import:production .
	docker build --file docker/php/Dockerfile --target web --tag feed-import-web:production .

## --- data ---

migrate: ## Run the migrations
	$(ARTISAN) migrate --no-interaction

seed: ## Seed the demo data
	$(ARTISAN) db:seed --no-interaction

reset: ## Drop everything, migrate and seed again; the consumer group skips the messages of the dropped data
	@if $(KAFKA_GROUPS) --group feed-import --describe --state 2>&1 | grep -Eq 'Stable|PreparingRebalance|CompletingRebalance'; then \
		echo 'A product-updates:consume of group feed-import is still running (or died less than 45 s ago); stop it and run make reset again.' >&2; \
		exit 1; \
	fi
	$(ARTISAN) db:wipe --database=price_history --no-interaction
	$(ARTISAN) migrate:fresh --seed --no-interaction
	$(KAFKA_GROUPS) --group feed-import --topic product-updates --reset-offsets --to-latest --execute

worker: ## Restart both queue workers after a code change
	$(DC) restart $(WORKERS)

import: ## Queue a feed import: PARTNER=acme FILE=database/seeders/feeds/acme.csv make import
	$(ARTISAN) feed:import $(or $(PARTNER),acme) $(or $(FILE),database/seeders/feeds/acme.csv)

consume: ## Consume product-updates for 10 seconds into the price history
	$(ARTISAN) product-updates:consume --max-time=10

consume-group: SHELL := /bin/bash
consume-group: ## Two consumers in one group; the first stops after 10 s and the second takes all partitions
	set -o pipefail; \
	$(ARTISAN) product-updates:consume --max-time=10 | sed -u 's/^/[one] /' & firstConsumer=$$!; \
	$(ARTISAN) product-updates:consume --max-time=25 | sed -u 's/^/[two] /' & secondConsumer=$$!; \
	wait $$firstConsumer; firstStatus=$$?; wait $$secondConsumer; secondStatus=$$?; \
	test $$firstStatus -eq 0 && test $$secondStatus -eq 0

demo: ## Drop all data, seed, import the sample feed, work the queue, consume the stream
	@# running workers would crash on the tables reset drops and race this queue:work
	$(DC) stop $(WORKERS)
	trap '$(DC) start $(WORKERS)' EXIT; \
	$(MAKE) --no-print-directory reset && \
	$(MAKE) --no-print-directory import && \
	$(ARTISAN) queue:work --queue=feeds,publishing --stop-when-empty
	@$(MAKE) --no-print-directory consume

CHANGED_FEED = storage/app/private/acme-changed.csv
demo-change: ## After make demo: import the sample feed with one price changed, consume, show that product's history
	sed 's/^ACM-001;Tool 001;12.90;/ACM-001;Tool 001;9.90;/' database/seeders/feeds/acme.csv > $(CHANGED_FEED)
	$(DC) stop $(WORKERS)
	trap '$(DC) start $(WORKERS)' EXIT; \
	$(ARTISAN) feed:import acme $(CHANGED_FEED) && \
	$(ARTISAN) queue:work --queue=feeds,publishing --stop-when-empty
	@$(MAKE) --no-print-directory consume
	@$(MAKE) --no-print-directory history SKU=ACM-001

history: ## The consumer's price history, newest first: make history, or SKU=ACM-001 make history
	$(DC) exec -T mysql sh -c 'MYSQL_PWD="$$MYSQL_ROOT_PASSWORD" exec mysql -uroot price_history --table -e "$$0"' \
		"SELECT sku, price, currency, feed_import_id, created_at FROM product_price_changes $(if $(SKU),WHERE sku = '$(SKU)') ORDER BY id DESC LIMIT 20"

SCALE_FEED = storage/app/private/scale.csv
scale: ## Drop all data, import a generated feed of ROWS products (default 100000), then the same feed with 1 % of prices changed
	@# one foreground worker, so each job's duration is printed here; the containers stop before reset drops their tables
	$(DC) stop $(WORKERS)
	trap '$(DC) start $(WORKERS)' EXIT; \
	$(MAKE) --no-print-directory reset && \
	$(ARTISAN) feed:generate $(SCALE_FEED) --rows=$(or $(ROWS),100000) && \
	$(ARTISAN) feed:import acme $(SCALE_FEED) && \
	$(ARTISAN) queue:work --queue=feeds,publishing --stop-when-empty && \
	$(ARTISAN) feed:generate $(SCALE_FEED) --rows=$(or $(ROWS),100000) --changed=1 && \
	$(ARTISAN) feed:import acme $(SCALE_FEED) && \
	$(ARTISAN) queue:work --queue=feeds,publishing --stop-when-empty
