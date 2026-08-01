## 1. Decide the open questions

- [x] 1.1 DECIDED: the operator is `$contains` (shipped)
- [ ] 1.2 Decide whether a credential share needs a `read` verb distinct from `use` (a UI must list a credential to let someone pick it)
- [x] 1.3 DECIDED: a flow share does NOT carry run history — recipients see only their own runs (design D7)
- [x] 1.4 DECIDED: granting and revoking is owner-only; no organisation-admin path (design D8)
- [x] 1.5 DECIDED (design D9): TWO derived unprefixed scalar lists beside the rich list. `$contains` compares scalars and the entry is an object, so a direct match finds nothing (verified against the shipped operator); and a match clause resolves whole tokens, so a single PREFIXED list would be unmatchable by `$userId` too. Rejected teaching the operator a dot-path into an array of objects: `jsonb_path_exists` on PostgreSQL versus a different construct on MariaDB is the platform-divergence class this change exists to avoid.

## 2. The share-check operator, on both enforcement paths

- [x] 2.1 `$contains` in `OperatorEvaluator` — scalar-in-array plus ANY-intersection for an array operand (`$user.groups`)
- [x] 2.2 The SQL side: ONE platform-branched builder, called by BOTH the QueryBuilder and raw-SQL emitters (the QueryBuilder cannot express JSON containment on either platform), so the two list paths cannot drift
- [x] 2.3 Unit tests for the PHP operator — 12 cases, including null / non-array / empty-operand / strict-typing / array-intersection
- [x] 2.4a FIXED EN ROUTE: `MagicRbacHandler` kept a private token resolver that recognised only BARE tokens, so every dotted form (`$user.groups`, `$user.uid`, `$user.email`, `$organisation.<prop>`) resolved on `find` and fell through as a LITERAL STRING on `list`. Delegated to the one shared resolver; 8 parity tests pin it
- [x] 2.5 Positive control: disabling the `$contains` case fails 4 tests, so they are not vacuous
- [x] 2.4 The end-to-end verdict-parity matrix over a LIVE PostgreSQL database — 10 fixtures, each run through the single-object path AND the real RBAC-filtered list query, compared to each other and to the expected verdict. Found a genuine pre-existing divergence en route (see 2.6), plus two harness traps now documented as D11
- [x] 2.6 FIXED EN ROUTE: `MagicRbacHandler::hasPermission()` did not honour the `authenticated` pseudo-group, in either its simple-rule or conditional-rule branch, while BOTH SQL emitters and `PermissionHandler` do. So `{"group":"authenticated","match":{…}}` was GRANTED by the list query and DENIED on the single-object path — reachable in production via `RelationHandler`. This is the rule shape the share grants use

## 3. The `sharedWith[]` shape

- [x] 3.1 `sharedWith[]` on `credential_broker_register.json` (optional; absent grants nothing)
- [x] 3.2 `sharedWith[]` + `credentialIdentity` on `flow_register.json`
- [x] 3.3 The DERIVED scalar lists — TWO of them, `sharedUsers` + `sharedGroups`, unprefixed (design D9)
- [x] 3.4 `SharePrincipalDeriver::apply()` recomputes them on every write and DISCARDS any client-supplied value. 17 unit tests, including that clearing the share list clears the principals, and that the output serialises as a JSON ARRAY — a gappy integer key would encode as an object, which jsonb containment does not match, so the share would fail on the list path only
- [x] 3.5 Applied and VERIFIED against the live database: `brokeredcredential` 7 -> 10 properties, `flow` 10 -> 14. No `force` flag was needed — the version bump is what opens the gate — but the bump had to clear the version in the DATABASE, not the one in the file (design D12)
- [x] 3.6 Per-entry validation in `SharePrincipalDeriver::validEntries()`, failing closed per entry so one malformed entry is dropped without invalidating its siblings
- [ ] 3.7 Backfill/repair step for the derived lists, so an object written before this change (or through a direct API write that bypasses the deriver) cannot sit with a stale one
- [ ] 3.8 Wire `SharePrincipalDeriver::apply()` into the object write path so it cannot be bypassed — it exists and is tested, but nothing calls it yet (lands with the share APIs in groups 5 and 6)

## 4. Credential sharing (broker guard chain)

- [x] 4.1 Guard 1c added to `loadAdmittedCredential()`. It only ever ADMITS, never denies, so the scope guards keep producing their specific denial reasons and every pre-existing verdict is unchanged
- [x] 4.2 Group principals resolved via `IGroupManager` as permission principals only. Injected NULLABLE with a default: eight call sites construct this service directly and a drifted constructor is a FATAL, not a test failure. The fallback is fail-closed — no group manager means a group share admits nobody
- [x] 4.3 Tenant edge enforced in `shareWithinTenant()`: a credential declaring an `organisation` admits a named principal only inside it; a session is authoritative and an asserted organisation is ignored, so a request-context caller cannot escalate
- [x] 4.4 Fails closed on no acting identity, absent/malformed `sharedWith`, unresolvable principal, and outside-tenant — falling through to the existing static 403
- [x] 4.5 Tested: owner still admitted, non-owner still denied, empty share list changes nothing
- [x] 4.6 Tested: a share recipient is still refused by `allowedApps`. 15 new tests, and a positive control — disabling the branch fails 5 of them, so they are not vacuous
- [x] 4.7 Tested: the recipient's response never contains the secret (a share grants USE, not sight)

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
