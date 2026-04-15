# Founder's Roadmap: The Next 10 Tasks to Onboard 10 Clients

> [!IMPORTANT]
> Historical roadmap. Canonical current truth is in [`artifacts/MEMORY.md`](MEMORY.md) and [`artifacts/ARCHITECTURE.md`](ARCHITECTURE.md). Telegram webhook flow described here is no longer current, and WhatsApp integration references may describe planned or scaffolded work rather than fully implemented behavior.

Based on a thorough review of your project's `MEMORY.md`, `ARCHITECTURE.md`, `RELEASE_NOTES.md`, and the `architecture.html` visual layout, you have brilliantly solved the hardest infrastructure challenge: remote Docker orchestration and tenant isolation. 

However, `architecture.html` reveals two massive missing pieces that prevent a paying customer from actually using the product: The **Skill Registry** and the **Channel Connection Wizard**. 

To successfully onboard your **first 10 external clients**, you need to complete the product loop, secure the platform, and capture value. Here are the 10 most valuable tasks.

---

## Phase 1: Delivering the Actual Product Value (Do This First)

*Your client instances spin up, but right now they do nothing useful for the end user because the skills aren't packaged and the messaging channels aren't hooked up.*

### Task 1: Build the Skill Registry & Skill Deployer
You've proven the AI workflows manually. Now you must build Layer 5. 
*   **Action**: Create a centralized repository (or database structure) for your version-controlled "Skill Bundles" (Email Triage, Booking, Invoicing). 
*   **Action**: Build the `SkillDeployerService` to dynamically inject the selected config templates and instructions into the tenant's container at provisioning time.

### Task 2: Build the Channel Connection Wizard (The Final Activation Step)
A digital employee is useless if it isn't connected to the business.
*   **Action**: Build a UI flow within the user dashboard for the customer to securely input their WhatsApp Business API keys, IMAP/SMTP credentials for email, and Cal.com/Google Calendar OAuth tokens. Securely propagate these into the tenant's `.env` and restart the OpenClaw container.

### Task 3: Implement the "Skill Selector" Up-sell UI
Your database supports `skill_pack`, but there is no UI.
*   **Action**: Build the actual frontend component where a customer can browse, select, or upgrade their digital employee's skills. 

---

## Phase 2: Security & Stability (Do This Before Collecting Data)

> [!CAUTION]
> Your `MEMORY.md` explicitly calls out that secrets (LiteLLM, Brevo, SSH passwords) were shared in-thread. You must treat them as compromised before putting real customer data on this platform.

### Task 4: Rotate Secrets & Move to SSH Key Auth
Password-based SSH provisioning is explicitly marked as a "temporary bridge".
*   **Action**: Generate secure SSH keys, deploy the public keys to the OpenClaw client VPS and primary app server. Remove password reliance. 
*   **Action**: Immediately rotate your Brevo API key, LiteLLM Master Key, and any leaked passwords.

### Task 5: Robust Secret Management & ENV Drift Control
Missing a variable will break remote provisioning. 
*   **Action**: Add an admin dashboard check to verify there is no drift between required variables and what is present in production before authorizing new signups.

### Task 6: Setup Basic VPS Resource Monitoring
You have a hard limit of ~70-100 tenants per IP/Port range on the `89.116.28.191` Client VPS.
*   **Action**: Install a basic monitoring agent. You need to know how many tenants that specific VPS can hold before it crashes from OOM (Out of Memory) errors so you can confidently set `max_clients`.

---

## Phase 3: Core User & Business Logic (Monetization & Flow)

### Task 7: Integrate Stripe for Subscriptions & Billing
You cannot successfully run a SaaS without capturing value. 
*   **Action**: Integrate Laravel Cashier (Stripe). Wire this up to the signup flow so users must input a card, or at least have a Stripe Customer record for when their trial expires.

### Task 8: Implement Tenant Lifecycle Actions (Suspend/Resume/Destroy)
You must automate what happens when a client churns or fails to pay.
*   **Action**: Implement webhook listeners that trigger Laravel Jobs to suspend (stop container + set LiteLLM budget to 0) or destroy a workspace when a subscription is cancelled or a trial expires.

### Task 9: Implement Self-Serve Password Reset
Thanks to the recent Release Notes, you already have Brevo integrated and delivering emails reliably.
*   **Action**: Implement the standard Laravel password reset flows (using Brevo) to avoid doing manual IT support for the first 10 clients.

---

## Phase 4: Data Safety & Scale Protection

### Task 10: Client Workspace & Database Backup Strategy
OpenClaw container instances have a `data/` and `workspace/` mounted volume. Your clients' bots are storing their memory, configuration, and state there.
*   **Action**: Write an automated system (e.g., a daily `rsync` cronjob to S3) that securely backs up all `/srv/sync360/runtime/tenants/<slug>/data` directories from the Client VPS, as well as the main PostgreSQL Control Plane database.

---

## Phase 5: Onboarding Wizard & Business Profile Management

*Recent brainstorm decision: Replace the technical signup-to-dashboard flow with a guided, non-technical onboarding experience. The customer journey becomes: Sign up → Tell us about your business → Choose personality → Choose capabilities → Connect channel → Go live.*

### Task 11: Business Profile Schema & Data Layer
The platform needs a dedicated business-data layer that powers onboarding and later profile editing.
*   **Action**: Create `business_profiles` table (one-to-one with tenant) storing all business context: name, website, contacts, services, FAQs, hours, tax details, extracted website data, and profile completeness score.
*   **Action**: Create `business_profile_files` table to durably store generated agent markdown files (IDENTITY.md, SOUL.md, USER.md, BOOTSTRAP.md, HEARTBEAT.md) before and after go-live sync.
*   **Action**: Extend `tenants` table with onboarding state fields: `onboarding_status`, `onboarding_step`, `tone`, `capabilities`, `channel`, `channel_config` (encrypted), `agent_status`, `webhook_secret`, and health check fields.

### Task 12: AI-Powered Business Extraction Service
Use the Sync360 platform LiteLLM key (not tenant keys) to extract business data from customer websites.
*   **Action**: Build `BusinessExtractionService` with `extractFromUrl()` — analyze a website URL via Claude/LiteLLM and return structured business JSON (name, description, services, FAQs, tone hints, contacts, hours).
*   **Action**: Build `generateAgentFiles()` — take business profile + tone + capabilities and generate the 4 agent markdown files (IDENTITY, SOUL, USER, BOOTSTRAP).
*   **Action**: Wire to authenticated JSON endpoint `POST /onboarding/extract-business` with graceful fallback to manual entry on extraction failure.

### Task 13: Agent Profile Sync & Go-Live Service
Bridge onboarding completion to a live digital employee using the existing server/runtime model.
*   **Action**: Build `AgentProfileSyncService::goLive()` — render markdown files, write into tenant runtime, sync to assigned server via existing `DockerComposeRunner`, restart workspace, verify readiness, mark tenant `live`.
*   **Action**: Create queued `GoLiveTenantAgent` job, dispatched from `POST /onboarding/go-live` only when `provisioning_status === ready`.

### Task 14: Onboarding Wizard API & Frontend
Build the 6-step wizard inside the existing Blade app with authenticated JSON endpoints.
*   **Action**: Build endpoints: `GET /onboarding/state`, `POST /onboarding/extract-business`, `POST /onboarding/business-info`, `POST /onboarding/personality`, `POST /onboarding/capabilities`, `POST /onboarding/channel`, `POST /onboarding/go-live`, `POST /onboarding/skip`.
*   **Action**: Build Blade view `onboarding/show.blade.php` with lightweight JS — 6-step wizard that is resumable, non-technical (no mention of OpenClaw/LiteLLM/SSH), and uses customer-friendly language ("digital employee", "go live").
*   **Action**: Modify signup flow to create `BusinessProfile` + `BusinessProfileFiles` at registration and redirect to `/onboarding` instead of `/tenant/setup`.

### Task 15: Channel Webhook Handlers & Conversation Logging
Connect live digital employees to real customer messaging channels.
*   **Action**: Build WhatsApp and Telegram webhook routes (`/webhooks/whatsapp/{tenantId}`, `/webhooks/telegram/{tenantId}`) that forward inbound messages to tenant workspace `/chat` endpoint and send replies back on the same channel.
*   **Action**: Create `conversation_logs` table and `ProcessIncomingMessage` queued job for reliable message processing.
*   **Action**: Build `WhatsAppSender` and `TelegramSender` services reading decrypted credentials from `tenant.channel_config`.

### Task 16: Dashboard Integration & Profile Editing
Wire the existing dashboard to real onboarding and conversation data.
*   **Action**: Extend `/dashboard` to show onboarding progress, digital employee status, recent conversations, and business profile summary.
*   **Action**: Build profile editing routes (`GET /profile`, `PATCH /profile`, `POST /profile/sync-agent`) so customers can update business details post-onboarding with background agent resync.
*   **Action**: Build `TenantHealthCheckService` and admin resync/restart actions for live tenant support.

---

## Phase 6: First Skill Pack — Text-to-Quote

*The first production skill pack targeting NZ tradies. Converts a WhatsApp message like "Quote bathroom reno at 45 Queen St for John" into a branded PDF quote with line items, GST, and delivery.*

### Task 17: Text-to-Quote Skill Pack
Build the complete skill pack with lifecycle management, ready for the Skill Registry.
*   **Action**: Build the skill pack folder structure with `skill.json` manifest, `config.schema.json`, lifecycle hooks (install, activate, deactivate, uninstall, health-check, self-test), and core logic modules.
*   **Action**: Build `parser.js` (LLM-powered message extraction), `calculator.js` (rate card matching + GST), `pdf-generator.js` (branded PDF via Puppeteer), `sender.js` (email + WhatsApp delivery), and `outcome.js` (Sync360 dashboard events).
*   **Action**: Handle all edge cases: missing client info, low confidence messages, quote amendments, duplicate detection, SMTP failures, and rate card fallbacks.
*   **Action**: Build `registry-logger.js` for dual logging (remote to Sync360 + local JSONL fallback) of all lifecycle and runtime events.

---

### Conclusion for the Founder
Your engineering architecture for the Control Plane MVP is highly robust. The immediate priority is **Phase 1**—you must bridge the gap between "A container is running" and "A business owner's emails are being triaged via AI". Once the Skill Registry and Connection Wizard are built, you have a product ready to sell to 10 businesses.

**Phase 5** (Onboarding) transforms the customer experience from technical to delightful — a business owner enters their website URL and gets a configured digital employee in minutes. **Phase 6** (Text-to-Quote) delivers the first tangible skill pack that generates real revenue value for tradie customers.
