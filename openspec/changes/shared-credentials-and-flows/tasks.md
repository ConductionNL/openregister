## 1. Decide the open questions

- [x] 1.1 DECIDED: the operator is `$contains` (shipped)
- [ ] 1.2 Decide whether a credential share needs a `read` verb distinct from `use` (a UI must list a credential to let someone pick it)
- [x] 1.3 DECIDED: a flow share does NOT carry run history — recipients see only their own runs (design D7)
- [x] 1.4 DECIDED: granting and revoking is owner-only; no organisation-admin path (design D8)
- [ ] 1.5 DECIDE the storage shape (design D9) — `$contains` compares SCALARS, and the declared `sharedWith[]` entry is an OBJECT `{type, id, permission}`, so `{"sharedWith": {"$contains": "$userId"}}` matches NOTHING. Verified against the shipped operator. Options: (a) a derived scalar principal list beside the rich list, (b) teach the operator a dot-path into an array of objects. (b) needs `jsonb_path_exists` on PostgreSQL and a different construct on MariaDB — the platform-divergence class this change exists to avoid. Recommend (a).

## 2. The share-check operator, on both enforcement paths

- [x] 2.1 `$contains` in `OperatorEvaluator` — scalar-in-array plus ANY-intersection for an array operand (`$user.groups`)
- [x] 2.2 The SQL side: ONE platform-branched builder, called by BOTH the QueryBuilder and raw-SQL emitters (the QueryBuilder cannot express JSON containment on either platform), so the two list paths cannot drift
- [x] 2.3 Unit tests for the PHP operator — 12 cases, including null / non-array / empty-operand / strict-typing / array-intersection
- [x] 2.4a FIXED EN ROUTE: `MagicRbacHandler` kept a private token resolver that recognised only BARE tokens, so every dotted form (`$user.groups`, `$user.uid`, `$user.email`, `$organisation.<prop>`) resolved on `find` and fell through as a LITERAL STRING on `list`. Delegated to the one shared resolver; 8 parity tests pin it
- [x] 2.5 Positive control: disabling the `$contains` case fails 4 tests, so they are not vacuous
- [x] 2.4 The end-to-end verdict-parity matrix over a LIVE PostgreSQL database — 10 fixtures, each run through the single-object path AND the real RBAC-filtered list query, compared to each other and to the expected verdict. Found a genuine pre-existing divergence en route (see 2.6), plus two harness traps now documented as D11
- [x] 2.6 FIXED EN ROUTE: `MagicRbacHandler::hasPermission()` did not honour the `authenticated` pseudo-group, in either its simple-rule or conditional-rule branch, while BOTH SQL emitters and `PermissionHandler` do. So `{"group":"authenticated","match":{…}}` was GRANTED by the list query and DENIED on the single-object path — reachable in production via `RelationHandler`. This is the rule shape the share grants use

## 2. The share-check operator, on both enforcement paths

- [ ] 2.1 Add the operator to `OperatorEvaluator` — "the object's array-valued property contains the resolved value" — with array-to-array intersection so `$user.groups` works unchanged
- [ ] 2.2 Add the same operator to `MagicRbacHandler`'s SQL emitter (`buildSingleOperatorCondition`), per the class contract that operators live in `OperatorEvaluator` and only the emission stays local
- [ ] 2.3 Unit-test the PHP operator: scalar-in-array, array-intersects-array, empty array, null value, non-array property, malformed entry — each denying rather than passing through
- [ ] 2.4 Write the verdict-parity matrix (design D6): one fixture set run through the single-object path AND the list path, asserting identical verdicts for owner, org member, non-member, shared user, member of a shared group, revoked share, anonymous, malformed entry
- [ ] 2.5 Confirm the parity matrix FAILS if either implementation is removed — a parity test that passes with one side stubbed is proving nothing

## 3. The `sharedWith[]` shape

- [ ] 3.1 Add `sharedWith[]` to `lib/Settings/credential_broker_register.json` (optional; absent grants nothing)
- [ ] 3.2 Add `sharedWith[]` and `credentialIdentity` to `lib/Settings/flow_register.json`
- [ ] 3.3 Add the DERIVED scalar principal list the RBAC predicate matches (per 1.5), e.g. `sharedPrincipals: ["user:alice", "group:finance"]`
- [ ] 3.4 Derive it server-side on every write to `sharedWith[]` — NEVER accept it from the client. Two representations of one fact is a drift hazard, and a stale derived list is an access-control bug in whichever direction it is stale
- [ ] 3.5 Apply both registers with a **forced** import and verify the properties exist on a live schema — a non-forced import advances the version WITHOUT applying it
- [ ] 3.6 Validate entries server-side: `type` in {user, group}, non-blank `id`, known `permission`; reject unknown shapes rather than storing them
- [ ] 3.7 Backfill/repair step for the derived list, so an object written before this change (or by a direct API write) cannot sit with a stale one

## 4. Credential sharing (broker guard chain)

- [ ] 4.1 Add the third admit branch to Guard 1 in `CredentialBrokerService::loadAdmittedCredential()`, ordered after the personal-owner and organisation-member branches
- [ ] 4.2 Resolve group principals via `IGroupManager`, as permission principals only — never as a tenant discriminator (ADR-002 Rule 1)
- [ ] 4.3 Enforce the tenant edge: deny a named principal who is outside the credential's organisation
- [ ] 4.4 Keep the branch fail-closed: unauthenticated, unresolvable principal, malformed entry, store error all deny through the single static 403, logged secret-free
- [ ] 4.5 Test that existing verdicts are byte-for-byte unchanged for credentials with no `sharedWith[]`
- [ ] 4.6 Test that a share recipient still passes through `allowedApps`, allow-rule, and host-lock guards, and is denied by each when it should be
- [ ] 4.7 Test that no share path returns secret material — object read, export, audit row, error body

## 5. Credential share management API

- [ ] 5.1 Owner-only grant / revoke endpoints on `CredentialController`, with an explicit auth posture on every route
- [ ] 5.2 Reject `sharedWith[]` writes from a share recipient (no self-widening, no onward re-sharing)
- [ ] 5.3 "Shared with me" read: credentials naming the user directly or via a group they belong to
- [ ] 5.4 Verify every new response is secret-free (ADR-004 Rule 1)

## 6. Flow sharing

- [ ] 6.1 Express the flow share grant as ONE conditional RBAC rule in the flow schema's `authorization` block, matching the derived principal list — so one server-side decision covers both `find` and `list`. One schema rule plus per-object data; NOT a rule per share
- [ ] 6.2 Distinguish `read` from `run`. NOTE: `run` is not an object RBAC verb (the actions are create/read/update/delete), so RBAC grants VISIBILITY and the trigger endpoint enforces the verb by reading the rich `sharedWith[]`. Two enforcement points for one grant — state it, and test that a `read`-only recipient is refused at the trigger
- [ ] 6.3 Ensure a share never grants `edit` — definition, `sharedWith[]`, and `credentialIdentity` all stay owner-only
- [ ] 6.4 Owner-only grant / revoke endpoints for flow shares, plus "flows shared with me"
- [ ] 6.5 Test the revocation path on BOTH read and list, since they are separate implementations
- [ ] 6.6 Scope run history to the requester for a share recipient — they see their own runs only, never the owner's or another recipient's (design D7)
- [ ] 6.7 Test that a non-owner cannot grant or revoke (design D8)

## 7. Credential identity on a run

- [ ] 7.1 Add `credentialIdentity` handling to the flow, defaulting to `runner` when absent so current behaviour is preserved exactly
- [ ] 7.2 Reject a `credentialIdentity` write from anyone but the flow owner
- [ ] 7.3 Add a `FlowRun` field for the resolved credential identity, separate from `triggeredBy`, plus its migration
- [ ] 7.4 Resolve the identity at queue time and record it on the run
- [ ] 7.5 Thread the resolved identity into the broker's sessionless in-process assertion at execution time (`FlowRunWorker` has no session)
- [ ] 7.6 Test that the HTTP-routed broker path cannot set the acting identity from a parameter, header, or body field
- [ ] 7.7 Test that `owner` mode returns no secret to the runner — it grants use, not sight

## 8. Documentation and ADR amendment

- [ ] 8.1 Amend ADR-004 Rule 4: its text enumerates the guard chain, which now has a third admit branch in Guard 1
- [ ] 8.2 Record in ADR-004 that a share grants use and never disclosure, so the boundary is documented where a reviewer will look
- [ ] 8.3 Note in the flow docs that `credentialIdentity: owner` is a delegation of the owner's authority, with its audit trail

## 9. Verify

- [ ] 9.1 `composer check:strict` clean (run in the container — host PHP is too old)
- [ ] 9.2 The full parity matrix green, and re-checked after the last change to either path
- [ ] 9.3 Live-verify a share end to end: grant, recipient uses it, owner revokes, recipient is denied — through the UI, not only the API
- [ ] 9.4 Live-verify a lent-credential flow run and confirm the run records both identities

## 10. Leaf app changes (separate changes, dependent on this one)

- [ ] 10.1 doriath — credential share UI and "shared with me"
- [ ] 10.2 hermiq — flow share UI plus the owner-only `credentialIdentity` control
- [ ] 10.3 hermiq — add shared credentials as a candidate in `CredentialScopeResolver`, which stays a selector re-validated by the broker's guards and so cannot widen access on its own
