<?php

/**
 * SchemaDefaults: the identifiers and required list a saved schema always has.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;
use Symfony\Component\Uid\Uuid;

/**
 * Fills what a schema must carry when its author left it out.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class SchemaDefaults {

	/**
	 * Ensure the schema has a UUID, slug, version and source.
	 *
	 * @param Schema $schema Schema to update.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is standard Symfony UID pattern
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function ensureIdentifiers(Schema $schema): void {
		if ($schema->getUuid() === null) {
			$schema->setUuid((string)Uuid::v4());
		}

		if (empty($schema->getSlug()) === true) {
			$schema->setSlug($this->slugOf(title: ($schema->getTitle() ?? 'schema')));
		}

		if ($schema->getVersion() === null) {
			$schema->setVersion('0.0.1');
		}

		$this->ensureSource(schema: $schema);
	}//end ensureIdentifiers()

	/**
	 * Build the required fields array from the property flags, when none is given.
	 *
	 * @param Schema $schema Schema to update.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function buildRequired(Schema $schema): void {
		if (empty($schema->getRequired()) === false) {
			return;
		}

		$requiredFields = [];
		foreach ($schema->getProperties() as $propertyKey => $property) {
			if (is_array($property) === true && $this->isRequired(property: $property) === true) {
				$requiredFields[] = $propertyKey;
			}
		}

		$schema->setRequired($requiredFields);
	}//end buildRequired()

	/**
	 * Default an unset or empty source to internal.
	 *
	 * @param Schema $schema Schema to update.
	 *
	 * @return void
	 */
	private function ensureSource(Schema $schema): void {
		if ($schema->getSource() === null || $schema->getSource() === '') {
			$schema->setSource('internal');
		}
	}//end ensureSource()

	/**
	 * Generate a slug from a title.
	 *
	 * @param string $title Title to convert.
	 *
	 * @return string
	 */
	private function slugOf(string $title): string {
		$slug = strtolower(trim($title));
		$slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
		$slug = preg_replace('/-+/', '-', $slug);
		return trim($slug, '-');
	}//end slugOf()

	/**
	 * Whether a property is marked as required.
	 *
	 * @param array<string, mixed> $property The property definition.
	 *
	 * @return bool
	 */
	private function isRequired(array $property): bool {
		$requiredValue = ($property['required'] ?? null);
		if ($requiredValue === true) {
			return true;
		}

		return is_string($requiredValue) === true && strtolower(trim($requiredValue)) === 'true';
	}//end isRequired()
}//end class
