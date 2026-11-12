<?php

/**
 * ObjectImporterInterface: import objects from a CSV, Excel or JSON file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Kernel\Contracts\Value\ImportRequest;

/**
 * Imports objects into a register and schema from a file, and undoes an import by its job id. Implemented by ImportService.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The signatures mirror ImportService, which carries the same suppression.
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    The signatures mirror ImportService, which carries the same suppression.
 */
interface ObjectImporterInterface {

	/**
	 * Import data from CSV file.
	 *
	 * @param ImportRequest $request       The file, the register and schema (required), the user, the save options and the optional migration pack or
	 *                               dry run.
	 * @param bool          $_rbac         Whether to enforce RBAC checks.
	 * @param bool          $_multitenancy Whether to enable multi-tenancy.
	 *
	 * @return array Import results by schema
	 */
	public function importFromCsv(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Import data from Excel file.
	 *
	 * @param ImportRequest $request       The file, the register and schema (a register alone imports one schema per sheet), the user and the save
	 *                               options.
	 * @param bool          $_rbac         Whether to enforce RBAC checks.
	 * @param bool          $_multitenancy Whether to enable multi-tenancy.
	 *
	 * @return (array|int|null|string)[][]
	 *
	 * @phpstan-return array<string, array{
	 *     found: int,
	 *     created: array<mixed>,
	 *     updated: array<mixed>,
	 *     unchanged: array<mixed>,
	 *     errors: array<mixed>,
	 *     debug?: array,
	 *     schema?: array{id: int, slug: null|string, title: null|string},
	 *     deduplication_efficiency?: string
	 * }|string>
	 *
	 * The `|string` is not slack: the result is a map of sheet title => summary,
	 * but the method also writes a scalar `importJobId` into the SAME map. A
	 * caller that iterates the result and assumes every value is a summary array
	 * will hit that key. Kept as-is because moving it would change the response
	 * shape for existing clients; the union at least makes it visible.
	 *
	 * @psalm-return array<string, array{
	 *     created: array,
	 *     errors: array,
	 *     found: int,
	 *     unchanged?: array,
	 *     updated: array,
	 *     deduplication_efficiency?: string,
	 *     schema?: array{id: int, title: null|string, slug: null|string}|null,
	 *     debug?: array{headers: array<never, never>, processableHeaders: array<never, never>,
	 *             schemaProperties: list<array-key>}
	 * }>
	 */
	public function importFromExcel(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Import objects of a single schema from a JSON document
	 *
	 * Inverse of `ExportService::exportToJson()`. Accepts either a bare JSON
	 * array of objects or a `{ "results": [...] }` envelope, then upserts every
	 * object (by uuid) through `ObjectService::saveObject()` — the same
	 * single-object path the REST create/update uses, applying RBAC and
	 * multi-tenancy. JSON carries no spreadsheet, so no PhpSpreadsheet (and
	 * therefore no ZipStream) is involved.
	 *
	 * When `$register` is null (the caller could not resolve the target
	 * register by slug), the payload is inspected for a register-bundle
	 * envelope — the same `components.registers` + `components.schemas` shape
	 * `ConfigurationService::importFromApp()` and the Repair-step register
	 * importers understand. A bundle auto-creates its register and schemas
	 * (reusing that same creation path — no duplicated logic, ADR-011) before
	 * objects import into it; idempotent — re-importing an existing register's
	 * bundle skips creation (registers/schemas are looked up by slug first).
	 * A non-bundle payload with no resolvable register fails with a clear,
	 * actionable error naming the missing slug rather than a silent no-op or
	 * an opaque exception.
	 *
	 * @param ImportRequest $request       The file; the register (null: the payload is checked for a register bundle, and the scope's registerSlug
	 *                               names the missing register) and schema (required unless a bundle carries it); the user, save options and optional
	 *                               migration pack or dry run.
	 * @param bool          $_rbac         Whether to apply RBAC permissions.
	 * @param bool          $_multitenancy Whether to apply multi-tenancy filtering.
	 *
	 * @return array<string, mixed> Sheet-shaped summary (keyed 'JSON') plus importJobId
	 *
	 * @throws \InvalidArgumentException When no schema is given (and none is resolvable from a bundle) or the
	 *                                  payload is not an array of objects
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When `$register` is null and the payload is not a creatable
	 *                                   register-bundle (REQ-IMP-AC-02)
	 */
	public function importFromJson(ImportRequest $request, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Serialize per-row import errors to a UTF-8 CSV blob with BOM.
	 *
	 * Walks the sheet-based import summary, extracting any `errors` entries
	 * and emitting one row per failure. Columns: `sheet`, `row`, `field`,
	 * `error_message`, `original_value`. Output is UTF-8 with BOM so Excel
	 * opens it cleanly (matches the existing template-export pattern).
	 *
	 * Returns an empty string when the summary contains no errors so callers
	 * can cheaply skip attaching the artefact.
	 *
	 * @param array<string, mixed> $summary The sheet-based import summary as
	 *                                      returned by importFromExcel /
	 *                                      importFromCsv.
	 *
	 * @return string The CSV payload (UTF-8 BOM prefixed) or empty string.
	 *
	 * @phpstan-param array<string, array{errors?: array<int, mixed>}> $summary
	 */
	public function serializeErrorsToCsv(array $summary): string;

	/**
	 * Soft-delete every object whose `create` audit row carries the
	 * given import-job UUID. Implements the rollback contract added by
	 * the `data-import-export` change (decision 2026-05-02): on critical
	 * failure or explicit user request, the import-job UUID is handed
	 * back and every tagged object is soft-deleted as a unit.
	 *
	 * Out of scope (option A from the design — not shipped here):
	 * compensation pass for materialised relation rows. The objects are
	 * soft-deleted, so they remain recoverable via the standard restore
	 * path; relations on other objects pointing at the deleted ones
	 * stay live and surface as broken references on next read (the
	 * existing `referential-integrity` machinery already handles this).
	 *
	 * @param string $importJobId UUID v4 of the import job to roll back.
	 *
	 * @return array{
	 *     importJobId: string,
	 *     candidates: int,
	 *     softDeleted: list<string>,
	 *     errors: list<array{uuid: string, error: string}>
	 * }
	 */
	public function softDeleteByImportJobId(string $importJobId): array;
}//end interface
