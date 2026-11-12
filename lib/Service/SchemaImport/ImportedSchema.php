<?php

/**
 * OpenRegister Schema Import — ImportedSchema value object.
 *
 * The pure result of mapping an external standard definition into an
 * OpenRegister schema: the schema array (title, description, properties) plus
 * the `configuration` fragments (`jsonld` vocabulary mapping that
 * json-ld-output consumes, and the `importSource` provenance block). Carries
 * no Nextcloud dependencies so importers are unit-testable without a database.
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
 * The mapped schema produced by a dialect importer.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class ImportedSchema implements ImportedSchemaInterface {
	/**
	 * The schema title.
	 *
	 * @var string
	 */
	public readonly string $title;

	/**
	 * The schema description.
	 *
	 * @var string
	 */
	public readonly string $description;

	/**
	 * JSON Schema property definitions keyed by name.
	 *
	 * @var array<string, array<string,mixed>>
	 */
	public readonly array $properties;

	/**
	 * The `configuration.jsonld` block (@vocab, type, properties map).
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $jsonld;

	/**
	 * Constructor.
	 *
	 * @param ImportedSchemaHeading $heading The schema title and description.
	 * @param ImportedSchemaDefinition $definition The property definitions and JSON-LD block.
	 * @param array<string, mixed> $importSource The `configuration.importSource` provenance block.
	 * @param array<int, string> $unknownRequested Requested property names not present on the source type.
	 */
	public function __construct(
		ImportedSchemaHeading $heading,
		ImportedSchemaDefinition $definition,
		public readonly array $importSource,
		public readonly array $unknownRequested = [],
	) {
		$this->title       = $heading->title;
		$this->description = $heading->description;
		$this->properties  = $definition->properties;
		$this->jsonld      = $definition->jsonld;
	}//end __construct()

	/**
	 * Requested property names not present on the source type.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function unknownRequested(): array {
		return $this->unknownRequested;
	}//end unknownRequested()

	/**
	 * Render the schema payload ready for SchemaMapper hydration / upload.
	 *
	 * Merges the jsonld + importSource fragments under `configuration`,
	 * preserving any caller-supplied configuration.
	 *
	 * @param array<string, mixed> $baseConfiguration Existing configuration to merge into (e.g. on update).
	 *
	 * @return array<string, mixed> The schema array (title, description, properties, configuration).
	 *
	 * @spec openspec/specs/schema-import/spec.md
	 */
	public function toSchemaArray(array $baseConfiguration = []): array {
		$configuration = $baseConfiguration;

		if ($this->jsonld !== []) {
			$configuration['jsonld'] = $this->jsonld;
		}

		$configuration['importSource'] = $this->importSource;

		return [
			'title' => $this->title,
			'description' => $this->description,
			'properties' => $this->properties,
			'configuration' => $configuration,
		];
	}//end toSchemaArray()
}//end class
