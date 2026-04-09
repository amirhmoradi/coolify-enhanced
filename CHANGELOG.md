# Changelog

This changelog is the public release history for `corelix-platform`.

It is curated from the private canonical repository history and intentionally summarizes approved, user-facing changes without exposing private development history or pro-only implementation details.

## 2026-03-07

### Platform positioning clarified
- Documented the Corelix cloud platform direction as a managed offering built on top of the `corelix-platform` codebase.
- Started formalizing the repository split between the private canonical source and the public free-edition mirror.

## 2026-03-06

### Pro edition and release pipeline hardening
- Added Docker Registry Management for team-level registry credentials, provider-specific authentication, server sync, and ECR token refresh support.
- Refined the feature-gating system so the free and pro editions can be built more reliably from the same codebase.

## 2026-02-28 to 2026-02-27

### Branding, themes, and build workflows
- Added a build-time and runtime whitelabeling system for commercial/platform distributions.
- Introduced additional application build types including Railpack, Heroku Buildpacks, and Paketo Buildpacks.
- Expanded the theming work into a multi-theme system with improved TailAdmin integration and safer SPA navigation behavior.

## 2026-02-26 to 2026-02-25

### Cluster management and UI maturity
- Added Docker Swarm cluster management with dashboards, service and task visibility, visualizer views, structured swarm configuration, and operational tooling.
- Stabilized the theme system with multiple follow-up fixes for PHP errors, route behavior, and color consistency.
- Improved custom template logo rendering and related UI polish.

## 2026-02-21 to 2026-02-20

### MCP server and automation
- Added the standalone MCP server package for AI-driven Coolify management.
- Documented the MCP workflow and added npm publishing automation.
- Expanded the project’s operational docs, including Coolify upstream issue tracking.

### Network management launch
- Delivered environment-level Docker network isolation, proxy isolation, and Docker Swarm overlay network support.
- Addressed early security and reliability findings after the first implementation wave.

## 2026-02-19

### Database classification and networking foundations
- Added enhanced database classification with a broader database image registry and explicit override support.
- Added multi-port database proxy support for database services that expose more than one TCP interface.
- Introduced the initial architecture and documentation framework for large feature work, including feature-specific PRDs, implementation plans, and READMEs.

## 2026-02-17

### Custom template sources
- Added support for external GitHub repositories as custom service template sources.
- Added source labels, source filtering, and warning handling for ignored or untested templates on the New Resource page.
- Reworked the README to cover the expanding feature set with clearer screenshots and onboarding guidance.

## 2026-02-15 to 2026-02-13

### Restore flows and backup reliability
- Added the Restore / Import Backups page in Settings.
- Fixed edge cases in volume backups, including file-based bind mounts.
- Improved backup schedule reliability and fixed `coolify_instance` edge-case crashes.

## 2026-02-12 to 2026-02-11

### Resource Backups
- Added full resource backups covering Docker volumes, configuration snapshots, and full combined backups.
- Added Coolify instance backup support and S3 path-prefix handling.
- Integrated Resource Backups into native Coolify-style navigation and views for applications, databases, services, and server-level management.
- Continued refining the UX to better match native Coolify patterns.

## 2026-02-10 to 2026-02-09

### Encrypted S3 backups and addon identity
- Renamed the project to `corelix-platform`.
- Added encrypted S3 backups using rclone crypt integration.
- Reworked the encryption settings UI to use view overlays and Coolify-native form components for better compatibility.
- Fixed policy coverage gaps and rclone execution issues discovered during early adoption.

## 2026-02-09 to 2026-02-08

### Permissions, install flow, and core usability
- Added the interactive installer and CLI install/uninstall/status flows.
- Expanded and refined the Access Matrix UI.
- Hardened permission enforcement for project, environment, and resource-level access control.
- Fixed boot-order and policy registration issues so custom authorization correctly overrides permissive upstream defaults.

## 2026-02-07 to 2026-02-04

### Initial public foundation
- Published the project to GitHub for public use.
- Added the first Docker image publishing workflow.
- Introduced the initial addon installation model, `docker-compose.custom.yml` integration, access matrix UI, and uninstall support.
- Added early documentation for revert and uninstall operations.

## Notes

- The public repository may use fresh snapshot history as part of the free-edition mirror workflow.
- The canonical implementation history remains in the private source repository.
- Public documentation and this changelog are the intended source of release context for the community edition.
