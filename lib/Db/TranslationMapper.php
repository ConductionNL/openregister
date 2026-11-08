<?php

/**
 * OpenRegister TranslationMapper
 *
 * CRUD + search + completeness queries against the unified
 * `openregister_translations` sidecar.
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the unified translations sidecar table.
 *
 * @template-extends QBMapper<Translation>
 */
class TranslationMapper extends QBMapper implements TranslationMapperInterface {
	/**
	 * Construct the mapper bound to the translations table.
	 *
	 * @param IDBConnection $db Database connection handle.
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_translations', entityClass: Translation::class);
	}//end __construct()

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
		string|null $value,
		string|null $status = null,
		string|null $translator = null,
		string|null $sourceLanguage = null,
	): Translation {
		$existing = $this->findOne(objectUuid: $objectUuid, property: $property, language: $language);
		$entity = $existing ?? new Translation();
		$entity->setObjectUuid($objectUuid);
		$entity->setProperty($property);
		$entity->setLanguage($language);
		$entity->setValue($value);
		$this->applyStatus(entity: $entity, status: $status);
		if ($translator !== null) {
			$entity->setTranslator($translator);
		}

		$this->applySourceLanguage(entity: $entity, sourceLanguage: $sourceLanguage);
		$entity->setUpdated(new DateTime());
		return $this->persist(entity: $entity, isNew: ($existing === null));
	}//end upsert()

	/**
	 * Set the workflow status: the override when given, else draft for a slot without one.
	 *
	 * @param Translation $entity The slot being written.
	 * @param string|null $status Optional workflow status override.
	 *
	 * @return void
	 */
	private function applyStatus(Translation $entity, ?string $status): void {
		if ($status !== null) {
			$entity->setStatus($status);
			return;
		}

		if ($entity->getStatus() === null) {
			$entity->setStatus(Translation::STATUS_DRAFT);
		}
	}//end applyStatus()

	/**
	 * Set the canonical source language when a non-empty one is given.
	 *
	 * @param Translation $entity The slot being written.
	 * @param string|null $sourceLanguage Optional canonical source language.
	 *
	 * @return void
	 */
	private function applySourceLanguage(Translation $entity, ?string $sourceLanguage): void {
		if (in_array($sourceLanguage, ['', null], true) === false) {
			$entity->setSourceLanguage($sourceLanguage);
		}
	}//end applySourceLanguage()

	/**
	 * Insert a new slot or update an existing one.
	 *
	 * @param Translation $entity The slot to persist.
	 * @param bool $isNew Whether the slot has no row yet.
	 *
	 * @return Translation Persisted translation row.
	 */
	private function persist(Translation $entity, bool $isNew): Translation {
		if ($isNew === true) {
			return $this->insert(entity: $entity);
		}

		return $this->update(entity: $entity);
	}//end persist()

	/**
	 * Find a single translation slot by its natural key.
	 *
	 * @param string $objectUuid UUID of the object being translated.
	 * @param string $property Property path within the object.
	 * @param string $language BCP-47 language tag of the slot.
	 *
	 * @return Translation|null Matching row, or null when not found.
	 */
	public function findOne(string $objectUuid, string $property, string $language): ?Translation {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_translations')
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('property', $qb->createNamedParameter($property)))
			->andWhere($qb->expr()->eq('language', $qb->createNamedParameter($language)))
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findOne()

	/**
	 * Find all translations for one object — all properties, all languages.
	 *
	 * @param string $objectUuid UUID of the object to look up.
	 *
	 * @return Translation[]
	 */
	public function findByObject(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_translations')
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
		return $this->findEntities(query: $qb);
	}//end findByObject()

	/**
	 * Delete every row for an object — called on object delete.
	 *
	 * @param string $objectUuid UUID of the object whose rows should be removed.
	 *
	 * @return int Number of rows deleted.
	 */
	public function deleteByObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('openregister_translations')
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
		return $qb->executeStatement();
	}//end deleteByObject()

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
	public function getCompletenessByObject(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('language')
			->selectAlias($qb->createFunction('COUNT(*)'), 'count')
			->from('openregister_translations')
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
		$this->whereValuePresent(qb: $qb);
		$qb->groupBy('language');

		$out = [];
		foreach ($this->fetchRows(qb: $qb) as $row) {
			$out[(string)$row['language']] = (int)$row['count'];
		}

		return $out;
	}//end getCompletenessByObject()

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
		string|null $query = null,
		string|null $language = null,
		string|null $status = null,
		string|null $objectUuid = null,
		int $limit = 100,
		string|null $sourceLanguage = null,
		bool $isOutOfDate = false,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('openregister_translations')
			->setMaxResults(max(1, min(1000, $limit)));
		$this->whereValueContains(qb: $qb, query: $query);
		$filters = [['language', $language], ['status', $status], ['object_uuid', $objectUuid], ['source_language', $sourceLanguage]];
		if ($isOutOfDate === true) {
			$filters[] = ['status', Translation::STATUS_OUTDATED];
		}

		$this->whereEachEquals(qb: $qb, filters: $filters);
		return $this->findEntities(query: $qb);
	}//end search()

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
	public function markDerivedOutdated(string $objectUuid, string $property, string $sourceLanguage): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('openregister_translations')
			->set('status', $qb->createNamedParameter(Translation::STATUS_OUTDATED))
			->set('updated', $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('property', $qb->createNamedParameter($property)))
			->andWhere($qb->expr()->neq('language', $qb->createNamedParameter($sourceLanguage)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter(Translation::FLIPPABLE_STATUSES, IQueryBuilder::PARAM_STR_ARRAY)));

		return $qb->executeStatement();
	}//end markDerivedOutdated()

	/**
	 * Back-fill the source_language column on rows that have an empty value.
	 *
	 * Streams candidate rows in batches and updates each row's
	 * `source_language` to the resolved `<defaultLanguage>` for the parent
	 * register. Used by the
	 * `openregister:translations:backfill-source-language` console command.
	 *
	 * @param array<int|string, string> $registerDefaults Map of `register_id =>
	 *        default_language`. Keys are int|string, not string: register ids are
	 *        canonical numeric strings, which PHP coerces to int array keys.
	 * @param int $batchSize Maximum rows updated per pass.
	 *
	 * @return int Number of rows updated across all batches.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	public function backfillSourceLanguage(array $registerDefaults, int $batchSize = 1000): int {
		if (count($registerDefaults) === 0) {
			return $this->fillEmptySourceLanguage();
		}

		$totalUpdated = 0;
		foreach ($registerDefaults as $registerId => $defaultLanguage) {
			$language = $this->languageOrDefault(language: $defaultLanguage);
			$scope = new TranslationBackfillScope(registerId: (string)$registerId, language: $language, batchSize: $batchSize);
			$totalUpdated += $this->backfillRegister(scope: $scope);
		}

		// Catch-all for orphans: rows whose object UUID no longer joins back to a register.
		return $totalUpdated + $this->fillEmptySourceLanguage();
	}//end backfillSourceLanguage()

	/**
	 * The register's default language, or 'nl' when it has none.
	 *
	 * @param mixed $language The register's configured default language.
	 *
	 * @return string The language to back-fill with.
	 */
	private function languageOrDefault(mixed $language): string {
		if (in_array($language, ['', null], true) === true) {
			return 'nl';
		}

		return (string)$language;
	}//end languageOrDefault()

	/**
	 * Back-fill one register's empty rows, bounded by the batch size where the driver allows it.
	 *
	 * @param TranslationBackfillScope $scope The register, its language and the batch size.
	 *
	 * @return int Number of rows updated.
	 */
	private function backfillRegister(TranslationBackfillScope $scope): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('openregister_translations', 't');
		$this->scopeToRegister(qb: $qb, scope: $scope)->setMaxResults(max(1, $scope->batchSize));

		try {
			return $qb->executeStatement();
		} catch (\Throwable $e) {
			// Driver doesn't support setMaxResults on UPDATE — fall back to
			// a single non-bounded UPDATE for this register.
			$fallback = $this->db->getQueryBuilder();
			$fallback->update('openregister_translations');
			return (int)$this->scopeToRegister(qb: $fallback, scope: $scope)->executeStatement();
		}
	}//end backfillRegister()

	/**
	 * Set the source language on the register's rows that still have the empty default.
	 *
	 * @param IQueryBuilder $qb An UPDATE on the translations table.
	 * @param TranslationBackfillScope $scope The register and its language.
	 *
	 * @return IQueryBuilder The same builder.
	 */
	private function scopeToRegister(IQueryBuilder $qb, TranslationBackfillScope $scope): IQueryBuilder {
		return $qb->set('source_language', $qb->createNamedParameter($scope->language))
			->where($qb->expr()->eq('source_language', $qb->createNamedParameter('')))
			->andWhere(
				'object_uuid IN (SELECT uuid FROM `*PREFIX*openregister_objects` WHERE register = '
				. $qb->createNamedParameter($scope->registerId) . ')'
			);
	}//end scopeToRegister()

	/**
	 * Set 'nl' as the source language on every row that still has the empty default.
	 *
	 * Used when no register is given, so the NOT NULL DEFAULT '' constraint is
	 * honoured, and as the conservative fallback for orphaned rows.
	 *
	 * @return int Number of rows updated.
	 */
	private function fillEmptySourceLanguage(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update('openregister_translations')
			->set('source_language', $qb->createNamedParameter('nl'))
			->where($qb->expr()->eq('source_language', $qb->createNamedParameter('')));
		return (int)$qb->executeStatement();
	}//end fillEmptySourceLanguage()

	/**
	 * Count rows whose `source_language` is the empty back-fill default.
	 *
	 * Used by the back-fill command's idempotency check.
	 *
	 * @return int Count of rows still pending back-fill.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-1
	 */
	public function countMissingSourceLanguage(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*) AS pending'))
			->from('openregister_translations')
			->where($qb->expr()->eq('source_language', $qb->createNamedParameter('')));

		$row = $this->fetchSingleRow(qb: $qb);
		return (int)($row['pending'] ?? 0);
	}//end countMissingSourceLanguage()

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
	public function getDominantSourceLanguage(string $objectUuid): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('source_language')
			->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
			->from('openregister_translations')
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->neq('source_language', $qb->createNamedParameter('')))
			->groupBy('source_language')->orderBy('cnt', 'DESC')->setMaxResults(1);

		$value = (string)($this->fetchSingleRow(qb: $qb)['source_language'] ?? '');
		if ($value === '') {
			return null;
		}

		return $value;
	}//end getDominantSourceLanguage()

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
	public function findObjectsMissingLanguage(string $language, array $properties, array $candidateUuids): array {
		if (count($properties) === 0 || count($candidateUuids) === 0) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid', 'property')->from('openregister_translations')
			->where($qb->expr()->eq('language', $qb->createNamedParameter($language)))
			->andWhere($qb->expr()->in('object_uuid', $qb->createNamedParameter($candidateUuids, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->in('property', $qb->createNamedParameter($properties, IQueryBuilder::PARAM_STR_ARRAY)));
		$this->whereValuePresent(qb: $qb);
		$present = $this->presentSlots(qb: $qb);

		return array_values(
			array_filter($candidateUuids, fn (string $uuid): bool => $this->lacksAnyProperty(present: ($present[$uuid] ?? []), properties: $properties))
		);
	}//end findObjectsMissingLanguage()

	/**
	 * Index the (object, property) slots a query returns.
	 *
	 * @param IQueryBuilder $qb A select of object_uuid and property.
	 *
	 * @return array<string, array<string, true>> Present properties per object uuid.
	 */
	private function presentSlots(IQueryBuilder $qb): array {
		$present = [];
		foreach ($this->fetchRows(qb: $qb) as $row) {
			$present[(string)$row['object_uuid']][(string)$row['property']] = true;
		}

		return $present;
	}//end presentSlots()

	/**
	 * Whether at least one required property is absent.
	 *
	 * @param array<string, true> $present Properties the object has a value for.
	 * @param string[] $properties Property paths required for completeness.
	 *
	 * @return bool True when one or more properties are missing.
	 */
	private function lacksAnyProperty(array $present, array $properties): bool {
		return count(array_diff($properties, array_keys($present))) > 0;
	}//end lacksAnyProperty()

	/**
	 * Narrow to rows with a non-null, non-empty value.
	 *
	 * @param IQueryBuilder $qb The query to narrow.
	 *
	 * @return void
	 */
	private function whereValuePresent(IQueryBuilder $qb): void {
		$qb->andWhere($qb->expr()->isNotNull('value'))
			->andWhere($qb->expr()->neq('value', $qb->createNamedParameter('')));
	}//end whereValuePresent()

	/**
	 * Narrow to rows whose value contains the query, case-insensitively, when a query is given.
	 *
	 * @param IQueryBuilder $qb The query to narrow.
	 * @param string|null $query Substring to look for.
	 *
	 * @return void
	 */
	private function whereValueContains(IQueryBuilder $qb, ?string $query): void {
		if (in_array($query, ['', null], true) === true) {
			return;
		}

		$qb->andWhere(
			$qb->expr()->iLike('value', $qb->createNamedParameter('%' . $this->escapeLike(value: $query) . '%'))
		);
	}//end whereValueContains()

	/**
	 * Add an equality predicate for each filter whose value is neither null nor empty.
	 *
	 * @param IQueryBuilder $qb The query to narrow.
	 * @param array<int, array{0: string, 1: string|null}> $filters Column and value pairs, in order.
	 *
	 * @return void
	 */
	private function whereEachEquals(IQueryBuilder $qb, array $filters): void {
		foreach ($filters as [$column, $value]) {
			if (in_array($value, ['', null], true) === true) {
				continue;
			}

			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		}
	}//end whereEachEquals()

	/**
	 * Execute a query and return every row.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function fetchRows(IQueryBuilder $qb): array {
		$stmt = $qb->executeQuery();
		$rows = [];
		while (($row = $stmt->fetch()) !== false) {
			$rows[] = $row;
		}

		$stmt->closeCursor();
		return $rows;
	}//end fetchRows()

	/**
	 * Execute a query and return its first row, or an empty array when there is none.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function fetchSingleRow(IQueryBuilder $qb): array {
		$stmt = $qb->executeQuery();
		$row = $stmt->fetch();
		$stmt->closeCursor();
		if ($row === false) {
			return [];
		}

		return $row;
	}//end fetchSingleRow()

	/**
	 * Escape `%` and `_` for use inside a LIKE pattern.
	 *
	 * @param string $value Raw value to be escaped for LIKE matching.
	 *
	 * @return string The escaped string safe for LIKE clauses.
	 */
	private function escapeLike(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}//end escapeLike()
}//end class
