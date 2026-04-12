# Superadmin Tenant Detail + Permanent Delete

## Status
Planned artifact. Not yet implemented as of `2026-04-12`.

## Summary
Add a dedicated tenant detail page under `admin/tenants/{tenant}` and slim down the existing `admin/tenants` list into a compact overview. Move destructive actions into the detail page and add a permanent delete workflow that fully removes the tenant from both the control app and the client server.

Deletion will be strict and synchronous:
- cleanup must succeed before the control-app record is removed
- the linked non-admin customer user will be deleted together with the tenant
- if any remote cleanup fails, the tenant stays in the database so the admin can retry without losing control of orphaned resources

## Key Changes
### Admin tenant UX
- Keep `GET /admin/tenants` as the list view, but reduce it to the essentials:
  - business name + slug
  - customer name + email
  - client VPS
  - provisioning status
  - agent status
  - workspace state / health summary
- Make each row open a new tenant detail page at `GET /admin/tenants/{tenant}`.
- Add a tenant detail page with grouped sections instead of one wide row:
  - summary and status badges
  - customer account details
  - server / runtime / workspace details
  - onboarding / channel / profile summary
  - latest provisioning job + latest error
  - support actions: retry, health check, resync, start, stop, restart
  - danger zone: permanent delete
- Remove delete from the list view; only expose it on the detail page.
- Use a strong confirmation on the detail page before delete submits:
  - show an irreversible warning
  - require typing the tenant slug to enable submission

### Permanent delete workflow
- Add a dedicated orchestration service, e.g. `TenantDeletionService`, so deletion logic is not embedded in the controller.
- Delete flow should run in this order:
  1. Load tenant with linked `user`, `server`, `businessProfile`, `businessProfileFiles`, `conversationLogs`, and latest jobs.
  2. Validate the tenant is eligible for admin deletion.
  3. Tear down workspace infrastructure:
     - stop/remove tenant Docker Compose project on the assigned server
     - remove tenant Caddy site file and reload Caddy when managed
     - remove the remote tenant runtime directory from the client server
     - remove the local staged runtime directory
  4. Delete the tenant LiteLLM virtual key.
  5. Delete the linked customer user record; let the tenant and its child rows cascade from the database.
- If any infrastructure or LiteLLM cleanup step fails:
  - abort deletion
  - keep the tenant and user records
  - return the admin to the detail page with a clear failure message
- If the tenant has no provisioned runtime yet:
  - treat missing compose/runtime/Caddy files as safe no-op cleanup targets
  - still allow deletion to complete
- If the linked user is unexpectedly an admin:
  - block deletion and show a manual-handling error instead of deleting that account

### Interfaces and implementation additions
- Add routes:
  - `GET /admin/tenants/{tenant}` for the detail page
  - `DELETE /admin/tenants/{tenant}` for permanent delete
- Extend the infrastructure runner abstraction to support directory removal on the assigned server, e.g. `removeDirectory(Server $server, string $remotePath, bool $sudo = false)`.
- Implement the new directory-removal method in both local and SSH runner implementations.
- Reuse the same project-name / compose-file conventions already used by the existing admin workspace actions so delete targets the exact tenant runtime that provisioning created.
- Keep route model binding by tenant slug.

## Test Plan
- Feature test: admin list view shows compact tenant summary and links to tenant detail.
- Feature test: admin detail page shows tenant operational data and support actions.
- Feature test: delete endpoint requires admin auth and rejects non-admin access.
- Feature test: successful delete removes:
  - linked customer user
  - tenant row
  - provisioning jobs
  - business profile
  - business profile files
  - conversation logs
- Unit/feature test: successful delete calls infrastructure cleanup in the expected order and deletes the LiteLLM key.
- Feature test: when remote cleanup fails, deletion is blocked and tenant/user/data remain present.
- Feature test: when LiteLLM deletion fails, deletion is blocked and tenant/user/data remain present.
- Feature test: deleting an unprovisioned or partially provisioned tenant succeeds with no-op cleanup for missing runtime artifacts.
- Feature test: delete is blocked if the linked user is an admin account.
- UI-level feature assertion: delete action appears only on the tenant detail page, not on the compact list.

## Assumptions
- The linked tenant user is a customer account in the current one-user-to-one-tenant model and should be removed with the tenant.
- Permanent delete is intentionally hard delete only; no soft-delete, archive, or recovery flow is added in this change.
- Delete runs synchronously from the admin detail page and returns a final success or failure message in the same request cycle.
- Missing remote files, stopped containers, or absent local runtime directories are treated as already-clean states, not as delete blockers.
