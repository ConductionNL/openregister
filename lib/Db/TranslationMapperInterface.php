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
	 * @param TranslationFilter $filter The language, status, object and source-language filters.
	 * @param string|null $query Case-insensitive substring against the value column.
	 * @param int $limit Maximum number of rows to return (1..1000).
	 * @param bool $isOutOfDate When true, narrows to status='outdated'.
	 *
	 * @return Translation[]
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function search(TranslationFilter $filter, ?string $query = null, int $limit = 100, bool $isOutOfDate = false): array;

	/**
	 * UPSERT a translation slot. Returns the persisted entity.
	 *
	 * Slot key is `(object_uuid, property, language)`. When updating
	 * an existing slot, status defaults to retaining the previous
	 * status unless the edit carries a non-null override.
	 *
	 * @param TranslationSlot $slot The object, property and language of the slot.
	 * @param TranslationEdit $edit The value, status, translator and source language to write.
	 *
	 * @return Translation Persisted translation row.
	 */
	public function upsert(TranslationSlot $slot, TranslationEdit $edit): Translation;

	/**
	 * Back-fill the source_language column on rows that have an empty value.
	 *
	 * @param array<int|string, string> $registerDefaults Map of `register_id => default_language`.
	 * @param int                       $batchSize        Maximum rows updated per pass.
	 *
	 * @return int Number of rows updated across all batches.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	public function backfillSourceLanguage(array $registerDefaults, int $batchSize = 1000): int;

	/**
	 * Count rows whose `source_language` is the empty back-fill default.
	 *
	 * @return int Count of rows still pending back-fill.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	public function countMissingSourceLanguage(): int;
}//end interface
