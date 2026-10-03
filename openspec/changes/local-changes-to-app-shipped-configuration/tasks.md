# Tasks: local-changes-to-app-shipped-configuration

## 1. The baseline

- [x] 1.1 `ShippedBaselineStore` keeps the shipped definition with the app,
      its version and the moment, as a `subject`-layer value in #3808's
      `ConfigurationValueStore` under the `schema.` open prefix — reused
      rather than a second table, so the effective-configuration explainer
      reaches it the same way it reaches everything else (ADR-012, D-6). No
      migration.
- [x] 1.2a Schemas: recorded on create and moved on a guarded update, for
      `properties`, `required` and `authorization`.
- [ ] 1.2b Registers and the declared configuration blocks. `KEY_REGISTER`
      exists and the store is subject-agnostic; the register import path is a
      second seam in the same 5,484-line handler and is its own task.
- [ ] 1.2c Annotations (`x-openregister-*`) are deliberately NOT guarded:
      `Schema::setConfiguration()` DROPS an unknown key, so a guarded
      annotation would read as removed on every import and conflict with
      itself forever. Guarding them needs the vocabulary check first.

## 2. The divergence

- [x] 2.1 `DivergenceComparator::states()` and `report()`, per part, with a
      fifth name (`converged`) for both sides having moved to the SAME value,
      because calling that a conflict would report something with nothing to
      resolve.
- [ ] 2.2 🔴 **BLOCKED, and not by this change.** A schema is an ENTITY, not
      an object, and entity edits do not reach the object audit trail, so
      there is nowhere to read the actor and the moment from. The report
      returns the divergence without inventing a `null` that reads as
      "nobody". Naming a schema edit on the trail is its own change and it is
      the same gap `settings-change-audit` closed for settings.
- [ ] 2.3 Beside the explainer: the baseline is already a value the
      explainer can address (that is why it lives in its store), but joining
      it into `ConfigurationExplainer::explain()` is a change to that service
      and its controller.

## 3. The guarded update

- [x] 3.1 Applied in `GuardedDescriptorMerge`, wired at `ImportHandler::importSchema()`.
- [x] 3.2 Including the sharp case: an upstream REMOVAL of a locally changed
      part is a conflict, not a deletion.
- [x] 3.3 `decisions` is a path list, per part, and each one writes a
      `configuration.conflict.decided` row on the trail.
- [ ] 3.3b The surface an administrator takes that decision on. The service
      accepts the decisions; nothing yet offers them.
- [x] 3.4 The guard has no throw in it, and `ImportHandler` treats an
      unresolvable guard as "import as before" rather than as a failure.

## 4. The way back

- [x] 4.1a `previewReset()` and `resetToBaseline()`: the preview writes
      nothing, and the act REFUSES without a session, so no repair step or
      unattended path can perform one.
- [ ] 4.1b The route and controller, and the write of the reset definition
      back through `SchemaMapper`. The service returns the definition; nothing
      calls it over HTTP yet.

## 5. Tests

- [x] 5.1 25 tests over the four states, the preserved addition, the
      conflict, the per-part decision, the upstream removal both ways,
      `required` as a set, and the absent-versus-empty baseline.
- [x] 5.2a At the service level: `testAnUnattendedUpgradeWithConflictsCompletes`.
- [ ] 5.2b Through a real repair step against a database, which needs an
      instance; the seam in `ImportHandler` is covered by reading, not by a
      test that executes it.
- [ ] 5.3 e2e: needs the surface from 2.3 to read.
- [x] 5.4 Recorded in the PR body: it generalises `specs/schema-import`'s
      "Imported schemas MUST record provenance and support guarded
      update-from-source" from the standards dialects to the app-shipped
      descriptor, and reuses #3808's value store rather than adding a second
      one.
