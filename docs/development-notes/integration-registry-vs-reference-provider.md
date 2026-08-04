# Decision Record: Integration Registry ↔ IReferenceProvider Convergence

**Status:** Decided · **Date:** 2026-06-15 · **Operationalizes:** ADR-041 Decision #3
(the deferred open question) · **Type:** Investigation / spike — no production code changed.

> ADR-041 §3 recommends that cross-app *rendering* of linked things SHOULD align with NC's
> native `OCP\Collaboration\Reference\IReferenceProvider`, while the OpenRegister integration
> registry keeps its bespoke layer only for the CRUD it genuinely adds beyond read-only
> references — and defers "whether and how it converges" to a dedicated investigation. This is
> that investigation.

## Headline recommendation

**KEEP-SEPARATE-BUT-ALIGN.** Do **not** converge the OpenRegister integration registry
(ADR-019 leaf system) onto `IReferenceProvider`. Keep the two mechanisms separate — each is the
correct shape for its job — and align the seam between them.

- **Cross-app *render-a-link* → `IReferenceProvider`.** OpenRegister already does this with
  `OCA\OpenRegister\Reference\ObjectReferenceProvider` (registered via
  `registerReferenceProvider`). Future "show app X's record as a rich card in app Y" SHOULD be a
  reference provider in the *owning* app, not a leaf in OR's registry.
- **Object-scoped *CRUD-over-linked-things* → OR integration registry.** The write verbs
  (`create/update/delete`), the `openregister_*_links` tables, the `(register, schema, objectId)`
  scoping, and the OpenConnector transport are genuine added value with no `IReferenceProvider`
  equivalent. Bound it (ADR-041 §2); do not replace it.

## Why (responsibilities matrix)

| OR registry responsibility | Bucket | Covered by `IReferenceProvider`? |
|---|---|---|
| `list(register,schema,objectId)` | READ, object-scoped | No — no "list the X of object Y" verb |
| `get(...entityId)` | READ/RENDER | Partial — returns full entity, not a preview card |
| `create / update / delete` | **VALUE-ADD** | No — reference providers are read-only |
| `openregister_*_links` tables + magic-column | **VALUE-ADD** | No — references are stateless |
| `(register, schema, objectId)` scoping | **VALUE-ADD** | No — no object-graph binding exists |
| `getStorageStrategy` + `ExternalIntegrationRouter` (OpenConnector) | **VALUE-ADD** | No — no transport abstraction |
| `getId/getLabel/getIcon/getGroup` discovery metadata | READ | Yes → `getId/getTitle/getIconUrl/getOrder` |
| render a link to an OR object in another app | READ/RENDER | **Yes — already done** by `ObjectReferenceProvider` |

The overlap is the narrow *render-a-link* edge, and it is **already served** by an
`IReferenceProvider`. Everything that makes the registry distinct has no reference-provider
analogue. Forcing the value-adding 90% (CRUD, link tables, scoping) through a read-only contract
would be a semantic abuse analogous to the `GetTaskProcessingProvidersEvent` abuse ADR-041
rejects.

## Contract comparison (the shape mismatch)

- `IReferenceProvider`: `matchReference` / `resolveReference → IReference` + `getCachePrefix` /
  `getCacheKey`. Cross-app by design (`registerReferenceProvider`), **read-only**, first-class
  caching via `IReferenceManager`. `IReference` is a *preview* (title/description/image/url/
  richObject), not a mutable entity.
- OR `IntegrationProvider`: `list/get/create/update/delete` + metadata, `(register,schema,
  objectId)`-scoped, registered **only** from OR's own boot (no sibling-app contribution hook).
  Storage strategies observed across the 22 built-ins: `link-table` ×19, `external` ×4,
  `query-time` ×3, `magic-column` ×1. CRUD-capable leaves include Notes, Tasks, Contacts, Deck,
  Email, OpenProject, Xwiki, Files, Tags, Calendar, Shares.

One line: `IReferenceProvider` = "render a link to a thing identified by URL/text" (cross-app,
cached, read-only); OR registry = "manage the things linked **to this object**" (object-scoped,
CRUD, persisted links).

## Migration blast radius (why full convergence is rejected)

| Dependent | Full-convergence | Keep-separate-but-align |
|---|---|---|
| Manifest `referenceType` markers (`single-entity` widgets) | **BREAK** — expect entity `get()`, not a preview | Transparent |
| 22 built-in providers (5 builtin + 17 providers) | **BREAK/rewrite** — split read vs write per leaf | Preserve |
| Frontend `useIntegrationRegistry` / `CnObjectSidebar` / `CnIntegrationCard` / `CnFormDialog` | **BREAK** — object-scoped CRUD has no reference model | Transparent |
| ADR-019 (registry pattern, Tier-2 parity, 3-stage filter) | **CONTRADICT** accepted ADR | Preserve + clarify |
| ADR-036 (universal widget manifest v2, `single-entity` surface) | **BREAK** | Transparent |
| `openregister_*_links` (×17) + migrations | **ORPHAN/migrate** | Preserve |

Full convergence is a fleet-wide breaking change for a benefit already delivered. Cost/benefit
favors keep-separate-but-align decisively.

## Phased follow-up (alignment — small, optional, additive)

- **Phase A (this record):** declare the boundary — references for cross-app render, registry for
  object-scoped CRUD.
- **Phase B (future, additive):** cross-app render needs (e.g. decidesk/docudesk cards in a
  sibling app) ship an `IReferenceProvider` in the **owning** app — not a leaf. No OR change.
- **Phase C (future, optional):** a leaf's read path MAY delegate preview rendering to
  `IReferenceManager::resolveReference()` instead of bespoke preview code. Per-leaf, reversible.
- **Phase D (guard):** a gate flagging any new leaf whose sole purpose is cross-app *rendering*
  (should be a reference provider), complementing the ADR-041 anti-RPC gate.

## Risks

- **R1** Two-mechanisms confusion — mitigated by the one-line rule + Phase-D guard.
- **R2** Preview duplication drift if Phase C is skipped — cosmetic, low severity.
- **R3** Temptation to re-add cross-app contribution to the registry — explicitly forbidden by
  ADR-041 §2; reaffirmed here.

## Read-only PoC (illustration only, not wired)

```php
// ILLUSTRATIVE — not added to any boot path. A leaf read path could reuse NC's
// cached, cross-app reference machinery for previews without any registry change:
$ref = $this->referenceManager->resolveReference($url); // IReferenceManager
return $ref === null ? null : [
    'title'       => $ref->getTitle(),
    'description' => $ref->getDescription(),
    'imageUrl'    => $ref->getImageUrl(),
    'richObject'  => $ref->getRichObject(),
];
```

## Decision

**KEEP-SEPARATE-BUT-ALIGN.** The ADR-041 deferred open question is resolved: do not converge.
`IReferenceProvider` is the cross-app render-a-link mechanism (already in use);
the OR integration registry is the object-scoped CRUD-over-linked-things mechanism (bounded by
ADR-041, not replaced). Align the seam via the optional additive phases above. No registry code
is changed by this investigation.
