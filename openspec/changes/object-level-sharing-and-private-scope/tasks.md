## 1. Settle the remaining design questions

> All seven are stated with their consequences in design.md "Open Questions".

- [x] 1.1 Q1 ANSWERED by research: do NOT register a provider — `IShareProvider`/`IShare` are Files-bound (`getNodeId(): int` non-nullable). Share the object's FOLDER instead; every object has one on demand, and `ShareLinkService` already reads the six types that matter on it
- [x] 1.2 Q2 DECIDED: mirror Files — `TYPE_EMAIL`, an account-less link addressed to an email
- [x] 1.3 Q3 DECIDED: both — schema default for new objects, object overrides
- [x] 1.4 Q4 DECIDED: amend ADR-006 — a link is a revocable, expiring CAPABILITY, not a visibility flag; Rule 3's concern is a data field treated as a boundary, which a core-issued token is not
- [x] 1.5 Q5 DECIDED: uniform core set mapped onto core's permission bitmask, plus per-schema extensions in `IAttributes`, governed by an ADR so the vocabulary cannot sprawl
- [x] 1.6 Q6 DECIDED: yes, separate — `use` implies `read`
- [x] 1.7 Q7 DECIDED: yes, they collapse — but only the ACCESS half. `scope` ALSO selects the vault owner (`personal` = the user, `organisation` = a reserved SYSTEM identity), so the storage selector survives verbatim; removing it would make every existing organisation credential's secret unreadable

## 2. `private` as a principal, on all four enforcement paths

> Landed. Vocabulary, PHP verdict and SQL predicate live once in
> `lib/Service/Rbac/ObjectScopeResolver.php`; every path is a caller. Storage is
> the `scope` key of an authorization block, at object and schema level, mirroring
> the existing non-action key `inheritFromPublic`.

- [x] 2.1 Add `private` to the principal vocabulary, resolved against the object
- [x] 2.2 Owner and administrator admits FIRST and unconditional, so an owner cannot lock themselves out (design D3)
- [x] 2.3 Suppress the schema's group rules for a private object — that is the scope's purpose — while keeping it unable to WIDEN
- [x] 2.4 `PermissionHandler` — the single-object verdict
- [x] 2.5 `MagicRbacHandler::hasPermission()` — the relation-path verdict
- [x] 2.6 `MagicRbacHandler` QueryBuilder emitter — list endpoints
- [x] 2.7 `MagicRbacHandler` raw-SQL emitter — the search path
- [x] 2.8 Verify an unrecognised scope value still admits owner and admins and nobody else
- [x] 2.9 Verify nothing changes for an object with no `private` scope (the opt-in guarantee)
- [x] 2.10 Schema-level validation: `scope` is a reserved non-action key, validated STRICTLY at
      authoring time (the runtime fail-closed still covers a value that arrives by import or by
      a version that knew a scope this one does not)
- [x] 2.11 Neither list emitter returns unfiltered any more — not on an unconditional grant, and
      not on a schema with NO authorization block. An OBJECT can declare itself private on an
      otherwise open schema, and bypassing there would leak exactly the objects on the schemas
      nobody is watching. The `IS NULL` disjunct leads the predicate so an unwritten column is
      decided without touching the JSON.

## 3. Verdict parity, over a live database

> Landed as `tests/Db/PrivateScopeParityIntegrationTest.php` (5 tests, live
> Postgres). Every fixture goes through all FOUR paths and the verdicts are
> compared to each other AND to the expectation.

- [x] 3.1 Parity matrix: one fixture set through the single-object path AND the real RBAC-filtered list query, compared to each other AND to the expected verdict
- [x] 3.2 Run it WITH an authenticated non-admin session — `applyRbacFilters()` bypasses RBAC entirely when there is no user and PHP_SAPI is cli, which reports a fail-open divergence that does not exist
- [x] 3.3 Own the fixtures with a non-session user — RBAC OR-s an `_owner = <uid>` condition in, which would mask the predicate under test
- [x] 3.4 Positive control: disabling either implementation must fail the matrix — run for ALL FOUR
      paths independently, each producing a distinct, attributable disagreement
- [x] 3.5 Fixtures: owner, admin, org member, malformed scope, non-string scope, empty-string scope,
      absent block, block without a scope key. (Invited user, group member, revoked grant and
      expired share arrive with the grant layer in group 4 — there is nothing to invite yet.)
- [x] 3.6 The UNION arm must be given TWO register/schema pairs. `searchAcrossMultipleTables()`
      falls back to the SEQUENTIAL implementation — which uses the QueryBuilder emitter — for a
      single pair, so a one-pair test compares one implementation with itself and reports perfect
      agreement.
- [x] 3.7 Feed the PHP paths objects READ BACK from the database, not built in memory alongside the
      fixtures — a fixture in the shape the code expects cannot catch the code reading the wrong shape.

### Findings recorded while doing groups 2–3

- [x] 3.8 **Pre-existing leak, now pinned by a test.** A per-object ACTION override in
      `_authorization` (e.g. `{"read": ["admin"]}`, live since Wave-12 Fix 5) is honoured by
      `PermissionHandler` and IGNORED by both list emitters and by
      `MagicRbacHandler::hasPermission()`: `resolveSchemaAuthorization()` calls the resolver with
      NO object, and `hasPermission()` reads `$schema->getAuthorization()` directly. So such an
      object is denied on `find` and RETURNED by `list`. NOT fixed here — this change adds `scope`
      to the same column and honours that key on all four paths; making the action overrides
      row-level in SQL is its own piece of work. Pinned by
      `testPerObjectActionOverrideIsNotYetHonouredByTheListPaths`, which fails when it is fixed.
- [ ] 3.9 **Pre-existing PHP warning**, proven against baseline: `QueryBuilder::select()` emits
      `Undefined array key 0` on the RBAC-filtered list path when a session is present — a spread
      of a single-element array with a non-zero key. Reproduces with the baseline
      `MagicRbacHandler`, so it is not from this change; surfaced because this is the first DB test
      to exercise that combination. Not localised.

## 4. Per-object grants

> **LANDED, read and write.** A grant is a real core share on the object's NC
> folder, read through `IManager` at decision time and memoised for ONE request
> only. Composed into all four paths as the single substitution
> `notPrivate` → `(notPrivate OR grantedToMe)` (design D3b). The owner-checked
> write surface is `ObjectSharingService` + `ObjectSharingController`.

- [x] 4.0 An OWNER can set their own object's `scope`, through a dedicated owner-checked
      endpoint rather than the object payload — `stripSelfInjectionFields()` strips
      `authorization` from every non-admin write and the write path omits the column, both
      correctly. Safe because `scope` can only ever NARROW, unlike the action lists in the
      same block, which can widen; so it is a `scope`-only carve-out and the write is
      read-modify-write of one key, leaving an admin-set seal intact (design D3a)
- [x] 4.1a Resolve a caller's grants from core's shares, folder-shares only, read-through.
      Verified live: `getObjectFolder()` names the folder after the object UUID, a
      `TYPE_USER` folder share is creatable, and the resolver maps it back
- [x] 4.1b Owner-only grant / revoke API on ONE object (create the share on the object's
      folder). `ShareLinkService::createShare()` CANNOT be reused: it requires a `$fileId`
      and rejects any node that is not a file inside the folder — that is the file-share
      concept and it stays separate (task 8.2)
- [x] 4.2 Compose with schema rules — narrows within, never widens. The schema is the
      CEILING and a grant re-opens a private row within it (design D3b); tested with a
      schema whose read rule names a group the caller lacks
- [x] 4.3 Enforce the tenant edge independently of the grant list — by forcing the EXISTING
      `applyOrganizationFilter()` on whenever the caller holds a grant (design D3c). TESTED,
      and the test took three attempts: the first two passed with the forcing DISABLED
      (`_multitenancy_explicit` turns the filter on by itself; and a schema whose rules do
      not bypass multitenancy gets the filter anyway). Only a schema whose read rule names a
      REAL group the caller is in reaches the branch where the forcing is load-bearing
- [ ] 4.4 Reject a recipient's attempt to widen or re-share onward
- [x] 4.5 Carry a permission on the grant and gate the ACTION on it. Threaded through all
      three decision points; a read-only grant no longer admits update or delete. An action
      outside core's five verbs maps to no bit, so a grant cannot carry it and the caller
      fails closed — RBAC grants visibility, extension verbs are enforced at the acting
      endpoint (design Q5)
- [x] 4.6 Revocation denies on the NEXT request — no cache, no job. True by construction:
      the resolver memoises for one request and stores nothing beyond it
- [x] 4.7 A share on a FILE inside the object's folder is NOT an object grant — otherwise
      attaching a document and sharing it would hand over the object's data too
- [x] 4.8 A grant is scoped to the object it names — guards against a predicate that admits
      every private row as soon as the caller holds any grant at all
- [x] 4.9 Positive control for the grant paths: the SQL builder's grant disjunct, the
      `PermissionHandler` fall-through and the relation-path fall-through each neutered
      independently, each producing a distinct attributable failure

### Finding fixed while doing group 4

- [x] 4.10 **`prepareObjectDataForTable()` DESTROYED per-object `_authorization` on every
      save.** The method strips `authorization` from incoming metadata (per-object RBAC is
      deliberately not writable by ordinary create/update calls) — but the field was ALSO
      listed in the metadata-column map, whose loop resolves each field as
      `$metadata[$field] ?? null`. So the stripped key returned as an explicit NULL and the
      UPDATE wrote it. A private object became visible again as soon as anything saved it,
      and resolving its folder does exactly that — so SHARING an object was enough to
      un-private it. The same wipe hit the Wave-12 Fix 5 per-object action overrides. Fixed
      by removing the field from the map: `updateObjectInRegisterSchemaTable()` only sets
      keys that are PRESENT, so the stored value is now carried forward untouched. Pinned by
      `testAnUpdateDoesNotDestroyPerObjectAuthorization`, which drives the REAL writer

## 5. The share provider surface (core owns the record)

- [ ] 5.1 Share the object's FOLDER through `OCP\Share\IManager` — no provider registration (Q1). Reuse `ShareLinkService`'s folder-resolve and six-type walk for the read half
- [ ] 5.2 Read share records THROUGH at decision time; keep NO OpenRegister-side copy (design D2 — `ShareLinkService` documents why a snapshot desyncs)
- [ ] 5.3 Resolve the caller's principal set once per REQUEST and pass it to the emitters, rather than per row
- [ ] 5.4 Link shares: token, expiry, password, revocation — all core's mechanics
- [ ] 5.8 Carry object verbs (`run`, `use`) in `IShare`'s `IAttributes`; core's bitmask has no such verbs
- [ ] 5.9 Make the file coupling explicit in the UI: a grant on the folder also reaches the files in it
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
- [ ] 8.6 Collapse `scope` as the ACCESS discriminator into `private` (Q7): `personal` -> private-with-no-invitations, `organisation` -> the default scope
- [ ] 8.7 KEEP `scope` as the VAULT-OWNER selector, untouched — and test that an organisation credential minted BEFORE the collapse is still readable after it
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

## 11. Documentation and ADRs

- [ ] 11.1 Document the sharing model: `private`, per-object grants, links, email invites, federation — and how each composes with schema-level RBAC
- [ ] 11.2 Amend the RBAC docs with the `private` principal and the all-four-paths rule
- [ ] 11.3 Record the distinction between the three pre-existing share concepts, so a future reader does not add a fourth
- [ ] 11.4 Document the breaking flow change and its upgrade note
- [ ] 11.5 Amend ADR-006 for capability-style links (Q4)
- [ ] 11.6 New ADR governing permission-verb extensions: declared by the schema, enforced at the acting endpoint, never redefining a core verb (Q5)
