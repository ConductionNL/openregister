## 1. Settle the remaining design questions

- [ ] 1.1 Decide which NC share type an object share registers as, and whether one provider can serve object shares without confusing the Files UI
- [ ] 1.2 Decide whether an email invite to a non-user creates a link share addressed by email (Files' behaviour) or requires an account first
- [ ] 1.3 Decide whether `private` lives on the object, on the schema as a default for new objects, or both

## 2. `private` as a principal, on all four enforcement paths

- [ ] 2.1 Add `private` to the principal vocabulary, resolved against the object
- [ ] 2.2 Owner and administrator admits FIRST and unconditional, so an owner cannot lock themselves out (design D3)
- [ ] 2.3 Suppress the schema's group rules for a private object — that is the scope's purpose — while keeping it unable to WIDEN
- [ ] 2.4 `PermissionHandler` — the single-object verdict
- [ ] 2.5 `MagicRbacHandler::hasPermission()` — the relation-path verdict
- [ ] 2.6 `MagicRbacHandler` QueryBuilder emitter — list endpoints
- [ ] 2.7 `MagicRbacHandler` raw-SQL emitter — the search path
- [ ] 2.8 Verify an unrecognised scope value still admits owner and admins and nobody else
- [ ] 2.9 Verify nothing changes for an object with no `private` scope (the opt-in guarantee)

## 3. Verdict parity, over a live database

- [ ] 3.1 Parity matrix: one fixture set through the single-object path AND the real RBAC-filtered list query, compared to each other AND to the expected verdict
- [ ] 3.2 Run it WITH an authenticated non-admin session — `applyRbacFilters()` bypasses RBAC entirely when there is no user and PHP_SAPI is cli, which reports a fail-open divergence that does not exist
- [ ] 3.3 Own the fixtures with a non-session user — RBAC OR-s an `_owner = <uid>` condition in, which would mask the predicate under test
- [ ] 3.4 Positive control: disabling either implementation must fail the matrix
- [ ] 3.5 Fixtures: owner, admin, org member, invited user, member of an invited group, revoked grant, expired share, anonymous, malformed scope

## 4. Per-object grants

- [ ] 4.1 Grant / revoke a principal on ONE object, owner-only
- [ ] 4.2 Compose with schema rules — narrows within, never widens
- [ ] 4.3 Enforce the tenant edge independently of the grant list
- [ ] 4.4 Reject a recipient's attempt to widen or re-share onward
- [ ] 4.5 Carry a permission on the grant, and enforce non-RBAC verbs at the endpoint that performs them (design: two enforcement points, deliberately)
- [ ] 4.6 Revocation denies on the NEXT request — no cache, no job

## 5. The share provider surface (core owns the record)

- [ ] 5.1 Register an OR share provider with `OCP\Share\IManager`
- [ ] 5.2 Read share records THROUGH at decision time; keep NO OpenRegister-side copy (design D2 — `ShareLinkService` documents why a snapshot desyncs)
- [ ] 5.3 Resolve the caller's principal set once per REQUEST and pass it to the emitters, rather than per row
- [ ] 5.4 Link shares: token, expiry, password, revocation — all core's mechanics
- [ ] 5.5 Email invitations through core's mailer; the message carries no object data, so revocation still works after delivery
- [ ] 5.6 A share never exceeds the sharer's own access
- [ ] 5.7 Verify a share revoked or expired in core takes effect immediately in OpenRegister

## 6. nc-vue: one component, two surfaces

- [ ] 6.1 A shares component: invite by user / group / email, create a link, set expiry, revoke — mirroring the Files share panel
- [ ] 6.2 Expose it as a detail-page **Shares** tab
- [ ] 6.3 Expose it as a `shared-with-me` dashboard widget
- [ ] 6.4 Register the widget in the dashboard catalogue and call `registerBuiltinDashboardWidgets()` — a bare side-effect import is tree-shaken and every registry tile silently renders "Widget not available"
- [ ] 6.5 Semantic icons via the ADR-077 vocabulary, and REGISTER every name used — an unregistered MDI name renders nothing at all, not a fallback
- [ ] 6.6 Publish on the `vue3` tag and verify the dist-tag MOVED before consuming it

## 7. Federated principals

- [ ] 7.1 A remote principal is one more principal, resolved through the existing `OpenRegisterCloudFederationProvider`
- [ ] 7.2 A federated grant yields the same verdict as a local grant of the same permission, from the same evaluator
- [ ] 7.3 Revoking a federated grant denies it
- [ ] 7.4 Reconcile with `FederatedShare`'s existing `objectUri` / `sharedWith` / `permissions` / `shareToken` rather than adding a second shape

## 8. Fleet-wide consolidation

- [ ] 8.1 Audit every existing share surface: openregister (12 files), launchpad (3), opencatalogi (3), doriath (1)
- [ ] 8.2 Keep FILE shares (`ShareLinkService`) distinct and unchanged — they share files in an object's folder, which is a different thing
- [ ] 8.3 Migrate the bespoke `sharedWith[]` on brokered credentials and flows to the primitive
- [ ] 8.4 Point the credential broker's share admit branch at the primitive instead of its own copy of the shape
- [ ] 8.5 Remove the per-schema derived lists once nothing reads them, with a data migration — not before

## 9. Flows (BREAKING — last, and it unblocks the previous change)

- [ ] 9.1 Give flows read authorization; they have `authorization = NULL` today, so this REMOVES tenant-wide visibility
- [ ] 9.2 Give flows run authorization: `flowRun#test`, `flowRun#retry` and `FlowMcpToolProvider::runFlow()` all run a flow with zero ownership checks today
- [ ] 9.3 Only then enable `credentialIdentity: owner` from `shared-credentials-and-flows` — until run authorization exists, any authenticated user could run someone else's flow and cause calls signed with that owner's secret
- [ ] 9.4 Scope run history to the requester for a share recipient (that change's design D7)

## 10. e2e (Playwright) and verification

- [ ] 10.1 A private object is invisible to another user, visible to its owner — through the UI, not only the API
- [ ] 10.2 Invite a user, they see it; revoke, they do not
- [ ] 10.3 Create a link, open it in a fresh context, revoke it, confirm it stops working
- [ ] 10.4 Email invite delivered and followable
- [ ] 10.5 The Shares tab and the shared-with-me widget both render real data, asserted against a direct API call
- [ ] 10.6 Assert on rendered SVGs and measured content, not on the manifest — an unregistered icon renders nothing and a stale bundle serves the pre-fix code
- [ ] 10.7 `composer check:strict` in the container (host PHP is too old)

## 11. Documentation

- [ ] 11.1 Document the sharing model: `private`, per-object grants, links, email invites, federation — and how each composes with schema-level RBAC
- [ ] 11.2 Amend the RBAC docs with the `private` principal and the all-four-paths rule
- [ ] 11.3 Record the distinction between the three pre-existing share concepts, so a future reader does not add a fourth
- [ ] 11.4 Document the breaking flow change and its upgrade note
