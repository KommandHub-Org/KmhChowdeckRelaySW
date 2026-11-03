# syntax=docker/dockerfile:1

# shopware-cli, for validating this plugin from inside its own test container.
#
# It is published as a purpose-built image containing nothing but the static Go
# binary (~50 MB, multi-arch: amd64 and arm64). Copying the binary out of it is
# the leanest install available: no apt source to add, no curl/gpg tooling to
# install, no archive to clean up, and a single layer. The alternative — the
# full shopware/shopware-cli:<version> image — is ~1.25 GB to pull for one file.
#
# ":bin" tracks the latest stable release, which is what this environment wants:
# validation should reflect what the Shopware Store runs today. The trade-off is
# that a rebuild can pick up a newer CLI. Pin it for a reproducible build by
# passing a digest:
#
#   docker compose build \
#     --build-arg SHOPWARE_CLI_IMAGE=shopware/shopware-cli:bin@sha256:<digest>
ARG SHOPWARE_CLI_IMAGE=shopware/shopware-cli:bin

FROM ${SHOPWARE_CLI_IMAGE} AS shopware-cli

FROM dockware/shopware:6.7.8.0

# Root is needed to write into /usr/local/bin.
USER root

# /usr/local/bin is already on PATH for every user and shell, so no PATH edit is
# needed for `shopware-cli` to be callable directly.
COPY --from=shopware-cli /shopware-cli /usr/local/bin/shopware-cli

# Fail the build — not the first `make validate-plugin` — if the binary is
# missing, not executable, or built for the wrong architecture.
RUN chmod +x /usr/local/bin/shopware-cli \
    && shopware-cli --version

# Restore the image's declared default user, or the container would run as root.
#
# It must be "dockware" specifically, not "www-data". They share uid 33, so the
# two look interchangeable — but dockware's entrypoint escalates with sudo, and
# the sudo rights are granted to the dockware account. Setting USER www-data
# starts the container as the same uid under a name sudo has no rule for, and
# boot dies with "sudo: a password is required".
USER dockware
