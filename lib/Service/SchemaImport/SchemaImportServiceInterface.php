<?php

/**
 * SchemaImportServiceInterface: what callers outside the Service component use of SchemaImportService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SchemaImport;

use OCA\OpenRegister\Exception\SchemaImportException;

/**
 * The methods of SchemaImportService that callers outside the Service component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to SchemaImportService. Guarded by F05SchemaImportInterfaceBindingsTest.
 */
interface SchemaImportServiceInterface {

	/**
	 * Discover importable types/objecttypes for a dialect.
	 *
	 * @param string $dialect The dialect key.
	 * @param string $query   The search term.
	 *
	 * @return array<string, mixed> { snapshotVersion: string, results: array }
	 *
	 * @throws SchemaImportException When the dialect is unknown.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function discover(string $dialect, string $query): array;

	/**
	 * The bundled snapshot version metadata for a dialect.
	 *
	 * @param string $dialect The dialect key.
	 *
	 * @return array<string, mixed> { dialect, snapshotVersion }
	 *
	 * @throws SchemaImportException When the dialect is unknown.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function snapshotInfo(string $dialect): array;

	/**
	 * Import a type/objecttype from a dialect's snapshot.
	 *
	 * @param string        $dialect   The dialect key.
	 * @param string        $reference The type reference.
	 * @param ImportOptions $options   Import options.
	 *
	 * @return ImportedSchema The mapped schema.
	 *
	 * @throws SchemaImportException When the dialect or reference is unknown.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function import(string $dialect, string $reference, ImportOptions $options): ImportedSchema;

	/**
	 * Import a GGM objecttype from an uploaded export (normalised intermediate).
	 *
	 * @param GgmUpload     $upload  The normalised GGM intermediate, the objecttype id/name to import and a provenance label.
	 * @param ImportOptions $options Import options.
	 *
	 * @return ImportedSchema The mapped schema.
	 *
	 * @throws SchemaImportException When the objecttype is unknown to the upload.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function importGgmUpload(GgmUpload $upload, ImportOptions $options): ImportedSchema;

	/**
	 * Resolve the effective dialect for an upload: explicit wins, else detect,
	 * else fail with 422 listing the supported dialects.
	 *
	 * @param array<string, mixed> $document        The decoded document.
	 * @param string|null          $explicitDialect The explicit dialect parameter, or null.
	 *
	 * @return string The resolved dialect.
	 *
	 * @throws SchemaImportException When the dialect cannot be resolved (422).
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function resolveUploadDialect(array $document, ?string $explicitDialect): string;

	/**
	 * Compute the classified update-from-source diff for an imported schema.
	 *
	 * @param SourceUpdate $update The stored import source (with baseline), the schema's current properties and the
	 *                             conflict property names confirmed by the caller.
	 *
	 * @return array<string, mixed> The merge result (added/removed/changed/keptLocal/conflicts/merged/applied).
	 *
	 * @throws SchemaImportException When the schema has no recorded import source.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function previewUpdateFromSource(SourceUpdate $update): array;
}//end interface
