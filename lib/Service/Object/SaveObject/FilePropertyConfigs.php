<?php

/**
 * OpenRegister FilePropertyConfigs
 *
 * The file configuration a schema gives one file property.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\Schema;

/**
 * Resolves a file property's configuration: its own, or its items' for an array, with the
 * schema-level autoShare as a default.
 *
 * @spec openspec/specs/content-versioning/spec.md
 */
final class FilePropertyConfigs {

	/**
	 * Constructor.
	 *
	 * @param FilePropertyLog $log The file property log.
	 */
	public function __construct(
		private readonly FilePropertyLog $log,
	) {
	}//end __construct()

	/**
	 * The file configuration of a property.
	 *
	 * @param Schema $schema       The schema containing property configuration.
	 * @param string $propertyName The name of the file property.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws Exception If the property is not in the schema or is not configured for files.
	 */
	public function fileConfigOf(Schema $schema, string $propertyName): array {
		$schemaProperties = $schema->getProperties() ?? [];
		if (isset($schemaProperties[$propertyName]) === false) {
			throw new Exception("Property '$propertyName' not found in schema configuration");
		}

		$fileConfig = $schemaProperties[$propertyName];
		if (($fileConfig['type'] ?? '') === 'array') {
			$fileConfig = ($fileConfig['items'] ?? []);
		}

		if (($fileConfig['type'] ?? '') !== 'file') {
			throw new Exception("Property '$propertyName' is not configured as a file property");
		}

		return $this->withSchemaAutoShare(fileConfig: $fileConfig, schema: $schema);
	}//end fileConfigOf()

	/**
	 * Default a file configuration's autoShare to the schema configuration's.
	 *
	 * When true, an NC share is automatically created for each uploaded file. The legacy
	 * `autoPublish` key is not acted on; it is logged as deprecated.
	 *
	 * @param array<string, mixed> $fileConfig The file configuration.
	 * @param Schema               $schema     The schema.
	 *
	 * @return array<string, mixed>
	 */
	private function withSchemaAutoShare(array $fileConfig, Schema $schema): array {
		if (isset($fileConfig['autoShare']) === true) {
			return $fileConfig;
		}

		$schemaConfig = $schema->getConfiguration() ?? [];
		if (isset($schemaConfig['autoShare']) === true) {
			$fileConfig['autoShare'] = $schemaConfig['autoShare'];
			return $fileConfig;
		}

		if (isset($schemaConfig['autoPublish']) === true) {
			$this->warnAutoPublishIgnored(schema: $schema);
		}

		return $fileConfig;
	}//end withSchemaAutoShare()

	/**
	 * Warn that a schema's legacy autoPublish key is ignored.
	 *
	 * DEPRECATED: autoPublish was renamed to autoShare in 2026; the legacy value is not acted on.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return void
	 */
	private function warnAutoPublishIgnored(Schema $schema): void {
		$this->log->warning(
			message: "[FilePropertyHandler] Schema config key 'autoPublish' is deprecated and ignored; "
				. "rename it to 'autoShare' to keep auto-sharing uploaded files.",
			context: ['schemaId' => $schema->getId()]
		);
	}//end warnAutoPublishIgnored()
}//end class
