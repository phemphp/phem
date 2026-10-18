.PHONY: up test check prod shell vscode vscode-install vscode-test

up:     ## Run the quickstart in watch mode on http://localhost:8080
	docker compose up --build dev

test:   ## Run cargo test and PHPUnit
	docker compose run --rm --build test

check:  ## Run the phemc diagnostics on the quickstart sources
	docker compose run --rm dev phemc check examples/quickstart/src

prod:   ## Build and run the production image on http://localhost:8081
	docker compose up --build prod

vscode: ## Build the VS Code extension: editors/vscode/phem-<version>.vsix
	docker run --rm -e NPM_CONFIG_UPDATE_NOTIFIER=false -e NPM_CONFIG_LOGLEVEL=warn -v "$(CURDIR)/editors/vscode:/ext" -w /ext node:22-alpine sh -c ' \
		npm install --global --no-fund --no-audit npm@12.2.0 > /dev/null && \
		npx --yes @vscode/vsce@4.0.0 package --skip-license --allow-missing-repository'

VSCODE_VERSION = $(shell sed -n 's/^    "version": "\(.*\)",/\1/p' editors/vscode/package.json)

vscode-install: vscode ## Build the VS Code extension and install it (then reload the VS Code window)
	code --install-extension editors/vscode/phem-$(VSCODE_VERSION).vsix --force

vscode-test: ## Run the tests of the VS Code extension
	docker run --rm -v "$(CURDIR)/editors/vscode:/ext" -w /ext node:22-alpine node --test

shell:
	docker compose run --rm dev sh
