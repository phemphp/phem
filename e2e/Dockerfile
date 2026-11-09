# syntax=docker/dockerfile:1.7
# The e2e image: the dev image (docker/Dockerfile) with Node.js and the Chromium of Playwright.
# Playwright PHP runs its Node server with the global `playwright` package of this image, so the
# browser and the package always have the same version.
FROM dev

COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

ARG PLAYWRIGHT_VERSION=1.65.0
# PHEMC_BIN: the phemc of compiler/ (the entrypoint builds it). Without it, TestKernel runs
# vendor/bin/phemc, the launcher of the release binary.
ENV PHEMC_BIN=/cargo-target/release/phemc \
    PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    NODE_PATH=/usr/local/lib/node_modules \
    PLAYWRIGHT_PATH=/usr/local/lib/node_modules/playwright \
    NPM_CONFIG_UPDATE_NOTIFIER=false
RUN npm install --global --no-fund --no-audit playwright@${PLAYWRIGHT_VERSION} \
 && playwright install --with-deps --only-shell chromium \
 && rm -rf /var/lib/apt/lists/*
