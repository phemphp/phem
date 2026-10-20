.PHONY: up test check prod shell lsp vscode vscode-install phpstorm phpstorm-install

up:     ## Run the quickstart in watch mode on http://localhost:8080
	docker compose up --build dev

test:   ## Run cargo test and PHPUnit
	docker compose run --rm --build test

check:  ## Run the phemc diagnostics on the quickstart sources
	docker compose run --rm dev phemc check examples/quickstart/src

prod:   ## Build and run the production image on http://localhost:8081
	docker compose up --build prod

lsp:    ## Build the language server for the editors: editors/server/phem-lsp.wasm
	docker compose run --rm --build --no-deps dev sh -c ' \
		cargo build --release --quiet --manifest-path compiler/Cargo.toml -p phemc-lsp --bin phem-lsp --target wasm32-wasip1 && \
		cp /cargo-target/wasm32-wasip1/release/phem-lsp.wasm editors/server/phem-lsp.wasm'

vscode: lsp ## Build the VS Code extension: editors/vscode/phem-<version>.vsix
	rm -rf editors/vscode/server && mkdir editors/vscode/server && cp editors/server/phem-lsp.cjs editors/server/phem-lsp.wasm editors/vscode/server/
	docker run --rm -e NPM_CONFIG_UPDATE_NOTIFIER=false -e NPM_CONFIG_LOGLEVEL=warn -v "$(CURDIR)/editors/vscode:/ext" -w /ext node:22-alpine sh -c ' \
		npm install --global --no-fund --no-audit npm@12.2.0 > /dev/null && \
		npm install --omit=dev --no-fund --no-audit && \
		npx --yes @vscode/vsce@4.0.0 package --skip-license --allow-missing-repository'

VSCODE_VERSION = $(shell sed -n 's/^    "version": "\(.*\)",/\1/p' editors/vscode/package.json)

vscode-install: vscode ## Build the VS Code extension and install it (then reload the VS Code window)
	code --install-extension editors/vscode/phem-$(VSCODE_VERSION).vsix --force

PHPSTORM_VERSION = $(shell sed -n 's/^pluginVersion = //p' editors/phpstorm/gradle.properties)
# The plugins directory of the newest PhpStorm configuration (macOS).
PHPSTORM_PLUGINS ?= $(shell ls -d "$(HOME)/Library/Application Support/JetBrains/"PhpStorm* 2>/dev/null | sort | tail -n 1)/plugins

phpstorm: lsp ## Build the PhpStorm plugin: editors/phpstorm/build/distributions/phem-phpstorm-<version>.zip (needs Java 21 and Gradle)
	gradle --quiet -p editors/phpstorm buildPlugin

phpstorm-install: phpstorm ## Build the PhpStorm plugin and install it (then restart PhpStorm)
	mkdir -p "$(PHPSTORM_PLUGINS)"
	rm -rf "$(PHPSTORM_PLUGINS)/phem-phpstorm"
	unzip -q editors/phpstorm/build/distributions/phem-phpstorm-$(PHPSTORM_VERSION).zip -d "$(PHPSTORM_PLUGINS)"
	@echo "Installed in $(PHPSTORM_PLUGINS)/phem-phpstorm. Restart PhpStorm."

shell:
	docker compose run --rm dev sh
