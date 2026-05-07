# Retrofit — oas-generation

Describes observed behavior of 2 methods under `oas-generation` as 2 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- lib/Controller/OasController.php::generateAll
- lib/Controller/OasController.php::generate

## REQ map

| REQ | Methods |
|-----|---------|
| REQ-001 | OasController::generateAll |
| REQ-002 | OasController::generate |

## Approach

Both methods are thin controller delegates to `OasService::createOas(?string $registerId)`. The distinguishing factor is whether a register ID is supplied (REQ-002) or not (REQ-001). Key behaviors documented: public accessibility (@PublicPage), RBAC bypass in the service layer, empty extended-endpoints whitelist, and the 500-for-all-failures error contract.

## Why a new capability rather than extending data-import-export

The nearest existing spec is `data-import-export`, which covers import/export of configuration and object data. OAS generation is distinct: it produces machine-readable API documentation derived from the live schema configuration, not a data transfer artifact. The audience (API consumers discovering endpoints) and output format (OpenAPI JSON) have no overlap with configuration import/export.

Source: openspec/coverage-report.md generated 2026-04-30. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
