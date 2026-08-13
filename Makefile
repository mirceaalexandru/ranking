# Thin dispatcher. CI runs these same targets, so "works locally, fails in CI"
# has one fewer cause.

.PHONY: help install check check-api check-web test up down logs smoke

help:
	@grep -E '^[a-z-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## Install dependencies for both halves
	cd api && composer install
	cd web && npm ci

check: check-api check-web ## Run every gate

check-api: ## Formatting, static analysis, container lint, tests, audit
	cd api && composer lint
	cd api && composer stan
	cd api && php bin/console lint:container
	cd api && php bin/console lint:yaml config
	cd api && composer test
	cd api && composer audit

check-web: ## Type check, lint, formatting, tests
	cd web && npm run check

test: ## Just the test suites
	cd api && composer test
	cd web && npm run test

up: ## Start the stack
	docker compose up -d --wait

down: ## Stop the stack
	docker compose down --volumes

logs: ## Follow container logs
	docker compose logs -f

smoke: ## Verify the running stack answers
	curl -fsS http://localhost:$${API_PORT:-8081}/api/health && echo
	curl -fsS http://localhost:$${WEB_PORT:-5174}/ >/dev/null && echo "frontend ok"
