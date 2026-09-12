<?php

/**
 * What a node says it is, or the default when it says nothing.
 *
 * Separate from {@see FlowNodeRegistry} because it answers a different
 * question. The registry decides which nodes exist and assembles the palette;
 * this decides what one node's declaration amounts to, and it is the only place
 * that knows the vocabularies are closed.
 *
 * 🔴 THE DEFAULTS LIVE HERE, NOT ON THE INTERFACE. PHP has no interface
 * defaults, so putting the methods on {@see IFlowNode} would REQUIRE them, and
 * on a measured instance 43 of 64 step types come from apps in other
 * repositories on their own release cycles. Requiring them fatals those apps on
 * the next release; guessing on their behalf produces a wrong BPMN element type
 * nobody revisits, because it looks answered. Defaulting them here keeps
 * "undeclared" visible, and an `other` in the palette is a prompt that gets
 * fixed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-both-are-optional-and-a-node-that-declares-neither-still-works
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Psr\Log\LoggerInterface;

/**
 * Resolves a node's kind and category, applying the defaults.
 */
class FlowNodeTaxonomyResolver {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Where a declaration outside the vocabulary is reported.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-both-are-optional-and-a-node-that-declares-neither-still-works
	 */
	public function __construct(private readonly LoggerInterface $logger) {

	}//end __construct()

	/**
	 * The node's BPMN kind, or `serviceTask`.
	 *
	 * @param IFlowNode $node The node.
	 *
	 * @return string One of IFlowNodeTaxonomy::KINDS.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-semantic-kind-drawn-from-bpmn
	 */
	public function kindOf(IFlowNode $node): string {
		return $this->declared(
			node: $node,
			declaration: static fn (IFlowNodeTaxonomy $taxonomy): string => $taxonomy->getKind(),
			allowed: IFlowNodeTaxonomy::KINDS,
			fallback: IFlowNodeTaxonomy::KIND_SERVICE_TASK,
			what: 'kind'
		);

	}//end kindOf()

	/**
	 * The node's palette category, or `other`.
	 *
	 * @param IFlowNode $node The node.
	 *
	 * @return string One of IFlowNodeTaxonomy::CATEGORIES.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-a-node-declares-a-palette-category-independent-of-its-kind
	 */
	public function categoryOf(IFlowNode $node): string {
		return $this->declared(
			node: $node,
			declaration: static fn (IFlowNodeTaxonomy $taxonomy): string => $taxonomy->getCategory(),
			allowed: IFlowNodeTaxonomy::CATEGORIES,
			fallback: IFlowNodeTaxonomy::CATEGORY_OTHER,
			what: 'category'
		);

	}//end categoryOf()

	/**
	 * Whether a node ends a path deliberately.
	 *
	 * Here rather than in the registry for the same reason `kindOf()` is: it
	 * asks what a node SAYS IT IS, which is one question with one home. The
	 * registry decides which nodes exist; this reads their declarations.
	 *
	 * @param IFlowNode $node The node.
	 *
	 * @return bool Whether it marks itself an end.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-triggers-or-ends-a-path
	 */
	public function isEnd(IFlowNode $node): bool {
		return ($node instanceof IFlowEndNode);

	}//end isEnd()

	/**
	 * Whether a run may begin at a node.
	 *
	 * @param IFlowNode $node The node.
	 *
	 * @return bool Whether it marks itself a trigger.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-triggers-or-ends-a-path
	 */
	public function isTrigger(IFlowNode $node): bool {
		return ($node instanceof IFlowTriggerNode);

	}//end isTrigger()

	/**
	 * What an editor calls the node: `trigger`, `end` or `step`.
	 *
	 * ALWAYS answered, so an editor never has to infer it. One that had to
	 * fell back to matching the id against a naming convention, which
	 * mis-labels every node another app contributes under a name that does
	 * not fit the pattern.
	 *
	 * @param IFlowNode $node The node.
	 *
	 * @return string The role.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-declares-whether-it-triggers-or-ends-a-path
	 */
	public function roleOf(IFlowNode $node): string {
		if ($this->isTrigger(node: $node) === true) {
			return 'trigger';
		}

		if ($this->isEnd(node: $node) === true) {
			return 'end';
		}

		return 'step';

	}//end roleOf()

	/**
	 * One declared value, checked against its closed vocabulary.
	 *
	 * 🔴 A VALUE OUTSIDE THE VOCABULARY IS DROPPED AND LOGGED, NOT SERVED. A
	 * node declaring `userTsak` would otherwise carry its typo into a BPMN
	 * export, where it becomes an element type nothing recognises, and grow a
	 * palette group of one that no author asked for. Falling back puts it where
	 * undeclared nodes already are, which is where people look for exactly this.
	 *
	 * @param IFlowNode          $node        The node.
	 * @param callable           $declaration Reads the value off the taxonomy.
	 * @param array<int, string> $allowed     The closed vocabulary.
	 * @param string             $fallback    What an undeclared node reports.
	 * @param string             $what        The field name, for the log.
	 *
	 * @return string The value to serve.
	 *
	 * @spec openspec/changes/flow-node-taxonomy/specs/flow-node-taxonomy/spec.md#requirement-both-are-optional-and-a-node-that-declares-neither-still-works
	 */
	private function declared(
		IFlowNode $node,
		callable $declaration,
		array $allowed,
		string $fallback,
		string $what
	): string {
		if (($node instanceof IFlowNodeTaxonomy) === false) {
			return $fallback;
		}

		$value = (string)$declaration($node);
		if (in_array($value, $allowed, true) === true) {
			return $value;
		}

		$this->logger->warning(
			message: sprintf(
				'[FlowNodeTaxonomyResolver] The node "%s" declared the %s "%s", which is not one of: %s. Serving "%s".',
				$node->getId(),
				$what,
				$value,
				implode(', ', $allowed),
				$fallback
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'type' => $node->getId()]
		);

		return $fallback;

	}//end declared()
}//end class
