<?php

/**
 * ReferenceLoops: the references of a JSON Schema document that come back to themselves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

/**
 * Finds the local references that return to their own node without moving through the data.
 *
 * Keywords such as `properties` and `items` step into the data, so a reference
 * that passes through one is recursion the data ends. Only a reference and the
 * keywords judging the same place count as a step here.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
final class ReferenceLoops {
	/**
	 * Keywords holding one schema for the same place in the data.
	 *
	 * @var array<int, string>
	 */
	private const SAME_PLACE_SCHEMAS = ['not', 'if', 'then', 'else'];

	/**
	 * Keywords holding a list of schemas for the same place in the data.
	 *
	 * @var array<int, string>
	 */
	private const SAME_PLACE_LISTS = ['allOf', 'anyOf', 'oneOf'];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $document The JSON Schema document.
	 */
	public function __construct(
		private readonly array $document,
	) {
	}//end __construct()

	/**
	 * Every node holding a reference that comes back to it.
	 *
	 * @return array<int, string> JSON pointers of those nodes.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function pointers(): array {
		$holders = $this->referenceHolders(node: $this->document, pointer: '');

		return array_values(array_filter($holders, fn (string $pointer): bool => $this->comesBack(start: $pointer)));
	}//end pointers()

	/**
	 * The nodes at or below a node that hold a reference.
	 *
	 * @param array<mixed> $node    The node.
	 * @param string       $pointer Its JSON pointer.
	 *
	 * @return array<int, string> JSON pointers.
	 */
	private function referenceHolders(array $node, string $pointer): array {
		$holders = [];
		if (is_string($node['$ref'] ?? null) === true) {
			$holders[] = $pointer;
		}

		foreach ($node as $key => $child) {
			if (is_array($child) === true) {
				$childPointer = $pointer . '/' . strtr((string)$key, ['~' => '~0', '/' => '~1']);
				$holders = array_merge($holders, $this->referenceHolders(node: $child, pointer: $childPointer));
			}
		}

		return $holders;
	}//end referenceHolders()

	/**
	 * Whether following same-place steps from a node reaches that node again.
	 *
	 * @param string $start JSON pointer of the node.
	 *
	 * @return bool True when it does.
	 */
	private function comesBack(string $start): bool {
		$seen = [];
		$open = $this->stepsFrom(pointer: $start);
		while ($open !== []) {
			$pointer = array_pop($open);
			if ($pointer === $start) {
				return true;
			}

			if (isset($seen[$pointer]) === false) {
				$seen[$pointer] = true;
				$open = array_merge($open, $this->stepsFrom(pointer: $pointer));
			}
		}

		return false;
	}//end comesBack()

	/**
	 * Where a node leads without moving through the data.
	 *
	 * @param string $pointer JSON pointer of the node.
	 *
	 * @return array<int, string> JSON pointers.
	 */
	private function stepsFrom(string $pointer): array {
		$node = $this->nodeAt(pointer: $pointer);
		if ($node === null) {
			return [];
		}

		$steps = $this->referenceTarget(node: $node);
		foreach (self::SAME_PLACE_SCHEMAS as $keyword) {
			if (is_array($node[$keyword] ?? null) === true) {
				$steps[] = $pointer . '/' . $keyword;
			}
		}

		return array_merge($steps, $this->listSteps(node: $node, pointer: $pointer));
	}//end stepsFrom()

	/**
	 * The members of a node's schema lists.
	 *
	 * @param array<mixed> $node    The node.
	 * @param string       $pointer Its JSON pointer.
	 *
	 * @return array<int, string> JSON pointers.
	 */
	private function listSteps(array $node, string $pointer): array {
		$steps = [];
		foreach (self::SAME_PLACE_LISTS as $keyword) {
			foreach (array_keys((array)($node[$keyword] ?? [])) as $index) {
				$steps[] = $pointer . '/' . $keyword . '/' . $index;
			}
		}

		return $steps;
	}//end listSteps()

	/**
	 * The node's local reference as a JSON pointer.
	 *
	 * @param array<mixed> $node The node.
	 *
	 * @return array<int, string> The pointer, empty for no reference or one into another document.
	 */
	private function referenceTarget(array $node): array {
		$reference = ($node['$ref'] ?? null);
		if ($reference === '#' || (is_string($reference) === true && str_starts_with($reference, '#/') === true)) {
			return [substr($reference, 1)];
		}

		return [];
	}//end referenceTarget()

	/**
	 * The schema node at a JSON pointer.
	 *
	 * @param string $pointer The pointer.
	 *
	 * @return array<mixed>|null The node, null when nothing is there.
	 */
	private function nodeAt(string $pointer): ?array {
		$node = $this->document;
		foreach (array_slice(explode('/', $pointer), 1) as $part) {
			$node = ($node[strtr($part, ['~1' => '/', '~0' => '~'])] ?? null);
			if (is_array($node) === false) {
				return null;
			}
		}

		return $node;
	}//end nodeAt()
}//end class
