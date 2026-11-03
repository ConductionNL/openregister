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

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
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
	 * Constructor.
	 *
	 * @param ObjectRetentionHandler $settingsHandler Names the selectielijst register and schema.
	 * @param RegisterMapper         $registerMapper  Resolves the register.
	 * @param SchemaMapper           $schemaMapper    Resolves the schema.
	 * @param MagicMapper            $objectMapper    Reads the rows already stored.
	 * @param SaveObject             $saveObject      Writes a row.
	 * @param LoggerInterface        $logger          Reports a row that could not be stored.
	 */
	public function __construct(
		private readonly ObjectRetentionHandler $settingsHandler,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly MagicMapper $objectMapper,
		private readonly SaveObject $saveObject,
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
		$result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0, 'failed' => []];

		$target = $this->target();
		if ($target === null) {
			$result['failed'] = $this->unconfigured(entries: $entries);
			return $result;
		}

		[$register, $schema] = $target;
		$stored = $this->storedRows(register: $register, schema: $schema);

		foreach ($entries as $key => $entry) {
			$row = $this->rowFor(entry: $this->withKeyAsCategory(entry: $entry, key: $key), appId: $appId);
			if (is_string($row) === true) {
				$result['failed'][] = ['category' => $this->categoryOf(entry: $entry), 'reason' => $row];
				continue;
			}

			$key = $this->keyOf(row: $row);
			$existing = ($stored[$key] ?? null);
			$outcome = $this->write(register: $register, schema: $schema, row: $row, existing: $existing);
			if ($outcome === 'failed') {
				$result['failed'][] = ['category' => $row['categorie'], 'reason' => 'The row could not be stored; see the log.'];
				continue;
			}

			$result[$outcome]++;
			if ($outcome === 'created') {
				$stored[$key] = new ObjectEntity();
				$stored[$key]->setObject($row);
			}
		}//end foreach

		return $result;
	}//end seed()

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
		$uuid = null;
		$data = [];
		if ($existing !== null) {
			$data = ($existing->getObject() ?? []);
			if (($data['bron'] ?? null) !== $row['bron']) {
				return 'kept';
			}

			if ($this->sameValues(stored: $data, row: $row) === true) {
				return 'unchanged';
			}

			$uuid = $existing->getUuid();
		}

		try {
			$this->saveObject->saveObject($register, $schema, array_merge($data, $row), $uuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[SelectionListSeeder] Could not store category ' . $row['categorie'] . ': ' . $e->getMessage()
			);
			return 'failed';
		}

		if ($uuid === null) {
			return 'created';
		}

		return 'updated';
	}//end write()

	/**
	 * The row an entry becomes, or the reason it cannot become one.
	 *
	 * @param mixed       $entry The shipped entry.
	 * @param string|null $appId The app shipping it.
	 *
	 * @return array<string, mixed>|string The row, or why the entry is refused.
	 */
	private function rowFor(mixed $entry, ?string $appId): array|string {
		if (is_array($entry) === false) {
			return 'An entry is an object with a category, an action and retentionYears.';
		}

		$category = $this->categoryOf(entry: $entry);
		if ($category === '') {
			return 'An entry needs a category.';
		}

		$action = trim((string)($entry['action'] ?? ''));
		if (in_array($action, Appraisal::ALL_ALIASES, true) === false) {
			return 'The action must be one of: ' . implode(', ', Appraisal::ALL_ALIASES) . '.';
		}

		$period = $this->periodOf(years: ($entry['retentionYears'] ?? null));
		if ($period === false
			|| ($period === null && in_array($action, Appraisal::RETAIN_PERMANENTLY_ALIASES, true) === false)
		) {
			return 'retentionYears must be a whole number of 0 or more (it may be left out only when the record is kept permanently).';
		}

		$appSource = null;
		if ($appId !== null) {
			$appSource = 'app:' . $appId;
		}

		$row = [
			'categorie' => $category,
			'archiefnominatie' => $action,
			'bewaartermijn' => $period,
			'omschrijving' => $this->textOrNull(value: ($entry['description'] ?? null)),
			'organisatie' => $this->textOrNull(value: ($entry['organisation'] ?? null)),
			'bron' => ($this->textOrNull(value: ($entry['source'] ?? null)) ?? $appSource),
		];

		$version = $this->textOrNull(value: ($entry['version'] ?? null));
		if ($version !== null) {
			$row[SelectielijstImportService::VERSION_KEY] = $version;
		}

		return $row;
	}//end rowFor()

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

		$rows = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false || is_array($object->getObject()) === false) {
				continue;
			}

			$rows[$this->keyOf(row: $object->getObject())] = $object;
		}

		return $rows;
	}//end storedRows()

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
	 * A trimmed string, or null when blank or not text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private function textOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end textOrNull()

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
