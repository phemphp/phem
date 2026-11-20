.PHONY: test test-js test-e2e test-claude-code eval-agents ui-test ui-test-e2e ui-check ui-demo ui-demo-watch bench shell lsp vscode vscode-install phpstorm phpstorm-install phpstorm-verify phpstorm-publish docs docs-build

# The build context of the dev image is the repository.
COMPOSE = docker compose -f docker/compose.yaml

test: test-js test-e2e test-claude-code ## Run cargo test, PHPUnit, the JS tests, the e2e tests and the tests of the Claude Code plugin
	$(COMPOSE) run --rm --build dev

eval-agents: ## Run the agent eval of the Claude Code plugin: real `claude -p` sessions, with the account of the user (about $0.30 a run). Pass options with ARGS="--tasks=otp-form --repeat=2"
	php editors/claude-code/evals/run.php --docker $(ARGS)

test-claude-code: ## Run the tests of the Claude Code plugin (editors/claude-code/): the phemc check hook, the MCP launcher, the skills
	$(COMPOSE) run --rm -T --entrypoint sh dev -c 'cargo build --release --quiet --manifest-path compiler/Cargo.toml -p phemc-cli && PHEMC_BIN=/cargo-target/release/phemc php editors/claude-code/tests/run.php'

test-js: ## Run the tests of the phem Stimulus controller (Vitest, jsdom)
	docker run --rm -e NPM_CONFIG_UPDATE_NOTIFIER=false -e NPM_CONFIG_LOGLEVEL=warn -v "$(CURDIR)/assets:/assets" -v "$(CURDIR)/src/Test/Resources:/src/Test/Resources:ro" -w /assets node:22-alpine sh -c ' \
		npm install --ignore-scripts --no-fund --no-audit && \
		npm test'

test-e2e: ## Run the e2e tests in Chromium (e2e/, Playwright PHP). Pass PHPUnit options with ARGS="--filter Smoke"
	$(COMPOSE) run --rm --build e2e /app/e2e/bin/test $(ARGS)

ui-test: ## Run the PHPUnit tests of phem/ui-base (ui/base/), with the phem of this repository
	$(MAKE) -C ui/base test ARGS="$(ARGS)"

ui-test-e2e: ## Run the browser tests of the phem/ui-base demo. Pass PHPUnit options with ARGS="--filter Dialog"
	$(MAKE) -C ui/base test-e2e ARGS="$(ARGS)"

ui-check: ## Check the phem/ui-base components with the phemc of this repository
	$(MAKE) -C ui/base check

ui-demo: ## Build the phem/ui-base demo image and serve it with FrankenPHP on http://localhost:8100, with the phem of this repository
	$(MAKE) -C ui/base demo

ui-demo-watch: ## Serve the phem/ui-base demo of this repository on http://localhost:8100, and restart FrankenPHP after each change
	$(MAKE) -C ui/base demo-watch

bench:  ## Run the benchmark of the client/server flow (bench/run.php): server time, bytes, requests
	$(COMPOSE) run --rm --build dev php bench/run.php

lsp:    ## Build the language server for the editors: editors/server/phem-lsp.wasm
	$(COMPOSE) run --rm --build --no-deps dev sh -c ' \
		cargo build --release --quiet --manifest-path compiler/Cargo.toml -p phemc-lsp --bin phem-lsp --target wasm32-wasip1 && \
		cp /cargo-target/wasm32-wasip1/release/phem-lsp.wasm editors/server/phem-lsp.wasm'

vscode: lsp ## Build the VS Code extension: editors/vscode/phem-<version>.vsix
	rm -rf editors/vscode/server && mkdir editors/vscode/server && cp editors/server/phem-lsp.cjs editors/server/phem-lsp.wasm editors/vscode/server/
	docker run --rm -e NPM_CONFIG_UPDATE_NOTIFIER=false -e NPM_CONFIG_LOGLEVEL=warn -v "$(CURDIR)/editors/vscode:/ext" -w /ext node:22-alpine sh -c ' \
		npm install --global --no-fund --no-audit npm@12.2.0 > /dev/null && \
		npm install --ignore-scripts --no-fund --no-audit && \
		npx --yes @vscode/vsce@4.0.0 package'

VSCODE_VERSION = $(shell sed -n 's/^    "version": "\(.*\)",/\1/p' editors/vscode/package.json)
# The VS Code profile to install the extension in. Empty means the Default profile.
VSCODE_PROFILE ?=

vscode-install: vscode ## Build the VS Code extension and install it (then reload the VS Code window)
	code --install-extension editors/vscode/phem-$(VSCODE_VERSION).vsix --force $(if $(VSCODE_PROFILE),--profile "$(VSCODE_PROFILE)")

PHPSTORM_VERSION = $(shell sed -n 's/^pluginVersion = //p' editors/phpstorm/gradle.properties)
# The oldest PhpStorm that the plugin supports. Verify and publish a build against it.
PHPSTORM_MIN = $(shell sed -n 's/^phpstormMinVersion = //p' editors/phpstorm/gradle.properties)
# The plugins directory of the newest PhpStorm configuration (macOS).
PHPSTORM_PLUGINS ?= $(shell ls -d "$(HOME)/Library/Application Support/JetBrains/"PhpStorm* 2>/dev/null | sort | tail -n 1)/plugins

phpstorm: lsp ## Build the PhpStorm plugin: editors/phpstorm/build/distributions/phem-phpstorm-<version>.zip (needs Java 21 and Gradle)
	gradle --quiet -p editors/phpstorm buildPlugin

phpstorm-install: phpstorm ## Build the PhpStorm plugin and install it (then restart PhpStorm)
	mkdir -p "$(PHPSTORM_PLUGINS)"
	rm -rf "$(PHPSTORM_PLUGINS)/phem-phpstorm"
	unzip -q editors/phpstorm/build/distributions/phem-phpstorm-$(PHPSTORM_VERSION).zip -d "$(PHPSTORM_PLUGINS)"
	@echo "Installed in $(PHPSTORM_PLUGINS)/phem-phpstorm. Restart PhpStorm."

phpstorm-verify: lsp ## Build the PhpStorm plugin against the oldest supported PhpStorm and verify it against all the supported versions (downloads each IDE)
	gradle -p editors/phpstorm verifyPlugin -PphpstormVersion=$(PHPSTORM_MIN) -PverifyRecommended=true

# The JetBrains Marketplace token. An environment variable wins. Otherwise the recipe reads it
# from the macOS Keychain. The value never goes in a file, in the shell, or in the output.
PHPSTORM_TOKEN_SERVICE = phem-jetbrains-marketplace

phpstorm-publish: lsp ## Build, verify and publish a new version of the PhpStorm plugin to the JetBrains Marketplace (token in the Keychain)
	@token="$${PUBLISH_TOKEN:-$$(security find-generic-password -s $(PHPSTORM_TOKEN_SERVICE) -w 2>/dev/null)}"; \
	if [ -z "$$token" ]; then \
		echo "No JetBrains Marketplace token. Add it to the Keychain (it asks for the value):"; \
		echo '  security add-generic-password -a "$$USER" -s $(PHPSTORM_TOKEN_SERVICE) -w'; \
		exit 1; \
	fi; \
	echo "gradle -p editors/phpstorm verifyPlugin publishPlugin -PphpstormVersion=$(PHPSTORM_MIN) -PverifyRecommended=true"; \
	PUBLISH_TOKEN="$$token" gradle -p editors/phpstorm verifyPlugin publishPlugin -PphpstormVersion=$(PHPSTORM_MIN) -PverifyRecommended=true

# The docs website (website/, Astro). It reads docs/ and the grammar of editors/, so the container
# gets the repository. git gives the "Last updated" date of each page. node_modules stays in a
# volume: the host and the container need different native binaries.
DOCS_NODE = docker run --rm $(DOCS_FLAGS) -e NPM_CONFIG_UPDATE_NOTIFIER=false -e NPM_CONFIG_LOGLEVEL=warn \
	-v "$(CURDIR):/repo" -v phem-website-node-modules:/repo/website/node_modules -w /repo/website node:22-alpine sh -c ' \
		apk add --no-cache git > /dev/null && git config --global --add safe.directory /repo && \
		npm ci --no-fund --no-audit &&

docs: DOCS_FLAGS = -it -p 4321:4321
docs: ## Run the docs website on http://localhost:4321/phem/ (it builds the pages again on each save)
	$(DOCS_NODE) npm run dev -- --host 0.0.0.0'

docs-build: ## Build the docs website into website/dist/ and check its links
	$(DOCS_NODE) npm run build'

shell:  ## Open a shell in the dev image (phemc, cargo, composer and PHPUnit)
	$(COMPOSE) run --rm dev sh
