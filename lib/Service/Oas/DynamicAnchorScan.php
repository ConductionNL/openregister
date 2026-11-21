<?php

/**
 * One walk of a schema that records its `$dynamicAnchor` declarations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * Where each `$dynamicAnchor` is declared, and how many nested `$id`s a schema has.
 *
 * Built by {@see OasRequestValidator} for one schema and never handed on
 * (ADR-011 rule 3): it replaces the two by-reference accumulators the walk
 * used to thread through every recursive call.
 *
 * @spec openspec/specs/oas-validation/spec.md#requirement-request-validation-against-oas-schema
 */
final class DynamicAnchorScan {

	/**
	 * Anchor name => list of JSON pointers that declare it.
	 *
	 * @var array<string, list<string>>
	 */
	public array $anchors = [];

	/**
	 * Number of `$id`s below the root.
	 *
	 * @var integer
	 */
	public int $nestedIds = 0;

	/**
	 * Record the anchors and nested `$id`s of a node and everything below it.
	 *
	 * @param mixed  $node    The schema node being walked.
	 * @param string $pointer The JSON pointer of that node ('' is the root).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md#requirement-request-validation-against-oas-schema
	 */
	public function collect(mixed $node, string $pointer=''): void {
		if (is_array($node) === false) {
			return;
		}

		$this->recordNode(node: $node, pointer: $pointer);

		foreach ($node as $key => $child) {
			$segment = str_replace(['~', '/'], ['~0', '~1'], (string)$key);
			$this->collect(node: $child, pointer: $pointer . '/' . $segment);
		}
	}//end collect()

	/**
	 * Count a nested `$id` and record a `$dynamicAnchor` on one node.
	 *
	 * @param array<int|string, mixed> $node    The schema node.
	 * @param string                   $pointer The JSON pointer of that node ('' is the root).
	 *
	 * @return void
	 */
	private function recordNode(array $node, string $pointer): void {
		if ($pointer !== '' && isset($node['$id']) === true) {
			$this->nestedIds++;
		}

		if (isset($node['$dynamicAnchor']) === true && is_string($node['$dynamicAnchor']) === true) {
			$this->anchors[$node['$dynamicAnchor']][] = $pointer;
		}
	}//end recordNode()
}//end class
