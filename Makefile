# Makefile
.PHONY: help build up rebuild down logs ps \
        build-dev up-dev rebuild-dev down-dev logs-dev ps-dev \
        install-dev ready

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
	@echo ""
	@echo "Dev:"
	@echo "  make build-dev    - Build dev api image (cached)"
	@echo "  make up-dev       - Start dev stack (build if needed)"
	@echo "  make rebuild-dev  - Rebuild dev api (no cache) and start"
	@echo "  make install-dev  - Run composer install in dev api (after up-dev)"
	@echo "  make logs-dev     - Tail dev logs"
	@echo "  make ps-dev       - Show dev services"
	@echo "  make down-dev     - Stop dev stack and remove volumes"
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

# Run composer install after code is bind-mounted (dev stage)
install-dev:
	$(DEV_ENV) $(COMPOSE_DEV) run --rm api composer install

logs-dev:
	$(DEV_ENV) $(COMPOSE_DEV) logs -f --tail=200

ps-dev:
	$(DEV_ENV) $(COMPOSE_DEV) ps

down-dev:
	$(DEV_ENV) $(COMPOSE_DEV) down -v

# -------------------
# UTIL
# -------------------
cache-clear:
	docker compose exec api php bin/console cache:clear --no-warmup

cache-warm:
	docker compose exec api php bin/console cache:warmup

health:
	@echo "Waiting for http://localhost:8080/health/ready ..."
	@SECONDS=0; \
	until curl -sf http://localhost:8080/health/ready >/dev/null; do \
	  sleep 0.5; \
	  if [ $$SECONDS -gt 60 ]; then echo "Timeout waiting for /health/ready"; exit 1; fi; \
	done; \
	echo "Health: OK"

