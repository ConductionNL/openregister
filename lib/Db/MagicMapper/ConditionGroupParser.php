<?php

/**
 * Parse the `_where` list-query parameter into a validated condition tree.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\LikeOperatorInterface;
use OCA\OpenRegister\Exception\ConditionGroupException;

/**
 * Turns the `_where` parameter — a JSON string on the query string, or an
 * object in a POST body or a stored config — into a {@see ConditionGroup}
 * tree of groups and {@see ConditionLeaf} leaves, each carrying its node
 * path. Every structural refusal (design D-3) is raised here as a
 * {@see ConditionGroupException} naming the failing path; the semantic
 * refusals that need the schema and the backend (an undeclared or encrypted
 * property, a Solr/Elasticsearch backend) are the condition builder's, which
 * reads the leaves this produces.
 *
 * The accepted leaf operators are the handler's `COMPARISON_OPERATORS` plus
 * `eq`, the no-operator form of a flat filter (`?place=Zuiddrecht`). The list
 * is held here rather than imported because the handler's copy is private; it
 * is covered by a test that fails if the two drift.
 *
 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
 */
final class ConditionGroupParser {

	/**
	 * The no-operator form of a flat filter.
	 */
	public const OPERATOR_EQ = 'eq';

	/**
	 * A tree may nest at most this many groups deep (design D-3).
	 */
	public const MAX_DEPTH = 5;

	/**
	 * A tree may hold at most this many leaves (design D-3).
	 */
	public const MAX_LEAVES = 200;

	/**
	 * The operators a leaf may name: `eq`, plus the handler's comparison set.
	 *
	 * @var array<int, string>
	 */
	public const OPERATORS = [
		self::OPERATOR_EQ,
		'gte',
		'lte',
		'gt',
		'lt',
		'in',
		'notIn',
		'ne',
		'isnull',
		LikeOperatorInterface::KEY,
	];

	/**
	 * Parse the `_where` parameter into a validated tree.
	 *
	 * @param mixed $where The parameter: a JSON string, or a decoded group array.
	 *
	 * @return ConditionGroup The root group.
	 *
	 * @throws ConditionGroupException When the parameter is not a valid group tree.
	 *
	 * @spec openspec/changes/list-query-condition-groups/specs/zoeken-filteren/spec.md
	 */
	public function parse(mixed $where): ConditionGroup {
		$decoded = $this->decoded(where: $where);

		$root = $this->group(node: $decoded, path: '$', depth: 1);

		$leafCount = count($root->leaves());
		if ($leafCount > self::MAX_LEAVES) {
			throw new ConditionGroupException(
				message: sprintf('A condition group holds %d leaves; at most %d are allowed.', $leafCount, self::MAX_LEAVES),
				path: '$'
			);
		}

		return $root;
	}//end parse()

	/**
	 * The parameter as an array, decoding a JSON string.
	 *
	 * @param mixed $where The raw parameter.
	 *
	 * @return array<string, mixed> The decoded group.
	 *
	 * @throws ConditionGroupException When the parameter is not valid JSON or not a group.
	 */
	private function decoded(mixed $where): array {
		if (is_array($where) === true) {
			return $where;
		}

		if (is_string($where) === false) {
			throw new ConditionGroupException(message: '_where must be a condition group.', path: '$');
		}

		$decoded = json_decode($where, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
			throw new ConditionGroupException(message: '_where is not valid JSON for a condition group.', path: '$');
		}

		return $decoded;
	}//end decoded()

	/**
	 * Validate one group node and its children.
	 *
	 * @param array<string, mixed> $node  The group node.
	 * @param string               $path  The node's path.
	 * @param int                  $depth How many groups deep this node is (the root is 1).
	 *
	 * @return ConditionGroup The validated group.
	 *
	 * @throws ConditionGroupException When the group is malformed or too deep.
	 */
	private function group(array $node, string $path, int $depth): ConditionGroup {
		if ($depth > self::MAX_DEPTH) {
			throw new ConditionGroupException(
				message: sprintf('A condition group is nested deeper than %d groups.', self::MAX_DEPTH),
				path: $path
			);
		}

		$type = ($node['type'] ?? null);
		if ($type !== ConditionGroup::TYPE_AND && $type !== ConditionGroup::TYPE_OR) {
			throw new ConditionGroupException(
				message: 'A condition group needs a "type" of "AND" or "OR".',
				path: $path
			);
		}

		$children = ($node['children'] ?? null);
		if (is_array($children) === false || $children === [] || array_is_list($children) === false) {
			throw new ConditionGroupException(
				message: 'A condition group needs a non-empty "children" list.',
				path: $path
			);
		}

		$parsed = [];
		foreach ($children as $index => $child) {
			$parsed[] = $this->child(node: $child, path: $path . '.children[' . $index . ']', depth: $depth);
		}

		return new ConditionGroup(type: $type, children: $parsed, path: $path);
	}//end group()

	/**
	 * Validate one child: a nested group when it carries a "type", else a leaf.
	 *
	 * @param mixed  $node  The child node.
	 * @param string $path  The child's path.
	 * @param int    $depth The parent group's depth.
	 *
	 * @return ConditionGroup|ConditionLeaf The validated child.
	 *
	 * @throws ConditionGroupException When the child is malformed.
	 */
	private function child(mixed $node, string $path, int $depth): ConditionGroup|ConditionLeaf {
		if (is_array($node) === false) {
			throw new ConditionGroupException(message: 'A condition must be a group or a leaf.', path: $path);
		}

		if (array_key_exists('type', $node) === true) {
			return $this->group(node: $node, path: $path, depth: ($depth + 1));
		}

		return $this->leaf(node: $node, path: $path);
	}//end child()

	/**
	 * Validate one leaf node.
	 *
	 * @param array<string, mixed> $node The leaf node.
	 * @param string               $path The leaf's path.
	 *
	 * @return ConditionLeaf The validated leaf.
	 *
	 * @throws ConditionGroupException When the leaf names no field or an unknown operator.
	 */
	private function leaf(array $node, string $path): ConditionLeaf {
		$field = ($node['field'] ?? null);
		if (is_string($field) === false || trim($field) === '') {
			throw new ConditionGroupException(message: 'A condition leaf needs a "field".', path: $path);
		}

		$operator = ($node['operator'] ?? self::OPERATOR_EQ);
		if (in_array($operator, self::OPERATORS, true) === false) {
			throw new ConditionGroupException(
				message: sprintf('"%s" is not a condition operator.', $this->printable(operator: $operator)),
				path: $path
			);
		}

		// `isnull` is the one operator that carries no value; every other
		// operator needs one, so its absence is caught here rather than in the
		// SQL builder where the path is already lost.
		if ($operator !== 'isnull' && array_key_exists('value', $node) === false) {
			throw new ConditionGroupException(message: 'A condition leaf needs a "value".', path: $path);
		}

		return new ConditionLeaf(
			field: $field,
			operator: $operator,
			value: ($node['value'] ?? null),
			path: $path
		);
	}//end leaf()

	/**
	 * A rejected operator as text for the message: the value itself when it is
	 * scalar, otherwise its type.
	 *
	 * @param mixed $operator The rejected operator.
	 *
	 * @return string The printable operator.
	 */
	private function printable(mixed $operator): string {
		if (is_scalar($operator) === true) {
			return (string)$operator;
		}

		return gettype($operator);
	}//end printable()
}//end class
