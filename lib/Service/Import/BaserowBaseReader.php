<?php

/**
 * BaserowBaseReader: read a Baserow database export into a neutral set of tables.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use InvalidArgumentException;
use ZipArchive;

/**
 * Reads Baserow's serialised application export: a list of applications, each
 * database holding tables with `fields` (id, type, name, primary, and for a
 * link `link_row_table_id`) and `rows` keyed `field_<id>`.
 *
 * The export comes as a zip archive holding that JSON, or as the JSON itself.
 * Exactly one database application is read; an archive holding several is
 * refused naming them, so the administrator exports the base they mean.
 * Computed fields (formula, lookup, rollup, count, created/modified stamps)
 * carry no stored value and are left out.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
final class BaserowBaseReader implements BaseSetReaderInterface {

	/**
	 * Baserow field types without a stored value.
	 *
	 * @var array<int, string>
	 */
	private const COMPUTED = [
		'formula',
		'lookup',
		'rollup',
		'count',
		'created_on',
		'last_modified',
		'created_by',
		'last_modified_by',
		'autonumber',
		'uuid',
	];

	/**
	 * Baserow field types by the neutral type they read as; any other type reads as a string.
	 *
	 * @var array<string, string>
	 */
	private const TYPES = [
		'long_text' => BaseColumn::TYPE_TEXT,
		'rating' => BaseColumn::TYPE_INTEGER,
		'boolean' => BaseColumn::TYPE_BOOLEAN,
		'email' => BaseColumn::TYPE_EMAIL,
		'url' => BaseColumn::TYPE_URL,
		'multiple_select' => BaseColumn::TYPE_LIST,
	];

	/**
	 * Constructor.
	 *
	 * @param BaserowCells $cells The cell conversion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function __construct(
		private readonly BaserowCells $cells=new BaserowCells(),
	) {
	}//end __construct()

	/**
	 * The product this reader reads.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function format(): string {
		return 'baserow';
	}//end format()

	/**
	 * Read a Baserow export (zip or JSON) into a set of tables.
	 *
	 * @param string $filePath Path to the export on disk.
	 *
	 * @return BaseSet The base.
	 *
	 * @throws InvalidArgumentException When the file is not a Baserow export of exactly one database.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function read(string $filePath): BaseSet {
		$database = $this->database(applications: $this->applications(filePath: $filePath));

		$tables = ($database['tables'] ?? []);
		if (is_array($tables) === false || $tables === []) {
			throw new InvalidArgumentException('The Baserow database "'.$this->name(item: $database).'" holds no tables.');
		}

		$namesById = [];
		foreach ($tables as $table) {
			$namesById[(string) ($table['id'] ?? '')] = $this->name(item: $table);
		}

		$read = [];
		foreach ($tables as $table) {
			$read[] = $this->table(table: $table, namesById: $namesById);
		}

		return new BaseSet(name: $this->name(item: $database), source: $this->format(), tables: $read);
	}//end read()

	/**
	 * The application list in the export, from a zip archive or a JSON file.
	 *
	 * @param string $filePath Path to the export on disk.
	 *
	 * @return array<int, array<string, mixed>> The applications.
	 *
	 * @throws InvalidArgumentException When no application list can be read.
	 */
	private function applications(string $filePath): array {
		foreach ($this->jsonDocuments(filePath: $filePath) as $document) {
			$decoded = json_decode($document, true);
			if (is_array($decoded) === true && isset($decoded['applications']) === true) {
				$decoded = $decoded['applications'];
			}

			if (is_array($decoded) === true && array_is_list($decoded) === true && $this->databases(applications: $decoded) !== []) {
				return $decoded;
			}
		}

		throw new InvalidArgumentException('The file is not a Baserow export: no database application was found in it.');
	}//end applications()

	/**
	 * The JSON documents in the file: every .json entry of a zip, or the file itself.
	 *
	 * @param string $filePath Path to the export on disk.
	 *
	 * @return array<int, string> The documents.
	 *
	 * @throws InvalidArgumentException When the file cannot be read.
	 */
	private function jsonDocuments(string $filePath): array {
		if (is_file($filePath) === false || is_readable($filePath) === false) {
			throw new InvalidArgumentException('The Baserow export "'.basename($filePath).'" cannot be read.');
		}

		$zip = new ZipArchive();
		if ($zip->open($filePath, ZipArchive::RDONLY) !== true) {
			return [(string) file_get_contents($filePath)];
		}

		$documents = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$entry = (string) $zip->getNameIndex($index);
			if (str_ends_with(strtolower($entry), '.json') === true) {
				$documents[] = (string) $zip->getFromIndex($index);
			}
		}

		$zip->close();
		return $documents;
	}//end jsonDocuments()

	/**
	 * The one database application to read.
	 *
	 * @param array<int, array<string, mixed>> $applications The applications in the export.
	 *
	 * @return array<string, mixed> The database.
	 *
	 * @throws InvalidArgumentException When the export holds more than one database.
	 */
	private function database(array $applications): array {
		$databases = $this->databases(applications: $applications);
		if (count($databases) > 1) {
			$names = array_map(fn (array $database): string => '"'.$this->name(item: $database).'"', $databases);
			throw new InvalidArgumentException(
				'The Baserow export holds '.count($databases).' databases ('.implode(', ', $names).'); export the one base to import.'
			);
		}

		return $databases[0];
	}//end database()

	/**
	 * The database applications among the applications.
	 *
	 * @param array<int, mixed> $applications The applications.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function databases(array $applications): array {
		return array_values(
			array_filter(
				$applications,
				static fn (mixed $application): bool => is_array($application) === true
					&& ($application['type'] ?? null) === 'database'
					&& isset($application['tables']) === true
			)
		);
	}//end databases()

	/**
	 * One Baserow table as a BaseTable.
	 *
	 * @param array<string, mixed>  $table     The serialised table.
	 * @param array<string, string> $namesById Table names by Baserow table id.
	 *
	 * @return BaseTable
	 */
	private function table(array $table, array $namesById): BaseTable {
		$columns = [];
		$fields  = [];
		foreach (($table['fields'] ?? []) as $field) {
			if (is_array($field) === false || in_array(($field['type'] ?? ''), self::COMPUTED, true) === true) {
				continue;
			}

			$columns[] = $this->column(field: $field, namesById: $namesById);
			$fields[]  = $field;
		}

		$rows = [];
		foreach (($table['rows'] ?? []) as $row) {
			if (is_array($row) === true) {
				$rows[(string) ($row['id'] ?? count($rows))] = $this->row(row: $row, fields: $fields);
			}
		}

		return new BaseTable(name: $this->name(item: $table), columns: $columns, rows: $rows);
	}//end table()

	/**
	 * One Baserow field as a BaseColumn.
	 *
	 * @param array<string, mixed>  $field     The serialised field.
	 * @param array<string, string> $namesById Table names by Baserow table id.
	 *
	 * @return BaseColumn
	 */
	private function column(array $field, array $namesById): BaseColumn {
		$name    = $this->name(item: $field);
		$primary = (($field['primary'] ?? false) === true);
		$type    = (string) ($field['type'] ?? 'text');

		if ($type === 'link_row') {
			return new BaseColumn(
				name: $name,
				type: BaseColumn::TYPE_LINK,
				linkTable: ($namesById[(string) ($field['link_row_table_id'] ?? '')] ?? null),
				multiple: (($field['link_row_multiple_relationships'] ?? true) !== false),
				primary: $primary
			);
		}

		return new BaseColumn(name: $name, type: $this->neutralType(field: $field), primary: $primary);
	}//end column()

	/**
	 * The neutral type of a non-link Baserow field.
	 *
	 * @param array<string, mixed> $field The serialised field.
	 *
	 * @return string One of the BaseColumn::TYPE_* constants.
	 */
	private function neutralType(array $field): string {
		$type = (string) ($field['type'] ?? 'text');
		if ($type === 'number') {
			if ((int) ($field['number_decimal_places'] ?? 0) === 0) {
				return BaseColumn::TYPE_INTEGER;
			}

			return BaseColumn::TYPE_NUMBER;
		}

		if ($type === 'date') {
			if (($field['date_include_time'] ?? false) === true) {
				return BaseColumn::TYPE_DATETIME;
			}

			return BaseColumn::TYPE_DATE;
		}

		return (self::TYPES[$type] ?? BaseColumn::TYPE_STRING);
	}//end neutralType()

	/**
	 * One Baserow row's values by column name.
	 *
	 * @param array<string, mixed>             $row    The serialised row (`field_<id>` keys).
	 * @param array<int, array<string, mixed>> $fields The stored fields of its table.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $row, array $fields): array {
		$values = [];
		foreach ($fields as $field) {
			$raw = ($row['field_'.($field['id'] ?? '')] ?? null);
			$values[$this->name(item: $field)] = $this->cells->value(raw: $raw, field: $field);
		}

		return $values;
	}//end row()

	/**
	 * The `name` of a serialised item.
	 *
	 * @param array<string, mixed> $item The item.
	 *
	 * @return string
	 */
	private function name(array $item): string {
		return trim((string) ($item['name'] ?? ''));
	}//end name()
}//end class
