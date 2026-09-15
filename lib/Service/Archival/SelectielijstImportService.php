<?php

/**
 * The selectielijst arrives as a file, and it arrives more than once.
 *
 * 🔴 IMPORTING WITHOUT A VERSION IS WORSE THAN NOT IMPORTING. The same category
 * carries different retention periods across selectielijst revisions, and the
 * lookup in {@see \OCA\OpenRegister\Service\RetentionService} finds a row by
 * category alone. Load a 2026 list beside a 2020 one and every nomination
 * quietly resolves to whichever row the store happened to return first, with
 * nothing on screen saying two rows were in play. So every imported row carries
 * the version it came from, and the instance names the version it applies.
 *
 * 🔴 AND A NEW LIST IS COMPARED BEFORE IT IS USED. "We support selectielijsten"
 * becomes something an archiefinspecteur can check only when an archivist can
 * see, before switching, which categories changed and what each change would do
 * to the records already nominated under them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports, versions and diffs a selectielijst or classification plan.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Importing register objects needs the
 *              register, the schema, the settings that name both, and the save path.
 */
class SelectielijstImportService {

	/**
	 * The property each imported row carries its version under.
	 */
	public const VERSION_KEY = 'selectielijstVersie';

	/**
	 * The property that identifies a row within a version.
	 */
	public const CATEGORY_KEY = 'categorie';

	/**
	 * The row fields a diff reports a change in.
	 *
	 * These are the two that decide what happens to a record: what it is
	 * nominated for, and for how long. A changed label is not a change an
	 * archivist has to act on; a changed bewaartermijn is.
	 *
	 * @var string[]
	 */
	public const COMPARED_FIELDS = ['archiefnominatie', 'bewaartermijn'];

	/**
	 * How many rows one import may carry, so a wrong file cannot fill a register.
	 */
	private const MAX_ROWS = 5000;

	/**
	 * Constructor.
	 *
	 * @param ObjectRetentionHandler $settingsHandler Names the selectielijst register and schema.
	 * @param RegisterMapper         $registerMapper  Resolves the register.
	 * @param SchemaMapper           $schemaMapper    Resolves the schema.
	 * @param MagicMapper            $objectMapper    Reads the rows already stored.
	 * @param SaveObject             $saveObject      Writes an imported row.
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
	 * Read a selectielijst out of the file an archivist was handed.
	 *
	 * CSV with a header row, or JSON holding a list of objects. A row without a
	 * category is refused rather than imported as an anonymous rule: a rule
	 * nothing can match is a rule nobody notices is broken.
	 *
	 * @param string $contents The file contents.
	 * @param string $filename The file name, which says which format to expect.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws InvalidArgumentException When the format is unknown, unparseable, empty or too large.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One refusal per way a file can be wrong,
	 *              and each says which way it was.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function parse(string $contents, string $filename): array {
		$extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));

		if ($extension === 'json') {
			$rows = json_decode($contents, true);
			if (is_array($rows) === false) {
				throw new InvalidArgumentException(
					'The selectielijst file is not readable JSON: ' . json_last_error_msg()
				);
			}
		} elseif ($extension === 'csv') {
			$rows = $this->parseCsv(contents: $contents);
		} else {
			throw new InvalidArgumentException(
				sprintf('A selectielijst is imported from a .csv or a .json file, not a ".%s"', $extension)
			);
		}

		$parsed = [];
		foreach ($rows as $index => $row) {
			if (is_array($row) === false) {
				throw new InvalidArgumentException(
					sprintf('Row %d of the selectielijst is not a set of fields', ((int)$index + 1))
				);
			}

			$category = trim((string)($row[self::CATEGORY_KEY] ?? ''));
			if ($category === '') {
				throw new InvalidArgumentException(
					sprintf(
						'Row %d of the selectielijst has no "%s". A row nothing can match is a rule nobody '
						. 'notices is broken, so the import refuses it.',
						((int)$index + 1),
						self::CATEGORY_KEY
					)
				);
			}

			$row[self::CATEGORY_KEY] = $category;
			$parsed[] = $row;
		}//end foreach

		if ($parsed === []) {
			throw new InvalidArgumentException('The selectielijst file holds no rows');
		}

		if (count($parsed) > self::MAX_ROWS) {
			throw new InvalidArgumentException(
				sprintf('The selectielijst file holds %d rows; the import accepts at most %d', count($parsed), self::MAX_ROWS)
			);
		}

		return $parsed;
	}//end parse()

	/**
	 * Read a CSV with a header row into a list of field maps.
	 *
	 * A row with a different column count than the header is refused rather
	 * than padded: a shifted column turns a bewaartermijn into a category, and
	 * a padded import of that is a wrong list nobody can see is wrong.
	 *
	 * @param string $contents The file contents.
	 *
	 * @return array<int, array<string, string>> The rows.
	 *
	 * @throws InvalidArgumentException When the file has no header or a row does not match it.
	 */
	private function parseCsv(string $contents): array {
		$lines = preg_split('/\r\n|\r|\n/', trim($contents));
		if ($lines === false || $lines === [] || trim((string)$lines[0]) === '') {
			throw new InvalidArgumentException('The selectielijst CSV has no header row');
		}

		$header = str_getcsv((string)array_shift($lines));
		$header = array_map(static fn ($column): string => trim((string)$column), $header);

		$rows = [];
		foreach ($lines as $index => $line) {
			if (trim((string)$line) === '') {
				continue;
			}

			$values = str_getcsv((string)$line);
			if (count($values) !== count($header)) {
				throw new InvalidArgumentException(
					sprintf(
						'Row %d of the selectielijst CSV has %d columns and the header has %d. A shifted column '
						. 'turns a bewaartermijn into a category, so the import refuses rather than pads.',
						((int)$index + 1),
						count($values),
						count($header)
					)
				);
			}

			$rows[] = array_combine($header, array_map(static fn ($v): string => trim((string)$v), $values));
		}//end foreach

		return $rows;
	}//end parseCsv()

	/**
	 * Store a parsed selectielijst as a named version.
	 *
	 * @param array<int, array<string, mixed>> $rows    The parsed rows.
	 * @param string                           $version The version these rows are.
	 * @param string|null                      $source  Where the file came from, recorded on each row.
	 *
	 * @return array{version: string, imported: int, failed: int} What was stored.
	 *
	 * @throws InvalidArgumentException When no selectielijst register and schema are configured,
	 *                                  or the version is blank, or it is already present.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function import(array $rows, string $version, ?string $source = null): array {
		$version = trim($version);
		if ($version === '') {
			throw new InvalidArgumentException(
				'A selectielijst import names the version it is, so the rows it writes can be told from the ones already here'
			);
		}

		[$register, $schema] = $this->target();

		if ($this->versions() !== [] && array_key_exists($version, $this->versions()) === true) {
			throw new InvalidArgumentException(
				sprintf(
					'Version "%s" is already imported. Import it under a different name, or compare it with '
					. 'the one in use instead of loading it twice.',
					$version
				)
			);
		}

		$imported = 0;
		$failed = 0;
		foreach ($rows as $row) {
			$row[self::VERSION_KEY] = $version;
			if ($source !== null) {
				$row['bron'] = ($row['bron'] ?? $source);
			}

			try {
				$this->saveObject->saveObject($register->getId(), $schema->getId(), $row);
				$imported++;
			} catch (Throwable $e) {
				$failed++;
				$this->logger->warning(
					'[SelectielijstImportService] Could not store row '
					. (string)$row[self::CATEGORY_KEY] . ' of version ' . $version . ': ' . $e->getMessage()
				);
			}
		}//end foreach

		return ['version' => $version, 'imported' => $imported, 'failed' => $failed];
	}//end import()

	/**
	 * Every selectielijst version stored here, with how many rows each holds.
	 *
	 * Rows written before versioning existed carry no version and are reported
	 * under `unversioned`, which is the honest answer and the one that tells an
	 * archivist there is a list here nobody named.
	 *
	 * @return array<string, int> Version mapped to its row count.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function versions(): array {
		$counts = [];
		foreach ($this->allRows() as $row) {
			$version = $this->versionOf(row: $row);
			$counts[$version] = (($counts[$version] ?? 0) + 1);
		}

		return $counts;
	}//end versions()

	/**
	 * Compare two stored versions, category by category.
	 *
	 * 🔴 THE DIFF SAYS WHAT A CHANGE WOULD DO, not merely that there is one. A
	 * bewaartermijn moving from P7Y to P10Y is three more years on every record
	 * nominated under that category, and an archivist switching lists has to see
	 * that before switching rather than after.
	 *
	 * @param string $from The version in use.
	 * @param string $to   The version being considered.
	 *
	 * @return array{from: string, to: string, added: array<int, string>, removed: array<int, string>,
	 *               changed: array<int, array<string, mixed>>} The comparison.
	 *
	 * @throws InvalidArgumentException When either version holds no rows.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function diff(string $from, string $to): array {
		$left = $this->rowsOf(version: $from);
		$right = $this->rowsOf(version: $to);

		if ($left === []) {
			throw new InvalidArgumentException(sprintf('No selectielijst rows are stored under version "%s"', $from));
		}

		if ($right === []) {
			throw new InvalidArgumentException(sprintf('No selectielijst rows are stored under version "%s"', $to));
		}

		$added = array_values(array_diff(array_keys($right), array_keys($left)));
		$removed = array_values(array_diff(array_keys($left), array_keys($right)));

		$changed = [];
		foreach ($right as $category => $row) {
			if (array_key_exists($category, $left) === false) {
				continue;
			}

			$fields = [];
			foreach (self::COMPARED_FIELDS as $field) {
				$before = ($left[$category][$field] ?? null);
				$after = ($row[$field] ?? null);
				if ($before === $after) {
					continue;
				}

				$fields[$field] = ['from' => $before, 'to' => $after];
			}

			if ($fields !== []) {
				$changed[] = ['category' => (string)$category, 'fields' => $fields];
			}
		}//end foreach

		return [
			'from' => $from,
			'to' => $to,
			'added' => $added,
			'removed' => $removed,
			'changed' => $changed,
		];
	}//end diff()

	/**
	 * The rows of one version, keyed by category.
	 *
	 * 🔴 FILTERED IN THE READING, NOT IN THE QUERY. A filter key the search
	 * handler does not recognise becomes `1 = 0` rather than an error, so a
	 * mis-spelled version filter would report "this version has no rows" on an
	 * instance that holds them, and an import would then look like it had never
	 * happened. Reading them all and selecting here cannot fail that way.
	 *
	 * @param string $version The version.
	 *
	 * @return array<string, array<string, mixed>> Category mapped to the row.
	 */
	public function rowsOf(string $version): array {
		$rows = [];
		foreach ($this->allRows() as $row) {
			if ($this->versionOf(row: $row) !== $version) {
				continue;
			}

			$category = trim((string)($row[self::CATEGORY_KEY] ?? ''));
			if ($category !== '') {
				$rows[$category] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Every stored selectielijst row, whatever version it belongs to.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function allRows(): array {
		try {
			[$register, $schema] = $this->target();
		} catch (InvalidArgumentException $e) {
			return [];
		}

		try {
			$objects = $this->objectMapper->findAll(
				limit: self::MAX_ROWS,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning('[SelectielijstImportService] Could not read the selectielijst: ' . $e->getMessage());
			return [];
		}

		$rows = [];
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = ($object->getObject() ?? []);
			if (is_array($data) === true) {
				$rows[] = $data;
			}
		}

		return $rows;
	}//end allRows()

	/**
	 * The version a row belongs to, or `unversioned`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The version label.
	 */
	private function versionOf(array $row): string {
		$version = trim((string)($row[self::VERSION_KEY] ?? ($row['versie'] ?? ($row['version'] ?? ''))));

		if ($version === '') {
			return 'unversioned';
		}

		return $version;
	}//end versionOf()

	/**
	 * The register and schema selectielijst rows live in.
	 *
	 * @return array{0: \OCA\OpenRegister\Db\Register, 1: \OCA\OpenRegister\Db\Schema} The pair.
	 *
	 * @throws InvalidArgumentException When the archival settings name neither.
	 */
	private function target(): array {
		$settings = $this->settingsHandler->getArchivalSettingsOnly();
		$registerId = ($settings['selectielijstRegister'] ?? null);
		$schemaId = ($settings['selectielijstSchema'] ?? null);

		if ($registerId === null || $schemaId === null) {
			throw new InvalidArgumentException(
				'No selectielijst register and schema are configured, so there is nowhere to put a list. '
				. 'Set them under Retention settings first.'
			);
		}

		return [
			$this->registerMapper->find((int)$registerId),
			$this->schemaMapper->find((int)$schemaId),
		];
	}//end target()
}//end class
