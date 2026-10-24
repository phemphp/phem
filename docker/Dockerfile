# syntax=docker/dockerfile:1.7
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1-php8.5

# --- Dev: FrankenPHP (PHP 8.5) + Composer + Rust toolchain (cargo, phemc) ---
FROM ${FRANKENPHP_IMAGE}
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip gcc libc6-dev \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=rust:1-bookworm /usr/local/rustup /usr/local/rustup
COPY --from=rust:1-bookworm /usr/local/cargo /usr/local/cargo
ENV RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/usr/local/cargo \
    CARGO_TARGET_DIR=/cargo-target \
    PATH=/usr/local/cargo/bin:/cargo-target/release:$PATH
# The editors run the language server as WebAssembly (`make lsp`).
RUN rustup target add wasm32-wasip1
WORKDIR /app
# The repository is mounted at /app, so edits to the entrypoint apply without a rebuild.
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["test"]
