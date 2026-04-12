# Release Notes

This file tracks product and engineering changes for the Sync360 Control App.

Newest updates appear first.

## Unreleased

Date: 2026-04-12
Branch: `cdx-feature/client-onbarding-wizard`
Status: In progress

Summary:
- Added the first end-to-end managed onboarding flow for Sync360 customers without exposing OpenClaw internals
- Added AI-assisted business extraction and assistant file generation using the control app LiteLLM virtual key
- Added channel connection, go-live sync, webhook routing, and conversation logging for WhatsApp and Telegram
- Added a richer customer dashboard, dedicated conversation browsing, editable business profile management, live assistant resync, and tenant health tooling
- Polished channel onboarding so customers now get tenant-specific webhook setup details directly inside the guided flow

Notable changes:
- Added onboarding data model and tenant state for guided activation:
  - [create_business_profiles_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_090000_create_business_profiles_table.php)
  - [create_business_profile_files_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_090100_create_business_profile_files_table.php)
  - [add_onboarding_fields_to_tenants_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_090200_add_onboarding_fields_to_tenants_table.php)
- Added onboarding models and relations in [BusinessProfile.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/BusinessProfile.php), [BusinessProfileFiles.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/BusinessProfileFiles.php), and [Tenant.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/Tenant.php)
- Extended [RegisterController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/Auth/RegisterController.php) so signup now seeds onboarding records while keeping the existing provisioning flow and `/tenant/setup` redirect intact
- Added the 6-step onboarding flow in [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php), [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php), and [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php)
- Added [BusinessExtractionService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/BusinessExtractionService.php) to:
  - extract business details from a website
  - generate assistant identity, soul, user, bootstrap, profile, and heartbeat files
  - use `LITELLM_VIRTUAL_KEY` for control-app AI calls with a deterministic local fallback when AI is unavailable
- Added Step 5 and Step 6 onboarding activation through [TenantAgentSyncService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantAgentSyncService.php) and [TenantRuntimeService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantRuntimeService.php)
- Added public WhatsApp and Telegram webhook handling in [WebhookController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/WebhookController.php), outbound senders in [WhatsAppSender.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/Channels/WhatsAppSender.php) and [TelegramSender.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/Channels/TelegramSender.php), and workspace message forwarding in [TenantWorkspaceMessenger.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantWorkspaceMessenger.php)
- Added queued inbound message processing in [ProcessIncomingMessage.php](/Users/gayanhewage/Projects/openclaw-saas/app/Jobs/ProcessIncomingMessage.php)
- Added conversation logging with [ConversationLog.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/ConversationLog.php) and [create_conversation_logs_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_120000_create_conversation_logs_table.php)
- Added customer dashboard onboarding and activity visibility in [DashboardController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/DashboardController.php) and [dashboard.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/dashboard.blade.php)
- Added a dedicated customer conversation browser in [ConversationsController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/ConversationsController.php), [conversations/index.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/conversations/index.blade.php), and [ConversationBrowserFlowTest.php](/Users/gayanhewage/Projects/openclaw-saas/tests/Feature/ConversationBrowserFlowTest.php)
- Added customer profile editing and live assistant resync in [ProfileController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/ProfileController.php), [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/profile/show.blade.php), and [TenantProfileSyncService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantProfileSyncService.php)
- Added tenant health checks and support actions in [TenantHealthCheckService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantHealthCheckService.php), [AdminController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/AdminController.php), [admin/tenants.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/admin/tenants.blade.php), and scheduled `tenants:health-check` in [routes/console.php](/Users/gayanhewage/Projects/openclaw-saas/routes/console.php)
- Polished Step 5 channel onboarding in [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php) and [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php) so WhatsApp verify tokens can auto-generate and customers can see their tenant-specific WhatsApp and Telegram webhook setup details in the guided setup
- Updated config and environment examples for LiteLLM and channel integrations in [config/services.php](/Users/gayanhewage/Projects/openclaw-saas/config/services.php), [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php), [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), and [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- Exempted `webhooks/*` from CSRF validation in [bootstrap/app.php](/Users/gayanhewage/Projects/openclaw-saas/bootstrap/app.php) so external providers can deliver inbound messages safely

Verification:
- `php artisan test --filter=OnboardingFlowTest` passed
- `php artisan test --filter=WebhookFlowTest` passed
- `php artisan test --filter=DashboardFlowTest` passed
- `php artisan test --filter=ProfileFlowTest` passed
- `php artisan test --filter=ConversationBrowserFlowTest` passed
- `php artisan test --filter=TenantHealthCheckFlowTest` passed
- `php artisan test --filter=AdminTenantOperationsTest` passed
- `php artisan test --filter=SignupFlowTest` passed
- `php artisan test --filter=AdminDebugTest` passed
- `php artisan test --filter=ProvisioningFlowTest` passed
- `php artisan test --filter=WebhookFlowTest` passed
- `php artisan test --filter=WebScraperServiceTest` passed (8 unit tests — Jina success, link discovery, exclusion, fallback, truncation, external links, API key)
- `php artisan test --filter=OnboardingFlowTest` passed after adding Jina Reader HTTP fakes (20 tests, 107 assertions)
- `docker compose exec -T app php artisan migrate --force` applied the new onboarding and conversation-log tables in the local runtime
- Live end-to-end pipeline tested against `stylesoftware.co.nz`: 6 pages scraped (30,617 chars), 18 services extracted, all profile fields populated correctly

Operational notes:
- Control-app AI features now use `LITELLM_VIRTUAL_KEY`
- Tenant key creation and management continue to use `LITELLM_MASTER_KEY`
- Deploying this slice requires running Laravel migrations before using the new dashboard, onboarding, profile, or webhook flows
- Test suites are stable when run sequentially; running multiple suites in parallel can still hit the shared temp-directory collision in [tests/TestCase.php](/Users/gayanhewage/Projects/openclaw-saas/tests/TestCase.php)
- `JINA_BASE_URL` and `JINA_API_KEY` added to `.env.example` and `.env.production.example`; no key is required for the free Jina tier (covers thousands of onboardings/month)
- LiteLLM proxy had `vector_store_ids: []` set on the `claude-sonnet-4-6` model config — removed via the LiteLLM admin API; all Anthropic calls now succeed
- Pre-existing tenants (created before this migration) will have their `business_profiles` and `business_profile_files` rows created automatically on first use of any onboarding step

Extra notable changes (onboarding extraction fix, follow-up to `94a4b68`):
- Added [WebScraperService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/WebScraperService.php) — smart multi-page business website scraper using Jina Reader. Fetches homepage then discovers and scores internal links (`about=10`, `services=10`, `contact=8`, `faq=8`, `pricing=8` etc.), fetching up to 5 additional pages. Falls back to direct HTTP + HTML stripping. Output capped at 100k chars.
- Added [WebScrapingFailedException.php](/Users/gayanhewage/Projects/openclaw-saas/app/Exceptions/WebScrapingFailedException.php)
- Added [WebScraperServiceTest.php](/Users/gayanhewage/Projects/openclaw-saas/tests/Unit/WebScraperServiceTest.php) — 8 unit tests
- Fixed [BusinessExtractionService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/BusinessExtractionService.php) — was sending a raw URL to Claude (which has no web browsing). Now uses two-stage pipeline: scrape via `WebScraperService` → send scraped markdown to Claude. Prompt placeholder changed from `{{URL}}` to `{{PAGE_CONTENT}}`.
- Fixed [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php) — `extractBusiness`, `saveBusinessInfo`, `savePersonality`, and `saveCapabilities` now use `firstOrCreate` for `BusinessProfile` and `BusinessProfileFiles` so pre-existing tenants without these rows are handled gracefully instead of silently discarding data
- Updated [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php) — Step 1 now shows an animated three-stage progress indicator (spinning SVG + step labels) while the 30–45s scrape and extraction runs. Button disables during fetch and re-enables on completion or error.

## 2026-04-11 - Workspace Emails And Control App Deploy

Date: 2026-04-11
Branch: `codex/control-app-prod-deploy`
Status: Released

Summary:
- Added Brevo-based workspace-ready email delivery
- Added a super-admin-safe control-app deploy trigger for the primary server
- Hardened the control-app deploy status and remote shell handling for production
- Added realtime deploy status polling and latest deployed commit visibility in the super-admin UI
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
- Added `/admin` deploy controls, live status polling, latest commit visibility, and status/log visibility in [admin/index.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/admin/index.blade.php)
- Added deploy status JSON endpoint in [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php) and [AdminController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/AdminController.php)
- Hardened deploy status reads so `/admin` stays available when deploy secrets are missing or misconfigured
- Fixed remote deploy shell execution by removing the extra `sh -lc` wrapping in [ControlAppDeploymentService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/ControlAppDeploymentService.php)
- Fixed the deploy status probe command to use proper statement separators for the remote shell
- Made the host-side deploy status file the source of truth for the latest deployed commit SHA and commit message, so the admin panel stays aligned with the code that was actually deployed
- Added branch-tip comparison for the deploy panel, so it now shows `Up-to-date` when production already matches `codex/control-app-prod-deploy` and only shows `Fetch Latest And Deploy` when a newer branch commit is available
- Hardened the host deploy script so it resolves the remote branch head first, fast-forwards to that exact commit, and fails the deployment if the checked-out HEAD does not match the intended remote commit
- Changed the UI deploy trigger to fetch the latest deploy script from `FETCH_HEAD` and execute that fetched script directly, so deploy-script updates take effect immediately instead of waiting for a separate manual bootstrap run
- Added deploy-related env config in [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example), and [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php)
- Updated [README.md](/Users/gayanhewage/Projects/openclaw-saas/README.md) and [ARCHITECTURE.md](/Users/gayanhewage/Projects/openclaw-saas/ARCHITECTURE.md)

Verification:
- `php artisan test` passes with `16 passed` and `171 assertions`
- `php artisan test --filter=AdminDebugTest --stop-on-failure` passes
- `php artisan test --filter=ControlAppDeploymentServiceTest --stop-on-failure` passes
- `sh -n deploy/scripts/run-control-app-deploy.sh` passes
- PHP syntax checks pass for the new deploy and email services
- Live Brevo test emails were accepted for delivery to `gayan.c@outlook.com`

Operational notes:
- Local sender is now configured as `hello@sync360.co.nz`
- Super-admin deploy requires the primary server env values for `SYNC360_CONTROL_DEPLOY_*`
- The `/admin` deploy panel now degrades gracefully when deploy SSH secrets are missing or misconfigured in production, instead of throwing a 500
- The deploy card now polls automatically, shows the latest deployed commit SHA and subject, and keeps status/log output fresh without a page reload
- The deploy script now records the fetched-and-deployed commit metadata after `git pull`, and the status endpoint only falls back to a live git lookup when no deploy status file exists yet
- The deploy status endpoint now also checks the current `origin/<branch>` tip and exposes whether production is already up to date, which drives the deploy button label and disabled state in the super-admin UI
- The host deploy script now verifies that the local checked-out HEAD exactly matches the target remote branch head before it rebuilds containers, preventing false-success deploys when the branch was not actually advanced
- The SSH trigger now bootstraps deployment from the just-fetched branch content, avoiding the self-update trap where an older checked-out deploy script would keep running outdated logic

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
