<?php

/**
 * OpenRegister Example Set Paths
 *
 * Reads and writes a value at a dot path (`authentication.modes`) inside an
 * object, for the changes an example set makes to objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Dot-path access to an object.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
 */
class ExampleSetPaths {
	/**
	 * The shortest part of a dot path that the object does not hold yet.
	 *
	 * @param array<string, mixed> $row  The object.
	 * @param string               $path The dot path.
	 *
	 * @return string The path itself when only its last step is missing (or nothing is).
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function firstMissing(array $row, string $path): string {
		$parts = explode('.', $path);
		$steps = count($parts);
		for ($length = 1; $length < $steps; $length++) {
			$prefix = implode('.', array_slice($parts, 0, $length));
			if ($this->atPath(row: $row, path: $prefix)['found'] === false) {
				return $prefix;
			}
		}

		return $path;
	}//end firstMissing()

	/**
	 * The value at a dot path, and whether there is one.
	 *
	 * @param array<string, mixed> $row  The object.
	 * @param string               $path The dot path.
	 *
	 * @return array{found: bool, value: mixed}
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function atPath(array $row, string $path): array {
		$here = $row;
		foreach (explode('.', $path) as $part) {
			if (is_array($here) === false || array_key_exists($part, $here) === false) {
				return ['found' => false, 'value' => null];
			}

			$here = $here[$part];
		}

		return ['found' => true, 'value' => $here];
	}//end atPath()

	/**
	 * The object with a value at a dot path, making the maps on the way.
	 *
	 * @param array<string, mixed> $row   The object.
	 * @param string               $path  The dot path.
	 * @param mixed                $value The value.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function withPath(array $row, string $path, mixed $value): array {
		[$head, $rest] = array_pad(explode('.', $path, 2), 2, null);
		if ($rest === null) {
			$row[$head] = $value;
			return $row;
		}

		$child      = $row[$head] ?? [];
		$row[$head] = $this->withPath(row: (array) $child, path: $rest, value: $value);

		return $row;
	}//end withPath()

	/**
	 * The object without the value at a dot path.
	 *
	 * @param array<string, mixed> $row  The object.
	 * @param string               $path The dot path.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-change-an-object-it-did-not-create-and-undo-exactly-that-req-exs-011
	 */
	public function withoutPath(array $row, string $path): array {
		[$head, $rest] = array_pad(explode('.', $path, 2), 2, null);
		if ($rest === null) {
			unset($row[$head]);
			return $row;
		}

		if (is_array($row[$head] ?? null) === true) {
			$row[$head] = $this->withoutPath(row: $row[$head], path: $rest);
		}

		return $row;
	}//end withoutPath()

	/**
	 * The object with each named field that holds a structure sent as JSON text.
	 *
	 * This is the one encoding for `jsonFields`: an object is created with it,
	 * and a change writes and validates the object with it again.
	 *
	 * @param array<string, mixed> $row    The object.
	 * @param array<int, mixed>    $fields The field names kept as JSON text.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function withJsonText(array $row, array $fields): array {
		foreach ($fields as $field) {
			if (is_string($field) === true && is_array($row[$field] ?? null) === true) {
				$row[$field] = (string) json_encode($row[$field]);
			}
		}

		return $row;
	}//end withJsonText()

	/**
	 * The object with each named field that holds JSON text decoded to its structure.
	 *
	 * A field whose text is not a JSON list or map stays as it is.
	 *
	 * @param array<string, mixed> $row    The object.
	 * @param array<int, mixed>    $fields The field names kept as JSON text.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	public function withJsonDecoded(array $row, array $fields): array {
		foreach ($fields as $field) {
			if (is_string($field) === false || is_string($row[$field] ?? null) === false) {
				continue;
			}

			$decoded = json_decode($row[$field], true);
			if (is_array($decoded) === true) {
				$row[$field] = $decoded;
			}
		}

		return $row;
	}//end withJsonDecoded()
}//end class
