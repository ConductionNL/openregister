<?php

/**
 * Writes the selectielijst categories an app ships with its register import
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * An app's `components.selectionLists`, written as selectielijst register rows.
 *
 * 🔴 NOT THE `SelectionList` ENTITY TABLE. Nothing reads that table: retention,
 * nomination and the destruction certificate all resolve a category through
 * SelectielijstResolver, which reads the selectielijst REGISTER that
 * SelectielijstImportService writes. Rows in the table would import cleanly
 * and change no retention decision, in silence.
 *
 * Idempotent by category, organisation and version. A row for the same key
 * that another source wrote (an archivist's own import) is left alone and
 * counted as `kept`: the list an archivist loaded outranks the one an app ships.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The branches are the ways a
 *              shipped entry can be wrong (no category, an unknown action, a
 *              period that is not whole years) and the four outcomes of writing
 *              one; each is named in the result, which is what an app author
 *              reads when a category did not land.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The register, the schema, the
 *              settings that name both, the reader and the save path.
 */
class SelectionListSeeder {

	/**
	 * How many stored rows are read to match against.
	 */
	private const MAX_ROWS = 5000;

	/**
	 * The row fields a shipped entry sets, compared to tell unchanged from updated.
	 *
	 * @var string[]
	 */
	private const WRITTEN_FIELDS = ['categorie', 'archiefnominatie', 'bewaartermijn', 'omschrijving', 'organisatie', 'bron'];

	/**
	 * A seed result before anything was written.
	 *
	 * @var array{created: int, updated: int, unchanged: int, kept: int, failed: array<int, array{category: string, reason: string}>}
	 */
	private const NOTHING_SEEDED = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0, 'failed' => []];

	/**
	 * Constructor.
	 *
	 * @param ArchivalSettingsReaderInterface $settingsHandler Names the selectielijst register and schema.
	 * @param RegisterMapperInterface         $registerMapper  Resolves the register.
	 * @param SchemaMapperInterface           $schemaMapper    Resolves the schema.
	 * @param MagicMapperInterface            $objectMapper    Reads the rows already stored.
	 * @param SaveObjectWriterInterface             $saveObject      Writes a row.
	 * @param LoggerInterface        $logger          Reports a row that could not be stored.
	 */
	public function __construct(
		private readonly ArchivalSettingsReaderInterface $settingsHandler,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly MagicMapperInterface $objectMapper,
		private readonly SaveObjectWriterInterface $saveObject,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the entries an app ships, matched on category, organisation and version.
	 *
	 * Entry fields: `category`, `retentionYears`, `action`, `description`,
	 * `organisation`, and optionally `source` and `version`.
	 *
	 * @param array<int|string, mixed> $entries The `components.selectionLists` entries.
	 * @param string|null              $appId   The app shipping them, recorded as the row's source.
	 *
	 * @return array{created: int, updated: int, unchanged: int, kept: int, failed: array<int, array{category: string, reason: string}>}
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function seed(array $entries, ?string $appId = null): array {
		$target = $this->target();
		if ($target === null) {
			$result = self::NOTHING_SEEDED;
			$result['failed'] = $this->unconfigured(entries: $entries);
			return $result;
		}

		return $this->seedRows(register: $target[0], schema: $target[1], entries: $entries, appId: $appId);
	}//end seed()

	/**
	 * Write every entry into the configured register, tallying each outcome.
	 *
	 * @param mixed                    $register The selectielijst register.
	 * @param mixed                    $schema   The selectielijst schema.
	 * @param array<int|string, mixed> $entries  The shipped entries.
	 * @param string|null              $appId    The app shipping them.
	 *
	 * @return array{created: int, updated: int, unchanged: int, kept: int, failed: array<int, array{category: string, reason: string}>}
	 */
	private function seedRows(mixed $register, mixed $schema, array $entries, ?string $appId): array {
		$result = self::NOTHING_SEEDED;
		$stored = $this->storedRows(register: $register, schema: $schema);
		foreach ($entries as $key => $entry) {
			$row = $this->rowFor(entry: $this->withKeyAsCategory(entry: $entry, key: $key), appId: $appId);
			if (is_string($row) === true) {
				$result['failed'][] = ['category' => $this->categoryOf(entry: $entry), 'reason' => $row];
				continue;
			}

			$outcome = $this->write(register: $register, schema: $schema, row: $row, existing: ($stored[$this->keyOf(row: $row)] ?? null));
			$stored = $this->remembered(stored: $stored, row: $row, outcome: $outcome);
			$result = $this->tally(result: $result, row: $row, outcome: $outcome);
		}

		return $result;
	}//end seedRows()

	/**
	 * The stored rows, with a row just created among them so a repeat in the same seed matches it.
	 *
	 * @param array<string, ObjectEntity> $stored  The stored rows, by key.
	 * @param array<string, mixed>        $row     The row written.
	 * @param string                      $outcome What writing it did.
	 *
	 * @return array<string, ObjectEntity> The stored rows.
	 */
	private function remembered(array $stored, array $row, string $outcome): array {
		if ($outcome === 'created') {
			$created = new ObjectEntity();
			$created->setObject($row);
			$stored[$this->keyOf(row: $row)] = $created;
		}

		return $stored;
	}//end remembered()

	/**
	 * Count one write's outcome; a failed write is named with its category.
	 *
	 * @param array<string, mixed> $result  The result so far.
	 * @param array<string, mixed> $row     The row written.
	 * @param string               $outcome What writing it did.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function tally(array $result, array $row, string $outcome): array {
		if ($outcome === 'failed') {
			$result['failed'][] = ['category' => $row['categorie'], 'reason' => 'The row could not be stored; see the log.'];
			return $result;
		}

		$result[$outcome]++;
		return $result;
	}//end tally()

	/**
	 * Store one row, or leave it: created, updated, unchanged, kept or failed.
	 *
	 * @param mixed                $register The selectielijst register.
	 * @param mixed                $schema   The selectielijst schema.
	 * @param array<string, mixed> $row      The row to write.
	 * @param ObjectEntity|null    $existing The stored row with the same key.
	 *
	 * @return string The outcome.
	 */
	private function write(mixed $register, mixed $schema, array $row, ?ObjectEntity $existing): string {
		if ($existing === null) {
			return $this->saveRow(register: $register, schema: $schema, data: $row, uuid: null);
		}

		$stored = ($existing->getObject() ?? []);
		$unwritten = $this->unwritten(stored: $stored, row: $row);
		if ($unwritten !== null) {
			return $unwritten;
		}

		return $this->saveRow(register: $register, schema: $schema, data: array_merge($stored, $row), uuid: $existing->getUuid());
	}//end write()

	/**
	 * Why a stored row is left alone: another source wrote it, or it already holds these values.
	 *
	 * @param array<string, mixed> $stored The stored row.
	 * @param array<string, mixed> $row    The row the entry becomes.
	 *
	 * @return string|null `kept`, `unchanged`, or null when the row is to be written.
	 */
	private function unwritten(array $stored, array $row): ?string {
		if (($stored['bron'] ?? null) !== $row['bron']) {
			return 'kept';
		}

		if ($this->sameValues(stored: $stored, row: $row) === true) {
			return 'unchanged';
		}

		return null;
	}//end unwritten()

	/**
	 * Save a row: created, updated, or failed when the save path refused it.
	 *
	 * @param mixed                $register The selectielijst register.
	 * @param mixed                $schema   The selectielijst schema.
	 * @param array<string, mixed> $data     The row as it is to be stored.
	 * @param string|null          $uuid     The stored row's uuid, or null for a new row.
	 *
	 * @return string The outcome.
	 */
	private function saveRow(mixed $register, mixed $schema, array $data, ?string $uuid): string {
		try {
			$this->saveObject->saveObject($register, $schema, $data, $uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[SelectionListSeeder] Could not store category ' . $data['categorie'] . ': ' . $e->getMessage()
			);
			return 'failed';
		}

		if ($uuid === null) {
			return 'created';
		}

		return 'updated';
	}//end saveRow()

	/**
	 * The row an entry becomes, or the reason it cannot become one.
	 *
	 * @param mixed       $entry The shipped entry.
	 * @param string|null $appId The app shipping it.
	 *
	 * @return array<string, mixed>|string The row, or why the entry is refused.
	 */
	private function rowFor(mixed $entry, ?string $appId): array|string {
		$refusal = $this->refusalOf(entry: $entry);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->rowOf(entry: $entry, appId: $appId);
	}//end rowFor()

	/**
	 * Why an entry cannot become a row, or null when it can.
	 *
	 * @param mixed $entry The shipped entry.
	 *
	 * @return string|null The refusal.
	 */
	private function refusalOf(mixed $entry): ?string {
		$shape = $this->shapeRefusal(entry: $entry);
		if ($shape !== null) {
			return $shape;
		}

		$action = $this->actionOf(entry: $entry);
		if (in_array($action, Appraisal::ALL_ALIASES, true) === false) {
			return 'The action must be one of: ' . implode(', ', Appraisal::ALL_ALIASES) . '.';
		}

		if ($this->periodRefused(entry: $entry, action: $action) === true) {
			return 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).';
		}

		return null;
	}//end refusalOf()

	/**
	 * Why an entry is not an object with a category, or null when it is.
	 *
	 * @param mixed $entry The shipped entry.
	 *
	 * @return string|null The refusal.
	 */
	private function shapeRefusal(mixed $entry): ?string {
		if (is_array($entry) === false) {
			return 'An entry is an object with a category, an action and retentionYears.';
		}

		if ($this->categoryOf(entry: $entry) === '') {
			return 'An entry needs a category.';
		}

		return null;
	}//end shapeRefusal()

	/**
	 * Whether the entry's retentionYears is unusable: not whole years, or missing for a record not kept permanently.
	 *
	 * @param array<string, mixed> $entry  The shipped entry.
	 * @param string               $action Its action.
	 *
	 * @return bool True when the period refuses the entry.
	 */
	private function periodRefused(array $entry, string $action): bool {
		$period = $this->periodOf(years: ($entry['retentionYears'] ?? null));

		return $period === false
			|| ($period === null && in_array($action, Appraisal::RETAIN_PERMANENTLY_ALIASES, true) === false);
	}//end periodRefused()

	/**
	 * The row an entry that passed refusalOf() becomes.
	 *
	 * @param array<string, mixed> $entry The shipped entry.
	 * @param string|null          $appId The app shipping it.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowOf(array $entry, ?string $appId): array {
		$row = [
			'categorie' => $this->categoryOf(entry: $entry),
			'archiefnominatie' => $this->actionOf(entry: $entry),
			'bewaartermijn' => $this->periodOf(years: ($entry['retentionYears'] ?? null)),
			'omschrijving' => ArchivalText::fromScalar(value: ($entry['description'] ?? null)),
			'organisatie' => ArchivalText::fromScalar(value: ($entry['organisation'] ?? null)),
			'bron' => (ArchivalText::fromScalar(value: ($entry['source'] ?? null)) ?? $this->appSource(appId: $appId)),
		];

		$version = ArchivalText::fromScalar(value: ($entry['version'] ?? null));
		if ($version !== null) {
			$row[SelectielijstImportService::VERSION_KEY] = $version;
		}

		return $row;
	}//end rowOf()

	/**
	 * An entry's action, trimmed.
	 *
	 * @param array<string, mixed> $entry The shipped entry.
	 *
	 * @return string The action.
	 */
	private function actionOf(array $entry): string {
		return trim((string)($entry['action'] ?? ''));
	}//end actionOf()

	/**
	 * The source a row an app ships is recorded under, when no source is given.
	 *
	 * @param string|null $appId The app shipping it.
	 *
	 * @return string|null `app:<id>`, or null when no app is named.
	 */
	private function appSource(?string $appId): ?string {
		if ($appId === null) {
			return null;
		}

		return 'app:' . $appId;
	}//end appSource()

	/**
	 * Every entry refused because there is no selectielijst register to write to.
	 *
	 * @param array<int|string, mixed> $entries The shipped entries.
	 *
	 * @return array<int, array{category: string, reason: string}> One refusal per entry.
	 */
	private function unconfigured(array $entries): array {
		$failed = [];
		foreach ($entries as $key => $entry) {
			$failed[] = [
				'category' => $this->categoryOf(entry: $this->withKeyAsCategory(entry: $entry, key: $key)),
				'reason' => 'No selectielijst register and schema are configured, so there is nowhere to put the row. '
					. 'Set them under Retention settings.',
			];
		}

		return $failed;
	}//end unconfigured()

	/**
	 * An entry of a map keyed by category (the way schemas are keyed by slug) gets its key as category.
	 *
	 * @param mixed      $entry The shipped entry.
	 * @param int|string $key   Its key in `components.selectionLists`.
	 *
	 * @return mixed The entry, with a category when it had none and its key is one.
	 */
	private function withKeyAsCategory(mixed $entry, int|string $key): mixed {
		if (is_array($entry) === true && isset($entry['category']) === false && is_string($key) === true) {
			$entry['category'] = $key;
		}

		return $entry;
	}//end withKeyAsCategory()

	/**
	 * `P<n>Y` for a whole number of years, null for none given, false for anything else.
	 *
	 * @param mixed $years The shipped value.
	 *
	 * @return string|null|false The ISO 8601 period.
	 */
	private function periodOf(mixed $years): string|null|false {
		if ($years === null || $years === '') {
			return null;
		}

		$whole = filter_var($years, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		if (is_int($whole) === false || is_bool($years) === true) {
			return false;
		}

		return 'P' . $whole . 'Y';
	}//end periodOf()

	/**
	 * Whether the stored row already holds every value the entry sets.
	 *
	 * @param array<string, mixed> $stored The stored row.
	 * @param array<string, mixed> $row    The row the entry becomes.
	 *
	 * @return bool True when writing would change nothing.
	 */
	private function sameValues(array $stored, array $row): bool {
		foreach (self::WRITTEN_FIELDS as $field) {
			if (($stored[$field] ?? null) !== ($row[$field] ?? null)) {
				return false;
			}
		}

		return true;
	}//end sameValues()

	/**
	 * Every stored row, keyed on category, organisation and version.
	 *
	 * Read whole and matched here: a filter key the search handler does not
	 * recognise becomes `1 = 0`, and a miss would re-create every row on every
	 * import.
	 *
	 * @param mixed $register The selectielijst register.
	 * @param mixed $schema   The selectielijst schema.
	 *
	 * @return array<string, ObjectEntity> The rows.
	 */
	private function storedRows(mixed $register, mixed $schema): array {
		try {
			$objects = $this->objectMapper->findAll(limit: self::MAX_ROWS, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('[SelectionListSeeder] Could not read the selectielijst: ' . $e->getMessage());
			return [];
		}

		return $this->keyedRows(objects: $objects);
	}//end storedRows()

	/**
	 * The stored objects that are rows, keyed on category, organisation and version.
	 *
	 * @param iterable<mixed> $objects What the mapper returned.
	 *
	 * @return array<string, ObjectEntity> The rows.
	 */
	private function keyedRows(iterable $objects): array {
		$rows = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === true && is_array($object->getObject()) === true) {
				$rows[$this->keyOf(row: $object->getObject())] = $object;
			}
		}

		return $rows;
	}//end keyedRows()

	/**
	 * The match key of a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string Category, organisation and version.
	 */
	private function keyOf(array $row): string {
		return implode(
			"\n",
			[
				trim((string)($row['categorie'] ?? '')),
				trim((string)($row['organisatie'] ?? '')),
				trim((string)($row[SelectielijstImportService::VERSION_KEY] ?? '')),
			]
		);
	}//end keyOf()

	/**
	 * An entry's category, trimmed, or '' when it has none.
	 *
	 * @param mixed $entry The shipped entry.
	 *
	 * @return string The category.
	 */
	private function categoryOf(mixed $entry): string {
		if (is_array($entry) === false || is_scalar($entry['category'] ?? null) === false) {
			return '';
		}

		return trim((string)$entry['category']);
	}//end categoryOf()

	/**
	 * The selectielijst register and schema, or null when none is configured.
	 *
	 * @return array{0: mixed, 1: mixed}|null The pair.
	 */
	private function target(): ?array {
		$settings = $this->settingsHandler->getArchivalSettingsOnly();
		$registerId = ($settings['selectielijstRegister'] ?? null);
		$schemaId = ($settings['selectielijstSchema'] ?? null);
		if (empty($registerId) === true || empty($schemaId) === true) {
			return null;
		}

		try {
			return [$this->registerMapper->find((int)$registerId), $this->schemaMapper->find((int)$schemaId)];
		} catch (Throwable $e) {
			$this->logger->warning('[SelectionListSeeder] The selectielijst register or schema is gone: ' . $e->getMessage());
			return null;
		}
	}//end target()
}//end class
