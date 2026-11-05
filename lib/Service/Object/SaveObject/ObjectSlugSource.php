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
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The value an object's slug is built from, before any uniqueness check.
 *
 * A property path or template is slugified as it is. The reserved value
 * {@see ObjectSlugHandler::SCHEMA_NAME_SOURCE} builds `<schema slug>-<object name>`
 * instead, the object name being the value of the schema's `objectNameField` (same
 * paths and templates), with no fallback to other fields: an object without a name
 * gets no slug, and the uuid link stays its address (Robert Zondervan, 2026-10-26).
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-an-object-slug-must-come-only-from-the-configured-slug-field-and-must-not-be-written-into-the-object-data
 */
class ObjectSlugSource {

	/**
	 * Width of the column that stores a slug.
	 */
	public const MAX_LENGTH = 255;

	/**
	 * Length of `-YYYYMMDDHHMMSS`.
	 */
	private const SUFFIX_LENGTH = 15;

	/**
	 * Reads a client-sent slug.
	 *
	 * @var ObjectSlugClientValue
	 */
	private ObjectSlugClientValue $clientValue;

	/**
	 * Constructor.
	 *
	 * @param MetadataHydrationHandler $hydrator    Field extraction and the slugify routine.
	 * @param ITimeFactory|null        $timeFactory Clock for the suffix (null = system clock).
	 */
	public function __construct(
		private readonly MetadataHydrationHandler $hydrator,
		private readonly ?ITimeFactory $timeFactory=null,
	) {
		$this->clientValue = new ObjectSlugClientValue(hydrator: $hydrator);
	}//end __construct()

	/**
	 * Append `-YYYYMMDDHHMMSS` (UTC), cutting the base so the result fits the column.
	 *
	 * @param string      $slug  The taken slug.
	 * @param string|null $stamp A `YmdHis` stamp to use instead of now.
	 *
	 * @return string The suffixed slug.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function withSuffix(string $slug, ?string $stamp=null): string {
		return $this->suffixBase(slug: $slug) . '-' . ($stamp ?? $this->currentStamp());
	}//end withSuffix()

	/**
	 * Whether a slug is a suffixed variant of a candidate (`<base>-<14 digits>`).
	 *
	 * @param string $slug      The stored slug.
	 * @param string $candidate The unsuffixed candidate.
	 *
	 * @return bool True when $slug is $candidate with a date-time suffix.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function isSuffixOf(string $slug, string $candidate): bool {
		return preg_match('/^' . preg_quote($this->suffixBase(slug: $candidate), '/') . '-\d{14}$/', $slug) === 1;
	}//end isSuffixOf()

	/**
	 * The current UTC time as a `YmdHis` stamp.
	 *
	 * @return string The stamp.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-objectslugsuffix-must-append-a-utc-date-time-suffix-to-a-colliding-slug
	 */
	public function currentStamp(): string {
		$timestamp = ($this->timeFactory?->now()->getTimestamp() ?? time());
		return gmdate('YmdHis', $timestamp);
	}//end currentStamp()

	/**
	 * The part of a slug a suffix is appended to, cut so the result fits the column.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string The base.
	 */
	private function suffixBase(string $slug): string {
		return rtrim(substr($slug, 0, self::MAX_LENGTH - self::SUFFIX_LENGTH), '-');
	}//end suffixBase()

	/**
	 * A client-sent slug, normalised like a generated one (BC5), or null when none was sent.
	 *
	 * @param string|null $clientSlug `@self.slug` or top-level `slug` as sent.
	 *
	 * @return string|null The normalised slug, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-client-supplied-slug-must-follow-the-same-uniqueness-and-freeze-rules
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
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-an-object-slug-must-come-only-from-the-configured-slug-field-and-must-not-be-written-into-the-object-data
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
