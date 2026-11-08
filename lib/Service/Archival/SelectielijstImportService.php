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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports, versions and diffs a selectielijst or classification plan.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The branches are the ways a
 *              handed-over file can be wrong, and each one names which way it
 *              was: an unknown format, an unparseable body, a row with no
 *              category, a row whose column count does not match the header, a
 *              version already here, a version with no rows. Folding them
 *              together would lower the number and hand an archivist "the
 *              import failed", which is exactly what sends them back to the
 *              file with nothing to go on.
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
	 * The refusal for a row without a category; takes the row number and the category key.
	 */
	private const NO_CATEGORY = 'Row %d of the selectielijst has no "%s". A row nothing can match is a rule nobody '
		. 'notices is broken, so the import refuses it.';

	/**
	 * Constructor.
	 *
	 * @param ArchivalSettingsReaderInterface $settingsHandler Names the selectielijst register and schema.
	 * @param RegisterMapperInterface         $registerMapper  Resolves the register.
	 * @param SchemaMapperInterface           $schemaMapper    Resolves the schema.
	 * @param MagicMapperInterface            $objectMapper    Reads the rows already stored.
	 * @param SaveObjectWriterInterface             $saveObject      Writes an imported row.
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
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function parse(string $contents, string $filename): array {
		$extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));

		$parsed = [];
		foreach ($this->rowsFromFile(contents: $contents, extension: $extension) as $index => $row) {
			$parsed[] = $this->parsedRow(row: $row, number: ((int)$index + 1));
		}

		$this->guardRowCount(count: count($parsed));

		return $parsed;
	}//end parse()

	/**
	 * One raw row as an imported row, its category trimmed.
	 *
	 * @param mixed $row    The raw row.
	 * @param int   $number Its 1-based position in the file.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @throws InvalidArgumentException When the row is not a set of fields or has no category.
	 */
	private function parsedRow(mixed $row, int $number): array {
		if (is_array($row) === false) {
			throw new InvalidArgumentException(sprintf('Row %d of the selectielijst is not a set of fields', $number));
		}

		$category = trim((string)($row[self::CATEGORY_KEY] ?? ''));
		if ($category === '') {
			throw new InvalidArgumentException(sprintf(self::NO_CATEGORY, $number, self::CATEGORY_KEY));
		}

		$row[self::CATEGORY_KEY] = $category;
		return $row;
	}//end parsedRow()

	/**
	 * Refuse a file with no rows, or with more than one import may carry.
	 *
	 * @param int $count How many rows the file holds.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the count is zero or over the cap.
	 */
	private function guardRowCount(int $count): void {
		if ($count === 0) {
			throw new InvalidArgumentException('The selectielijst file holds no rows');
		}

		if ($count > self::MAX_ROWS) {
			throw new InvalidArgumentException(
				sprintf('The selectielijst file holds %d rows; the import accepts at most %d', $count, self::MAX_ROWS)
			);
		}
	}//end guardRowCount()

	/**
	 * Read the file into raw rows, by the format its name declares.
	 *
	 * @param string $contents  The file contents.
	 * @param string $extension The lower-cased extension.
	 *
	 * @return array<int, mixed> The raw rows.
	 *
	 * @throws InvalidArgumentException When the format is unknown or the contents unparseable.
	 */
	private function rowsFromFile(string $contents, string $extension): array {
		if ($extension === 'csv') {
			return $this->parseCsv(contents: $contents);
		}

		if ($extension !== 'json') {
			throw new InvalidArgumentException(
				sprintf('A selectielijst is imported from a .csv or a .json file, not a ".%s"', $extension)
			);
		}

		return $this->parseJson(contents: $contents);
	}//end rowsFromFile()

	/**
	 * Read a JSON file into raw rows.
	 *
	 * @param string $contents The file contents.
	 *
	 * @return array<int|string, mixed> The raw rows.
	 *
	 * @throws InvalidArgumentException When the contents are not a JSON array or object.
	 */
	private function parseJson(string $contents): array {
		$rows = json_decode($contents, true);
		if (is_array($rows) === false) {
			throw new InvalidArgumentException(
				'The selectielijst file is not readable JSON: ' . json_last_error_msg()
			);
		}

		return $rows;
	}//end parseJson()

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
		$lines = $this->csvLines(contents: $contents);
		$header = array_map(static fn ($column): string => trim((string)$column), str_getcsv((string)array_shift($lines)));

		$rows = [];
		foreach ($lines as $index => $line) {
			if (trim((string)$line) !== '') {
				$rows[] = $this->csvRow(line: (string)$line, header: $header, number: ((int)$index + 1));
			}
		}

		return $rows;
	}//end parseCsv()

	/**
	 * The lines of a CSV file, the header first.
	 *
	 * @param string $contents The file contents.
	 *
	 * @return array<int, string> The lines.
	 *
	 * @throws InvalidArgumentException When the file has no header row.
	 */
	private function csvLines(string $contents): array {
		$lines = preg_split('/\r\n|\r|\n/', trim($contents));
		if ($lines === false || $lines === [] || trim((string)$lines[0]) === '') {
			throw new InvalidArgumentException('The selectielijst CSV has no header row');
		}

		return $lines;
	}//end csvLines()

	/**
	 * One CSV line as a field map under the header.
	 *
	 * @param string             $line   The line.
	 * @param array<int, string> $header The trimmed header columns.
	 * @param int                $number The line's 1-based position after the header.
	 *
	 * @return array<string, string> The row.
	 *
	 * @throws InvalidArgumentException When the column count differs from the header's.
	 */
	private function csvRow(string $line, array $header, int $number): array {
		$values = str_getcsv($line);
		if (count($values) !== count($header)) {
			throw new InvalidArgumentException(
				sprintf(
					'Row %d of the selectielijst CSV has %d columns and the header has %d. A shifted column '
					. 'turns a bewaartermijn into a category, so the import refuses rather than pads.',
					$number,
					count($values),
					count($header)
				)
			);
		}

		return array_combine($header, array_map(static fn ($v): string => trim((string)$v), $values));
	}//end csvRow()

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
		$version = $this->namedVersion(version: $version);
		[$register, $schema] = $this->target();
		$this->guardNewVersion(version: $version);

		$imported = 0;
		foreach ($rows as $row) {
			$row = $this->withVersionAndSource(row: $row, version: $version, source: $source);
			if ($this->storeRow(register: $register, schema: $schema, row: $row, version: $version) === true) {
				$imported++;
			}
		}

		return ['version' => $version, 'imported' => $imported, 'failed' => (count($rows) - $imported)];
	}//end import()

	/**
	 * The version an import is stored under, trimmed.
	 *
	 * @param string $version The version as given.
	 *
	 * @return string The trimmed version.
	 *
	 * @throws InvalidArgumentException When it is blank.
	 */
	private function namedVersion(string $version): string {
		$version = trim($version);
		if ($version === '') {
			throw new InvalidArgumentException(
				'A selectielijst import names the version it is, so the rows it writes can be told from the ones already here'
			);
		}

		return $version;
	}//end namedVersion()

	/**
	 * Refuse a version that is already imported.
	 *
	 * @param string $version The version, trimmed.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When rows of that version are already stored.
	 */
	private function guardNewVersion(string $version): void {
		if (array_key_exists($version, $this->versions()) === true) {
			throw new InvalidArgumentException(
				sprintf(
					'Version "%s" is already imported. Import it under a different name, or compare it with '
					. 'the one in use instead of loading it twice.',
					$version
				)
			);
		}
	}//end guardNewVersion()

	/**
	 * Mark a row with its version, and with the file it came from unless it names its own source.
	 *
	 * @param array<string, mixed> $row     The parsed row.
	 * @param string               $version The version.
	 * @param string|null          $source  Where the file came from.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function withVersionAndSource(array $row, string $version, ?string $source): array {
		$row[self::VERSION_KEY] = $version;
		if ($source !== null) {
			$row['bron'] = ($row['bron'] ?? $source);
		}

		return $row;
	}//end withVersionAndSource()

	/**
	 * Store one row; a row that cannot be stored is logged and counted as failed.
	 *
	 * @param mixed                $register The selectielijst register.
	 * @param mixed                $schema   The selectielijst schema.
	 * @param array<string, mixed> $row      The row.
	 * @param string               $version  The version, for the log line.
	 *
	 * @return bool True when the row was stored.
	 */
	private function storeRow(mixed $register, mixed $schema, array $row, string $version): bool {
		try {
			// The ENTITIES, not their ids. saveObject() accepts either, and a
			// register or schema whose id is not yet materialised would be
			// passed as null, which its signature refuses.
			$this->saveObject->saveObject($register, $schema, $row);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'[SelectielijstImportService] Could not store row '
				. (string)$row[self::CATEGORY_KEY] . ' of version ' . $version . ': ' . $e->getMessage()
			);
			return false;
		}
	}//end storeRow()

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
		$left = $this->storedRowsOf(version: $from);
		$right = $this->storedRowsOf(version: $to);

		return [
			'from' => $from,
			'to' => $to,
			'added' => array_values(array_diff(array_keys($right), array_keys($left))),
			'removed' => array_values(array_diff(array_keys($left), array_keys($right))),
			'changed' => $this->changedCategories(left: $left, right: $right),
		];
	}//end diff()

	/**
	 * The rows of a version that must hold some.
	 *
	 * @param string $version The version.
	 *
	 * @return array<string, array<string, mixed>> Category mapped to the row.
	 *
	 * @throws InvalidArgumentException When the version holds no rows.
	 */
	private function storedRowsOf(string $version): array {
		$rows = $this->rowsOf(version: $version);
		if ($rows === []) {
			throw new InvalidArgumentException(sprintf('No selectielijst rows are stored under version "%s"', $version));
		}

		return $rows;
	}//end storedRowsOf()

	/**
	 * Every category in both versions whose compared fields differ.
	 *
	 * @param array<string, array<string, mixed>> $left  The version in use.
	 * @param array<string, array<string, mixed>> $right The version being considered.
	 *
	 * @return array<int, array<string, mixed>> The category and the fields that change.
	 */
	private function changedCategories(array $left, array $right): array {
		$changed = [];
		foreach ($right as $category => $row) {
			if (array_key_exists($category, $left) === false) {
				continue;
			}

			$fields = $this->changedFields(before: $left[$category], after: $row);
			if ($fields !== []) {
				$changed[] = ['category' => (string)$category, 'fields' => $fields];
			}
		}

		return $changed;
	}//end changedCategories()

	/**
	 * The compared fields that differ between two rows of one category.
	 *
	 * @param array<string, mixed> $before The row in the version in use.
	 * @param array<string, mixed> $after  The row in the version being considered.
	 *
	 * @return array<string, array{from: mixed, to: mixed}> The changes.
	 */
	private function changedFields(array $before, array $after): array {
		$fields = [];
		foreach (self::COMPARED_FIELDS as $field) {
			$old = ($before[$field] ?? null);
			$new = ($after[$field] ?? null);
			if ($old !== $new) {
				$fields[$field] = ['from' => $old, 'to' => $new];
			}
		}

		return $fields;
	}//end changedFields()

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
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
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
		$rows = [];
		foreach ($this->storedObjects() as $object) {
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
	 * Whatever the store holds in the selectielijst register; nothing when it is unconfigured or unreadable.
	 *
	 * @return array<int, mixed> The stored objects.
	 */
	private function storedObjects(): array {
		try {
			[$register, $schema] = $this->target();
		} catch (InvalidArgumentException $e) {
			return [];
		}

		try {
			return $this->objectMapper->findAll(limit: self::MAX_ROWS, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning('[SelectielijstImportService] Could not read the selectielijst: ' . $e->getMessage());
			return [];
		}
	}//end storedObjects()

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
