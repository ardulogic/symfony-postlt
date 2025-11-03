# Makefile
.PHONY: help build up rebuild down logs ps exec sh fresh-seed test \
        build-dev up-dev rebuild-dev down-dev logs-dev ps-dev install-dev sh-dev \
        fresh-seed-dev fresh-diff-seed-dev test-dev cache-clear cache-warm health \
        worker worker-dev

# If your user isn't in the "docker" group, this will auto-fallback to sudo
DOCKER := $(shell groups | grep -qw docker && echo docker || echo "sudo docker")

BASE_COMPOSE := docker-compose.yml
DEV_COMPOSE  := docker-compose.dev.yml

COMPOSE      := $(DOCKER) compose -f $(BASE_COMPOSE)
COMPOSE_DEV  := $(DOCKER) compose -f $(BASE_COMPOSE) -f $(DEV_COMPOSE)

# Environment for dev target
DEV_ENV := BUILD_TARGET=dev APP_ENV=dev APP_DEBUG=1

help:
	@echo "Attention: Do not run this with sudo!"
	@echo "Prod:"
	@echo "  make build        - Build prod images (cached)"
	@echo "  make up           - Start prod stack (build if needed)"
	@echo "  make rebuild      - Rebuild prod images (no cache) and start"
	@echo "  make logs         - Tail prod logs"
	@echo "  make ps           - Show prod services"
	@echo "  make down         - Stop prod stack and remove volumes"
	@echo "  make sh           - Start shell within the container"
	@echo "  make seed-fresh   - !Caution. Recreate database and seed initial data."
	@echo "  make test   	   - Run Unit tests"
	@echo "  make worker       - Run Messenger worker (prod) --all --keepalive --sleep=1 -vv"
	@echo ""
	@echo "Dev:"
	@echo "  make build-dev    - Build dev api image (cached)"
	@echo "  make up-dev       - Start dev stack (build if needed)"
	@echo "  make rebuild-dev  - Rebuild dev api (no cache) and start"
	@echo "  make install-dev  - Run composer install in dev api (after up-dev)"
	@echo "  make logs-dev     - Tail dev logs"
	@echo "  make ps-dev       - Show dev services"
	@echo "  make down-dev     - Stop dev stack and remove volumes"
	@echo "  make sh-dev       - Start shell within the container"
	@echo "  make fresh-seed-dev      - !Caution. Recreate whole database and seed."
	@echo "  make fresh-diff-seed-dev - !Caution. Make db diff, migrate, purge data and seed."
	@echo "  make test-dev     - Run Unit tests"
	@echo "  make worker-dev   - Run Messenger worker (dev) --all --keepalive --sleep=1 -vv"
	@echo ""
	@echo "Utilities:"
	@echo "  make cache-clear  - Clear Symfony cache in api"
	@echo "  make cache-warm   - Warm Symfony cache in api"
	@echo "  make health       - Wait until http://localhost:8080/health/ready is 200"

# -------------------
# PROD
# -------------------
build:
	$(COMPOSE) build

up:
	$(COMPOSE) up -d --build

rebuild:
	$(COMPOSE) build --no-cache --pull
	$(COMPOSE) up -d

down:
	$(COMPOSE) down -v

logs:
	$(COMPOSE) logs -f --tail=200

ps:
	$(COMPOSE) ps

exec:
	@$(COMPOSE) exec $(INTERACTIVE_FLAG) api bash -lc '$(CMD)'

sh:
	@$(COMPOSE) exec -it api bash

fresh-seed:
	$(COMPOSE) exec api bash -lc '\
		set -euo pipefail; \
		php bin/console doctrine:database:drop --if-exists --force && \
		php bin/console doctrine:database:create --if-not-exists && \
		php bin/console doctrine:migrations:migrate -n && \
		php bin/console doctrine:fixtures:load -n --group=seed \

test:
	$(COMPOSE) exec api bash -lc '\
		set -euo pipefail; \
		find var/log/test -type f -name "*.log" -exec truncate -s 0 {} \; || true; \
		./vendor/bin/phpunit --testdox --colors=always --stop-on-defect'

worker:
	$(COMPOSE) exec -T api bash -lc ' \
		php bin/console messenger:consume --all --keepalive --sleep=1 -vv \
	'


# -------------------
# DEV
# -------------------
build-dev:
	$(DEV_ENV) $(COMPOSE_DEV) build api

up-dev:
	$(DEV_ENV) $(COMPOSE_DEV) up --build

rebuild-dev:
	$(DEV_ENV) $(COMPOSE_DEV) build --no-cache --pull api
	$(DEV_ENV) $(COMPOSE_DEV) up

install-dev:
	$(DEV_ENV) $(COMPOSE_DEV) run --rm api composer install

logs-dev:
	$(DEV_ENV) $(COMPOSE_DEV) logs -f --tail=200

ps-dev:
	$(DEV_ENV) $(COMPOSE_DEV) ps

down-dev:
	$(DEV_ENV) $(COMPOSE_DEV) down -v

sh-dev:
	@$(DEV_ENV) $(COMPOSE_DEV) exec -it api bash

fresh-seed-dev:
	$(DEV_ENV) $(COMPOSE_DEV) exec -T api bash -lc ' \
		set -euo pipefail; \
		php bin/console doctrine:database:drop --if-exists --force && \
		php bin/console doctrine:database:create --if-not-exists && \
		php bin/console doctrine:migrations:migrate -n && \
		php bin/console doctrine:fixtures:load -n --group=seed \
	'
fresh-diff-seed-dev:
	$(DEV_ENV) $(COMPOSE_DEV) exec -T api bash -lc ' \
		set -euo pipefail; \
		php bin/console doctrine:migrations:diff && \
		php bin/console doctrine:migrations:migrate -n && \
		php bin/console doctrine:fixtures:load -n --group=seed \
	'

test-dev:
	$(DEV_ENV) $(COMPOSE_DEV) exec api bash -lc '\
		set -euo pipefail; \
		find var/log/test -type f -name "*.log" -exec truncate -s 0 {} \; || true; \
		./vendor/bin/phpunit --testdox --colors=always --stop-on-defect'

worker-dev:
	$(DEV_ENV) $(COMPOSE_DEV) exec -T api bash -lc ' \
		php bin/console messenger:consume --all --keepalive --sleep=1 -vv \
	'

# -------------------
# UTIL
# -------------------
cache-clear:
	docker compose exec api php bin/console cache:clear --no-warmup

cache-warm:
	docker compose exec api php bin/console cache:warmup

health:
	@echo "Waiting for http://localhost:8080/api/health/ready ..."
	@SECONDS=0; \
	until curl -sf http://localhost:8080/api/health/ready >/dev/null; do \
	  sleep 0.5; \
	  if [ $$SECONDS -gt 60 ]; then echo "Timeout waiting for /api/health/ready"; exit 1; fi; \
	done; \
	echo "Health: OK"

