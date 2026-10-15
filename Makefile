.PHONY: up test check prod shell vscode vscode-install vscode-test

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

VSCODE_VERSION = $(shell sed -n 's/^    "version": "\(.*\)",/\1/p' editors/vscode/package.json)

vscode-install: vscode ## Build the VS Code extension and install it (then reload the VS Code window)
	code --install-extension editors/vscode/rhp-$(VSCODE_VERSION).vsix --force

vscode-test: ## Run the tests of the VS Code extension
	docker run --rm -v "$(CURDIR)/editors/vscode:/ext" -w /ext node:22-alpine node --test

shell:
	docker compose run --rm dev sh
