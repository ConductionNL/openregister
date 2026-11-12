<?php

/**
 * Resolves the `{{ path }}` values of an object-write step against one item.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

/**
 * Reads paths and placeholders out of an item's record.
 */
final class ObjectWriteTemplate {

	/**
	 * A value that is exactly one placeholder.
	 *
	 * @var string
	 */
	private const WHOLE = '/^\{\{\s*([^{}]+?)\s*\}\}$/';

	/**
	 * A placeholder inside a longer value.
	 *
	 * @var string
	 */
	private const INLINE = '/\{\{\s*([^{}]+?)\s*\}\}/';

	/**
	 * The answer for a path that holds nothing.
	 *
	 * @var array{found: bool, value: mixed}
	 */
	private const NOT_FOUND = ['found' => false, 'value' => null];

	/**
	 * Resolve one configured value against the item.
	 *
	 * A non-string is passed through unchanged, so literals and nested
	 * structures — including a deliberate `null`, which is how an author clears
	 * a property — can be authored directly. A value that is exactly one
	 * placeholder keeps the resolved value's TYPE: an array stays an array, a
	 * number stays a number. Anything else is substituted inline and stringified.
	 *
	 * A placeholder resolves to nothing when its path is absent, or holds null,
	 * or holds an empty array. That is reported rather than substituted.
	 *
	 * @param mixed $value The configured value.
	 * @param array $json  The item's record.
	 *
	 * @return array{resolved: bool, value: mixed} The verdict and the value.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function resolve(mixed $value, array $json): array {
		if (is_string($value) === false) {
			return ['resolved' => true, 'value' => $value];
		}

		$whole = [];
		if (preg_match(self::WHOLE, $value, $whole) === 1) {
			$found = $this->lookup(path: $whole[1], json: $json);
			return ['resolved' => $found['found'], 'value' => $found['value']];
		}

		return $this->resolveInline(template: $value, json: $json);
	}//end resolve()

	/**
	 * Walk a dotted path through the item's record.
	 *
	 * A path that lands on null or an empty array counts as not found: those are
	 * the two shapes OpenRegister's validator rejects for a nested object
	 * property, so the node reports them rather than writing them.
	 *
	 * @param string $path The dotted path.
	 * @param array  $json The item's record.
	 *
	 * @return array{found: bool, value: mixed} The verdict and the value.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function lookup(string $path, array $json): array {
		$path = trim($path);
		if ($path === '') {
			return self::NOT_FOUND;
		}

		$found = $this->walk(segments: explode('.', $path), json: $json);
		if ($found['value'] === null || $found['value'] === []) {
			return self::NOT_FOUND;
		}

		return $found;
	}//end lookup()

	/**
	 * Whether this item says it is already current, so no write is needed.
	 *
	 * Truthiness is deliberately narrow. A dot-path landing on the STRING
	 * "skip" counts, because that is the vocabulary the synchronisation
	 * pipeline's contract step stamps (`contract.outcome`), and the natural
	 * thing to configure is `skipWhen: "contract.outcome"`. Booleans count for
	 * a flow that computes its own flag. Everything else — including the
	 * strings "false" and "0", which are truthy in PHP — does NOT, because a
	 * value nobody meant as a skip must not silence a write.
	 *
	 * @param string $path The configured dot-path; empty means no item is skipped.
	 * @param array  $json The item's record.
	 *
	 * @return bool Whether the item is already current.
	 *
	 * @spec openspec/specs/flow-object-write-skip-when/spec.md
	 */
	public function isSkipped(string $path, array $json): bool {
		$value = $this->lookup(path: $path, json: $json)['value'];
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return (strtolower(trim($value)) === 'skip');
		}

		return false;
	}//end isSkipped()

	/**
	 * Substitute every placeholder inside a longer value.
	 *
	 * @param string $template The configured value.
	 * @param array  $json     The item's record.
	 *
	 * @return array{resolved: bool, value: mixed} The verdict and the value.
	 */
	private function resolveInline(string $template, array $json): array {
		$missing = false;
		$out = preg_replace_callback(self::INLINE, function (array $matches) use ($json, &$missing): string {
			$found = $this->lookup(path: $matches[1], json: $json);
			$missing = ($missing === true || $found['found'] === false);
			return $this->stringify(value: $found['value']);
		}, $template);

		if ($missing === true) {
			return ['resolved' => false, 'value' => null];
		}

		return ['resolved' => true, 'value' => $out];
	}//end resolveInline()

	/**
	 * Follow path segments down a record.
	 *
	 * @param array<int, string> $segments The path, split.
	 * @param array              $json     The item's record.
	 *
	 * @return array{found: bool, value: mixed} The verdict and the value.
	 */
	private function walk(array $segments, array $json): array {
		$cursor = $json;
		foreach ($segments as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return self::NOT_FOUND;
			}

			$cursor = $cursor[$segment];
		}

		return ['found' => true, 'value' => $cursor];
	}//end walk()

	/**
	 * Render a resolved value into an inline template.
	 *
	 * @param mixed $value The resolved value.
	 *
	 * @return string Its string form.
	 */
	private function stringify(mixed $value): string {
		if (is_scalar($value) === true && is_bool($value) === false) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end stringify()
}//end class
