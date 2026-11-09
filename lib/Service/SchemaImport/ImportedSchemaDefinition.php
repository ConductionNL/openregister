<?php

/**
 * OpenRegister Schema Import — ImportedSchemaDefinition value object.
 *
 * The property definitions and JSON-LD block an importer gives an ImportedSchema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
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
 * The mapped property definitions and JSON-LD block of an imported schema.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final readonly class ImportedSchemaDefinition {
	/**
	 * Constructor.
	 *
	 * @param array<string, array<string,mixed>> $properties JSON Schema property definitions keyed by name.
	 * @param array<string, mixed> $jsonld The `configuration.jsonld` block (@vocab, type, properties map).
	 */
	public function __construct(
		public array $properties,
		public array $jsonld,
	) {
	}//end __construct()
}//end class
