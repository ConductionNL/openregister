# Federation and sharing scope enforcement

## Problem

Enabling the Hydra mechanical gates on this repository (#2335) produced a green
tick that certified nothing: the gates are diff-scoped, and the enablement PR
touched three CI files, so 58 gates "passed" in about a second having read no
`lib/`, no `src/` and no `routes.php`.

Re-run against the FULL tree, 19 gates fail. Gates 6, 7, 8 and 9 — the ADR-005
authorisation family — account for 27 findings between them. Most are false
positives on legitimate delegation, but four are real, and all four are the same
shape: an authorisation decision that one code path makes and a neighbouring
path does not.

1. **`FlowController::state()` was an unguarded cross-organisation read.**
   `GET /api/flow/{flowId}/state` is `@NoAdminRequired` and handed the
   client-supplied uuid straight to `FlowStateMapper::findByFlow()`, which
   applies no organisation scoping at all. Any authenticated user could read any
   other tenant's flow state — arbitrary data written by flow nodes: slot
   holders, external identifiers, run bookkeeping — by naming its uuid. It was
   also the one method in the class that broke the invariant stated in the
   class's own file header ("every CRUD method goes through `FlowService`, never
   `FlowMapper`").

2. **`FederatedConfigController::bundle()` was `publish()` without the gate.**
   Same `serialise()` call, same bytes, no `canPublish()` check — so anyone the
   publish gate refused could ask for the export directly. Underneath it,
   `GenericObjectShareableConfigType::serialise()` passed `_rbac: false` and
   `_multitenancy: false` (engine escape hatches, on a path a REQUEST reaches),
   and `FlowShareableConfigType::serialise()` went to `FlowMapper::findByUuid()`
   directly.

3. **An object-scope federated share reached further than it granted.**
   `buildScopeConfig()` pins `filters['uuid']` on the collection endpoint, but
   `object()` / `updateObject()` / `deleteObject()` took `{id}` from the URL and
   never compared it to the grant. A token for ONE object read, overwrote or
   deleted ANY object in the same register/schema — confidential ones included,
   because `applyShareVisibility()` deliberately skips the confidentiality
   filter for object scope. The item path was strictly wider than the list path
   that guards it.

4. **A bulk row whose schema failed to load was written with no RBAC check.**
   `resolveSafeguardSchema()` swallowed every `\Throwable` into a bare `null`,
   which is also what a legitimate mixed-schema batch looks like — and that
   branch passes the row through. The write still went ahead, because the
   single-schema fast path re-resolves `$schema` downstream and never consults
   the safeguard's opinion.

## Solution

Enforce the scope at the point the request reaches, in each of the four cases,
and prove each with a test that fails without the change. See the spec delta.

## Non-goals

- Gate-7's remaining 17 findings and gate-9's 6 are false positives on this
  repository's idioms (organisation scoping that returns "not found" rather than
  "forbidden", and token-authenticated `#[PublicPage]` endpoints). They are
  reported upstream as gate bugs rather than worked around here.
- Whether an ordinary organisation member should be able to mint an outgoing
  federated share at all (`FederationController::createShare` is
  `#[NoAdminRequired]` with no group gate, unlike
  `federated_config_publish_groups`) is a product decision, not a defect.
