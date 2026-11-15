.PHONY: help install test check shell demo

DC = docker compose run --rm

help: ## Show the targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-10s %s\n", $$1, $$2}'

install: ## Install the Composer dependencies (phem/phem is a symlink to ../../phem)
	$(DC) dev composer install --no-interaction

test: ## Run PHPUnit (render tests of the components)
	$(DC) dev vendor/bin/phpunit $(ARGS)

check: ## Check the components with phemc
	$(DC) dev phemc check src

shell: ## Open a shell in the dev image
	$(DC) dev bash

demo: ## Serve the demo app on http://localhost:8100
	$(DC) --service-ports dev sh -c 'cd demo && composer install --no-interaction && bin/console tailwind:build && phemc build src && php -S 0.0.0.0:8000 -t public'
