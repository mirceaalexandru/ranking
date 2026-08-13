# Thin dispatcher over two toolchains. CI invokes these same targets, so the
# checks have one definition rather than two that can drift apart.

API_PORT ?= 8081
WEB_PORT ?= 5174

.PHONY: help install install-api install-web validate-api \
        check check-api check-web fix lint-api lint-web test test-api test-web audit-api \
        build up down logs logs-dump smoke

help: ## List the available targets
	@grep -E '^[a-z-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## -- setup ------------------------------------------------------------------

install: install-api install-web ## Install dependencies for both halves

install-api:
	cd api && composer install --no-interaction --no-progress --prefer-dist

install-web:
	cd web && npm ci

## -- verification -----------------------------------------------------------

check: check-api check-web ## Run every gate

check-api: validate-api lint-api test-api audit-api ## Every API gate

check-web: lint-web test-web ## Every frontend gate

validate-api: ## Manifest agrees with its lock file
	cd api && composer validate --strict --no-check-publish

fix: ## Apply the formatters
	cd api && composer fix
	cd web && npm run format

lint-api: ## Formatting, static analysis, container and config linting
	cd api && composer lint
	cd api && composer stan
	cd api && php bin/console lint:container
	cd api && php bin/console lint:yaml config

lint-web: ## Types, lint rules, formatting
	cd web && npm run typecheck
	cd web && npm run lint
	cd web && npm run format:check

test: test-api test-web ## Both test suites

test-api:
	cd api && composer test

test-web:
	cd web && npm run test

audit-api: ## Dependencies with published advisories
	cd api && composer audit

## -- running ----------------------------------------------------------------

build: ## Build both images
	docker compose build

up: ## Start the stack and wait for health
	docker compose up -d --wait --wait-timeout 180

down: ## Stop the stack
	docker compose down --volumes

logs: ## Follow container logs
	docker compose logs -f

logs-dump: ## Print container logs once
	docker compose logs --no-color

smoke: ## Verify the running stack answers
	@test "$$(curl -fsS http://localhost:$(API_PORT)/api/health)" = '{"status":"ok"}' && echo "api ok"
	@curl -fsS http://localhost:$(WEB_PORT)/ | grep -q '<title>AdWords Budgets</title>' && echo "frontend ok"
	@test "$$(curl -fsS http://localhost:$(WEB_PORT)/api/health)" = '{"status":"ok"}' && echo "proxy ok"
