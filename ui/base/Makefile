.PHONY: help install test test-e2e check shell demo

DC = docker compose run --rm

help: ## Show the targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-10s %s\n", $$1, $$2}'

install: ## Install the Composer dependencies (phem/phem from Packagist). composer.lock is not in git: a newer composer.json updates it
	$(DC) dev sh -c 'if [ ! -f composer.lock ] || [ composer.json -nt composer.lock ]; then composer update --no-interaction && touch composer.lock; else composer install --no-interaction; fi'

test: ## Run PHPUnit (render tests of the components)
	$(DC) dev vendor/bin/phpunit $(ARGS)

test-e2e: ## Run the browser tests of the demo (Playwright PHP, no web server). Pass PHPUnit options with ARGS="--filter Dialog"
	$(DC) --build e2e bin/test $(ARGS)

check: ## Check the components with phemc
	$(DC) dev vendor/bin/phemc check src

shell: ## Open a shell in the dev image
	$(DC) dev bash

demo: ## Serve the demo app on http://localhost:8100
	$(DC) --service-ports dev sh -c 'demo/bin/setup && cd demo && php -S 0.0.0.0:8000 -t public'
