# Retrofit: reverse-spec tmlo-metadata (2026-05-24)

## Why

The `tmlo-metadata` capability has been live in production for months (controller + service shipped under the original `tmlo-metadata` change, archived 2026-05-02) but the capability spec was never written. The archive operation only generated sibling specs (`tmlo-metadata-schema`, `tmlo-auto-populate`, `tmlo-export`, `tmlo-query-api`) and left no canonical spec for the *foundation* layer that those siblings compose against — the `TmloService` constants/enums, the `isTmloEnabled` register gate, field-value validation, the `archiefstatus` state-machine, and the shared `TmloController` error envelope.

This retrofit pulls those foundation behaviours out of the implementation in `lib/Service/TmloService.php` + `lib/Controller/TmloController.php` and codifies them so future change proposals (e.g. additional archival states, batch-archival workflows, MDTO 2.x migration) have a stable contract to amend rather than re-derive from code.

## What Changes

- Create `openspec/specs/tmlo-metadata/spec.md` with five new requirements describing the foundation contract:
  1. TmloService canonical constants (archiefnominatie, archiefstatus, MDTO namespace, TMLO_FIELDS, VALID_TRANSITIONS).
  2. Register-level TMLO enablement gate (`isTmloEnabled`).
  3. TMLO field-value validation (`validateFieldValues`).
  4. Archival status state-machine (`validateStatusTransition`) with terminal states and required-field rules per target.
  5. `TmloController` error envelope (422 / 500 / 400).
- Annotate 11 implementation methods across `lib/Service/TmloService.php` and `lib/Controller/TmloController.php` with `@spec` pointers to this change's `tasks.md` so ADR-008 retrofit annotation convention is satisfied.

No code behaviour changes — this is a documentation-only retrofit.

## Scope

**In scope** — the foundation behaviours not covered by sibling specs:

- `TmloService` constants (archiefnominatie, archiefstatus, namespace, transitions, field list).
- `isTmloEnabled` gate semantics.
- Field-value validation rules and error-message shape.
- Status state-machine transitions and per-target required-field/nominatie-coupling rules.
- Controller error envelope (HTTP codes, JSON body shape, logging contract).

**Out of scope** — already covered by sibling specs:

- `tmlo-metadata-schema` — the six TMLO sub-fields on `ObjectEntity` + migration of the `tmlo` column.
- `tmlo-auto-populate` — `TmloService::populateDefaults` merge logic, archiefactiedatum-from-bewaarTermijn calculation, schema-defaults precedence.
- `tmlo-export` — `generateMdtoXml`, `generateBatchMdtoXml`, MDTO XML element shape, batch-vs-single response.
- `tmlo-query-api` — query parameters for filtering, summary endpoint counts.

The scanner cluster also surfaced four private XML-shape helpers (`createMdtoObjectElement`, `mapArchiefnominatie`, `mapArchiefstatus`, `xmlEscape`); these are internal implementation details of the `tmlo-export` capability and are listed as DROPs in `tasks.md` (per the cluster JSON's own triage hints).

## Notes (spec-vs-code drift)

- **DOM element name typo in MDTO export**: `lib/Service/TmloService.php:476` creates an `mdto:waarpinaering` element where MDTO requires `mdto:waardering`. This is an existing bug in the shipped code; `tmlo-export` spec describes the intended element. Flagged here, not auto-fixed — a separate fix change should land before any external consumer enforces strict MDTO schema validation.
- **archiefstatus `'overgebracht'` mapping conflict**: `lib/Service/TmloService.php:554` maps `archiefstatus_overgebracht → 'overgebracht'` (MDTO value), which is the same literal as the TMLO source value. This is intentional; documenting here in case future MDTO revisions diverge.
- **Constructor injection order on TmloController** uses `private readonly` promoted params — matches the modern PHP-8 style adopted across OpenRegister and does not require a separate REQ; documented for retrofit auditors.
- **`TmloService::TMLO_FIELDS` array order** is treated as semantically meaningful by `populateDefaults` (foreach iteration). `tmlo-metadata-schema` already enumerates the six fields; this spec restates them in `TMLO_FIELDS` only as constant-shape contract, not as an authoritative field list.

## See also

- Sibling specs: `openspec/specs/tmlo-metadata-schema/spec.md`, `openspec/specs/tmlo-auto-populate/spec.md`, `openspec/specs/tmlo-export/spec.md`, `openspec/specs/tmlo-query-api/spec.md`.
- Original archived change: `tmlo-metadata` (archived 2026-05-02; created the sibling specs but left this one blank).
- Implementation: `lib/Service/TmloService.php`, `lib/Controller/TmloController.php`.
