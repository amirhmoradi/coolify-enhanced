# =================================================================
# Coolify Enhanced
# =================================================================
# This Dockerfile extends the official Coolify image to add:
#   - Granular user role and project-level access management
#   - Encrypted S3 backups using rclone crypt backend
#
# Usage:
#   docker build --build-arg COOLIFY_VERSION=latest -t coolify-enhanced:latest .
#
# =================================================================

ARG COOLIFY_VERSION=latest

FROM ghcr.io/coollabsio/coolify:${COOLIFY_VERSION}

USER root

WORKDIR /var/www/html

# Copy the enhanced package
COPY --chown=www-data:www-data . /tmp/coolify-enhanced/

# Remove docker folder from package (not needed inside container)
RUN rm -rf /tmp/coolify-enhanced/docker

# Configure composer to use the local package
RUN composer config repositories.coolify-enhanced path /tmp/coolify-enhanced && \
    composer require amirhmoradi/coolify-enhanced:@dev --no-interaction --no-progress

# =================================================================
# Overlay: Replace Coolify files with encryption-aware versions
# =================================================================
# These are modified versions of Coolify's original files that add
# rclone-based encryption support. When encryption is not enabled
# on an S3 destination, they fall back to the original behavior.
# =================================================================

# Override DatabaseBackupJob with encryption-aware version
COPY --chown=www-data:www-data src/Overrides/Jobs/DatabaseBackupJob.php \
    /var/www/html/app/Jobs/DatabaseBackupJob.php

# Override Import Livewire component with encryption-aware version
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/Database/Import.php \
    /var/www/html/app/Livewire/Project/Database/Import.php

# Override databases helper with encryption-aware version
COPY --chown=www-data:www-data src/Overrides/Helpers/databases.php \
    /var/www/html/bootstrap/helpers/databases.php

# Override storage show view to include encryption form natively
COPY --chown=www-data:www-data src/Overrides/Views/livewire/storage/show.blade.php \
    /var/www/html/resources/views/livewire/storage/show.blade.php

# Copy the s6-overlay script for addon migrations
COPY --chmod=755 docker/s6-overlay/s6-rc.d/addon-migration/ /etc/s6-overlay/s6-rc.d/addon-migration/

# Register addon-migration in s6 user services
RUN mkdir -p /etc/s6-overlay/s6-rc.d/user/contents.d && \
    touch /etc/s6-overlay/s6-rc.d/user/contents.d/addon-migration

# Update autoloader
RUN composer dump-autoload

USER www-data
