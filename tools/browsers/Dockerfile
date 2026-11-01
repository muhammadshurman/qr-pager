# =============================================================================
# THE BROWSER TOOLCHAIN, COMMITTED. Task 9 (QRPAGER-16).
# =============================================================================
#
# WHY THIS FILE EXISTS, measured rather than asserted. Task 9's gate is the first in this
# project that requires `Playwright E2E green on Chromium, Firefox AND WebKit`, and the
# build host cannot run any of the three:
#
#   $ pnpm exec playwright install chromium firefox webkit     # browsers download fine
#   $ node -e "await (await require('playwright').firefox.launch())"
#   Host system is missing dependencies to run browsers.  ...  libasound2t64
#
# Installing them needs `sudo apt-get`, and this host has no passwordless sudo. So the
# browsers run here instead, and the image is pinned so the engine behind every figure in
# the report is the engine a reviewer can reproduce.
#
# ⚠ THE PLAYWRIGHT VERSION IS NOT TYPED HERE. It is read from the workspace lockfile at
# build time by `tools/browsers/bin/browsers`, which passes it as PW_VERSION. Two sources
# for that number -- one in package.json and one hard-coded in a Dockerfile -- is the
# duplicated-list shape this project has already paid for three times (QA H-1, guard rule
# 11c, .nvmrc). The browser build is tied to the package version: a mismatch silently tests
# against a browser nobody verified, which is the same argument playwright.config.ts already
# makes about pinning `playwright` to an exact version.
#
# php-cli is here because `bin/classify-reds` and `bin/known-flakes-summary` are PHP and a
# red captured in this container has to be classifiable in it.
#
# NOT A PRODUCTION IMAGE and not in `compose.yaml`: it builds nothing that ships. It is
# development and test infrastructure, which `§1`'s stand-in rules put outside `apps/`.
ARG NODE_IMAGE=node:22-bookworm
FROM ${NODE_IMAGE}

ARG PW_VERSION
RUN test -n "${PW_VERSION}" || (echo "PW_VERSION build-arg is required" >&2; exit 1)

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright

RUN apt-get update \
 && apt-get install -y --no-install-recommends php-cli \
 && rm -rf /var/lib/apt/lists/*

# --with-deps is the whole point: it installs the system libraries the host cannot.
# chmod so a non-root `--user` can execute the browsers.
RUN npx --yes playwright@${PW_VERSION} install --with-deps chromium firefox webkit \
 && chmod -R a+rx /ms-playwright

# corepack resolves pnpm from the repo's `packageManager` field. Prepared at build time so a
# measurement run needs no network for its own toolchain.
RUN corepack enable pnpm

WORKDIR /repo
