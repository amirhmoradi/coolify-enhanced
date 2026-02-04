# =================================================================
# Coolify with Granular Permissions
# =================================================================
# This Dockerfile extends the official Coolify image to add
# granular user role and project-level access management.
#
# Usage:
#   docker build --build-arg COOLIFY_VERSION=latest -t coolify-custom:latest .
#
# =================================================================

ARG COOLIFY_VERSION=latest

FROM ghcr.io/coollabsio/coolify:${COOLIFY_VERSION}

USER root

WORKDIR /var/www/html

# Copy the granular permissions package
COPY --chown=www-data:www-data . /tmp/coolify-granular-permissions/

# Remove docker folder from package (not needed inside container)
RUN rm -rf /tmp/coolify-granular-permissions/docker

# Configure composer to use the local package
RUN composer config repositories.coolify-permissions path /tmp/coolify-granular-permissions && \
    composer require amirhmoradi/coolify-granular-permissions:@dev --no-interaction --no-progress

# Copy the s6-overlay script for addon migrations
COPY --chmod=755 docker/s6-overlay/s6-rc.d/addon-migration/ /etc/s6-overlay/s6-rc.d/addon-migration/

# Register addon-migration in s6 user services
RUN mkdir -p /etc/s6-overlay/s6-rc.d/user/contents.d && \
    touch /etc/s6-overlay/s6-rc.d/user/contents.d/addon-migration

# Update autoloader
RUN composer dump-autoload

USER www-data
