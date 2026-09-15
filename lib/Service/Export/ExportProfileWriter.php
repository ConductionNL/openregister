<?php

/**
 * ExportProfileWriter — one profile, one field order, one value mode, stated.
 *
 * A file that mixes raw codes and rendered labels is a file somebody has to
 * guess about, and a file whose column order follows whichever screen was open
 * last is one a receiving system cannot read by position. The profile settles
 * both, once, and the file says which answer it got (design D-4).
 *
 * WHERE THE MODE IS WRITTEN. A JSON export carries it in the envelope beside
 * the rows. A CSV has nowhere to put it, so the writer puts it on a first line
 * beginning with `#`, ahead of the header row. That line is part of the
 * contract, not decoration: a consumer skips it the way it skips any comment
 * line, and it is the only thing standing between a receiving system and
 * inferring the mode from the data. The same values go out as response headers
 * for callers who would rather not parse anything.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\CacheHandler;
use Throwable;

/**
 * Projects objects onto a profile's field set and writes them out.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportProfileWriter {
	/**
	 * The prefix of the CSV metadata line.
	 *
	 * @var string
	 */
	public const CSV_METADATA_PREFIX = '#openregister-export ';

	/**
	 * Wire the name resolver.
	 *
	 * @param CacheHandler $cacheHandler Uuid to name resolution for rendered relations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CacheHandler $cacheHandler,
	) {
	}//end __construct()

	/**
	 * Write the objects the profile declares, in the order it declares them.
	 *
	 * @param ExportProfile  $profile The profile.
	 * @param ObjectEntity[] $objects The objects to write.
	 * @param Schema|null    $schema  The schema, when it resolves, for enum labels and relation hints.
	 *
	 * @return array{bytes: string, rowCount: int, metadata: array<string, mixed>} The file and what it is.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function write(ExportProfile $profile, array $objects, ?Schema $schema = null): array {
		$fields = $profile->getFieldsArray();
		$rows = $this->rows(profile: $profile, objects: $objects, fields: $fields, schema: $schema);

		$metadata = [
			'profile' => ($profile->getName() ?? ''),
			'profileUuid' => ($profile->getUuid() ?? ''),
			'valueMode' => ($profile->getValueMode() ?? ExportProfile::MODE_STORED),
			'format' => ($profile->getFormat() ?? 'csv'),
			'fields' => $fields,
			'rowCount' => count($rows),
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		if (($profile->getFormat() ?? 'csv') === 'json') {
			return [
				'bytes' => $this->toJson(metadata: $metadata, rows: $rows, fields: $fields),
				'rowCount' => count($rows),
				'metadata' => $metadata,
			];
		}

		return [
			'bytes' => $this->toCsv(metadata: $metadata, rows: $rows, fields: $fields),
			'rowCount' => count($rows),
			'metadata' => $metadata,
		];
	}//end write()

	/**
	 * One CSV data line for a single object, with no header and no metadata.
	 *
	 * The whole-set extract writes one object at a time into a file that is
	 * already open, so it needs the row without the envelope the request path
	 * builds around it.
	 *
	 * @param ExportProfile $profile The profile.
	 * @param ObjectEntity  $object  The object.
	 * @param Schema|null   $schema  The schema, when it resolves.
	 *
	 * @return string The CSV line, newline terminated.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function csvLineFor(ExportProfile $profile, ObjectEntity $object, ?Schema $schema = null): string {
		$fields = $profile->getFieldsArray();
		$rows = $this->rows(profile: $profile, objects: [$object], fields: $fields, schema: $schema);
		if ($rows === []) {
			return '';
		}

		return $this->csvRow(row: $rows[0], fields: $fields) . "\n";
	}//end csvLineFor()

	/**
	 * The CSV header line for a profile, with its metadata line above it.
	 *
	 * @param ExportProfile $profile The profile.
	 *
	 * @return string The two opening lines of a whole-set file.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function csvOpeningFor(ExportProfile $profile): string {
		$fields = $profile->getFieldsArray();
		$metadata = [
			'profile' => ($profile->getName() ?? ''),
			'profileUuid' => ($profile->getUuid() ?? ''),
			'valueMode' => ($profile->getValueMode() ?? ExportProfile::MODE_STORED),
			'format' => 'csv',
			'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		return $this->metadataLine(metadata: $metadata) . "\n"
			. implode(',', array_map([$this, 'csvCell'], $fields)) . "\n";
	}//end csvOpeningFor()

	/**
	 * Project every object onto the field set.
	 *
	 * @param ExportProfile  $profile The profile.
	 * @param ObjectEntity[] $objects The objects.
	 * @param array<int, string> $fields The ordered field set.
	 * @param Schema|null    $schema  The schema, when it resolves.
	 *
	 * @return array<int, array<string, mixed>> One map per object, keyed by field.
	 */
	private function rows(ExportProfile $profile, array $objects, array $fields, ?Schema $schema): array {
		$rendered = (($profile->getValueMode() ?? ExportProfile::MODE_STORED) === ExportProfile::MODE_RENDERED);
		$names = [];
		if ($rendered === true) {
			$names = $this->nameMap(objects: $objects, fields: $fields);
		}

		$properties = [];
		if ($schema !== null) {
			$properties = $schema->getProperties();
		}

		$rows = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity === false) {
				continue;
			}

			$row = [];
			foreach ($fields as $field) {
				$raw = $this->rawValue(object: $object, field: $field);
				if ($rendered === false) {
					$row[$field] = $this->stored(value: $raw);
					continue;
				}

				$row[$field] = $this->rendered(
					value: $raw,
					property: ($properties[$field] ?? []),
					names: $names
				);
			}

			$rows[] = $row;
		}//end foreach

		return $rows;
	}//end rows()

	/**
	 * The value the object holds for one declared field.
	 *
	 * `@self.` addresses the metadata the object carries beside its data, the
	 * same prefix the list and the spreadsheet export already use, so a profile
	 * is written in the vocabulary an administrator already has.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string       $field  The declared field.
	 *
	 * @return mixed The raw value, or null when the object does not carry it.
	 */
	private function rawValue(ObjectEntity $object, string $field) {
		if (str_starts_with(haystack: $field, needle: '@self.') === true) {
			$meta = substr(string: $field, offset: 6);

			return ($object->getObjectArray()[$meta] ?? null);
		}

		return ($object->getObject()[$field] ?? null);
	}//end rawValue()

	/**
	 * A value written as the object holds it.
	 *
	 * Nothing is resolved and nothing is reformatted. A structure is JSON
	 * encoded rather than flattened, because flattening it would be a rendering
	 * decision, and this mode makes none.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The cell.
	 */
	private function stored($value): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			return ($value === true ? 'true' : 'false');
		}

		if (is_array($value) === true || is_object($value) === true) {
			return (string)json_encode($value);
		}

		return (string)$value;
	}//end stored()

	/**
	 * A value written as a surface would show it.
	 *
	 * @param mixed                $value    The raw value.
	 * @param array<string, mixed> $property The schema property, when the schema resolved.
	 * @param array<string, string> $names   Uuid to object name map.
	 *
	 * @return string The cell.
	 */
	private function rendered($value, array $property, array $names): string {
		if ($value === null) {
			return '';
		}

		if (is_bool($value) === true) {
			return ($value === true ? 'yes' : 'no');
		}

		if (is_array($value) === true) {
			$parts = [];
			foreach ($value as $item) {
				$parts[] = $this->rendered(value: $item, property: $property, names: $names);
			}

			return implode('; ', $parts);
		}

		if (is_object($value) === true) {
			return (string)json_encode($value);
		}

		$scalar = (string)$value;

		if (isset($names[$scalar]) === true) {
			return $names[$scalar];
		}

		$label = $this->enumLabel(value: $scalar, property: $property);
		if ($label !== null) {
			return $label;
		}

		return $this->formatDate(value: $scalar);
	}//end rendered()

	/**
	 * The administered label for a code list value, when the schema names one.
	 *
	 * JSON Schema has no label field of its own, so the convention every editor
	 * settled on is used: `enumNames` parallel to `enum`. A schema that declares
	 * an enum and no names has no labels to render, and the code is the label.
	 *
	 * @param string               $value    The stored value.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return string|null The label, or null when there is none.
	 */
	private function enumLabel(string $value, array $property): ?string {
		$enum = ($property['enum'] ?? null);
		$labels = ($property['enumNames'] ?? ($property['enumLabels'] ?? null));
		if (is_array($enum) === false || is_array($labels) === false) {
			return null;
		}

		$index = array_search($value, $enum, true);
		if ($index === false) {
			return null;
		}

		$label = ($labels[$index] ?? null);
		if (is_string($label) === false) {
			return null;
		}

		return $label;
	}//end enumLabel()

	/**
	 * An ISO 8601 timestamp as a surface would show it, or the value unchanged.
	 *
	 * @param string $value The stored value.
	 *
	 * @return string The cell.
	 */
	private function formatDate(string $value): string {
		if (str_contains(haystack: $value, needle: 'T') === false) {
			return $value;
		}

		try {
			return (new DateTime($value))->format('Y-m-d H:i:s');
		} catch (Throwable $e) {
			return $value;
		}
	}//end formatDate()

	/**
	 * Resolve every uuid the declared fields carry to an object name.
	 *
	 * @param ObjectEntity[] $objects The objects.
	 * @param array<int, string> $fields The ordered field set.
	 *
	 * @return array<string, string> Uuid to name.
	 */
	private function nameMap(array $objects, array $fields): array {
		$map = [];
		$uuids = [];

		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity === false) {
				continue;
			}

			$uuid = $object->getUuid();
			$name = $object->getName();
			if ($uuid !== null && $name !== null) {
				$map[$uuid] = $name;
			}

			$data = $object->getObject();
			foreach ($fields as $field) {
				$this->collectUuids(value: ($data[$field] ?? null), uuids: $uuids);
			}
		}

		$missing = array_values(array_diff(array_unique($uuids), array_keys($map)));
		if ($missing === []) {
			return $map;
		}

		try {
			return array_merge($map, $this->cacheHandler->getMultipleObjectNames($missing));
		} catch (Throwable $e) {
			// A name that cannot be resolved renders as the uuid it already was.
			// Failing the whole export over one unresolvable relation would turn
			// a cosmetic gap into a missed aanlevering.
			return $map;
		}
	}//end nameMap()

	/**
	 * Collect the uuid-shaped strings out of a value.
	 *
	 * @param mixed $value The value.
	 * @param array<int, string> $uuids The collected uuids, by reference.
	 *
	 * @return void
	 */
	private function collectUuids($value, array &$uuids): void {
		if (is_array($value) === true) {
			foreach ($value as $item) {
				$this->collectUuids(value: $item, uuids: $uuids);
			}

			return;
		}

		if (is_string($value) === false) {
			return;
		}

		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
			$uuids[] = $value;
		}
	}//end collectUuids()

	/**
	 * The file as JSON, with the metadata in the envelope.
	 *
	 * @param array<string, mixed> $metadata The metadata.
	 * @param array<int, array<string, mixed>> $rows The projected rows.
	 * @param array<int, string> $fields The ordered field set.
	 *
	 * @return string The bytes.
	 */
	private function toJson(array $metadata, array $rows, array $fields): string {
		$ordered = [];
		foreach ($rows as $row) {
			$entry = [];
			foreach ($fields as $field) {
				$entry[$field] = ($row[$field] ?? '');
			}

			$ordered[] = $entry;
		}

		return (string)json_encode(
			['export' => $metadata, 'results' => $ordered],
			(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		);
	}//end toJson()

	/**
	 * The file as CSV, with the metadata on its first line.
	 *
	 * @param array<string, mixed> $metadata The metadata.
	 * @param array<int, array<string, mixed>> $rows The projected rows.
	 * @param array<int, string> $fields The ordered field set.
	 *
	 * @return string The bytes.
	 */
	private function toCsv(array $metadata, array $rows, array $fields): string {
		$lines = [
			$this->metadataLine(metadata: $metadata),
			implode(',', array_map([$this, 'csvCell'], $fields)),
		];

		foreach ($rows as $row) {
			$lines[] = $this->csvRow(row: $row, fields: $fields);
		}

		return implode("\n", $lines) . "\n";
	}//end toCsv()

	/**
	 * The metadata line that opens a CSV export.
	 *
	 * @param array<string, mixed> $metadata The metadata.
	 *
	 * @return string The line, without its newline.
	 */
	private function metadataLine(array $metadata): string {
		$parts = [];
		foreach (['profile', 'profileUuid', 'valueMode', 'generatedAt'] as $key) {
			$parts[] = $key . '=' . (string)($metadata[$key] ?? '');
		}

		return self::CSV_METADATA_PREFIX . implode(' ', $parts);
	}//end metadataLine()

	/**
	 * One CSV row, in the profile's field order.
	 *
	 * @param array<string, mixed> $row The projected row.
	 * @param array<int, string> $fields The ordered field set.
	 *
	 * @return string The line, without its newline.
	 */
	private function csvRow(array $row, array $fields): string {
		$cells = [];
		foreach ($fields as $field) {
			$cells[] = $this->csvCell(value: (string)($row[$field] ?? ''));
		}

		return implode(',', $cells);
	}//end csvRow()

	/**
	 * Quote one CSV cell.
	 *
	 * @param string $value The cell value.
	 *
	 * @return string The quoted cell.
	 */
	private function csvCell(string $value): string {
		return '"' . str_replace('"', '""', $value) . '"';
	}//end csvCell()
}//end class
