<?php

/**
 * TranslationMapperInterface: what other components ask of the translations mapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * The translations-sidecar queries that callers outside lib/Db use.
 *
 * Bound to TranslationMapper in DbRegistrar.
 */
interface TranslationMapperInterface {

	/**
	 * Delete one translation row (QBMapper).
	 *
	 * @param Translation $entity The row to delete.
	 *
	 * @return Translation The deleted row.
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Delete every row for an object — called on object delete.
	 *
	 * @param string $objectUuid UUID of the object whose rows should be removed.
	 *
	 * @return int Number of rows deleted.
	 */
	public function deleteByObject(string $objectUuid): int;

	/**
	 * Find all translations for one object — all properties, all languages.
	 *
	 * @param string $objectUuid UUID of the object to look up.
	 *
	 * @return Translation[]
	 */
	public function findByObject(string $objectUuid): array;

	/**
	 * Find object UUIDs missing a translation in the given language for
	 * one or more properties.
	 *
	 * Finds the (uuid, property) pairs that DO have the language and subtracts
	 * them from the (uuid × property) cross-product to get the missing slots.
	 *
	 * @param string $language BCP-47 language tag to test for completeness.
	 * @param string[] $properties Property paths required for completeness.
	 * @param string[] $candidateUuids Restrict to these uuids (e.g. to scope to a register/schema).
	 *
	 * @return string[] List of object_uuids missing at least one translation
	 */
	public function findObjectsMissingLanguage(string $language, array $properties, array $candidateUuids): array;

	/**
	 * Find a single translation slot by its natural key.
	 *
	 * @param string $objectUuid UUID of the object being translated.
	 * @param string $property Property path within the object.
	 * @param string $language BCP-47 language tag of the slot.
	 *
	 * @return Translation|null Matching row, or null when not found.
	 */
	public function findOne(string $objectUuid, string $property, string $language): ?Translation;

	/**
	 * Per-language completeness count for a single object.
	 *
	 * Returns `[language => count]` — caller divides against the
	 * schema's translatable-property total to derive the ratio.
	 *
	 * @param string $objectUuid UUID of the object to count rows for.
	 *
	 * @return array<string, int>
	 */
	public function getCompletenessByObject(string $objectUuid): array;

	/**
	 * Find the most common source_language across an object's translatable
	 * properties. Used to drive the `X-Source-Language` response header.
	 *
	 * Returns null when the object has no translation rows.
	 *
	 * @param string $objectUuid UUID of the object to inspect.
	 *
	 * @return string|null Dominant source language, or null when no rows exist.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function getDominantSourceLanguage(string $objectUuid): ?string;

	/**
	 * Flip every non-source-language row for one (object, property) to outdated.
	 *
	 * Targets every row WHERE `object_uuid = $objectUuid AND property = $property
	 * AND language != $sourceLanguage AND status IN (approved,
	 * human_reviewed, machine_translated)`. Rows already in `outdated` or
	 * `draft` are not re-flipped.
	 *
	 * @param string $objectUuid UUID of the object whose translations to flip.
	 * @param string $property Property whose derived translations to flip.
	 * @param string $sourceLanguage Source language for the property (never flipped).
	 *
	 * @return int Number of rows transitioned to `outdated`.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
	 */
	public function markDerivedOutdated(string $objectUuid, string $property, string $sourceLanguage): int;

	/**
	 * Search translations by content + optional filters.
	 *
	 * - `query`           — case-insensitive substring against `value`
	 * - `language`        — scope to a specific language (null = cross-language)
	 * - `status`          — filter by workflow status
	 * - `objectUuid`      — scope to one object
	 * - `sourceLanguage`  — scope to rows whose canonical source-of-truth
	 *                       language equals the given BCP-47 tag
	 *                       (`i18n-source-of-truth`)
	 * - `isOutOfDate`     — when true, narrows to rows in `outdated` status
	 *
	 * Uses `LOWER(value) LIKE LOWER(?)` so the query works on both
	 * Postgres and MariaDB without DB-specific FTS. tsvector
	 * optimisation is a v1.1 follow-up.
	 *
	 * @param string|null $query Case-insensitive substring against the value column.
	 * @param string|null $language Optional language filter (BCP-47 tag).
	 * @param string|null $status Optional workflow-status filter.
	 * @param string|null $objectUuid Optional object-uuid scope.
	 * @param int $limit Maximum number of rows to return (1..1000).
	 * @param string|null $sourceLanguage Optional source-language filter (BCP-47 tag).
	 * @param bool $isOutOfDate When true, narrows to status='outdated'.
	 *
	 * @return Translation[]
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function search(
		?string $query = null,
		?string $language = null,
		?string $status = null,
		?string $objectUuid = null,
		int $limit = 100,
		?string $sourceLanguage = null,
		bool $isOutOfDate = false,
	): array;

	/**
	 * UPSERT a translation slot. Returns the persisted entity.
	 *
	 * Slot key is `(object_uuid, property, language)`. When updating
	 * an existing slot, status defaults to retaining the previous
	 * status unless caller passes a non-null override.
	 *
	 * @param string $objectUuid UUID of the object being translated.
	 * @param string $property Property path within the object.
	 * @param string $language BCP-47 language tag of the slot.
	 * @param string|null $value Translated value (null clears).
	 * @param string|null $status Optional workflow status override.
	 * @param string|null $translator Optional UID of the translator.
	 * @param string|null $sourceLanguage Optional canonical source language for the property.
	 *
	 * @return Translation Persisted translation row.
	 */
	public function upsert(
		string $objectUuid,
		string $property,
		string $language,
		?string $value,
		?string $status = null,
		?string $translator = null,
		?string $sourceLanguage = null,
	): Translation;
}//end interface
