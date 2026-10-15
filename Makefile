.PHONY: up test check prod shell vscode

up:     ## Run the quickstart in watch mode on http://localhost:8080
	docker compose up --build dev

test:   ## Run cargo test and PHPUnit
	docker compose run --rm --build test

check:  ## Run the rhpc diagnostics on the quickstart sources
	docker compose run --rm dev rhpc check examples/quickstart/src

prod:   ## Build and run the production image on http://localhost:8081
	docker compose up --build prod

vscode: ## Build the VS Code extension: editors/vscode/rhp-<version>.vsix
	docker run --rm -v "$(CURDIR)/editors/vscode:/ext" -w /ext node:22-alpine \
		npx --yes @vscode/vsce@3.6.2 package --skip-license --allow-missing-repository

shell:
	docker compose run --rm dev sh
