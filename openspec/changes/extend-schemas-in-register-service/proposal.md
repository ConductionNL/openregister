## Anonymisation lineage

This change is one of two scaffolding changes that unblock the dossier-anonymisation pipeline (DocuDesk's `add-dossier-schema` and downstream anonymisation hooks). The pipeline depends on accurate schema expansion via DI: DocuDesk's `RegisterDiscoveryService` needs full schema objects (not bare IDs) to drive its admin-settings dropdowns and its per-schema anonymisation routing. Today that path silently returns IDs, so DocuDesk's anonymisation configuration UI is non-functional. This change fixes the contract; DocuDesk's follow-up PR consumes it. The sibling change `validate-self-folder-access` hardens the `@self.folder` binding so anonymised dossiers cannot leak across tenants when an attacker passes a foreign folder ID.

## Why

`RegisterService::findAll()` and `RegisterService::find()` advertise an `_extend` parameter (e.g. `_extend: ['schemas']`) that consumer apps rely on to receive registers with fully-expanded schemas rather than just schema IDs. In reality, `_extend` is silently dropped at `RegisterMapper::findAll()` (suppressed as `@SuppressWarnings(PHPMD.UnusedFormalParameter)`), and `Register::jsonSerialize()` hardcodes `schemas` to an ID-only array with the explicit comment *"Always return schemas as array of IDs"*. The only place expansion actually happens is inside `RegistersController::index()` — which means any app that calls the service via DI (DocuDesk, OpenCatalogi, softwarecatalog) receives unusable ID-only data. The immediate symptom: DocuDesk's admin settings schema dropdown is empty because it expects schema objects. The underlying problem is a broken public contract on a core OpenRegister service, and every consumer that hits this silently works around it or ships a visible bug.

**DocuDesk follow-up:** swap `$register->jsonSerialize()` for `$this->registerSerializer->serialize($register, ['schemas'])` inside `docudesk/lib/Service/RegisterDiscoveryService::serializeRegister()`. Filed as a follow-up issue once this change merges; not blocking on it.

## What Changes

- Introduce a service-layer extension mechanism so `_extend` passed to `RegisterService::findAll()` and `RegisterService::find()` is honored by every caller, not just HTTP consumers.
- Move schema-expansion logic (ID → full schema object) from `RegistersController::index()` into a dedicated serializer component in its own namespace, so the controller and downstream apps use the same expansion path — and so future entity serializers (`SchemaSerializer`, `ObjectSerializer`, …) have an obvious home.
- Support the `_extend` values currently recognized by the controller:
  - `schemas` — expand schema IDs into full schema objects. The serializer **preserves the `properties` field** on each expanded schema; any consumer-side stripping (e.g. DocuDesk's `filterSchemaProperties()`) stays in the consumer.
  - `@self.stats` — when combined with `schemas`, attach schema-level object counts via `RegisterService::getSchemaObjectCounts()`.
- Keep `Register::jsonSerialize()` ID-only (its current contract) — expansion is a separate, opt-in serializer step. This avoids breaking internal OpenRegister callsites that depend on ID-only schemas.
- `RegistersController::index()` delegates to the new serializer so its HTTP response shape is **unchanged** (back-compat for the `/api/registers?_extend=schemas` endpoint and any frontend relying on it).
- Remove the `@SuppressWarnings(PHPMD.UnusedFormalParameter)` annotations on `_extend` once the parameter is honored end-to-end.

No breaking changes to the HTTP response shape on the happy path, and no changes to `Register::jsonSerialize()`. **One deliberate divergence on the edge case:** when a register references a schema ID that has been deleted, the response now retains the orphan ID in its original array position instead of silently dropping it. This produces a heterogeneous `schemas` array (objects + bare IDs) on the affected edge case — a wire-format change that JSON consumers in statically-typed clients (Go/Java/Kotlin) MUST be prepared for. Documented under Risks and in the changelog. The change is otherwise additive at the service layer.

## Capabilities

### New Capabilities

- `register-service-extensions`: Defines the `_extend` contract on `RegisterService::findAll()` and `RegisterService::find()` — which extension keys are recognized (`schemas`, `@self.stats`), the resulting payload shape for each, and the guarantee that HTTP and DI callers receive identical expanded data.

### Modified Capabilities

None. No existing spec defines the `_extend` contract for `RegisterService`, so this is purely additive. (Candidates checked: no `register-service`, `service-api`, or similar spec exists under `openspec/specs/`.)

## Impact

**Affected code (OpenRegister):**
- `lib/Service/RegisterService.php` — `findAll()` and `find()` gain real `_extend` support by delegating to the new serializer.
- `lib/Controller/RegistersController.php` — `index()` delegates expansion to the serializer; the existing inline schema-expansion + `@self.stats` loops are removed.
- `lib/Db/RegisterMapper.php` — `@SuppressWarnings(PHPMD.UnusedFormalParameter)` is removed from `findAll()` and `find()` once `_extend` is handled by the service/serializer layer (or the `_extend` parameter is dropped from the mapper signature entirely if all post-processing moves up a layer).
- `lib/Db/Register.php` — no change to `jsonSerialize()`; schemas remain ID-only there by contract.
- **New serializer namespace** — a dedicated folder for entity-level serialization with extension support. `RegisterSerializer` is its first inhabitant; the structure explicitly anticipates follow-ups like `SchemaSerializer` and `ObjectSerializer`. The exact folder path (`lib/Service/Serializer/` vs. `lib/Serializer/`) and naming convention are settled in `design.md`.
- Unit tests covering: no extend (IDs preserved), `['schemas']` (full objects with `properties` stripped), `['schemas', '@self.stats']` (objects + counts), missing/deleted schema IDs (graceful skip, matching controller's current warning log).

**Affected downstream apps (not changed in this proposal — follow-ups):**
- `docudesk/lib/Service/RegisterDiscoveryService.php` — bug fixed automatically once the service honors `_extend: ['schemas']`. Admin settings schema dropdown for `publicationConsent` becomes populated. (Tracked as an acceptance criterion in `tasks.md`, but the fix is a side effect — no DocuDesk code change in this change.)
- `opencatalogi` and `softwarecatalog` — if they call `RegisterService::findAll(_extend: ['schemas'])` via DI, they'll also get correct data. Worth a grep as part of verification.

**APIs / dependencies:**
- HTTP API `/api/registers?_extend=schemas[,@self.stats]` — response shape unchanged.
- DI service API `RegisterService::findAll(_extend: [...])` and `::find($id, _extend: [...])` — now actually honors `_extend`. This is the intended contract, not a breaking change.

**Architectural alignment:**
- ADR-008 (Backend Layering): this change is the right direction — business logic (schema expansion) moves out of the controller and into the service/serializer layer where it belongs.
- ADR-011 (Deduplication): the new serializer namespace centralizes expansion so future consumers reuse it rather than reinventing it. Future serializers live alongside it and reuse common helpers.
