# Tasks: Retrofit reverse-spec tmlo-metadata (2026-05-24)

> All tasks reflect already-shipped behaviour observed in `lib/Service/TmloService.php` and `lib/Controller/TmloController.php` on `development` at the time of the scan. Marked `[x]` because the implementation pre-dates the spec.

## Annotate methods against the new REQs

- [x] **task-1** — REQ "TmloService exposes canonical TMLO constants": annotate `TmloService` constant declarations (covers `ARCHIEFNOMINATIE_*`, `ARCHIEFSTATUS_*`, `MDTO_NAMESPACE`, `VALID_ARCHIEFNOMINATIE`, `VALID_ARCHIEFSTATUS`, `TMLO_FIELDS`, `VALID_TRANSITIONS`) and the `TmloService::__construct` + `TmloController::__construct` docblocks (the consumers wiring those constants in).
- [x] **task-2** — REQ "Register-level TMLO enablement gate": annotate `TmloService::isTmloEnabled` and `TmloService::populateDefaults` (the short-circuit consumer).
- [x] **task-3** — REQ "TMLO field-value validation": annotate `TmloService::validateFieldValues`.
- [x] **task-4** — REQ "Archival status state-machine": annotate `TmloService::validateStatusTransition`.
- [x] **task-5** — REQ "TmloController error envelope": annotate `TmloController::exportSingle`, `TmloController::exportBatch`, `TmloController::summary`.

## DROP

The following cluster entries are documented as DROPs and are intentionally NOT annotated under `tmlo-metadata`. The scanner flagged them (some were already triaged by the cluster JSON itself as "wrong-capability" from the `tmlo-validation#ISO-8601` cluster).

- **`lib/Service/TmloService.php::generateMdtoXml`** — DROP: covered by `openspec/specs/tmlo-export/spec.md` "MDTO-compliant XML export" (single object). Not foundation behaviour.
- **`lib/Service/TmloService.php::generateBatchMdtoXml`** — DROP: covered by `openspec/specs/tmlo-export/spec.md` "Batch export objects as MDTO XML". Not foundation behaviour.
- **`lib/Service/TmloService.php::getSchemaDefaults`** — DROP: covered by `openspec/specs/tmlo-auto-populate/spec.md` (schema-defaults precedence is part of the auto-populate contract). Not foundation behaviour.
- **`lib/Service/TmloService.php::populateDefaults`** — covered primarily by `openspec/specs/tmlo-auto-populate/spec.md`. Annotated here ONLY against task-2 because of the `isTmloEnabled` short-circuit it implements (foundation-level gate behaviour); auto-populate merge logic remains under the sibling spec.
- **`lib/Service/TmloService.php::createMdtoObjectElement`** *(private)* — DROP: MDTO XML shape, internal to `tmlo-export`. Already triaged as wrong-capability in the cluster JSON.
- **`lib/Service/TmloService.php::mapArchiefnominatie`** *(private)* — DROP: MDTO mapping helper, internal to `tmlo-export`. Already triaged as wrong-capability in the cluster JSON.
- **`lib/Service/TmloService.php::mapArchiefstatus`** *(private)* — DROP: MDTO mapping helper, internal to `tmlo-export`. Already triaged as wrong-capability in the cluster JSON.
- **`lib/Service/TmloService.php::xmlEscape`** *(private)* — DROP: generic XML helper, internal to `tmlo-export`. Already triaged as wrong-capability in the cluster JSON.
