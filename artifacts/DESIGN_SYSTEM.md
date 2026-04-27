# Sync360 Design System

Last verified: `2026-04-25`

This document is the canonical design-system reference for the Sync360 Control App. It describes the current code-backed UI system used by the Blade surfaces in this repo.

If this document conflicts with the code, trust the code first, then update this document. Historical design artifacts such as `ui-redesign-plan.html` are reference material only and are not normative design truth.

## 1. Source Of Truth

The design system currently lives in the shared Blade layouts:

- `resources/views/components/layouts/app.blade.php` — the `<style>` block here holds nearly all component CSS for authenticated product and control-plane screens (colors, panels, buttons, badges, meta, notes, forms, nav)
- `resources/views/components/layouts/guest.blade.php` — the `<style>` block here holds component CSS for landing, auth, and workspace-access screens
- `resources/css/app.css` — Tailwind entrypoint; defines the daisyUI PoC theme, the `dui-` prefix, Tailwind font tokens, and PoC component helpers such as `sync-poc-card`, `sync-poc-table`, and `sync-poc-status-icon`

Known tech debt: most design-system CSS lives inside Blade `<style>` blocks rather than a real stylesheet. Treat the inline blocks as canonical today, but prefer moving shared primitives into `app.css` (or a dedicated design-system stylesheet) over time.

Use shared classes, Blade wrappers, and tokens from those files before adding view-local inline styles. If a new UI primitive is needed across more than one screen, add it to the relevant shared layout or `resources/views/components/ui/*` first, then document it here.

Implementation status, 2026-04-19: the shared app and guest layouts now load `DM Sans` plus `JetBrains Mono`, expose the reusable `type-*` typography classes, and define the current spacing scale. Existing Blade views are being migrated toward those classes as they are touched.

**2026-04-22 Landing Page Typography Enhancement:** Landing page and auth views now use the full semantic typography scale for consistent hierarchy: `type-display` (hero/display headings), `type-h2` (major section headings), `type-h3` (outcome card titles), `type-body-lg` (supporting paragraphs in hero/CTA), `type-body` (standard body copy), `type-kicker` (eyebrow/section markers), `type-label` (small labels for metrics), `type-value` (displayed metric values), and `type-value--large` (hero metrics with animated counters). New landing page patterns include section connectors (`.section-connector`, `.section-connector-line`, `.section-connector-dot`), trust bar (`.trust-bar`, `.trust-stat`, `.trust-divider`), flow diagram (`.flow-diagram`, `.flow-step`, `.flow-step-icon`, `.flow-step-content`), testimonials grid (`.testimonials-grid`, `.testimonial-card`, `.testimonial-content`, `.testimonial-author`, `.testimonial-metric`), and value metric cards (`.value-metric`, `.metric-value--large`, `.metric-label`).
**2026-04-27 Admin expired-trial override panel:** tenant admin Overview now includes an `Expired-trial Runtime Overrides` panel directly beneath `Trial & AI Usage`. It uses the existing calm panel/subpanel pattern rather than introducing a new warning-heavy treatment: one short explanatory note, three checkbox rows for `Allow inbox polling`, `Allow runtime replies`, and `Keep LiteLLM key active`, followed by a compact four-field state summary. Even though the controls affect expired tenants, the panel should read as an operator policy surface, not an alarm banner.
**2026-04-27 Admin paused-agent badge rule:** on admin tenant surfaces, `Agent` and `Workspace` must not collapse into one status. If an expired tenant's runtime replies are blocked by policy, the `Agent` badge should read `paused` even when the workspace container is still `running`. This keeps the operator-facing state honest: runtime health stays separate from customer-facing reply availability.
**2026-04-27 Customer dashboard analytics switcher:** the dashboard analytics area now includes a compact shared time-window switcher above the main analytics band. It should read as one global analytics scope control for the customer, not a per-card filter cluster. When present, `Estimated Time Saved` and `Estimated ROI` belong in the always-visible KPI row for `Performance Overview` rather than hiding until the chart has rich data.
**2026-04-27 Customer profile logo control:** the business-logo upload control on `/profile` should be treated as a compact field-level enhancement inside `Identity and positioning`, not as a full-width standalone section. Keep it minimal: a small square preview, one short metadata line, compact `Upload/Replace` plus `Remove` actions, and a short status/error note beneath. Use a warm neutral field container, not the louder orange `note` treatment reserved for broader guidance and alerts. Long explanatory copy belongs in the general profile context, not repeated inside the upload control itself.

## 2. Design Principles

- Customer clarity first. Product screens should explain what is ready, blocked, or next without exposing backend implementation details.
- Quiet reassurance over operational detail. Customer dashboard status cards should prove the assistant is alive and useful with plain-language cues like `Watching your inbox` or `Last checked 6 minutes ago`, not monitor jargon.
- Calm control plane. Admin screens can be dense, but hierarchy should remain readable and operational actions should be easy to scan.
- Scan hierarchy before truncation. In operational tables, keep tenant identity and primary risk signals readable first; collapse or truncate secondary metadata only after the main comparison targets remain recognizable.
- Compress secondary admin summary UI. Summary strips above dense tables should stay compact and supportive; they are there to orient triage, not compete with the table itself.
- Make Overview the shared tenant summary. On tabbed admin detail screens, keep shared identifiers, trial posture, and common rollout evidence in Overview; other tabs should focus on unique evidence and actions instead of repeating the same panels.
- Brand warmth with restraint. Use Sync360 orange, soft surfaces, rounded panels, and direct language without making every element loud.
- Typography carries hierarchy. Use `DM Sans` for the product voice and reserve `JetBrains Mono` for technical precision.
- Semantic components over one-off styling. Prefer shared classes such as `panel`, `badge`, `type-label`, and `type-value--technical` over repeated inline font, spacing, and tracking rules.
- Long technical strings must wrap. Tenant IDs, URLs, runtime paths, timestamps, logs, and command output should not break layout or collide visually.

## 3. Brand Voice And UI Tone

Sync360 should sound direct, confident, and helpful.

Use:

- "Your workspace is being prepared."
- "Connect Google Workspace."
- "Continue Setup."
- "Workspace setup in progress."
- "No recent provisioning error recorded."

Avoid:

- internal implementation labels in customer UI
- "MVP", "OpenClaw", "LiteLLM", raw CLI terminology, or platform internals in customer-facing copy
- alarmist reconnect language unless the actual runtime state needs attention
- overly decorative technical language where a business owner needs a next action

Admin/control-plane UI may use precise operational labels, but it should still explain what an action does in plain language.

Success banners on admin rollout/apply flows should describe the exact transition, for example: publish changed the catalog only, rollout changed tenant assignments, apply queued tenant runtime work.

The Admin Overview System Health panel uses the existing `.panel`, `.meta`, `.meta-item`, `.badge`, `.badge--technical`, `.note`, and table patterns. Scheduler, queue worker, queue backlog, and scheduled-command health should remain compact operational status, with timestamps and errors in technical text rather than custom color systems or process-level decorative UI.

## 4. UI Surface Split

### Customer UI

Customer UI includes landing, auth, signup, onboarding, dashboard, profile, conversations, tenant setup, and workspace-ready screens.

Customer UI may be more expressive:

- atmospheric backgrounds on guest pages
- larger headings on landing/auth surfaces
- warmer onboarding copy
- friendly progress and next-action language
- impact/analytics cards should use estimated wording when metrics come from Sync360-authored defaults rather than measured tenant workflow data
- compact skill reassurance cards may appear near the top of the dashboard when a feature is enabled and active for that tenant; these should stay small, use one primary status, one short note, one lightweight value line, and only one CTA when something needs attention
- the customer dashboard should now be outcomes-first: use a tight hero, a four-card health rail (`Assistant`, `Workspace`, `Trial`, `Inbox`), then analytical panels for performance, trial runway, top skills, and inbox performance
- for expired trial state, use a single global urgent banner as the loud message; demote the health rail to compact status (`Paused`, `Expired`) and make the runway panel action-oriented instead of repeating the same alarm copy
- when the top dashboard analytics row pairs a large chart with a companion runway/recovery panel, keep both panels visually aligned in height so the row reads as one balanced band
- the customer profile page should feel like a calm accuracy surface: use a tight hero, a four-card health rail, grouped business-detail sections, and a sticky sync-status rail instead of one long undifferentiated settings form
- on the profile page, surface Google Workspace / inbox-monitor issues as one focused sidebar action card plus compact sync-status rows; do not let warning copy spread across the main form body
- keep the business-logo upload compact and inline with identity fields; it should read like one optional profile field, not like a separate feature panel competing with the form
- the onboarding wizard should stay a calm single-column guide: one full-width progress rail at the top, one focused step canvas, one primary CTA per step, and quiet reassurance about background setup instead of technical setup chatter
- in onboarding, present Telegram as the only live customer channel path, keep WhatsApp visibly future-facing but subdued, and structure Google Workspace as `state + what it means + next action` rather than a long wall of status prose
- remove profile-heavy content (`Your Business`, contact details, industry, module lists) from the dashboard; that material belongs on the profile/settings surface
- do not show completed onboarding as a large dashboard panel; only surface setup progress when onboarding is incomplete, and prefer a compact step wizard instead of a full meta list

Customer UI must stay readable and avoid internal system names unless the customer must act on them.

### Control-Plane UI

Control-plane UI includes admin overview, tenant detail, jobs, skill catalog, runtime controls, and support actions.

Control-plane UI should be:

- denser than customer UI
- calmer than marketing UI
- explicit about operational status
- careful with destructive actions
- precise for IDs, paths, ports, timestamps, logs, and runtime state
- comfortable showing compact rollups plus recent evidence rows on the same screen for admin analytics surfaces

Admin skill forms should use concrete placeholder examples that match the current catalog reference skill. Use `hello-world` for the built-in custom-skill example rather than historical `appointment-booking` copy.

The admin `Agent Runtime` tab is the home for tenant-scoped runtime model and credential overrides. Runtime model remains a plain text field to match operator flexibility, while sensitive runtime API key overrides must render as an empty password input plus masked saved-state copy and an explicit clear control. Never echo a saved runtime secret back into the form value.

The Skill Catalog status badge must describe publish-gated availability: imported skills without an active published version show `unavailable`, not `assignable`, even if the repo scan/import succeeded.

For skill-version UX, tenant admin cards must distinguish `assigned version` from `latest published` instead of using one badge to imply both. Skill Catalog rollout screens should describe `publish`, `roll out`, and `apply` as separate transitions.

### Shared Rules

- Use `DM Sans` by default.
- Use `JetBrains Mono` only for technical values.
- Use badges for compact status, not for long explanations.
- For multi-part operational systems such as Google, health, and workspace state, prefer icon + label state rows over stacking several equal-weight badges inside one cell.
- In dense admin comparison tables, clamp secondary notes to a single line wherever the detail page already holds the full explanation.
- Use explicit but quiet row-open affordances such as a subtle chevron chip when an entire table row is clickable.
- Use notes for guidance, blockers, and warnings.
- Use panels/cards to group a single decision area or data set.
- On tenant-detail screens, prefer a light status strip plus calm tabs over a loud badge rail and repeated panel-level restatements.
- On action-heavy admin tabs such as Support, group controls into small purpose-led clusters (`Recovery`, `Lifecycle`, etc.) so the operator reads intent before button labels.
- On the customer dashboard, do not use recent-message feeds or conversation logs as a top-level widget. Favor analytical trend panels and compact health summaries over verbose activity blocks.
- When an analytics metric is undefined, omit the card/row instead of showing a misleading zero value.
- For estimated operational metrics such as time saved or ROI, label them explicitly as estimated in headings or supporting copy.
- For background rollout/apply work, prefer compact polling progress panels with `queued`, `running`, `completed`, and `failed` badges plus a short explanatory line over noisy log-style output.
- Tenant admin may add narrow operator tabs for one subsystem when a skill needs its own operational evidence view. The new `Inbox Monitor` tab follows that rule: it stays tenant-scoped, uses the same calm panel language as other admin tabs, shows polling/backoff/failure facts plus recent monitor rows, and keeps customer-facing auto-reply history out of this panel.
- Skill-authored customer reply behavior should be grounded in tenant files and current thread context. When UI or guidance references email reply capability, prefer explicit verified command shapes over generic “inspect help first” language once the tool surface has been confirmed in production-like runtime.

## 5. Design Tokens

### Color

Authenticated app layout:

- `--bg: #f7f5f3` - warm app background
- `--panel: #ffffff` - panel base
- `--ink: #1a1a1a` - primary text
- `--muted: #6b7280` - supporting text
- `--accent: #FF6B35` - primary Sync360 orange
- `--accent-dark: #e55a25` - hover/strong orange
- `--stroke: #e5e7eb` - borders
- `--warning: #9c6a08` - warning text
- `--danger: #9f3737` - destructive/error text
- `--danger-bg: #fff1f1` - destructive/error surface

Note: the authenticated layout does not currently define a `--success` token. Success styling is applied via badge state classes (see §7 Badges). Conversely, the guest layout defines `--success` but no `--warning`. These two layouts also use the name `--danger` for different roles (dark red text in app; light pink surface in guest) — do not assume equivalence across surfaces.

Guest layout:

- `--bg: #110f0d` and `--bg-soft: #181512` - dark atmospheric surfaces
- `--panel: rgba(255, 255, 255, 0.08)` - translucent dark panel
- `--panel-strong: rgba(255, 255, 255, 0.94)` - light auth card
- `--ink: #f7f5f1` and `--ink-dark: #1a1a1a` - dark/light text contexts
- `--muted: rgba(247, 245, 241, 0.72)` and `--muted-dark: #6b7280`
- `--accent: #FF6B35`, `--accent-dark: #e55a25`
- `--success: #10b981`
- `--danger`, `--danger-ink`, `--danger-bg`
- `--shadow: 0 18px 60px rgba(0, 0, 0, 0.22)`

### Typography

Tailwind theme:

- `--font-sans: 'DM Sans', ui-sans-serif, system-ui, sans-serif`
- `--font-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace`

Shared tracking tokens:

- `--type-display-tracking`
- `--type-heading-tracking`
- `--type-kicker-tracking`
- `--type-label-tracking`
- `--type-tech-tracking`

Core classes:

- `type-display` - large page/hero display treatment
- `type-section-title` - panel and section headings
- `type-body` - readable body copy
- `type-muted` and `hint` - supporting copy
- `type-kicker` and `eyebrow` - compact section markers
- `type-label` - small field/card labels
- `type-value` - important displayed values
- `type-value--technical` - important technical values
- `type-tech` - compact technical text
- `type-tech--wrap` - logs, output, or technical strings that must wrap

Dashboard wrapper components in `resources/views/components/ui/*`:

- `metric-card` - compact outcome/health card with status icon, short value, and one supporting note
- `health-rail` - responsive four-card dashboard health strip
- `chart-panel` - daisyUI-backed Sync360 analytical panel wrapper
- `bar-chart` - vertical grouped bars for trends and horizontal bars for ranked impact
- `runway-meter` - compact dual progress treatment for AI credit and remaining trial time
- `step-wizard` - condensed setup progress for incomplete onboarding only
- `empty-analytics` - empty-state wrapper for chart/analytics panels
- `step-progress` - onboarding progress rail plus current-step summary wrapper
- `setup-status` - slim onboarding status strip for background setup or other single-message guidance when attention is actually needed

### Spacing Scale

Spacing uses a 4px base with 2px half-steps for dense surfaces. All new CSS should use these tokens; do not introduce new raw pixel values.

| Token | Value | Typical use |
|---|---|---|
| `--space-0-5` | 2px | fine nudges (sub-label offsets) |
| `--space-1` | 4px | icon-to-text gap, tight chip padding |
| `--space-1-5` | 6px | dense list row gap |
| `--space-2` | 8px | small gaps, topbar paragraph offset |
| `--space-2-5` | 10px | compact button padding-y, badge row gap |
| `--space-3` | 12px | nav-link padding, button padding-y |
| `--space-3-5` | 14px | input padding, meta grid gap, hero inner gap |
| `--space-4` | 16px | standard gap, sidebar-mobile padding |
| `--space-4-5` | 18px | grid gap, panel inner rhythm |
| `--space-5` | 20px | large nav row, secondary panel padding |
| `--space-5-5` | 22px | hero-card top padding on guest |
| `--space-6` | 24px | panel padding (app), stats gap |
| `--space-7` | 28px | topbar bottom margin, panel padding (guest hero variants) |
| `--space-8` | 32px | page content padding (authenticated) |
| `--space-9` | 36px | guest auth-card padding |
| `--space-10` | 40px | section break |
| `--space-12` | 48px | major vertical rhythm on guest |
| `--space-16`, `--space-18` | 64px, 72px | guest hero vertical spacing only |

Guest-only tokens (`--space-16`, `--space-18`) are not available in the authenticated layout — do not reference them from app screens.

**Migration status:** these tokens were added on 2026-04-19. Most existing rules still use raw pixel values; migration is gradual. When editing or adding CSS, replace nearby raw values with the closest token on the scale. If a design needs a value not on the scale, first consider whether the scale value (+/- 2px) is acceptable; only extend the scale for a real, repeated need.

### Radius Scale

Radius is tokenized separately per layout because the guest surface uses softer, larger corners than the authenticated app.

**Authenticated app layout:**

| Token | Value | Typical use |
|---|---|---|
| `--radius-sm` | 8px | chips, tight pills, small inline controls |
| `--radius-md` | 10px | note, meta item, compact card |
| `--radius-lg` | 14px | input, meta grid, form controls |
| `--radius-xl` | 20px | panel (default) |
| `--radius-2xl` | 24px | large panel variants |
| `--radius-pill` | 999px | badges, buttons, eyebrow |
| `--radius-circle` | 50% | avatars, indicator dots |

**Guest layout:**

| Token | Value | Typical use |
|---|---|---|
| `--radius-sm` | 8px | small controls |
| `--radius-md` | 12px | rounded inline tokens |
| `--radius-lg` | 16px | panels, secondary cards |
| `--radius-xl` | 22px | hero sub-card |
| `--radius-2xl` | 26px | large atmospheric panel |
| `--radius-3xl` | 30px | hero card, auth card |
| `--radius-pill` | 999px | badges, buttons |
| `--radius-circle` | 50% | indicator dots, avatars |

Do not introduce intermediate radius values (e.g. `18px`, `22px` in the app layout). If a new component needs a radius outside the scale, first try the nearest token; only extend the scale for a real, repeated need.

### Elevation (Shadow)

Shadows are semantic — named by purpose rather than by size — so swapping an elevation doesn't require updating every callsite.

**Authenticated app layout:**

| Token | Value | Use |
|---|---|---|
| `--shadow-panel` | `0 4px 24px rgba(26, 26, 26, 0.05)` | default `.panel` elevation |
| `--shadow-focus` | `0 0 0 3px rgba(255, 107, 53, 0.14)` | focus halo (paired with `outline: 2px solid var(--accent)`) |

**Guest layout:**

| Token | Value | Use |
|---|---|---|
| `--shadow` | `0 18px 60px rgba(0, 0, 0, 0.22)` | hero card, auth card (original token, kept for existing references) |
| `--shadow-elevated` | aliases `--shadow` | semantic alias for hero/modal-like elevation |
| `--shadow-focus` | `0 0 0 3px rgba(255, 107, 53, 0.14)` | focus halo |

Brand-colored button halos (e.g. `0 6px 20px rgba(255, 107, 53, 0.28)`) are currently inline on `.button--primary` and `.button--danger`. They are not tokenized yet because they vary with the button's rest/hover state and with brand vs danger color — tokenize when a third variant lands.

**Migration status:** radius and elevation tokens were added on 2026-04-19 alongside the spacing scale. Most existing rules still use raw values; migration is gradual. When editing or adding CSS, replace nearby raw values with the closest token.

## 6. Typography Rules

### DM Sans

Use `DM Sans` for:

- navigation
- headings
- labels
- buttons
- body copy
- form text
- most badges
- customer-facing explanations
- admin section titles and field labels

DM Sans is the voice of Sync360. It should carry hierarchy and confidence.

### JetBrains Mono

Use `JetBrains Mono` for:

- tenant IDs and slugs when they need precision
- ports
- runtime paths
- command output and logs
- raw command names or code-like identifiers
- short session IDs or hashes

Do not use mono for ordinary navigation, prose, business names, explanatory copy, status badges, trial labels, money, or every label in admin screens. Human-scanned timestamps should usually remain in DM Sans unless they are part of raw logs or command output.

### Casing, Tracking, And Line Height

- Kicker/eyebrow text can be uppercase with modest tracking.
- Field labels can be uppercase, but should use `DM Sans` unless the label itself is technical.
- Body copy should generally use `line-height` between `1.5` and `1.65`.
- Section headings should usually sit around `1.1` to `1.2`.
- Technical text should use enough leading for x-height clarity, usually `1.35` to `1.5`.
- Long strings should use `overflow-wrap: anywhere` or `type-tech--wrap`.

## 7. Components

### Page Topbar

Use `.topbar` for page headers with:

- `eyebrow` or `type-kicker`
- `h2` / display title
- short supporting paragraph
- optional right-aligned action group

Customer topbars should emphasize next actions. Admin topbars should explain operational scope.

### Eyebrow / Kicker

Use `.eyebrow` for compact section context. Use `.type-kicker` where the same behavior is needed outside the standard eyebrow surface.

Do not use eyebrow text as the only heading for a panel that needs a clear title.

### Buttons

Use:

- `.button` as the base button style
- `.button--primary` for the main action
- `.button--secondary` for navigation or secondary actions
- `.button--ghost` for low-emphasis sidebar/dark-surface actions
- `.button--danger` for destructive actions

Buttons should use clear verbs: "Continue Setup", "Open Workspace", "Queue Google Sync", "Delete Tenant Permanently".

Action buttons should use icon + text when the icon clarifies direction or destination:

- Back/previous-screen navigation uses a left arrow before the label.
- Links that open a customer workspace, external product surface, or new browser context use an external-link icon after the label.
- Destructive and queueing actions may use text only unless the icon adds real scan value.

Do not use icon-only buttons for primary page actions unless space is severely constrained; icon-only controls require `aria-label` and a visible tooltip.

### Badges

Use `.badge` for compact human-readable status.

Use `.badge--technical` when the badge contains compact operational tokens such as:

- `ready`
- `failed`
- `draft v3`
- `provision_tenant`
- workspace/runtime state strings

Avoid putting long sentences inside badges. Use `.note` for explanatory state.

Badges still use the primary `DM Sans` family. Do not switch status badges to JetBrains Mono just because the value is operational; reserve JetBrains for raw IDs, URLs, hashes, command output, code-like skill IDs, and other values where fixed-width scanning materially helps.

In dense navigation, do not put full status text inside the tab label. Use a fixed-size status icon with an accessible label/title so changing text such as `connected`, `pending`, or `no skills assigned` does not shift the tab layout. Keep text badges for table cells, status rows, and places where the status value itself must be read inline.

#### Badge State Map

The authenticated layout defines the following state classes (apply alongside `.badge` or `.badge--technical`):

| Class | Surface | Text | Meaning |
|---|---|---|---|
| `.badge.pending`, `.badge.queued` | `#fef3c7` | `#92400e` | Waiting / not yet started |
| `.badge.provisioning`, `.badge.running` | `#dbeafe` | `#1d4ed8` | Active / in progress |
| `.badge.ready`, `.badge.completed`, `.badge.trial_active` | `#dcfce7` | `#15803d` | Success / healthy |
| `.badge.failed`, `.badge.trial_expired` | `var(--danger-bg)` | `var(--danger)` | Failed / expired / needs attention |

Use these canonical state classes instead of inventing new colors. If a new semantic state is needed, add the class to `app.blade.php` and document it in this table.

### Panels And Cards

Use `.panel` for major content groups. A panel should usually contain one topic: business data, sync status, onboarding progress, runtime controls, or a skill assignment workflow.

Guest pages use richer cards such as `.hero-card`, `.auth-card`, and landing preview surfaces. These can be more expressive but should still use shared typography classes.

Avoid repeating a page title as the first panel title. If the page topbar already names the table or primary dataset, the panel can be titleless and use an `aria-label` for assistive technology. Panel titles should add new hierarchy, not echo the page heading.

The daisyUI PoC wraps major admin groups with `<x-ui.panel>` and keeps daisyUI classes behind Sync360 Blade components. Tenant detail pages now use this wrapper across the overview, workspace, Google, skills, analytics, agent runtime, and support tabs. Keep this as the preferred PoC pattern: page-level layout stays custom Sync360; cards/panels/buttons/badges use wrappers.

### Stat Cards

Use `.stats` with `.stat` for small metric groups. Stat labels should stay short and values should be easy to scan.

Do not use stat cards for long paragraphs or multi-step instructions.

### Meta Grid / Meta Item

Use `.meta` and `.meta-item` for label/value data.

Use:

```html
<small class="type-label">Workspace URL</small>
<strong class="type-value type-value--technical">https://example.workspace.test</strong>
```

Use `type-value` for business/customer values and most readable admin values. Use `type-value--technical` for IDs, URLs, ports, runtime paths, hashes, raw command names, and code-like skill IDs. Do not use the technical font for ordinary status words, money, trial labels, or human-scanned timestamps unless the screen is specifically comparing raw machine output.

For daisyUI-backed admin PoC surfaces, use `.sync-poc-detail-grid` and `.sync-poc-field` for the same label/value job when the data is inside an `<x-ui.panel>`. Preserve `type-label`, `type-value`, and `type-value--technical` where tests or older screens depend on those exact classes.

### Notes And Alerts

Use `.note` for guidance, neutral warnings, blockers, and important context. Use `.note.error` for errors or destructive warnings.

Notes should explain the meaning or next action, not just repeat a status label.

### Wizard Operation Notes

The onboarding wizard uses a view-local `.wizard-operation-note` with `role="status"` and `aria-live="polite"` for in-flight save/connect/go-live progress. It appears only while a request is running and should use short action text such as "Saving business details..." or "Going live...".

While this note is visible, wizard navigation and competing actions should be disabled or ignored so customers cannot accidentally move backward/forward while the current step is saving. Do not use this pattern as a permanent blocker; release it on both success and failure.

For already-live onboarding Step 7, the primary action reads `Resync Assistant` and uses the same operation-note pattern with resync-specific copy. Keep this visually equivalent to `Go Live` because it performs the same customer-facing assistant update path.

Onboarding Step 4 is now a modules step. Present included `core` modules as non-editable reassurance rows and `featured` modules as the only selectable checkboxes. Do not reintroduce `Skill Pack` or granular `capabilities` language on customer screens.

### Forms

Use `.field-grid` for two-column form sections and `.field-single` for stacked fields.

Labels should be plain and readable. Placeholder text should clarify format, not replace labels.

### Navigation

Authenticated app navigation uses the sidebar:

- brand block
- section labels
- `.nav-link`
- active state with orange accent
- tenant users can reach `Skill Outcomes` from the sidebar; it anchors to the dashboard impact panel
- admins can reach the global `Skill Analytics` surface from the admin sidebar section
- admin overview should not duplicate primary sidebar destinations as page-header button rows

Guest navigation uses the brand mark/wordmark and top nav links. Keep guest nav simpler and more brand-led.

Tenant-detail side tabs may include compact status indicators, but those indicators should be icon-based, fixed width, and accessible. Do not use variable-width text badges inside tab labels when they can crowd or push neighboring tabs on tablet/mobile widths.

Tab hover states should be quiet. Hover may use a subtle neutral surface tint and light border, but should not look as strong as the active state. Reserve Sync360 orange emphasis for the active/current tab and primary actions.

Tenant-detail tab content should avoid mixing old raw `.panel`/`.button` markup with PoC wrappers. Use `<x-ui.button>` for form actions and links, `<x-ui.badge>` for status, `<x-ui.table>` for histories/event rows, and `.sync-poc-pre` for markdown previews, hashes, logs, and raw runtime command output.

Multi-stage admin workflow panels should surface background pipeline stages separately instead of collapsing everything into one generic status. The current pattern is the skill rollout panel, which shows apply counts and auto-resync counts independently, and the tenant skills progress panel, which shows the rollout-triggered auto-resync as a second stage under the primary apply status.

### Tables

Use tables for dense operational data where comparison across rows matters. The page topbar should carry the dataset name and plain-language scope. Avoid placing a duplicate table title immediately above the table; use the column headers and row content to do the work.

When a table lives inside a titleless panel, add an accessible name to the wrapper, for example `aria-label="Tenant records table"`.

Tables should fit inside the available page width by default. Do not let long URLs, emails, tenant IDs, runtime paths, or health messages create page-level horizontal scrolling. Use fixed table layout, planned column widths, and one-line truncation with an accessible `title`/full-value escape where the full string is secondary to scanability.

Use ellipsis for secondary table details such as:

- workspace URLs
- email addresses
- hostnames
- health messages
- timestamps or sync metadata
- long tenant slugs when the row also links to the detail page

Do not truncate primary decision labels such as status badges, destructive action labels, or the only visible business/customer identifier unless the column is genuinely constrained and the full value is available elsewhere.

Avoid adding table-header sorting by default. It can add visual noise to dense operational tables and imply stronger data tooling than the page provides. Add sorting only when it is a clear workflow requirement and the interaction has been designed intentionally.

### Technical Blocks And Logs

Use `.type-tech` and `.type-tech--wrap` for log output, raw command output, markdown previews, hashes, and runtime inspection results.

Technical blocks should preserve whitespace where useful, wrap long lines, and remain visually secondary to the surrounding explanation.

## 8. Usage Examples

### Customer UI

Use this pattern for business/profile data:

```html
<div class="meta-item">
    <small class="type-label">Business Name</small>
    <span class="type-value">Acme Plumbing</span>
</div>
```

Use this pattern for onboarding copy:

```html
<span class="eyebrow">Guided Setup</span>
<h2>Set up your digital employee</h2>
<p class="type-body">Tell us about your business and connect the tools your workspace needs.</p>
```

Avoid exposing runtime names or raw provisioning mechanics in customer copy unless needed for support.

### Control-Plane UI

Use this pattern for admin tenant details:

```html
<div class="sync-poc-status-strip">
    <section class="sync-poc-status-card sync-poc-status-card--compact">
        <span class="sync-poc-status-card__label">Workspace</span>
        <span class="sync-poc-status-card__value">Stopped</span>
    </section>
</div>
```

Use Overview as the canonical summary and keep other tabs unique:

```html
<x-ui.panel title="Provisioning Outcome">
    <strong class="type-value">completed</strong>
</x-ui.panel>
```

### Do / Avoid

Do:

- use `DM Sans` for most labels and readable UI
- use `JetBrains Mono` for exact technical values
- wrap long technical strings
- keep tenant-detail tabs quiet and make state icons do more work than repeated badges
- cluster destructive or operational buttons by intent instead of presenting one flat button wall
- add reusable shared classes before repeating inline styles

Avoid:

- using mono for every admin label
- duplicating the same provisioning or trial panel across multiple tenant-detail tabs
- making body copy uppercase
- adding untracked colors or shadows for one screen
- putting long explanations inside badges
- treating historical redesign artifacts as current design truth

## 9. Accessibility

Sync360 targets WCAG 2.1 AA for all customer and control-plane surfaces.

### Contrast

- Body and label text must meet 4.5:1 against its background. Large text (≥18pt or ≥14pt bold) and non-text UI must meet 3:1.
- `--muted: #6b7280` on `--bg: #f7f5f3` sits near the 4.5:1 floor. Do not use `--muted` for small (`<0.875rem`) or low-weight text, and do not stack muted text on top of non-panel backgrounds without re-checking contrast.
- Badge state colors in §7 are chosen to pass AA on their paired backgrounds. Preserve the pairing; do not swap a badge background onto a different text color without re-checking.

### Focus

- Every interactive element (buttons, links, nav links, inputs, selects, textareas) must show a visible focus indicator for keyboard users.
- Use `:focus-visible` so the ring appears for keyboard navigation but not for mouse clicks.
- The shared layouts draw a 2px `var(--accent)` outline with a 2–3px offset on focused inputs, selects, textareas, buttons, and nav links. New interactive elements should follow the same pattern.

### Motion

- Hover and transition effects (e.g. the `translateY(-1px)` on `.button:hover`) must be suppressed under `@media (prefers-reduced-motion: reduce)`.
- Avoid auto-playing animation on customer surfaces. Progress indicators should be meaningful, not decorative.

### Semantics

- Use real heading order (`h1` → `h2` → `h3`); do not use `.type-display` or `.type-section-title` on a `div` where a heading element is correct.
- Icon-only buttons must carry an `aria-label`. Badges that convey state ("failed", "ready") must either repeat the state in readable text or be paired with an `sr-only` span.
- Notes and error surfaces should use `role="status"` or `role="alert"` when the state is conveyed only visually.

## 10. Responsive Behavior

Sync360 surfaces are responsive but intentionally do not use a universal breakpoint scale. Each layout has its own breakpoints, tuned to its content, which is why this section documents both.

### Authenticated App Layout

Source: [app.blade.php:623](resources/views/components/layouts/app.blade.php#L623)

Two breakpoints:

**≤980px (tablet/mobile):**

- `.shell` collapses from sidebar + content grid to a single column
- `.sidebar` becomes a sticky top bar (`position: sticky; top: 0; z-index: 100; flex-direction: row`) with brand left and hamburger button right
- Desktop `<nav>` and `.sidebar-footer` are hidden (`display: none`)
- `.hamburger-btn` becomes visible (`display: inline-flex`); three-bar icon animates to an × when open
- When the hamburger is tapped, `.nav-open` is toggled on the sidebar; the `.mobile-drawer` expands below the top bar containing: alerts bell (with its own dropdown), all nav links with section labels, and a footer row with user name + logout button
- Nothing is dropped on mobile — bell, nav links, user name, and logout are all present in the drawer
- `toggleMobileNav()` manages `nav-open` and `aria-expanded`; `toggleNotifBell(dropdownId)` takes an explicit ID so desktop and mobile bells toggle independently
- **Critical**: `.sidebar` must have `overflow: visible` at ≤980px — the desktop `overflow-y: auto` would otherwise clip the absolutely-positioned drawer. Always set `overflow: visible` when toggling from a scrollable vertical sidebar to a sticky horizontal bar.
- `.content` padding reduces to `var(--space-4)` (16px)
- `.topbar` stacks vertically (`flex-direction: column`) so heading and action buttons don't crowd
- `.stats`, `.grid-2`, `.meta`, and `.field-grid` collapse to a single column

**≤480px (phone):**

- `.content` padding tightens further to `var(--space-3)` (12px)
- `.panel` padding reduces to `var(--space-4)` (16px)

Design intent: the app is sidebar-first on desktop; on mobile it collapses to a compact sticky top bar with a hamburger drawer that preserves all navigation and account actions.

### Guest Layout

Source: [guest.blade.php:996](resources/views/components/layouts/guest.blade.php#L996), [:1026](resources/views/components/layouts/guest.blade.php#L1026), [:1053](resources/views/components/layouts/guest.blade.php#L1053)

Three breakpoints:

| Max-width | Intent |
|---|---|
| **≤1100px** | Landing hero, story grid, and auth wrap collapse to single column. Hero copy tightens (`gap`, `max-width`). Final CTA stacks vertically. |
| **≤900px** | Dense grids collapse — `.field-grid`, `.preview-stats`, `.outcome-strip`, `.mini-grid`, `.auth-metric-grid`, `.preview-surface`. Preview sidebar rearranges to a 2-col sub-grid. |
| **≤720px** | Phone. Containers inset to `100% - 28px`, section padding tightens, hero heading scales down (`clamp(2.7rem, 13vw, 4.2rem)`), nav wraps. |

Design intent: the landing/auth experience is content-first; grids collapse progressively rather than snapping at one breakpoint.

### Rules For New Responsive CSS

- Prefer the existing breakpoints for the surface you're working on. Do not introduce a fourth guest breakpoint or a second app breakpoint without a real reason — content collapse should happen at one of the documented tiers.
- Use **max-width** queries (mobile-adjusted-from-desktop) to match the existing style. Do not mix min-width and max-width in the same surface.
- Collapse multi-column grids to a single column at the narrowest breakpoint where that content becomes unreadable, not preemptively.
- `flex-wrap: wrap` is the standard fallback for toolbars, nav links, and CTA rows — use it instead of hiding elements unless there is a clear reason.
- Do not hide operational information on narrow viewports in the control-plane UI. The sidebar may collapse, but tenant IDs, runtime status, and error notes must remain reachable.
- Guest-layout hero typography uses `clamp(min, viewport-scaled, max)`; use this pattern for new display headings rather than fixed breakpoint jumps.

### Known Responsive Gaps

- The app layout has no breakpoint between 980px and the narrowest desktop sizes. Dense admin tables on small laptops (<1200px) can feel cramped; this is a known trade-off, not a documented behavior.
- Guest `@media (max-width: 1100px)` and `@media (max-width: 900px)` ranges overlap for some rules — when editing, verify both queries to avoid drift.

## 11. Dark-Mode Strategy

Sync360 does **not** implement OS-level dark mode on the authenticated app surface. There is no `@media (prefers-color-scheme: dark)` query and none is planned for the near term.

### Why The Guest Layout Is Dark

The guest layout (`guest.blade.php`) uses a dark atmospheric palette (`--bg: #110f0d`, `--bg-soft: #181512`) by design choice, not as a response to the user's OS preference. It is always dark regardless of system setting. The `-dark` suffix tokens in guest (e.g. `--ink-dark`, `--muted-dark`, `--stroke-dark`) are **surface-contrast tokens** — they provide the light-on-dark values needed when content sits on the lighter auth card or panel-strong surface within the dark page. They are not OS dark-mode tokens.

### Authenticated App Surface

The authenticated app layout is always light (`--bg: #f7f5f3`). There is no dark variant today.

### If Dark Mode Is Added Later

If an app dark-mode is introduced:
- Add a `@media (prefers-color-scheme: dark)` block inside the app layout's `:root` that overrides the color tokens only.
- Do not add a separate layout; the token system is designed to allow this override cleanly.
- Test contrast ratios for all text/background token pairs under the new values before shipping.
- Document the new dark-mode token set in this section.

### Rules For New Code

- Do not add `prefers-color-scheme` queries to individual views — if dark mode is added it must be centrally controlled via the layout's `:root`.
- Do not assume the guest surface is "the dark mode" of the app. They are independent surfaces with independent palettes.

## 12. Icon, Illustration, And Motion

### Icons

Sync360 currently uses **inline SVG** for all icons. There is no external icon library or sprite system.

Rules:
- Keep icons inline in Blade. Do not add an icon library dependency without a documented decision.
- Size icons with `width`/`height` attributes or `em`-relative CSS. Do not use pixel-fixed icon sizes that won't scale with text.
- Use `fill="currentColor"` or `stroke="currentColor"` so icons inherit text color from their parent.
- Icon-only controls (buttons, links) must carry an `aria-label`. See §9 Accessibility.
- Alert/notification icons in the app layout currently come from a `$alert['icon']` string (emoji or short text). Prefer SVG or a CSS-drawn indicator for new alert types.

### Illustration

No illustration system is currently defined. The guest landing preview uses CSS-drawn UI mockups (`.product-preview`, `.preview-surface`) rather than image assets. Prefer this approach for landing decorations — no image loading, no alt-text debt, scales cleanly.

If raster or vector illustrations are added in future, document the asset location and naming convention here.

### Motion

Current motion in use:
- **Hover transitions:** `transition: 0.15s ease` on nav links, inputs, and buttons (app); `0.18s ease` on buttons (guest). Properties: `background`, `color`, `border-color`, `transform`, `box-shadow`.
- **Button lift:** `transform: translateY(-1px)` on `.button:hover` (app), `translateY(-2px)` (guest).
- **Loading pulse:** `@keyframes pulse` animation on the branded progress bar in the app layout. Uses `translateX` + `opacity` cycling at `1.4s ease-in-out infinite alternate`.

Known gap: **no `@media (prefers-reduced-motion: reduce)` guards exist.** All transitions and the pulse animation fire regardless of OS motion preference. This violates WCAG 2.3.3 (Animation from Interactions, AAA) and is poor practice even at AA. Fix by adding:

```css
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        transition-duration: 0.01ms !important;
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
    }
}
```

Add this block to both shared layouts. Until it is added, do not introduce new keyframe animations or transitions longer than `0.2s`.

Rules for new motion:
- Use `transition-duration` of `0.1s`–`0.2s` for micro-interactions. Do not exceed `0.3s` for UI feedback.
- Animate `transform` and `opacity` (GPU-composited). Avoid animating `width`, `height`, `padding`, or layout-affecting properties.
- Never auto-play decorative animation on customer-facing screens without a reduced-motion guard.
- Looping animations (spinners, pulse bars) must stop or reduce under `prefers-reduced-motion`.

## 13. Form Validation States

Sync360 has two levels of form error presentation: **page-level** (validation bag dump) and **field-level** (per-field error text and input state). Both surfaces implement page-level; field-level is available as a CSS primitive but not yet used in Blade views.

### Page-Level Error (Validation Bag)

Both layouts render `$errors->any()` at the top of the content area automatically.

**Authenticated app layout** — uses `.note.error`:

```html
<div class="note error">
    <div>The email field is required.</div>
</div>
```

**Guest layout** — uses `.alert.alert--error`:

```html
<div class="alert alert--error">
    <strong>Please fix the following:</strong>
    <ul class="list">
        <li>The email field is required.</li>
    </ul>
</div>
```

The guest layout also has `.alert.alert--success` for `session('status')` flash messages.

### Field-Level Error

Use `.field-error` for a per-field error message beneath an input, and `aria-invalid="true"` on the input itself to communicate the invalid state to screen readers.

```html
<div class="field-single">
    <label class="type-label" for="email">Email</label>
    <input id="email" name="email" type="email" aria-invalid="true" value="{{ old('email') }}">
    <span class="field-error">Please enter a valid email address.</span>
</div>
```

The invalid input gets a danger-colored border and halo automatically via:

```css
input[aria-invalid="true"],
select[aria-invalid="true"],
textarea[aria-invalid="true"] {
    border-color: var(--danger);
    box-shadow: 0 0 0 3px rgba(159, 55, 55, 0.1);
}
```

`.field-error` is defined in both shared layouts. It renders below the field using `display: block`, danger color, and a small top margin.

### Success Flash

Use `.note` (app) or `.alert.alert--success` (guest) for success flash messages. Do not use green text inline — keep success state at the page or section level, not per-field.

### Rules

- Always use `aria-invalid="true"` on inputs that fail server-side validation, not just a visual class.
- Pair `aria-invalid` with `aria-describedby` pointing at the `.field-error` element when the error text is present.
- Do not rely on color alone to communicate an error — the border change, the error text, and `aria-invalid` together cover visual, text, and assistive-tech channels.
- Never surface raw server exception messages in customer-facing field errors. Validate at the Form Request level and return user-readable messages.

## 14. Maintenance Rules

- Update this document when shared layout tokens, typography classes, or reusable component patterns change.
- Update this document when a new repeated UI primitive is introduced.
- Do not document one-off inline styles as canonical patterns.
- If code and this document drift, update whichever is wrong; current code wins until corrected.
- Future UI implementation should read this file before changing Blade presentation.
- `ui-redesign-plan.html` is historical reference only and should not override this document.

## 15. Dependency Health UI

Google Workspace and Inbox Triage dependency state now follow one shared presentation rule across customer and admin surfaces:

- Do not present Google Workspace as "ready" based only on historical connection state.
- Customer-facing Google copy should derive from the shared dependency-health payload and use plain-language states such as `connected and working`, `needs attention`, `will need reconnecting soon`, or `reconnect required`.
- Customer-facing dependency warnings belong in compact, action-oriented surfaces: onboarding Step 6, dashboard Inbox card, setup/workspace-ready panels, and the shared authenticated alert bell.
- Customer-facing dependency UI should use one clear CTA only, usually a reconnect/setup action. Avoid multiple competing repair buttons on customer pages.
- Admin/operator panels may show richer metadata such as predicted expiry, last health check, last verified success, and customer-alert delivery timestamps, but they should still read from the same shared health model rather than inventing a second interpretation.
- Admin overview action rows can mix a small inline management button with a status badge when the action is tightly related to the panel's primary object. The current example is the tenant trial panel, which pairs the `Add 7 Days` action with the trial status badge in the panel actions area.

## 16. Runtime Verification Panels

Admin runtime diagnostics now follow a compact verification-summary pattern:

- Lead with a small status badge that answers the operator's main question first, such as `verified` or `verification pending`.
- Keep technical companion badges nearby for narrow secondary state like `Workspace state: running`.
- Show expected-vs-verified runtime data as short hint lines, not as large alert blocks or nested cards.
- Use warning-colored hint text for mismatch/error details such as missing skills or last verification failure.
- Preserve raw command output below the summary block as supporting evidence, not as the primary status surface.
- Runtime diagnostics should stay read-only in presentation. Assignment controls, rollout controls, and verification evidence should remain visually distinct even when they live in the same tab.
