# Release Notes

This file tracks product and engineering changes for the Sync360 Control App.

Newest updates appear first.

## Unreleased

Date: 2026-04-11
Branch: `codex/control-app-prod-deploy`
Status: In progress

Summary:
- Added Brevo-based workspace-ready email delivery
- Added a super-admin-safe control-app deploy trigger for the primary server
- Updated documentation and production env examples for both features

Notable changes:
- Added [WorkspaceReadyEmailService](/Users/gayanhewage/Projects/openclaw-saas/app/Services/WorkspaceReadyEmailService.php) to send a confirmation email after successful provisioning
- The workspace-ready email now includes:
  - confirmation that the workspace has been created
  - workspace link
  - username
  - initial password
- Initial passwords are stored encrypted in the provisioning job payload and removed after a successful email send
- Brevo failures are logged without marking an already-ready tenant as failed
- Added [ControlAppDeploymentService](/Users/gayanhewage/Projects/openclaw-saas/app/Services/ControlAppDeploymentService.php) for safe super-admin-triggered control-plane deployments
- Added host-side deploy script [run-control-app-deploy.sh](/Users/gayanhewage/Projects/openclaw-saas/deploy/scripts/run-control-app-deploy.sh)
- Added `/admin` deploy controls and status/log visibility in [admin/index.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/admin/index.blade.php)
- Added deploy-related env config in [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example), and [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php)
- Updated [README.md](/Users/gayanhewage/Projects/openclaw-saas/README.md) and [ARCHITECTURE.md](/Users/gayanhewage/Projects/openclaw-saas/ARCHITECTURE.md)

Verification:
- `php artisan test` passes with `16 passed` and `171 assertions`
- `sh -n deploy/scripts/run-control-app-deploy.sh` passes
- PHP syntax checks pass for the new deploy and email services
- Live Brevo test emails were accepted for delivery to `gayan.c@outlook.com`

Operational notes:
- Local sender is now configured as `hello@sync360.co.nz`
- Super-admin deploy requires the primary server env values for `SYNC360_CONTROL_DEPLOY_*`
- The `/admin` deploy panel now degrades gracefully when deploy SSH secrets are missing or misconfigured in production, instead of throwing a 500
- Fixed the control-app deploy SSH command construction so remote deploy commands are no longer double-escaped through an extra `sh -lc` wrapper
- Fixed the deploy status probe command to separate remote shell statements with semicolons so production status checks no longer fail on `fi printf` parsing
- Added live polling and latest-commit visibility to the super-admin control app deploy panel so deployment state updates without a page refresh

## 2026-04-11 - Production Deployment Packaging

Date: 2026-04-11
Branch: `codex/control-app-prod-deploy`
Commit: `1de117e`

Summary:
- Added a production-ready packaging path for the control app

Notable changes:
- Added [docker-compose.prod.yml](/Users/gayanhewage/Projects/openclaw-saas/docker-compose.prod.yml)
- Added production startup scripts:
  - [start-prod-app.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-app.sh)
  - [start-prod-worker.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-worker.sh)
- Updated [Dockerfile](/Users/gayanhewage/Projects/openclaw-saas/Dockerfile) with a production image target
- Added [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- Added Apache reverse-proxy template [app.sync360.co.nz.conf](/Users/gayanhewage/Projects/openclaw-saas/deploy/apache/app.sync360.co.nz.conf)
- Added trusted-proxy handling in [bootstrap/app.php](/Users/gayanhewage/Projects/openclaw-saas/bootstrap/app.php)
- Made super-admin seeding production-safe in [DatabaseSeeder.php](/Users/gayanhewage/Projects/openclaw-saas/database/seeders/DatabaseSeeder.php)

Verification:
- `php artisan test` passed
- `docker compose -f docker-compose.prod.yml config` passed
- `docker build --target production -t sync360-control-app:prod-test .` passed

## 2026-04-11 - VPS-Ready Client Deployment Architecture

Date: 2026-04-11
Branch: `codex/vps-ready-architecture`
Commit: `92d95ee`

Summary:
- Refactored the app for primary-server plus client-VPS provisioning

Notable changes:
- Added server-aware tenant placement and provisioning
- Added SSH-based remote Docker orchestration to the client VPS
- Added tenant-specific Caddy routing and public HTTPS readiness checks
- Added production-like validation for remote provisioning to `89.116.28.191`
- Updated architecture and deployment documentation

Verification:
- Automated tests passed
- Remote client VPS bootstrap and tenant provisioning were validated successfully

## 2026-04-11 - Sync360 Control App MVP

Date: 2026-04-11
Branch: `main`
Commit: `498fb67`

Summary:
- Initial Laravel MVP for the Sync360 Control App

Notable changes:
- Landing page, signup, login, dashboard, tenant setup, and admin/debug flows
- User, tenant, provisioning job, and server data model
- Queue-backed provisioning flow with Redis and PostgreSQL
- Local Docker Compose development stack
- Blade-first UI and local runtime generation

Verification:
- Core end-to-end local flow was implemented and tested
