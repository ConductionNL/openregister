# syntax=docker/dockerfile:1
#
# Evaluation image for openregister: the official Nextcloud image with this app,
# built from this repository, and OpenRegister, taken from its release.
#
#   docker build -t ghcr.io/conductionnl/openregister .
#
# The supported production install is still the Nextcloud App Store. This image
# is for trying the app out, for demos and for test environments.
#
# Rendered from ConductionNL/.github templates/app-deploy. Change the template,
# not this copy, and render it again.
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2

# Nextcloud 33 is the newest major whose image still ships PHP 8.3. The 34 and
# 35 images ship PHP 8.5, which some apps in the fleet do not support yet.
ARG NEXTCLOUD_IMAGE=nextcloud:33.0.10-apache
ARG APP_ID=openregister

# ── Frontend: the same steps the release workflow runs ──
FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY . .
# Honour engines.npm: Node 22 bundles npm 10, several apps require npm 11.
RUN set -eu; \
    range="$(node -p "((require('./package.json').engines)||{}).npm||''")"; \
    if [ -n "$range" ]; then npm install -g "npm@$range"; fi; \
    npm ci --no-audit --no-fund; \
    npm run build; \
    rm -rf node_modules

# ── Backend: runtime composer dependencies only ──
FROM composer:2 AS vendor
WORKDIR /app
COPY --from=frontend /app /app
# The lock was resolved for the app's own platform; the composer image does not
# carry Nextcloud's PHP extensions, so platform checks are left to Nextcloud.
RUN composer install --no-dev --no-interaction --no-progress \
        --optimize-autoloader --classmap-authoritative --ignore-platform-reqs

# ── Package: the same file set as the release tarball ──
FROM alpine:3.20 AS package
ARG APP_ID
# hadolint ignore=DL3018
RUN apk add --no-cache rsync
COPY --from=vendor /app /app
RUN mkdir -p "/out/${APP_ID}" && rsync -a \
        --exclude='/.git*' --exclude='/.github' --exclude='/.claude' \
        --exclude='/.hydra' --exclude='/.vscode' --exclude='/.nextcloud' \
        --exclude='/docker' --exclude='/charts' \
        --exclude='/Dockerfile' --exclude='/.dockerignore' \
        --exclude='/docker-compose*.yml' --exclude='/*-compose.yaml' \
        --exclude='/docs' --exclude='/docusaurus' --exclude='/website' \
        --exclude='/node_modules' \
        --include='/src/' --include='/src/manifest.json' --exclude='/src/*' \
        --exclude='/tests' --exclude='/test-scenarios' --exclude='/openspec' \
        --exclude='/data' --exclude='/resources' --exclude='/phpcs-custom-sniffs' \
        --exclude='/package*.json' --exclude='/composer.*' \
        --exclude='/*.config.js' --exclude='/*.config.mjs' --exclude='/*.config.ts' \
        --exclude='/php*.xml' --exclude='/psalm.xml' --exclude='/phpstan*' \
        --exclude='/sbom.cdx.json' --exclude='/.phpunit*' \
        /app/ "/out/${APP_ID}/"

# ── OpenRegister: the newest stable release, checked against its SHA-256 ──
FROM alpine:3.20 AS openregister
ARG APP_ID
# Empty means the newest stable release at build time. Prereleases are refused.
# Pin it for a reproducible build: --build-arg OPENREGISTER_VERSION=2.1.0
ARG OPENREGISTER_VERSION=
SHELL ["/bin/ash", "-euo", "pipefail", "-c"]
# hadolint ignore=DL3018
RUN apk add --no-cache curl jq tar
RUN mkdir -p /out; \
    if [ "${APP_ID}" = "openregister" ]; then exit 0; fi; \
    version="${OPENREGISTER_VERSION#v}"; \
    if [ -z "$version" ]; then \
        version="$(curl -fsSL https://api.github.com/repos/ConductionNL/openregister/releases/latest \
            | jq -r '.tag_name' | sed 's/^v//')"; \
    fi; \
    case "$version" in \
        ''|*-*) echo "OpenRegister '$version' is not a stable release" >&2; exit 1 ;; \
    esac; \
    base="https://github.com/ConductionNL/openregister/releases/download/v${version}/openregister-${version}.tar.gz"; \
    curl -fsSL -o /tmp/or.tar.gz "$base"; \
    sha="$(curl -fsSL "${base}.sha256" | cut -d' ' -f1)"; \
    echo "${sha}  /tmp/or.tar.gz" | sha256sum -c -; \
    mkdir /tmp/stage && tar -xzf /tmp/or.tar.gz -C /tmp/stage; \
    mv "$(find /tmp/stage -mindepth 1 -maxdepth 1 -type d | head -n 1)" /out/openregister; \
    printf '%s sha256:%s\n' "$version" "$sha" > /out/openregister/.image-release

# ── Runtime ──
# hadolint ignore=DL3006
FROM ${NEXTCLOUD_IMAGE}
ARG APP_ID
LABEL org.opencontainers.image.title="${APP_ID}" \
      org.opencontainers.image.description="Nextcloud with ${APP_ID} and OpenRegister, for evaluation" \
      org.opencontainers.image.source="https://github.com/ConductionNL/${APP_ID}" \
      org.opencontainers.image.vendor="Conduction B.V."
# The entrypoint copies these into the custom_apps volume on first start; the
# before-starting hook keeps that copy in step with the image afterwards.
COPY --from=openregister --chown=www-data:www-data /out/ /usr/src/nextcloud/custom_apps/
COPY --from=package --chown=www-data:www-data /out/ /usr/src/nextcloud/custom_apps/
COPY --chmod=0755 docker/entrypoint-hooks/before-starting/ /docker-entrypoint-hooks.d/before-starting/
# Enabled in this order: OpenRegister owns the registers the app declares into.
ENV NEXTCLOUD_IMAGE_APPS="openregister ${APP_ID}"
# Apache starts as root to bind port 80 and serves every request as www-data;
# the entrypoint and its hooks run occ as www-data too.
