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

# Override DatabaseBackupJob with encryption-aware + classification-aware version
COPY --chown=www-data:www-data src/Overrides/Jobs/DatabaseBackupJob.php \
    /var/www/html/app/Jobs/DatabaseBackupJob.php

# Override StartDatabaseProxy with expanded database port mapping
COPY --chown=www-data:www-data src/Overrides/Actions/Database/StartDatabaseProxy.php \
    /var/www/html/app/Actions/Database/StartDatabaseProxy.php

# Override ServiceDatabase model with wire-compatible database type mappings
COPY --chown=www-data:www-data src/Overrides/Models/ServiceDatabase.php \
    /var/www/html/app/Models/ServiceDatabase.php

# Override Import Livewire component with encryption-aware version
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/Database/Import.php \
    /var/www/html/app/Livewire/Project/Database/Import.php

# Override constants helper with expanded DATABASE_DOCKER_IMAGES list
COPY --chown=www-data:www-data src/Overrides/Helpers/constants.php \
    /var/www/html/bootstrap/helpers/constants.php

# Override databases helper with encryption-aware version
COPY --chown=www-data:www-data src/Overrides/Helpers/databases.php \
    /var/www/html/bootstrap/helpers/databases.php

# Override storage show view to include encryption form natively
COPY --chown=www-data:www-data src/Overrides/Views/livewire/storage/show.blade.php \
    /var/www/html/resources/views/livewire/storage/show.blade.php

# Override settings backup view to include instance file backup section
COPY --chown=www-data:www-data src/Overrides/Views/livewire/settings-backup.blade.php \
    /var/www/html/resources/views/livewire/settings-backup.blade.php

# Override resource configuration views to add Resource Backups sidebar item
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/application/configuration.blade.php \
    /var/www/html/resources/views/livewire/project/application/configuration.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/database/configuration.blade.php \
    /var/www/html/resources/views/livewire/project/database/configuration.blade.php

COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/service/configuration.blade.php \
    /var/www/html/resources/views/livewire/project/service/configuration.blade.php

# Override server sidebar to add Resource Backups item
COPY --chown=www-data:www-data src/Overrides/Views/components/server/sidebar.blade.php \
    /var/www/html/resources/views/components/server/sidebar.blade.php

# Override settings navbar to add Restore + Templates + Networks + Appearance tabs
COPY --chown=www-data:www-data src/Overrides/Views/components/settings/navbar.blade.php \
    /var/www/html/resources/views/components/settings/navbar.blade.php

# Override base layout to inject enhanced UI theme when enabled
COPY --chown=www-data:www-data src/Overrides/Views/layouts/base.blade.php \
    /var/www/html/resources/views/layouts/base.blade.php

# Enhanced UI theme CSS (optional corporate-grade theme, activatable in Settings > Appearance)
RUN mkdir -p /var/www/html/public/vendor/coolify-enhanced
COPY --chown=www-data:www-data resources/assets/theme.css \
    /var/www/html/public/vendor/coolify-enhanced/theme.css

# Override shared helper to merge custom template sources into service templates
COPY --chown=www-data:www-data src/Overrides/Helpers/shared.php \
    /var/www/html/bootstrap/helpers/shared.php

# Override proxy helper for proxy network isolation (Phase 2)
COPY --chown=www-data:www-data src/Overrides/Helpers/proxy.php \
    /var/www/html/bootstrap/helpers/proxy.php

# Override docker helper for Traefik/Caddy proxy network label injection (Phase 2)
COPY --chown=www-data:www-data src/Overrides/Helpers/docker.php \
    /var/www/html/bootstrap/helpers/docker.php

# Override Service/Index Livewire component with multi-port proxy support
COPY --chown=www-data:www-data src/Overrides/Livewire/Project/Service/Index.php \
    /var/www/html/app/Livewire/Project/Service/Index.php

# Override service index view with multi-port proxy UI
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/service/index.blade.php \
    /var/www/html/resources/views/livewire/project/service/index.blade.php

# Override New Resource select view to show custom template source labels
COPY --chown=www-data:www-data src/Overrides/Views/livewire/project/new/select.blade.php \
    /var/www/html/resources/views/livewire/project/new/select.blade.php

# Copy the s6-overlay script for addon migrations
COPY --chmod=755 docker/s6-overlay/s6-rc.d/addon-migration/ /etc/s6-overlay/s6-rc.d/addon-migration/

# Register addon-migration in s6 user services
RUN mkdir -p /etc/s6-overlay/s6-rc.d/user/contents.d && \
    touch /etc/s6-overlay/s6-rc.d/user/contents.d/addon-migration

# Update autoloader
RUN composer dump-autoload

USER www-data
