<?php

/**
 * LinkedEntityPropertyHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Extracts Nc* property values and populates corresponding _ metadata columns.
 *
 * When an object has properties with Nc* types (NcMail, NcContact, etc.), this handler
 * extracts the `id` field from each reference envelope and appends it to the corresponding
 * metadata column (_mail, _contacts, etc.). Ad-hoc links (created via sidebar) are preserved.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */
class LinkedEntityPropertyHandler {
	/**
	 * Map of Nc* type names to their metadata column, read and written
	 * through the entity's get<Column>() and set<Column>().
	 */
	private const NC_TYPE_COLUMNS = [
		'NcMail' => 'Mail',
		'NcContact' => 'Contacts',
		'NcNote' => 'Notes',
		'NcTodo' => 'Todos',
		'NcCalendarEvent' => 'Calendar',
		'NcTalk' => 'Talk',
		'NcDeck' => 'Deck',
		'NcFile' => 'Files',
	];

	/**
	 * Constructor for LinkedEntityPropertyHandler.
	 *
	 * @param LoggerInterface $logger Logger for logging operations
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Extract Nc* property references and populate metadata columns.
	 *
	 * Scans the schema properties for Nc* types, extracts the `id` from each
	 * reference envelope in the object data, and merges them into the corresponding
	 * metadata column. Existing ad-hoc links (from sidebar) are preserved.
	 *
	 * The references are read from the object's own data, which the save has
	 * already set to the prepared data.
	 *
	 * @param ObjectEntity $object The object entity to update, carrying the data being saved
	 * @param Schema       $schema The schema definition
	 *
	 * @return ObjectEntity The updated object entity
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	public function extractAndPopulate(ObjectEntity $object, Schema $schema): ObjectEntity {
		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return $object;
		}

		// Merge extracted IDs into metadata columns, preserving ad-hoc links.
		foreach ($this->extractIds(properties: $properties, data: $object->getObject()) as $ncType => $ids) {
			$this->mergeIntoMetadataColumn(object: $object, linked: new LinkedEntityIds(ncType: $ncType, ids: $ids));
		}

		return $object;
	}//end extractAndPopulate()

	/**
	 * Collect the reference ids of every Nc* typed property, grouped by type.
	 *
	 * @param array $properties The schema properties.
	 * @param array $data The object data.
	 *
	 * @return array<string, array<int, string>> The ids by Nc* type; a type without ids is absent.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function extractIds(array $properties, array $data): array {
		$extractedIds = [];
		foreach ($properties as $propertyName => $propertyConfig) {
			$linked = $this->linkedIds(propertyConfig: $propertyConfig, value: $data[$propertyName] ?? null);
			if ($linked !== null) {
				$extractedIds[$linked->ncType] = array_merge($extractedIds[$linked->ncType] ?? [], $linked->ids);
			}
		}

		return $extractedIds;
	}//end extractIds()

	/**
	 * The ids a single property holds: one envelope for a direct Nc* type, a
	 * list of envelopes for an array of Nc* items.
	 *
	 * @param mixed $propertyConfig The property configuration from the schema; a non-array is skipped.
	 * @param mixed $value The property's value in the object data.
	 *
	 * @return LinkedEntityIds|null The ids, or null when the property is not Nc* typed or holds no id.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function linkedIds(mixed $propertyConfig, mixed $value): ?LinkedEntityIds {
		if (is_array($propertyConfig) === false) {
			return null;
		}

		$type = $propertyConfig['type'] ?? null;
		if ($this->isNcType(type: $type) === true) {
			return $this->envelopeIds(ncType: $type, values: [$value]);
		}

		return $this->itemIds(propertyConfig: $propertyConfig, value: $value);
	}//end linkedIds()

	/**
	 * The ids of an array property whose items are an Nc* type.
	 *
	 * @param array $propertyConfig The property configuration from the schema.
	 * @param mixed $value The property's value; a non-array holds no ids.
	 *
	 * @return LinkedEntityIds|null The ids, or null when this is no such array or it holds no id.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function itemIds(array $propertyConfig, mixed $value): ?LinkedEntityIds {
		if (($propertyConfig['type'] ?? null) !== 'array') {
			return null;
		}

		$itemsType = $propertyConfig['items']['type'] ?? null;
		if ($this->isNcType(type: $itemsType) === false) {
			return null;
		}

		return $this->envelopeIds(ncType: $itemsType, values: $value);
	}//end itemIds()

	/**
	 * Whether a schema type is one of the Nc* types.
	 *
	 * @param mixed $type The type.
	 *
	 * @return bool True for a type with a metadata column.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function isNcType(mixed $type): bool {
		return $type !== null && isset(self::NC_TYPE_COLUMNS[$type]) === true;
	}//end isNcType()

	/**
	 * The ids in a list of reference envelopes, in order.
	 *
	 * @param string $ncType The Nc* type.
	 * @param mixed $values The envelopes; a non-array holds none.
	 *
	 * @return LinkedEntityIds|null The ids, or null when none of the values holds an id.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function envelopeIds(string $ncType, mixed $values): ?LinkedEntityIds {
		if (is_array($values) === false) {
			return null;
		}

		$ids = $this->idsInEnvelopes(values: $values);
		if ($ids === []) {
			return null;
		}

		return new LinkedEntityIds(ncType: $ncType, ids: $ids);
	}//end envelopeIds()

	/**
	 * The ids the envelopes in a list hold, in order; values without an id are skipped.
	 *
	 * @param array $values The envelopes.
	 *
	 * @return list<string>
	 */
	private function idsInEnvelopes(array $values): array {
		$ids = [];
		foreach ($values as $value) {
			$id = $this->extractIdFromEnvelope(value: $value);
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsInEnvelopes()

	/**
	 * Extract the `id` field from a reference envelope.
	 *
	 * Accepts both full envelope format `{ "type": "NcMail", "id": "1/6" }`
	 * and plain string IDs.
	 *
	 * @param mixed $value The property value
	 *
	 * @return string|null The extracted ID or null
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function extractIdFromEnvelope(mixed $value): ?string {
		// Full envelope: { "type": "NcMail", "id": "1/6", "label": "..." }.
		if (is_array($value) === true) {
			return $this->envelopeId(envelope: $value);
		}

		// Plain string ID (for simple references).
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end extractIdFromEnvelope()

	/**
	 * The `id` of a full envelope, as a string, or null when it has none.
	 *
	 * @param array $envelope The envelope.
	 *
	 * @return string|null
	 */
	private function envelopeId(array $envelope): ?string {
		if (isset($envelope['id']) === true) {
			return (string)$envelope['id'];
		}

		return null;
	}//end envelopeId()

	/**
	 * Merge extracted IDs into the object's metadata column, preserving existing ad-hoc links.
	 *
	 * @param ObjectEntity $object The object entity
	 * @param LinkedEntityIds $linked The Nc* type and the newly extracted IDs
	 *
	 * @return void
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md#requirement-metadata-columns-on-entity-tables
	 */
	private function mergeIntoMetadataColumn(ObjectEntity $object, LinkedEntityIds $linked): void {
		$column = self::NC_TYPE_COLUMNS[$linked->ncType];

		// Existing IDs include ad-hoc links from the sidebar; merge and deduplicate.
		$existingIds = $object->{'get' . $column}() ?? [];
		$mergedIds = array_values(array_unique(array_merge($existingIds, $linked->ids)));
		$object->{'set' . $column}($mergedIds);

		$this->logger->debug(
			'[LinkedEntityPropertyHandler] Merged IDs into metadata column',
			['ncType' => $linked->ncType, 'newIds' => $linked->ids, 'existing' => count($existingIds), 'merged' => count($mergedIds)]
		);
	}//end mergeIntoMetadataColumn()
}//end class
