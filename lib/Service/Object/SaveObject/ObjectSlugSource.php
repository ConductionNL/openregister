<?php

/**
 * OpenRegister ObjectSlugSource
 *
 * Builds the slug candidate a schema's `objectSlugField` describes: a property
 * path or template, or the reserved `@schema.name` source (#4037).
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

use OCA\OpenRegister\Db\Schema;

/**
 * The value an object's slug is built from, before any uniqueness check.
 *
 * A property path or template is slugified as it is. The reserved value
 * {@see ObjectSlugHandler::SCHEMA_NAME_SOURCE} builds `<schema slug>-<object name>`
 * instead, the object name being the value of the schema's `objectNameField` (same
 * paths and templates), with no fallback to other fields: an object without a name
 * gets no slug, and the uuid link stays its address (Robert Zondervan, 2026-10-26).
 *
 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-an-object-slug-must-come-only-from-the-configured-slug-field-and-must-not-be-written-into-the-object-data
 */
class ObjectSlugSource {

	/**
	 * Reads a client-sent slug.
	 *
	 * @var ObjectSlugClientValue
	 */
	private ObjectSlugClientValue $clientValue;

	/**
	 * Constructor.
	 *
	 * @param MetadataHydrationHandler $hydrator Field extraction and the slugify routine.
	 */
	public function __construct(
		private readonly MetadataHydrationHandler $hydrator,
	) {
		$this->clientValue = new ObjectSlugClientValue(hydrator: $hydrator);
	}//end __construct()

	/**
	 * A client-sent slug, normalised like a generated one (BC5), or null when none was sent.
	 *
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 *
	 * @return string|null The normalised slug, or null.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 */
	public function normaliseClient(?string $clientSlug): ?string {
		return $this->clientValue->normalise(clientSlug: $clientSlug);
	}//end normaliseClient()

	/**
	 * Whether a client slug asks for something other than the stored slug.
	 *
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 * @param string      $stored     The stored slug.
	 *
	 * @return bool True when the client asked for a different slug.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
	 */
	public function differsFromStored(?string $clientSlug, string $stored): bool {
		return $this->clientValue->differsFromStored(clientSlug: $clientSlug, stored: $stored);
	}//end differsFromStored()

	/**
	 * The candidate built from the schema's `objectSlugField`.
	 *
	 * @param array  $objectData The object's business data (or an envelope with an `object` key).
	 * @param Schema $schema     The schema.
	 * @param int    $maxLength  Width of the slug column.
	 *
	 * @return string|null The slugified value cut to the column, or null when there is none.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-an-object-slug-must-come-only-from-the-configured-slug-field-and-must-not-be-written-into-the-object-data
	 */
	public function candidate(array $objectData, Schema $schema, int $maxLength): ?string {
		$businessData = $objectData;
		if (isset($objectData['object']) === true && is_array($objectData['object']) === true) {
			$businessData = $objectData['object'];
		}

		$path = $this->fieldPathAndPrefix(schema: $schema);
		if ($path === null) {
			return null;
		}

		[$fieldPath, $prefix] = $path;
		$value = $this->hydrator->extractMetadataValue(
			data: $businessData,
			fieldPath: $fieldPath,
			schemaProperties: $schema->getProperties() ?? []
		);
		if ($value === null || trim($value) === '') {
			return null;
		}

		// The name is slugified on its own: a name that slugifies to nothing (only
		// punctuation, only non-Latin letters) is no name, so the schema part alone
		// never becomes the slug.
		$name = $this->hydrator->createSlug(text: trim($value));
		if ($name === '') {
			return null;
		}

		$slug = $name;
		if ($prefix !== '') {
			$slug = $prefix.'-'.$name;
		}

		// Cut to the column without leaving a trailing hyphen at the cut.
		return rtrim(substr($slug, 0, $maxLength), '-');
	}//end candidate()

	/**
	 * Which field to read and what to put in front of it.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array{0: string, 1: string}|null The field path and the prefix (empty for a
	 *                                          property source); null when the schema-name
	 *                                          source has no name field or no schema name.
	 */
	private function fieldPathAndPrefix(Schema $schema): ?array {
		$configuration = ($schema->getConfiguration() ?? []);
		$fieldPath = (string)($configuration['objectSlugField'] ?? '');
		if ($fieldPath !== ObjectSlugHandler::SCHEMA_NAME_SOURCE) {
			return [$fieldPath, ''];
		}

		$nameField = ($configuration['objectNameField'] ?? null);
		if (is_string($nameField) === false || trim($nameField) === '') {
			return null;
		}

		$prefix = $this->schemaPart(schema: $schema);
		if ($prefix === '') {
			return null;
		}

		return [$nameField, $prefix];
	}//end fieldPathAndPrefix()

	/**
	 * The schema half of a schema-name slug: the schema slug, or its slugified title
	 * when the schema has no slug.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The slugified schema name, empty when the schema has neither.
	 */
	private function schemaPart(Schema $schema): string {
		$slug = trim((string)($schema->getSlug() ?? ''));
		if ($slug !== '') {
			return $this->hydrator->createSlug(text: $slug);
		}

		return $this->hydrator->createSlug(text: trim((string)($schema->getTitle() ?? '')));
	}//end schemaPart()
}//end class
