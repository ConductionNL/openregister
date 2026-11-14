<?php

/**
 * Every place OpenRegister holds a Nextcloud file or folder id.
 *
 * Moving the managed tree to another storage gives every file and folder a new
 * id. Each place listed here must follow it, or the reference breaks. A column
 * whose name looks like a file id but holds something else is listed in
 * NOT_FILE_IDS with the reason, so `DocumentReferenceMapTest` can tell a
 * deliberate omission from a forgotten one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
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

namespace OCA\OpenRegister\Service\File;

/**
 * The columns and object properties that hold a moved node's id.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */
final class DocumentReferenceMap {

	/**
	 * A reference to a file.
	 */
	public const KIND_FILE = 'file';

	/**
	 * A reference to a folder.
	 */
	public const KIND_FOLDER = 'folder';

	/**
	 * Columns holding a Nextcloud node id, with the condition a row must meet.
	 *
	 * `where` narrows a column that holds a file id only for some rows (chunks
	 * hold a file id only when their source is a file).
	 *
	 * @var list<array{table: string, column: string, kind: string, where: array<string, string>}>
	 */
	private const COLUMNS = [
		['table' => 'openregister_registers', 'column' => 'folder', 'kind' => self::KIND_FOLDER, 'where' => []],
		['table' => 'openregister_objects', 'column' => 'folder', 'kind' => self::KIND_FOLDER, 'where' => []],
		['table' => 'openregister_file_texts', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
		['table' => 'openregister_chunks', 'column' => 'source_id', 'kind' => self::KIND_FILE, 'where' => ['source_type' => 'file']],
		['table' => 'openregister_anonymisation_log', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
		['table' => 'openregister_entity_relations', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
		['table' => 'openregister_export_runs', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
		['table' => 'openregister_file_restrictions', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
		['table' => 'openregister_file_digests', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
		['table' => 'openregister_text_disclosures', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
		['table' => 'openregister_text_signatures', 'column' => 'file_id', 'kind' => self::KIND_FILE, 'where' => []],
	];

	/**
	 * Columns named like a file id that do not hold one, as "table.column" => reason.
	 *
	 * @var array<string, string>
	 */
	private const NOT_FILE_IDS = [
		'openregister_flow_steps.node_id' => 'The id of a node in a flow graph, a string the flow defines.',
		'openregister_tasks.node_id' => 'The flow graph node that opened the task, not a Nextcloud node.',
		'openregister_flow_timers.node_id' => 'The flow graph node a timer belongs to, not a Nextcloud node.',
		'openregister_task_sequences.node_id' => 'The flow graph node that opened the sequence, not a Nextcloud node.',
		'openregister_run_object_locks.node_id' => 'The flow graph node holding the lock, not a Nextcloud node.',
		'openregister_scheduled_reports.delivery_folder' => 'A folder path in the recipient\'s own Files, outside the managed tree.',
	];

	/**
	 * Every column that holds a node id.
	 *
	 * @return list<array{table: string, column: string, kind: string, where: array<string, string>}>
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function columns(): array {
		return self::COLUMNS;
	}//end columns()

	/**
	 * The file-id-shaped columns that hold no node id, with the reason.
	 *
	 * @return array<string, string> "table.column" => reason.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function notFileIds(): array {
		return self::NOT_FILE_IDS;
	}//end notFileIds()

	/**
	 * Whether "table.column" is a mapped reference.
	 *
	 * @param string $table  The table name, without prefix.
	 * @param string $column The column name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function isMapped(string $table, string $column): bool {
		foreach (self::COLUMNS as $entry) {
			if ($entry['table'] === $table && $entry['column'] === $column) {
				return true;
			}
		}

		return false;
	}//end isMapped()

	/**
	 * The values of an object's file-typed properties that are node ids.
	 *
	 * A file property holds one file id, a list of them, or (as the API returns
	 * it) a file object with an `id`. Only ids are references; anything else is
	 * not touched by a move.
	 *
	 * @param array<string, mixed> $object     The object's data.
	 * @param list<string>         $properties The names of its file-typed properties.
	 *
	 * @return array<string, list<int>> Property name => the file ids it holds.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function objectFileIds(array $object, array $properties): array {
		$found = [];
		foreach ($properties as $property) {
			if (array_key_exists($property, $object) === false) {
				continue;
			}

			$value = $object[$property];
			$items = [$value];
			if (is_array($value) === true && array_is_list($value) === true) {
				$items = $value;
			}

			$ids = [];
			foreach ($items as $item) {
				$id = self::idOf(value: $item);
				if ($id !== null) {
					$ids[] = $id;
				}
			}

			if ($ids !== []) {
				$found[$property] = $ids;
			}
		}

		return $found;
	}//end objectFileIds()

	/**
	 * An object's data with every file id in its file-typed properties replaced.
	 *
	 * @param array<string, mixed> $object     The object's data.
	 * @param list<string>         $properties The names of its file-typed properties.
	 * @param array<int, int>      $moved      Old file id => new file id.
	 *
	 * @return array<string, mixed> The data, with ids not in `$moved` left as they are.
	 *
	 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
	 */
	public function rewriteObject(array $object, array $properties, array $moved): array {
		foreach ($properties as $property) {
			if (array_key_exists($property, $object) === false) {
				continue;
			}

			$value = $object[$property];
			if (is_array($value) === true && array_is_list($value) === true) {
				$object[$property] = array_map(fn ($item) => self::rewriteValue(value: $item, moved: $moved), $value);
				continue;
			}

			$object[$property] = self::rewriteValue(value: $value, moved: $moved);
		}

		return $object;
	}//end rewriteObject()

	/**
	 * One file property value with its id replaced, in the same shape.
	 *
	 * @param mixed           $value The value: an id, a numeric string, or an array with `id`.
	 * @param array<int, int> $moved Old file id => new file id.
	 *
	 * @return mixed
	 */
	private static function rewriteValue(mixed $value, array $moved): mixed {
		$id = self::idOf(value: $value);
		if ($id === null || isset($moved[$id]) === false) {
			return $value;
		}

		if (is_array($value) === true) {
			$value['id'] = $moved[$id];
			return $value;
		}

		if (is_string($value) === true) {
			return (string)$moved[$id];
		}

		return $moved[$id];
	}//end rewriteValue()

	/**
	 * The node id a file property value holds, or null.
	 *
	 * @param mixed $value An id, a numeric string, or an array with `id`.
	 *
	 * @return int|null
	 */
	private static function idOf(mixed $value): ?int {
		if (is_array($value) === true) {
			$value = $value['id'] ?? null;
		}

		if (is_int($value) === true && $value > 0) {
			return $value;
		}

		if (is_string($value) === true && ctype_digit($value) === true && (int)$value > 0) {
			return (int)$value;
		}

		return null;
	}//end idOf()
}//end class
