<?php

/**
 * BaseSchemaProposer: propose a register, schemas and relations for a base read from another product.
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

/**
 * Turns a BaseSet into a proposal the administrator confirms before anything
 * is written: one register, one schema per table with a property per column,
 * and a relation for every link column between tables.
 *
 * A link to a row that is not in the set is not a failure: it becomes a
 * per-row outcome naming the row and the missing row, and the row is
 * imported without that link.
 *
 * Writes nothing.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
class BaseSchemaProposer {

	/**
	 * JSON-schema property fragments by neutral column type (links are built separately).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PROPERTIES = [
		BaseColumn::TYPE_STRING => ['type' => 'string'],
		BaseColumn::TYPE_TEXT => ['type' => 'string'],
		BaseColumn::TYPE_INTEGER => ['type' => 'integer'],
		BaseColumn::TYPE_NUMBER => ['type' => 'number'],
		BaseColumn::TYPE_BOOLEAN => ['type' => 'boolean'],
		BaseColumn::TYPE_DATE => ['type' => 'string', 'format' => 'date'],
		BaseColumn::TYPE_DATETIME => ['type' => 'string', 'format' => 'date-time'],
		BaseColumn::TYPE_EMAIL => ['type' => 'string', 'format' => 'email'],
		BaseColumn::TYPE_URL => ['type' => 'string', 'format' => 'uri'],
		BaseColumn::TYPE_LIST => ['type' => 'array', 'items' => ['type' => 'string']],
	];

	/**
	 * Propose the register, schemas and relations for a base.
	 *
	 * @param BaseSet $set The base.
	 *
	 * @return array{
	 *     register: array{title: string, slug: string, source: string},
	 *     schemas: array<string, array{title: string, slug: string, columns: array<string, string>, properties: array<string, array<string, mixed>>}>,
	 *     relations: array<int, array{schema: string, property: string, target: string, multiple: bool}>,
	 *     outcomes: array<int, array{table: string, row: string, column: string, missing: string, message: string}>
	 * } The proposal: schemas keyed by table name, `columns` maps column name to property name.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function propose(BaseSet $set): array {
		$slugs = $this->schemaSlugs(set: $set);

		$schemas   = [];
		$relations = [];
		foreach ($set->tables as $tableName => $table) {
			$columns    = $this->propertyNames(table: $table);
			$properties = [];
			foreach ($table->columns as $column) {
				$property = $columns[$column->name];
				$properties[$property] = $this->property(column: $column, slugs: $slugs);
				if ($column->isLink() === true) {
					$relations[] = [
						'schema' => $slugs[$tableName],
						'property' => $property,
						'target' => $slugs[$column->linkTable],
						'multiple' => $column->multiple,
					];
				}
			}

			$schemas[$tableName] = [
				'title' => $tableName,
				'slug' => $slugs[$tableName],
				'columns' => $columns,
				'properties' => $properties,
			];
		}//end foreach

		return [
			'register' => ['title' => $set->name, 'slug' => $this->slug(value: $set->name, fallback: 'base'), 'source' => $set->source],
			'schemas' => $schemas,
			'relations' => $relations,
			'outcomes' => $this->danglingLinks(set: $set),
		];
	}//end propose()

	/**
	 * The links that point at a row not in the set, one outcome per missing row.
	 *
	 * @param BaseSet $set The base.
	 *
	 * @return array<int, array{table: string, row: string, column: string, missing: string, message: string}>
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function danglingLinks(BaseSet $set): array {
		$outcomes = [];
		foreach ($set->tables as $tableName => $table) {
			foreach ($table->columns as $column) {
				if ($column->isLink() === false) {
					continue;
				}

				$target = $set->table(name: (string) $column->linkTable);
				foreach ($table->rows as $rowId => $values) {
					foreach ((array) ($values[$column->name] ?? []) as $linked) {
						if ($target !== null && $target->hasRow(rowId: (string) $linked) === true) {
							continue;
						}

						$outcomes[] = [
							'table' => $tableName,
							'row' => (string) $rowId,
							'column' => $column->name,
							'missing' => (string) $linked,
							'message' => 'Row '.$rowId.' of "'.$tableName.'" links through "'.$column->name.'" to row '.$linked
								.' of "'.$column->linkTable.'", which is not in the export; it is imported without that link.',
						];
					}
				}
			}//end foreach
		}//end foreach

		return $outcomes;
	}//end danglingLinks()

	/**
	 * One property fragment for a column.
	 *
	 * @param BaseColumn            $column The column.
	 * @param array<string, string> $slugs  Schema slugs by table name.
	 *
	 * @return array<string, mixed>
	 */
	private function property(BaseColumn $column, array $slugs): array {
		$property = ['title' => $column->name];
		if ($column->isLink() === true) {
			$reference = ['type' => 'string', 'format' => 'uuid', '$ref' => $slugs[$column->linkTable]];
			if ($column->multiple === true) {
				return $property + ['type' => 'array', 'items' => $reference];
			}

			return $property + $reference;
		}

		if ($column->type === BaseColumn::TYPE_LINK) {
			// A link into a table outside the base keeps the linked ids as text.
			return $property + self::PROPERTIES[BaseColumn::TYPE_LIST];
		}

		return $property + (self::PROPERTIES[$column->type] ?? self::PROPERTIES[BaseColumn::TYPE_STRING]);
	}//end property()

	/**
	 * Unique schema slugs by table name.
	 *
	 * @param BaseSet $set The base.
	 *
	 * @return array<string, string>
	 */
	private function schemaSlugs(BaseSet $set): array {
		$slugs = [];
		foreach (array_keys($set->tables) as $tableName) {
			$slugs[$tableName] = $this->unique(
				name: $this->slug(value: (string) $tableName, fallback: 'table'),
				taken: $slugs
			);
		}

		return $slugs;
	}//end schemaSlugs()

	/**
	 * Unique property names by column name.
	 *
	 * @param BaseTable $table The table.
	 *
	 * @return array<string, string>
	 */
	private function propertyNames(BaseTable $table): array {
		$names = [];
		foreach ($table->columns as $column) {
			$names[$column->name] = $this->unique(
				name: $this->slug(value: $column->name, fallback: 'column'),
				taken: $names
			);
		}

		return $names;
	}//end propertyNames()

	/**
	 * A name not yet taken: the name itself, else the name with _2, _3, ...
	 *
	 * @param string                $name  The wanted name.
	 * @param array<string, string> $taken The names taken so far.
	 *
	 * @return string
	 */
	private function unique(string $name, array $taken): string {
		$candidate = $name;
		$suffix    = 2;
		while (in_array($candidate, $taken, true) === true) {
			$candidate = $name.'_'.$suffix;
			$suffix++;
		}

		return $candidate;
	}//end unique()

	/**
	 * A lower-case slug of a name; the fallback when nothing of it remains.
	 *
	 * @param string $value    The name.
	 * @param string $fallback The slug when the name has no letters or digits.
	 *
	 * @return string
	 */
	private function slug(string $value, string $fallback): string {
		$ascii = $value;
		if (function_exists('iconv') === true) {
			$converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
			if ($converted !== false) {
				$ascii = $converted;
			}
		}

		$slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)), '_');
		if ($slug === '') {
			return $fallback;
		}

		return $slug;
	}//end slug()
}//end class
