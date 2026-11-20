.PHONY: help install test test-e2e check shell demo demo-watch

# The services of phem/ui-base are in the compose file of the repository: the phem image, with the
# phem of this repository and the phemc that the entrypoint builds from compiler/.
DC = docker compose -f ../../docker/compose.yaml run --rm

help: ## Show the targets
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-10s %s\n", $$1, $$2}'

install: ## Install the Composer dependencies (phem/phem from the root of the repository). composer.lock is not in git: a newer composer.json updates it
	$(DC) ui sh -c 'if [ ! -f composer.lock ] || [ composer.json -nt composer.lock ]; then composer update --no-interaction && touch composer.lock; else composer install --no-interaction; fi'

test: check ## Check the components and the examples, then run PHPUnit (render tests of the components)
	$(DC) ui vendor/bin/phpunit $(ARGS)

test-e2e: ## Run the browser tests of the demo (Playwright PHP, no web server). Pass PHPUnit options with ARGS="--filter Dialog"
	$(DC) --build ui-e2e bin/test $(ARGS)

check: install ## Check the components and the examples with phemc, and run the retrieval eval of the search
	$(DC) ui sh -c '"$$PHEMC_BIN" check tests/app/src --examples-of phem/ui-base && "$$PHEMC_BIN" search --eval tests/retrieval.yaml --src tests/app/src'

shell: ## Open a shell in the image
	$(DC) ui bash

demo: ## Build the production image of the demo (demo/etc/Dockerfile) and serve it with FrankenPHP on http://localhost:8100
	@test -f demo/.env.local || printf 'APP_SECRET=%s\n' "$$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')" > demo/.env.local
	docker build -f demo/etc/Dockerfile -t phem-ui-base-demo ../..
	docker run --rm --init -p 8100:8080 phem-ui-base-demo

demo-watch: ## Serve the demo of this repository in the dev environment on http://localhost:8100: FrankenPHP restarts after a change (demo/etc/watch)
	docker build -f demo/etc/Dockerfile --target watch -t phem-ui-base-demo-watch ../..
	docker run --rm --init -p 8100:8080 -v "$(abspath ../..):/app" phem-ui-base-demo-watch
