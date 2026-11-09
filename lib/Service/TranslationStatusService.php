<?php

/**
 * OpenRegister TranslationStatusService
 *
 * Public API for the translation sidecar: status updates,
 * per-object completeness queries, search, and bulk discovery
 * ("find objects missing X translation").
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\TranslationFilter;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Db\TranslationEdit;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapperInterface;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCP\IUserSession;

class TranslationStatusService implements TranslationStatusServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param TranslationMapperInterface $translationMapper The translation mapper.
	 * @param TranslationHandler $translationHandler The translation handler.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		private readonly TranslationMapperInterface $translationMapper,
		private readonly TranslationHandler $translationHandler,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Update the workflow status for a translation slot.
	 *
	 * Caller is the human/automation that knows the new status (e.g. a
	 * translator's UI promotes draft → human_reviewed). The translator
	 * uid is derived from the active session.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $property The property name.
	 * @param string $language The language code.
	 * @param string $status The new workflow status.
	 *
	 * @return Translation The persisted translation row.
	 *
	 * @throws InvalidArgumentException When status is invalid or no slot exists.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function setStatus(string $objectUuid, string $property, string $language, string $status): Translation {
		$this->assertKnownStatus(status: $status);
		$existing = $this->requireSlot(objectUuid: $objectUuid, property: $property, language: $language);

		$translator = $this->userSession->getUser()?->getUID();

		return $this->translationMapper->upsert(
			slot: new TranslationSlot(objectUuid: $objectUuid, property: $property, language: $language),
			edit: new TranslationEdit(value: $existing->getValue(), status: $status, translator: $translator)
		);
	}//end setStatus()

	/**
	 * Refuse a status that is not one of the known workflow statuses.
	 *
	 * @param string $status The requested status.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the status is unknown.
	 */
	private function assertKnownStatus(string $status): void {
		if (in_array($status, Translation::ALL_STATUSES, true) === true) {
			return;
		}

		throw new InvalidArgumentException(
			sprintf(
				'Invalid translation status "%s"; expected one of: %s',
				$status,
				implode(', ', Translation::ALL_STATUSES)
			)
		);
	}//end assertKnownStatus()

	/**
	 * The existing translation slot, or an exception when there is none.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $property   The property name.
	 * @param string $language   The language code.
	 *
	 * @return Translation The existing slot.
	 *
	 * @throws InvalidArgumentException When no slot exists.
	 */
	private function requireSlot(string $objectUuid, string $property, string $language): Translation {
		$existing = $this->translationMapper->findOne($objectUuid, $property, $language);
		if ($existing !== null) {
			return $existing;
		}

		throw new InvalidArgumentException(
			sprintf(
				'No translation slot for object=%s property=%s language=%s — set a value before promoting status.',
				$objectUuid,
				$property,
				$language
			)
		);
	}//end requireSlot()

	/**
	 * Per-object completeness ratio per language.
	 *
	 * Returns `[language => ['translated' => int, 'total' => int, 'ratio' => float]]`.
	 * `total` is the count of translatable properties on the schema;
	 * `translated` is the count of slots with non-empty values for the
	 * given language. `ratio` is `translated / total` rounded to 2dp.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param Schema $schema The schema entity.
	 *
	 * @return array<string, array{translated: int, total: int, ratio: float}>
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function completenessForObject(string $objectUuid, Schema $schema): array {
		$translatableProps = $this->translationHandler->getTranslatableProperties($schema);
		$total = count($translatableProps);
		if ($total === 0) {
			return [];
		}

		$counts = $this->translationMapper->getCompletenessByObject($objectUuid);
		return array_map(fn ($count): array => $this->completenessEntry(count: $count, total: $total), $counts);
	}//end completenessForObject()

	/**
	 * One language's completeness entry; the ratio is capped at 1 and rounded to 2dp.
	 *
	 * @param mixed $count The language's translated-slot count.
	 * @param int   $total The schema's translatable-property count (> 0).
	 *
	 * @return array{translated: mixed, total: int, ratio: float}
	 */
	private function completenessEntry(mixed $count, int $total): array {
		return [
			'translated' => $count,
			'total' => $total,
			'ratio' => round(min($count, $total) / $total, 2),
		];
	}//end completenessEntry()

	/**
	 * Search translation rows.
	 *
	 * @param string|null $query Optional full-text query.
	 * @param string|null $language Optional language filter.
	 * @param string|null $status Optional status filter.
	 * @param string|null $objectUuid Optional object UUID filter.
	 * @param int $limit Maximum number of results.
	 * @param string|null $sourceLanguage Optional source-language filter.
	 * @param bool $isOutOfDate When true, restrict to status=outdated.
	 *
	 * @return array<string, mixed>[]
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
	): array {
		$rows = $this->findRows(filters: compact('query', 'language', 'status', 'objectUuid', 'limit', 'sourceLanguage', 'isOutOfDate'));
		return array_map(fn (Translation $t) => $t->jsonSerialize(), $rows);
	}//end search()

	/**
	 * Search translation rows and attach the source-language value side-by-side.
	 *
	 * Used to power `GET /api/translations/search?compareToSource=true` so
	 * editorial tooling can render the source vs target value pair without
	 * a follow-up round trip.
	 *
	 * @param string|null $query Optional full-text query.
	 * @param string|null $language Optional language filter.
	 * @param string|null $status Optional status filter.
	 * @param string|null $objectUuid Optional object UUID filter.
	 * @param int $limit Maximum number of results.
	 * @param string|null $sourceLanguage Optional source-language filter.
	 * @param bool $isOutOfDate When true, restrict to status=outdated.
	 *
	 * @return array<string, mixed>[] Each row carries an extra `sourceValue` field.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
	 */
	public function searchWithSourceValues(
		?string $query = null,
		?string $language = null,
		?string $status = null,
		?string $objectUuid = null,
		int $limit = 100,
		?string $sourceLanguage = null,
		bool $isOutOfDate = false,
	): array {
		$rows = $this->findRows(filters: compact('query', 'language', 'status', 'objectUuid', 'limit', 'sourceLanguage', 'isOutOfDate'));
		return $this->withSourceValues(rows: $rows);
	}//end searchWithSourceValues()

	/**
	 * Serialise each row with its source-language value as an extra `sourceValue` field.
	 *
	 * @param Translation[] $rows The translation rows.
	 *
	 * @return array<string, mixed>[]
	 */
	private function withSourceValues(array $rows): array {
		// Resolve (uuid, property, sourceLanguage) -> sourceValue lookup once.
		$cache = [];
		$out = [];
		foreach ($rows as $row) {
			$entry = $row->jsonSerialize();
			$entry['sourceValue'] = $this->sourceValueFor(row: $row, cache: $cache);
			$out[] = $entry;
		}

		return $out;
	}//end withSourceValues()

	/**
	 * Run the mapper search with the caller's filters.
	 *
	 * @param array<string, mixed> $filters The search() parameters by name.
	 *
	 * @return Translation[]
	 */
	private function findRows(array $filters): array {
		$filter = new TranslationFilter(
			language: $filters['language'],
			status: $filters['status'],
			objectUuid: $filters['objectUuid'],
			sourceLanguage: $filters['sourceLanguage']
		);

		return $this->translationMapper->search(filter: $filter, query: $filters['query'], limit: $filters['limit'], isOutOfDate: $filters['isOutOfDate']);
	}//end findRows()

	/**
	 * The source-language value of a row, memoised per (uuid, property, sourceLanguage).
	 *
	 * A null lookup result is not memoised (isset), so it is looked up again.
	 *
	 * @param Translation                $row   The translation row.
	 * @param array<string, string|null> $cache The per-search memo, updated in place.
	 *
	 * @return string|null The source value, or null when unknown.
	 */
	private function sourceValueFor(Translation $row, array &$cache): ?string {
		$uuid = (string)$row->getObjectUuid();
		$prop = (string)$row->getProperty();
		$source = (string)($row->getSourceLanguage() ?? '');
		if ($source === '') {
			return null;
		}

		$key = $uuid . '|' . $prop . '|' . $source;
		if (isset($cache[$key]) === false) {
			$cache[$key] = $this->translationMapper->findOne($uuid, $prop, $source)?->getValue();
		}

		return $cache[$key];
	}//end sourceValueFor()

	/**
	 * Mark every non-source-language Translation row for a property as `outdated`.
	 *
	 * When the source-language value for a translatable property changes,
	 * every derived translation becomes stale by definition; flipping the
	 * status surface immediately surfaces the staleness to translators +
	 * editorial tooling. Rows already in `outdated` (or `draft`) status are
	 * not re-flipped.
	 *
	 * @param string $objectUuid UUID of the object whose translations to flip.
	 * @param string $property Property whose derived translations to flip.
	 * @param string $sourceLanguage Resolved source language for the property.
	 *
	 * @return int Number of derived rows transitioned to `outdated`.
	 *
	 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
	 */
	public function markDerivedTranslationsOutdated(
		string $objectUuid,
		string $property,
		string $sourceLanguage,
	): int {
		if ($objectUuid === '' || $property === '' || $sourceLanguage === '') {
			return 0;
		}

		return $this->translationMapper->markDerivedOutdated($objectUuid, $property, $sourceLanguage);
	}//end markDerivedTranslationsOutdated()

	/**
	 * Find objects missing translation slots in a language.
	 *
	 * Returns the subset of `$candidateUuids` missing at least one
	 * translatable-property value in `$language`.
	 *
	 * @param string $language The language code to check.
	 * @param Schema $schema The schema describing translatable properties.
	 * @param string[] $candidateUuids List of object UUIDs to consider.
	 *
	 * @return string[] Subset of `$candidateUuids` lacking the language.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function findObjectsMissingLanguage(string $language, Schema $schema, array $candidateUuids): array {
		$properties = $this->translationHandler->getTranslatableProperties($schema);
		return $this->translationMapper->findObjectsMissingLanguage($language, $properties, $candidateUuids);
	}//end findObjectsMissingLanguage()
}//end class
