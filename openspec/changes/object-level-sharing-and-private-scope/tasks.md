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
- [x] 3.9 **Pre-existing PHP warning — LOCALISED, and it is NOT in OpenRegister.** `QueryBuilder::select()`
      emits `Undefined array key 0`. The cause is a **named argument on a variadic parameter**:
      `$qb->select(selects: '*')` makes PHP collect the argument as `['selects' => '*']`, a
      string-keyed array, so core's `count($selects) === 1 && is_array($selects[0])` unwrap reads a
      key that does not exist. Backtrace (captured via `auto_prepend_file` + `set_error_handler`)
      lands in **launchpad** `lib/Db/DashboardMapper.php`, reached from openregister's tests only
      because a `tearDown()` user deletion fires launchpad's `UserDeletedListener`. 174 call sites,
      all in launchpad, none in openregister. The string form works by luck (`quoteColumnNames()`
      maps over values); the **array** form is genuinely broken — the unwrap that was supposed to
      flatten it never runs, so an array is passed as a column name
      (`launchpad/lib/Migration/Version001006Date20260430130000.php:192`). Fixing it is mechanical
      (drop the `selects:` label) and belongs to the launchpad work in group 8.3, not here.

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
- [x] 4.4 Reject a recipient's attempt to widen or re-share onward. TWO halves, and only the
      first was already covered. `requireOwnerOrAdmin()` guards all five write methods on
      `ObjectSharingService`, so a recipient cannot add a principal or widen through OUR
      endpoints — now asserted, with the control that matters: the caller is GRANTED read and
      confirmed to see the object first, because a stranger would also be refused and that
      would prove nothing about recipients. The second half was a real gap: a grant IS a share
      on the object's FOLDER and core's Files UI acts on that folder directly, so a mask
      carrying `PERMISSION_SHARE` let the recipient re-share the folder through core — and
      since the resolver reads grants from exactly those folder shares, the result was a valid
      object grant created by someone never allowed to create one. The API guard would be
      intact and the property still false. Core's re-share bit is now cleared in ONE place used
      by both share-construction paths (`grant()` and `newFolderShare()`, which backs links and
      email invitations) so the two cannot drift. Stripped rather than rejected: a caller
      passing a convenience mask like 31 does not mean to delegate re-sharing, and the safe
      reading of an ambiguous request is the narrower one. Verified by removing the clamp and
      watching the test fail on the persisted share (`16 is not identical to 0`); the test also
      asserts read and update SURVIVE, so a blanket zero could not pass instead.
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

- [x] 5.1 Share the object's FOLDER through `OCP\Share\IManager` — no provider registration (Q1)
- [x] 5.2 Read share records THROUGH at decision time; keep NO OpenRegister-side copy (design D2)
- [x] 5.3 Resolve the caller's principal set once per REQUEST and pass it to the emitters
- [x] 5.4 Link shares: token, expiry, password, revocation — all core's mechanics, redeemed on a
      PUBLIC endpoint because a link admits whoever holds the token and there is no principal
      for RBAC to resolve
- [ ] 5.8 Carry object verbs (`run`, `use`) in `IShare`'s `IAttributes`; core's bitmask has no such verbs
      HALF SHIPPED, and the half that shipped is `use`. 8.4 required ADR-010's IAttributes
      mechanism, so grants already carry extension verbs and `grantCarriesVerb()` is deliberately
      separate from `isGranted()`, which keeps answering only for the five core verbs.
      Deferred is `run` specifically: it has no consumer until run authorization exists (9.2,
      flow-engine owner), and shipping a verb nothing reads would be a control that silently does
      nothing — the exact defect shape this programme kept finding.
- [x] 5.9 Make the file coupling explicit in the UI: a grant on the folder also reaches the files
      in it. The grants section now states it — "Everyone listed here can also open the files
      attached to this item" — with a unit test that fails without it (nextcloud-vue#591).
      ⚠️ Not yet VISIBLE in openregister: it ships in the release after 3.0.0-vue3.2, so it
      appears on the next consuming bump.
- [x] 5.5 Email invitations through core's mailer (`TYPE_EMAIL`); the message carries no object
      data, so revocation still works after delivery
- [x] 5.6 A share never exceeds the sharer's own access — owner-or-admin is required to create
      one, and core clamps the permission against the node
- [x] 5.7 A share revoked or expired in core takes effect on the NEXT request, with nothing for
      OpenRegister to invalidate — both are checks inside `getShareByToken()`. Tested, with the
      control: neutering the revoke makes the test fail

## 6. nc-vue: one component, two surfaces

- [x] 6.1 A shares component: invite by user / group / email, create a link, set expiry, revoke —
      mirroring the Files share panel. `CnObjectAccessTab`. ⚠️ It shipped with the grant type sent
      as `shareType: 0|1`, a key the controller does not read, so it fell through to the "user"
      default and GROUP grants silently became user grants; user grants worked by coincidence.
      Fixed in nextcloud-vue#591. The old unit test asserted only `permissions` and passed over
      it — the replacement asserts the WHOLE request body, because a test that checks the fields
      it knows about cannot see a field that is MISSING.
- [x] 6.2 Expose it as a detail-page **Shares** tab. `ObjectDetails.vue`, gated on
      `relationContext` — the component declares register/schema/objectId REQUIRED and requests on
      mount, so an ungated render would fire at `/objects/undefined/undefined/undefined/shares`.
- [ ] 6.3 Expose it as a `shared-with-me` dashboard widget. BLOCKED, and the blocker is measured
      rather than suspected. A grant resolves to an object UUID, but objects live in
      per-register/schema tables, the legacy central `openregister_objects` table holds 0 rows, and
      the object folder path is `files/Open Registers/{Register TITLE}/{uuid}` — no schema segment
      at all, and the register only by title. So a cross-register list needs the cross-table search,
      and `testUnionPathTenantEdgeIsCharacterised` now shows that path returns rows from ANOTHER
      organisation: the scope-and-grant predicate is applied there (groups 2–4 put it there) but the
      ORGANISATION filter is not. `TODO(SEC-CTRL-1)` in ObjectsController is therefore accurate for
      multitenancy and stale for RBAC. Building this widget over that path would leak across
      tenants, so it waits until tenancy is wired into `searchAcrossMultipleTables()` — at which
      point that characterisation test fails, which is the signal to flip it and build. (An HTTP-level
      probe of the existing cross-table endpoints was INCONCLUSIVE: my pair arguments were invalid,
      so it returned "No valid magic-mapped register+schema combinations found" for admin too. The
      exposure is proven at the mapper level; reachability from HTTP is not established either way.)
- [ ] 6.4 Register the widget in the dashboard catalogue and call `registerBuiltinDashboardWidgets()` — a bare side-effect import is tree-shaken and every registry tile silently renders "Widget not available"
- [ ] 6.5 Semantic icons via the ADR-077 vocabulary, and REGISTER every name used — an unregistered MDI name renders nothing at all, not a fallback
- [x] 6.6 Publish on the `vue3` tag and verify the dist-tag MOVED before consuming it. Verified
      from `npm view dist-tags` each time, not from a green Release run. ⚠️ The line moved to a
      MAJOR mid-programme (2.1.0-vue3.19 -> 3.0.0-vue3.2) while this change was open; the major is
      solely the removal of three flow editors, none referenced in openregister, so the consuming
      bump crossed it safely.

## 7. Federated principals

- [x] 7.1 A remote principal is one more principal — satisfied STRUCTURALLY: both remote share
      types sit in the same lists as user/group, so a remote grant uses the same resolve, the
      same SQL disjunct and the same PHP verdict. Pinned by `FederatedPrincipalVocabularyTest`,
      because the property is invisible and nothing else would notice it being edited away
- [ ] 7.2 A federated grant yields the same verdict as a local grant — NOT PROVEN, and cannot be
      on one instance. Needs a SECOND Nextcloud and an OCM handshake. Structurally it must hold
      (there is only one evaluator), but that is an argument, not evidence
- [ ] 7.3 Revoking a federated grant denies it — same two-instance dependency as 7.2
- [x] 7.4 RESOLVED THE OTHER WAY, and the original wording was wrong. `FederatedShare` shares a
      register/schema/object/query with an ORGANISATION on a peer instance, authorised by
      OpenRegister's own bearer token and served by `federation#objects`; a `TYPE_REMOTE` grant
      invites a remote USER to one object and is decided by the ordinary RBAC filter. Folding one
      into the other would give the grant a SECOND decision path — what D4 forbids — and put
      object-level RBAC behind a token designed to authorise a whole register. They stay distinct,
      for the same reason file shares do (8.2). See design D5

## 8. Fleet-wide consolidation

- [x] 8.1 Audited. The count was wrong and so was the framing: openregister has 32 files across
      FIVE concepts, not 12 across four. Four are legitimately distinct and stay — file shares,
      `FederatedShare`, and `ShareableConfigType` (config distribution over GitHub, which this
      design had not accounted for at all). Only the bespoke `sharedWith[]` duplicates the new
      primitive. Fleet-wide the duplicates are launchpad's manifest `sharedWith[]` and doriath's
      `sharedWithMe` dashboards; opencatalogi's three files are FILE-oriented and need nothing.
      See design D6
- [x] 8.2 FILE shares stay distinct and unchanged — and so do `FederatedShare` and
      `ShareableConfigType`, for the same reason: sharing a container, distributing a
      configuration, and inviting a person are three different acts
- [ ] 8.3 Migrate the bespoke `sharedWith[]` to the primitive. Scoped by the audit to THREE
      consumers: openregister credentials + flows, launchpad manifests, doriath dashboards
      PARTIALLY DONE, and deliberately not a migration. The decision (2026-08-03) was to add
      grant-sourcing to launchpad as NET-NEW rather than to move the bespoke list: launchpad
      manifests now fold in dashboards granted through the primitive alongside the user's own
      (lp#24), which is 8.4's "read both" shape rather than a flag day. Retiring the bespoke
      lists is 8.5 and stays blocked on nothing reading them.
      Still untouched: doriath dashboards, and the credential/flow lists in openregister.
- [x] 8.4 The broker reads the primitive as Guard 1d, ALONGSIDE its own 1c rather than instead of
      it — so a credential shared either way is admitted and retiring the bespoke list becomes a
      data migration, not a flag day. The verb is `use`, not `read` (Q6), which required building
      ADR-010's IAttributes half: grants can now carry extension verbs, and `grantCarriesVerb()`
      is separate from `isGranted()` so RBAC keeps answering only for the five core verbs
- [ ] 8.6 Collapse `scope` as the ACCESS discriminator into `private` (Q7): `personal` -> private-with-no-invitations, `organisation` -> the default scope
- [ ] 8.7 KEEP `scope` as the VAULT-OWNER selector, untouched — and test that an organisation credential minted BEFORE the collapse is still readable after it
- [ ] 8.5 Remove the per-schema derived lists once nothing reads them, with a data migration — not before

## 9. Flows (BREAKING — last, and it unblocks the previous change)

- [x] 9.1 Give flows read authorization; they have `authorization = NULL` today, so this REMOVES tenant-wide visibility.
      DONE: the `flow` schema in `lib/Settings/flow_register.json` now declares
      `scope: private` plus explicit `read`/`create`/`update`/`delete` rules for `authenticated` — the four
      action rules preserve the existing capability, `scope` narrows which objects it applies to.
      Lands via the existing `ImportFlowRegister` repair step on `occ upgrade`; no `--force` needed, because
      `ImportHandler::schemaContentDiffers()` compares `authorization` and so applies the block even when the
      stored version is not older. Descriptor bumped 1.3.0 → 1.4.0, and the drifted
      `ImportFlowRegister::REGISTER_VERSION` (`1.1.0`) corrected to match. BREAKING; upgrade note in CHANGELOG.
      The safety property — that `_rbac: false` bypasses the private scope, so triggers, scheduled runs and
      sub-flows keep resolving — is now pinned by
      `testRbacFalseBypassesThePrivateScopeSoTheFlowEngineStillResolves`, which runs its control first so
      "the row is visible" cannot pass for a row that was never private.
- [ ] 9.2 Give flows run authorization: `flowRun#test`, `flowRun#retry` and `FlowMcpToolProvider::runFlow()` all run a flow with zero ownership checks today
      DEFERRED, deliberately and not for lack of a design: the flow engine is being consolidated
      into OpenRegister under a different owner, and run authorization belongs with the code that
      executes a run. Half of the original finding DID ship here — `retry()` was an open IDOR and
      is fixed (#2290) — because that was a live hole, not a design question. `test()` and
      `FlowMcpToolProvider::runFlow()` are the engine owner's to close.
- [ ] 9.3 Only then enable `credentialIdentity: owner` from `shared-credentials-and-flows` — until run authorization exists, any authenticated user could run someone else's flow and cause calls signed with that owner's secret
      DEFERRED to the flow-engine migration, same owner as 9.2.
- [x] 9.4 Scope run history to the requester for a share recipient (that change's design D7).
      The task understated it: `FlowRunController::index()` had NO scoping at all —
      `findAllRuns()` filtered only by flowId and status — so any authenticated user could list
      EVERY run on the instance, including each run's log, which records the subject data the
      flow touched. That is the exposure D7 exists to prevent, and it was live for everyone, not
      only for share recipients. Now scoped per caller: runs you TRIGGERED, plus runs of flows
      you OWN. The second disjunct is load-bearing because `triggered_by` is NULL for cron- and
      trigger-fired runs, so "only runs you triggered" would have blinded a flow's own owner to
      every automated run of it. Owned flow ids resolve in ONE query, not per run, and the owner
      filter is NESTED under `@self` — a bare `owner` key is read as a filter on a schema
      PROPERTY, which the flow schema does not have, so it silently matches nothing. An
      administrator still gets the unscoped view; `isAdmin()` fails CLOSED, so a missing group
      manager or session scopes rather than widens, and an empty owned-list narrows to "runs I
      triggered" rather than collapsing to nothing or to everything. Four live-DB tests, and the
      control is the discriminating half: with the predicate disabled 3 of the 4 fail (another
      user's run visible, an unowned cron run visible, everybody's runs visible on an empty
      owned-list) while the null-requester admin test correctly still passes.

## 10. e2e (Playwright) and verification

- [x] 10.1 A private object is invisible to another user, visible to its owner — through the UI.
      The switch is clicked in the browser; the verdict is read from a separate API context as the
      other user, and the owner is checked too so the toggle cannot pass by locking everybody out.
      Control: the object must be readable by the other user BEFORE the click.
      ⚠️ The browser is authenticated as `e2e-owner` by HEADER, and the spec ASSERTS
      `OC.getCurrentUser().uid` — the config authenticates everything as admin, and an admin
      bypasses the private scope, so a silent fallback would leave the test green and empty.
- [x] 10.2 Invite a user, they see it; revoke, they do not — through the UI. Both directions in
      one test: the grant is typed into the tab and the consequence is measured by a direct API
      call as the recipient, then the revoke button is clicked and the same call must fail again.
      The recipient is blocked BEFORE the grant, so the restored access cannot be a false pass.
- [x] 10.3 Create a link in the UI, open it in a fresh context, revoke it, confirm it stops
      working. The token is read out of the rendered panel and resolved from an ANONYMOUS
      browser context — a second context is required, not tidiness: the describe block puts
      the owner's Authorization header on every request the test context makes, so reusing
      the page would prove only that an owner can read their own object.
      The HTTP sibling mints its OWN link, so it stays green even if the UI control posts to
      the wrong endpoint or renders a token it never received; this drives the control.
- [ ] 10.4 Email invite delivered and followable
- [ ] 10.5 The Shares tab and the shared-with-me widget both render real data, asserted against a
      direct API call. HALF DONE and the other half is blocked. The TAB half is covered by
      `tests/e2e/ci/object-shares-tab.spec.ts`: it asserts the tab renders the live surface (not
      an empty panel, and not its error branch), and every UI action is verified by a direct API
      call as the OTHER user. The WIDGET half waits on 6.3.
- [ ] 10.6 Assert on rendered SVGs and measured content, not on the manifest — an unregistered icon renders nothing and a stale bundle serves the pre-fix code
- [x] 10.7 `composer check:strict` in the container (host PHP is too old). Its four constituents
      run as separate CI jobs — phpcs, phpmd, psalm, phpstan — and all four are green on every PR
      in this programme, which is stronger than one local run: they run on PHP 8.3 AND 8.4.

## 11. Documentation and ADRs

- [x] 11.1 `docs/Features/object-sharing.md` — the scope, grants, links, email invites, the
      ceiling rule, and the fact that granting an object also reaches its FILES
- [x] 11.2 `docs/Features/access-control.md` links to it from the object level, and the new page
      states the all-four-paths rule and the one-line verdict
- [x] 11.3 There were FOUR pre-existing concepts, not three — see the audit in design D6. All four
      distinctions are recorded there and the user-facing ones in the docs page
- [x] 11.4 Document the breaking flow change and its upgrade note. The CHANGELOG entry carries
      WHAT BREAKS / HOW TO MIGRATE / HOW IT LANDS; `docs/Features/object-sharing.md` now carries
      an operator-facing "Upgrading: flows became private" section, because an admin reading the
      feature docs would otherwise learn about scope and grants and NOT that their existing flows
      had just become invisible. It states the 404-not-403 choice (a distinguishable 403 is an
      id-enumeration oracle) and gives the one grant call that restores a team flow.
- [x] 11.5 ADR-006 Rule 4 — a link is a revocable, expiring, attributable CAPABILITY and is not a
      data flag, so it does not violate Rules 1-3. Includes what it does NOT license
- [x] 11.6 ADR-010 — uniform core set on core's bitmask; an action with no core bit is not
      admitted by a grant (fail closed); extensions declared by the schema, enforced at the acting
      endpoint, never redefining a core verb
