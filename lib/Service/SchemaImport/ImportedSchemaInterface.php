<?php

/**
 * ImportedSchemaInterface: what callers outside the Service component use of ImportedSchema.
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

/**
 * The methods of ImportedSchema that callers outside the Service component use.
 *
 * A value the import service builds and hands back, never resolved from the
 * container, so this is a type only and has no binding. Callers outside the
 * Service component type against it. Guarded by F05SchemaImportInterfaceBindingsTest.
 */
interface ImportedSchemaInterface {

	/**
	 * Render the schema payload ready for SchemaMapper hydration / upload.
	 *
	 * @param array<string, mixed> $baseConfiguration Existing configuration to merge into (e.g. on update).
	 *
	 * @return array<string, mixed> The schema array (title, description, properties, configuration).
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function toSchemaArray(array $baseConfiguration = []): array;

	/**
	 * Requested property names not present on the source type.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function unknownRequested(): array;
}//end interface
