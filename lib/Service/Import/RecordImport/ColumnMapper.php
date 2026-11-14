<?php

/**
 * ColumnMapper: proposes and confirms how a record file's columns map onto a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import\RecordImport;

/**
 * Column mapping is proposed, never applied silently.
 *
 * The proposal matches a column to a property by exact name first, then by
 * name ignoring case and separators (`Post code`, `post_code` and `postCode`
 * are one name). Every other column is listed as unmatched. Confirmation
 * refuses while any column is neither mapped nor explicitly ignored, and
 * never picks an identifying column on the user's behalf.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
class ColumnMapper {

	/**
	 * The proposed mapping.
	 *
	 * @param list<string> $columns    The file's columns.
	 * @param list<string> $properties The schema's property names.
	 *
	 * @return array{mapped: array<string, string>, unmatched: list<string>} Column => property, and the columns without one.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function propose(array $columns, array $properties): array {
		$mapped = [];
		$taken = [];
		foreach ($columns as $column) {
			if (in_array($column, $properties, true) === true) {
				$mapped[$column] = $column;
				$taken[$column] = true;
			}
		}

		$byLooseName = [];
		foreach ($properties as $property) {
			$byLooseName[self::looseName(name: $property)][] = $property;
		}

		foreach ($columns as $column) {
			if (isset($mapped[$column]) === true) {
				continue;
			}

			$candidates = array_values(array_filter(
				($byLooseName[self::looseName(name: $column)] ?? []),
				static fn (string $property): bool => isset($taken[$property]) === false
			));
			// Two properties with the same loose name are ambiguous: the user decides.
			if (count($candidates) === 1) {
				$mapped[$column] = $candidates[0];
				$taken[$candidates[0]] = true;
			}
		}

		$ordered = [];
		foreach ($columns as $column) {
			if (isset($mapped[$column]) === true) {
				$ordered[$column] = $mapped[$column];
			}
		}

		return [
			'mapped'    => $ordered,
			'unmatched' => array_values(array_filter($columns, static fn (string $column): bool => isset($ordered[$column]) === false)),
		];
	}//end propose()

	/**
	 * Confirm the user's decisions for every column.
	 *
	 * @param list<string>               $columns    The file's columns.
	 * @param list<string>               $properties The schema's property names.
	 * @param array<string, string|null> $decisions  Column => property, or null to ignore the column.
	 * @param string|null                $identifier The property that identifies an existing object, or null to create only.
	 *
	 * @return ConfirmedMapping The mapping to import with.
	 *
	 * @throws UnconfirmedMappingException While a column is undecided, or a decision names no property of the schema.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function confirm(array $columns, array $properties, array $decisions, ?string $identifier = null): ConfirmedMapping {
		$undecided = array_values(array_filter($columns, static fn (string $column): bool => array_key_exists($column, $decisions) === false));
		if ($undecided !== []) {
			throw new UnconfirmedMappingException(
				message: 'Map or ignore every column before importing: ' . implode(', ', $undecided) . '.',
				columns: $undecided
			);
		}

		$map = [];
		$ignored = [];
		foreach ($columns as $column) {
			$property = $decisions[$column];
			if ($property === null) {
				$ignored[] = $column;
				continue;
			}

			if (in_array($property, $properties, true) === false) {
				throw new UnconfirmedMappingException(
					message: sprintf('Column "%s" is mapped to "%s", which the schema does not have.', $column, $property),
					columns: [$column]
				);
			}

			if (in_array($property, $map, true) === true) {
				throw new UnconfirmedMappingException(message: sprintf('Two columns are mapped to "%s".', $property), columns: [$column]);
			}

			$map[$column] = $property;
		}//end foreach

		if ($identifier !== null && in_array($identifier, $map, true) === false) {
			throw new UnconfirmedMappingException(message: sprintf('The identifying property "%s" is not mapped from any column.', $identifier), columns: []);
		}

		return new ConfirmedMapping(map: $map, ignored: $ignored, identifier: $identifier);
	}//end confirm()

	/**
	 * A name without case or separators.
	 *
	 * @param string $name The name.
	 *
	 * @return string The loose name.
	 */
	private static function looseName(string $name): string {
		return (string)preg_replace('/[\s_\-.]+/u', '', mb_strtolower(trim($name)));
	}//end looseName()
}//end class
