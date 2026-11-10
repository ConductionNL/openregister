<?php

/**
 * How a configuration import classifies a schema change and writes it to the changelog.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schema\SchemaChangeRecord;
use OCA\OpenRegister\Service\Schema\SchemaChangeSet;
use OCA\OpenRegister\Service\Schema\SchemaVersioningService;
use Psr\Log\LoggerInterface;

/**
 * Classifies the definition an import is about to write and records the change.
 *
 * Without a versioning service the import stays unclassified.
 *
 * @spec openspec/specs/schema-migration/spec.md
 */
final class ImportedSchemaChangeLog
{

	/**
	 * Constructor.
	 *
	 * @param SchemaVersioningService|null $versioning The versioning service, when one is wired.
	 * @param LoggerInterface              $logger     The logger.
	 * @param string|null                  $appId      The app whose import makes the change.
	 */
	public function __construct(
		private readonly ?SchemaVersioningService $versioning,
		private readonly LoggerInterface $logger,
		private readonly ?string $appId,
	) {
	}//end __construct()

	/**
	 * Classify the definition an import is about to write against the stored one.
	 *
	 * Null when there is no versioning service, when the import carries no
	 * definition, or when classifying failed: the import itself never breaks
	 * on this, it is only left unclassified.
	 *
	 * @param Schema               $existing The schema already stored.
	 * @param array<string, mixed> $data     The incoming schema, as it will be written.
	 *
	 * @return SchemaChangeSet|null The change set, or null when not classified.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function classify(Schema $existing, array $data): ?SchemaChangeSet {
		if ($this->versioning === null || $this->carriesDefinition(data: $data) === false) {
			return null;
		}

		try {
			return $this->versioning->classify(existing: $existing, newDefinition: $this->definition(existing: $existing, data: $data));
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: ImportLogTag::PREFIX.'Could not classify an imported schema change: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'schema_id' => $existing->getId()]
			);
			return null;
		}
	}//end classify()

	/**
	 * Write the changelog entry for an imported schema change.
	 *
	 * @param Schema               $schema    The schema as written.
	 * @param SchemaChangeSet|null $changeSet The classified change, or null when not classified.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function record(Schema $schema, ?SchemaChangeSet $changeSet): void {
		if ($this->versioning === null || $changeSet === null || $changeSet->hasChanges() === false) {
			return;
		}

		$this->versioning->recordChange(
			schemaId: (int)$schema->getId(),
			record: new SchemaChangeRecord(changeSet: $changeSet, acknowledged: false, version: $schema->getVersion(), origin: $this->origin())
		);
	}//end record()

	/**
	 * Whether the incoming schema carries a definition at all.
	 *
	 * @param array<string, mixed> $data The incoming schema.
	 *
	 * @return bool True when it has properties or a required list.
	 */
	private function carriesDefinition(array $data): bool {
		return isset($data['properties']) === true || isset($data['required']) === true;
	}//end carriesDefinition()

	/**
	 * The definition to classify: what the import says, the stored schema for the rest.
	 *
	 * @param Schema               $existing The schema already stored.
	 * @param array<string, mixed> $data     The incoming schema.
	 *
	 * @return array{properties: array, required: array} The definition.
	 */
	private function definition(Schema $existing, array $data): array {
		return [
			'properties' => ($data['properties'] ?? $existing->getProperties() ?? []),
			'required' => ($data['required'] ?? $existing->getRequired() ?? []),
		];
	}//end definition()

	/**
	 * The changelog origin of this import.
	 *
	 * @return string The origin.
	 */
	private function origin(): string {
		if ($this->appId === null) {
			return 'configuration import';
		}

		return 'configuration import of ' . $this->appId;
	}//end origin()
}//end class
