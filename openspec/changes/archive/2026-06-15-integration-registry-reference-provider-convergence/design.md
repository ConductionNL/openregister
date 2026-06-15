# Design — Integration Registry ↔ IReferenceProvider Convergence (Investigation)

This is the **investigation deliverable** required by ADR-041 Decision #3. It is a decision
record + recommendation, not a refactor design. The same content is published as a standalone
decision record at `docs/development-notes/integration-registry-vs-reference-provider.md`; this
file is its in-change copy.

---

## 1. The two systems, side by side

### 1a. OpenRegister integration registry (ADR-019 leaf system)

- **Contract:** `OCA\OpenRegister\Service\Integration\IntegrationProvider` — full CRUD over
  *linked things*: `list / get / create / update / delete`, plus metadata
  (`getId`, `getLabel`, `getIcon`, `getGroup`, `getRequiredApp`, `getStorageStrategy`,
  `getOpenConnectorSource`, `isEnabled`, `requiresPermission`, `authRequirements`, `health`).
- **Registry:** `IntegrationRegistry` — in-process map keyed by id;
  `addProvider / get / list / listIds / getEnabled / isValidIntegrationId`. Providers are
  registered **only** from OpenRegister's own `Application::boot()` via
  `bootBuiltinIntegrationProviders()` (`addProvider(...)`). There is **no event/DI hook for a
  sibling NC app to contribute a leaf** — ADR-041's core finding.
- **Base class:** `AbstractIntegrationProvider` — defaults CRUD verbs to
  `throw NotImplementedException` (template method); concrete providers override the verbs their
  storage strategy supports.
- **External dispatch:** `ExternalIntegrationRouter::call()` → OpenConnector `CallService`
  (HTTP) for `storage='external'` providers.
- **Scope:** every call is `(register, schema, objectId)`-scoped. This is the *object-graph*
  scoping that makes a leaf "the files **of this object**", "the deck cards **of this object**".
- **Storage strategies (observed, 22 providers + 5 builtin = inspected):**
  `link-table` ×19 (dedicated `openregister_*_links` tables), `external` ×4 (OpenConnector),
  `query-time` ×3 (live read, no store), `magic-column` ×1 (link on the OR row).
- **Frontend:** paired by id with `OCA.OpenRegister.integrations.register({ id, tab, widget })`;
  consumed via `useIntegrationRegistry` driving `CnObjectSidebar` tabs and `CnIntegrationCard /
  CnIntegrationWidgetGrid`. Four widget surfaces incl. `single-entity` (a schema `reference`
  property with a `referenceType` marker renders the referenced entity through the provider's
  `get()`).
- **Already present read-render sibling:** OR ships
  `OCA\OpenRegister\Reference\ObjectReferenceProvider extends ADiscoverableReferenceProvider
  implements ISearchableReferenceProvider`, registered via
  `$context->registerReferenceProvider(...)` in `Application.php`. So the two systems **already
  coexist in OR today** — the registry for object-scoped leaves, an `IReferenceProvider` for
  Smart-Picker rich links to OR objects.

### 1b. Nextcloud native `IReferenceProvider` (OCP/Collaboration/Reference)

- **Contract (`IReferenceProvider`):** `matchReference(string $referenceText): bool`,
  `resolveReference(string $referenceText): ?IReference`,
  `getCachePrefix(string $referenceId): string`, `getCacheKey(string $referenceId): ?string`.
  **Read/resolve only — there is no create/update/delete.**
- **`IReference`:** a rich-preview value object — `id / title / description / imageUrl /
  url / richObject(type, data) / openGraph`. A *render* payload, not an entity with mutable
  sub-resources.
- **`IDiscoverableReferenceProvider`:** adds `getId / getTitle / getOrder / getIconUrl` so the
  provider shows up in the Smart-Picker provider list (discovery).
- **`ISearchableReferenceProvider`:** adds `getSupportedSearchProviderIds()` so the rich-text
  picker can search via unified-search providers.
- **`RenderReferenceEvent`:** dispatched so apps can ship JS to render references richly.
- **`IReferenceManager`:** `extractReferences / resolveReference / getReferenceByCacheKey /
  getReferenceFromCache / invalidateCache / getDiscoverableProviders / touchProvider`. **Caching
  is first-class and provider-owned** (`getCachePrefix`/`getCacheKey`).
- **Registration:** `IRegistrationContext::registerReferenceProvider(class)` — **cross-app by
  design**. Any NC app registers a provider; NC's `ReferenceManager` collects them all. This is
  exactly the cross-app collection ADR-019's registry lacks.

### 1c. The shape mismatch in one line

`IReferenceProvider` answers **"render a link to a thing identified by a URL/text"**
(cross-app, cached, read-only). The OR registry answers **"manage the things *linked to this
object*"** (object-scoped, CRUD, with link tables). They overlap only on the *read/render*
edge.

---

## 2. Responsibilities matrix (REQ-CONV-001)

| Responsibility (OR registry) | Bucket | Can `IReferenceProvider` cover it? |
|---|---|---|
| `list(register,schema,objectId)` — the linked things **of an object** | READ, **but object-scoped** | **No.** `IReferenceProvider` resolves a single reference from text/URL; it has no "list the X of object Y" verb. Object-scoped enumeration is value-add. |
| `get(register,schema,objectId,entityId)` — fetch one linked entity | READ/RENDER | **Partial.** Resolving one entity to a preview ≈ `resolveReference` + `IReference`. But the OR `get()` returns the *full entity* (for `single-entity` widgets / form prefill), not a preview card. |
| `create / update / delete` | **VALUE-ADD** | **No.** `IReferenceProvider` is strictly read-only. The write verbs are the registry's genuine added value. |
| Link tables (`openregister_*_links`) + `magic-column` persistence | **VALUE-ADD** | **No.** References are stateless/derived; the registry persists the *link* between an OR object and the linked thing. |
| `(register, schema, objectId)` scoping | **VALUE-ADD** | **No.** This is the object-graph binding that has no analogue in the reference model. |
| `getStorageStrategy / getOpenConnectorSource` + `ExternalIntegrationRouter` (OpenConnector HTTP) | **VALUE-ADD** | **No.** Reference providers carry no transport/storage abstraction. |
| `isEnabled / getRequiredApp / health / authRequirements` (3-stage filter, OCS capabilities) | mostly VALUE-ADD | Partial overlap with discovery (`IDiscoverableReferenceProvider`), but the *required-app/health/auth* semantics are richer. |
| `getId / getLabel / getIcon / getGroup` (discovery metadata) | READ | **Yes.** Maps cleanly to `getId / getTitle / getIconUrl / getOrder`. |
| Rich preview / "render a link to an OR object in another app" | READ/RENDER | **Yes — already done** by `ObjectReferenceProvider`. This is the canonical home for cross-app *rendering*, exactly as ADR-041 says. |
| Schema `reference` property `single-entity` resolution | READ/RENDER | Partial — could resolve via a reference, but it currently needs the full entity, not a preview. |

**Read of the matrix:** the registry's *render-a-link-to-an-OR-object-in-another-app* edge is
already (correctly) served by `IReferenceProvider`. Everything that makes the registry
*distinct* — object-scoped enumeration, CRUD write verbs, link tables, OpenConnector transport
— has **no `IReferenceProvider` analogue**. The overlap is narrow and largely already resolved.

---

## 3. Recommendation (REQ-CONV-002)

### Headline: **KEEP-SEPARATE-BUT-ALIGN.**

Do **not** converge the OR integration registry onto `IReferenceProvider`, and do **not** grow
a bespoke cross-app provider-contribution mechanism on the registry. Instead, keep the two
systems separate (each is the right shape for its job) and **align the seam**:

1. **The cross-app *render-a-link* job stays on `IReferenceProvider`.** OR already does this with
   `ObjectReferenceProvider`. Any future "show a decidesk decision / docudesk contract as a rich
   card in another app" SHOULD be a reference provider in the *owning* app, not a leaf in OR's
   registry. This is the ADR-041 §3 alignment, achievable with **zero registry changes**.
2. **The object-scoped CRUD-over-linked-entities job stays on the OR registry.** It is genuinely
   value-adding (write verbs, link tables, `(register,schema,objectId)` scope) and has no NC
   native equivalent. Bounding it (ADR-041 §2) is the right move; replacing it is not.
3. **Alignment, not convergence:** where a leaf's read side wants a rich preview, it MAY *consume*
   `IReferenceManager::resolveReference()` internally rather than re-implementing preview
   rendering. This is opt-in per leaf, additive, and reversible.

### Rationale

- The responsibilities matrix shows the overlap is the narrow render edge, which is **already
  covered** by `ObjectReferenceProvider`. Convergence would force the registry's value-adding
  90% (CRUD, link tables, scoping) through a contract (`IReferenceProvider`) that cannot express
  any of it — a semantic abuse exactly analogous to the `GetTaskProcessingProvidersEvent` abuse
  ADR-041 rejects.
- ADR-041 §3 already *says* "the OR registry keeps its bespoke layer only for the CRUD it
  genuinely adds beyond read-only references." The matrix confirms that's nearly all of it.
- Full convergence has a large blast radius (Section 4) for a small, already-served benefit.
- Keep-separate-but-align is the lowest-risk path that still honors ADR-041's intent: cross-app
  rendering aligns with the native mechanism; the bespoke layer is bounded, not abandoned.

### Phased follow-up plan (alignment work — small, optional)

This recommendation needs **no convergence migration**. The optional alignment follow-ups, if
pursued, are:

- **Phase A (doc-only, this change):** record the boundary; declare `IReferenceProvider` the home
  for cross-app *rendering*; declare the registry the home for object-scoped *CRUD*.
- **Phase B (additive, future change):** for cross-app render needs (decidesk/docudesk cards in a
  sibling app), add an `IReferenceProvider` in the **owning** app — not a leaf. No OR change.
- **Phase C (optional, future):** let a leaf's read path *optionally* delegate preview rendering
  to `IReferenceManager` instead of bespoke preview code. Per-leaf, additive, reversible.
- **Phase D (guard):** a lint/gate that flags any new leaf whose sole purpose is cross-app
  *rendering* (it should be a reference provider instead). Complements the ADR-041 anti-RPC gate.

### Risks (of the alignment path)

- **R1 — Two-mechanisms confusion:** authors must learn "render-a-link → reference provider;
  manage-linked-things → leaf." Mitigation: the decision record's one-line rule + Phase-D guard.
- **R2 — Preview duplication drift (if Phase C skipped):** leaves keep bespoke preview code that
  diverges from reference-provider previews. Low severity; cosmetic.
- **R3 — Temptation to re-add cross-app contribution to the registry:** explicitly forbidden by
  ADR-041 §2; this record reaffirms it.

---

## 4. Migration blast radius (REQ-CONV-003)

Enumerated for the **rejected** full-convergence option, to justify rejecting it; and noted for
the recommended alignment path (near-zero).

| Dependent | Full-convergence impact | Keep-separate-but-align impact |
|---|---|---|
| **Manifest `referenceType` markers** (schema `reference` property → `single-entity` widget; present across doriath, opencatalogi, larpingapp, softwarecatalog, etc.) | **BREAK.** `referenceType` points at a registry id and expects `IntegrationProvider::get()` to return a full entity. `IReferenceProvider` returns a preview `IReference`, not the entity — every `single-entity` widget would need re-plumbing. | **Transparent.** Unchanged; still resolved by the registry. |
| **22 built-in providers** (5 `BuiltinProviders/*` + 17 `Providers/*`) | **BREAK / rewrite.** Each would need its read side split out into a reference provider and its write side kept — a fork of every leaf. Many (`Notes`, `Tasks`, `Contacts`, `Deck`, `Email`, `OpenProject`, `Xwiki`) implement real `create/update/delete` with no reference-provider home. | **Preserve.** Unchanged. |
| **Frontend single-entity widgets / `useIntegrationRegistry`** (`CnObjectSidebar`, `CnIntegrationCard`, `CnIntegrationWidgetGrid`, `CnFormDialog`, the `integrations/builtin/*` cards in `@conduction/nextcloud-vue`) | **BREAK.** The composable resolves leaves by id and calls the object-scoped CRUD endpoints; a reference-provider model has no object-scoped CRUD. Library + every consuming app rebuild. | **Transparent.** Unchanged. |
| **ADR-019** (integration registry pattern; 22 providers at Tier-2 parity; 3-stage filter; widget-parity CI rule) | **CONTRADICT.** Convergence would invalidate the accepted ADR-019 contract that the whole fleet builds on. | **Preserve + clarify.** ADR-019 stays; ADR-041 bounds it; this record aligns the seam. |
| **ADR-036** (universal widget manifest v2; `widgets[]` `kind`-discriminated registry, `single-entity` surface) | **BREAK.** V2's widget registry references integration ids; the `single-entity` surface assumes provider `get()`. | **Transparent.** Unchanged. |
| **Link tables** (`openregister_*_links` ×17 + magic-column) + migrations | **ORPHAN/migrate.** References are stateless; the link rows would need a new home or deletion. | **Preserve.** Unchanged. |
| **OCS capabilities discovery + 3-stage filter + `health()`** | **Re-model.** `IDiscoverableReferenceProvider` covers discovery but not required-app/health/auth. | **Preserve.** Unchanged. |

**Conclusion:** full convergence is a fleet-wide breaking change (manifest markers, 22 providers,
the shared Vue library, two accepted ADRs, 17 link tables) for a benefit
(cross-app rendering) that is **already delivered** by `ObjectReferenceProvider`. The cost/benefit
overwhelmingly favors **keep-separate-but-align**.

---

## 5. Read-only PoC snippet (illustration only — NOT wired)

To show the alignment path is feasible with the *existing* NC API, a leaf's read side could
delegate a rich preview to the reference manager without any registry change:

```php
// ILLUSTRATIVE ONLY — not added to any boot path.
// Inside a hypothetical leaf read method that wants an NC-native rich preview:
public function previewLinkedThing(string $url): ?array
{
    // $this->referenceManager is OCP\Collaboration\Reference\IReferenceManager
    $ref = $this->referenceManager->resolveReference($url); // cross-app, cached
    if ($ref === null) {
        return null;
    }
    return [
        'title'       => $ref->getTitle(),
        'description' => $ref->getDescription(),
        'imageUrl'    => $ref->getImageUrl(),
        'richObject'  => $ref->getRichObject(),
    ];
}
```

This consumes the native reference machinery (incl. its caching) for *previews* while the leaf
keeps its object-scoped CRUD. It demonstrates "align" without "converge." It is not added to
production in this change.

---

## 6. Decision

**KEEP-SEPARATE-BUT-ALIGN.** Resolve the ADR-041 deferred open question as: do not converge.
Keep `IReferenceProvider` as the cross-app *render-a-link* mechanism (already in use via
`ObjectReferenceProvider`); keep the OR integration registry as the object-scoped
*CRUD-over-linked-things* mechanism (bounded by ADR-041, not replaced). Align the seam with the
optional, additive phases above. No registry code changes in this spike.
