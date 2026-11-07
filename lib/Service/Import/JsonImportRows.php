<?php

/**
 * Resolves the rows of a JSON import to a uuid and a body.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Service\MigrationPack\MappingEngine;

/**
 * One resolution pass over the rows of a JSON import for one schema.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class JsonImportRows {

	/**
	 * Constructor.
	 *
	 * @param MappingEngine      $mappingEngine The migration pack engine.
	 * @param array|null         $pack          The migration pack, or null for plain rows.
	 * @param array<string, int> $propertyKeys  The schema's property names, as keys.
	 */
	public function __construct(
		private readonly MappingEngine $mappingEngine,
		private readonly ?array $pack,
		private readonly array $propertyKeys,
	) {
	}//end __construct()

	/**
	 * Resolve every row; an entry that is not an array is left out.
	 *
	 * @param array<int|string, mixed> $objects The rows.
	 *
	 * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function resolve(array $objects): array {
		$summary = self::emptySummary(found: count($objects));
		$rows = [];
		foreach (array_filter($objects, 'is_array') as $rowIndex => $raw) {
			// Keys are list indexes for a JSON array export; the cast guards an object-with-string-keys envelope.
			$resolved = $this->resolveRow(raw: $raw, rowNumber: ((int)$rowIndex + 1));
			array_push($summary['errors'], ...$resolved['errors']);
			array_push($summary['warnings'], ...array_filter([$resolved['warning']]));
			array_push($rows, ...array_filter([$resolved['row']]));
		}

		return ['rows' => $rows, 'summary' => $summary];
	}//end resolve()

	/**
	 * The summary before anything is saved.
	 *
	 * @param int $found The number of rows found.
	 *
	 * @return array<string, mixed>
	 */
	private static function emptySummary(int $found): array {
		return [
			'found' => $found,
			'created' => [],
			'updated' => [],
			'unchanged' => [],
			'errors' => [],
			// Non-fatal findings, such as a dropped undeclared property.
			'warnings' => [],
		];
	}//end emptySummary()

	/**
	 * Resolve one row, through the pack when there is one.
	 *
	 * @param array<string, mixed> $raw       The row.
	 * @param int                  $rowNumber Its 1-based number.
	 *
	 * @return array{row: array<string, mixed>|null, errors: list<array<string, mixed>>, warning: string|null}
	 */
	private function resolveRow(array $raw, int $rowNumber): array {
		if ($this->pack === null) {
			return $this->plainRow(raw: $raw, rowNumber: $rowNumber);
		}

		if ($this->mappingEngine->isRowSkipped(pack: $this->pack, rowNumber: $rowNumber) === true) {
			return ['row' => null, 'errors' => [], 'warning' => null];
		}

		return self::mappedRow(
			raw: $raw,
			mapped: $this->mappingEngine->mapRow(pack: $this->pack, sourceRow: $raw, rowNumber: $rowNumber)
		);
	}//end resolveRow()

	/**
	 * A row as the pack mapped it, or its errors.
	 *
	 * @param array<string, mixed> $raw    The source row.
	 * @param array<string, mixed> $mapped The outcome of the mapping.
	 *
	 * @return array{row: array<string, mixed>|null, errors: list<array<string, mixed>>, warning: null}
	 */
	private static function mappedRow(array $raw, array $mapped): array {
		if (empty($mapped['errors']) === false) {
			$format = new MappingErrorRow();
			$errors = array_map(fn (array $error): array => $format->format(error: $error), $mapped['errors']);
			return ['row' => null, 'errors' => array_values($errors), 'warning' => null];
		}

		$body = $mapped['data'];
		$uuid = self::uuidOf(value: ($body['id'] ?? null));
		unset($body['id']);

		return ['row' => ['uuid' => $uuid, 'body' => $body, 'name' => ($raw['name'] ?? $uuid)], 'errors' => [], 'warning' => null];
	}//end mappedRow()

	/**
	 * A row without a pack: the declared properties, an empty string read as null.
	 *
	 * @param array<string, mixed> $raw       The row.
	 * @param int                  $rowNumber Its 1-based number.
	 *
	 * @return array{row: array<string, mixed>, errors: array{}, warning: string|null}
	 */
	private function plainRow(array $raw, int $rowNumber): array {
		$uuid = self::uuidOf(value: ($raw['@self']['id'] ?? $raw['id'] ?? $raw['uuid'] ?? null));
		$body = array_intersect_key($raw, $this->propertyKeys);
		foreach (array_keys($body, '', true) as $key) {
			$body[$key] = null;
		}

		return [
			'row' => ['uuid' => $uuid, 'body' => $body, 'name' => ($raw['name'] ?? $uuid)],
			'errors' => [],
			'warning' => self::droppedWarning(dropped: $this->droppedKeys(raw: $raw), rowNumber: $rowNumber),
		];
	}//end plainRow()

	/**
	 * A row's uuid as text.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null The uuid, or null when the row names none.
	 */
	private static function uuidOf(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end uuidOf()

	/**
	 * The user data keys of a row the schema does not declare.
	 *
	 * @param array<string, mixed> $raw The row.
	 *
	 * @return list<string>
	 */
	private function droppedKeys(array $raw): array {
		$names = array_map('strval', array_keys($raw));
		return array_values(
			array_filter(
				$names,
				fn (string $name): bool => array_key_exists($name, $this->propertyKeys) === false && self::isUserDataKey(key: $name) === true
			)
		);
	}//end droppedKeys()

	/**
	 * Whether a key holds user data, not an identifier or metadata.
	 *
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private static function isUserDataKey(string $key): bool {
		return $key !== '' && $key !== 'id' && $key !== 'uuid' && $key[0] !== '@' && $key[0] !== '_';
	}//end isUserDataKey()

	/**
	 * The warning for dropped keys.
	 *
	 * @param list<string> $dropped   The dropped keys.
	 * @param int          $rowNumber The row's 1-based number.
	 *
	 * @return string|null The warning, or null when nothing was dropped.
	 */
	private static function droppedWarning(array $dropped, int $rowNumber): ?string {
		if ($dropped === []) {
			return null;
		}

		$plural = 'ies';
		if (count($dropped) === 1) {
			$plural = 'y';
		}

		return sprintf('Row %d: dropped %d propert%s the schema does not declare: %s.', $rowNumber, count($dropped), $plural, implode(', ', $dropped));
	}//end droppedWarning()
}//end class
