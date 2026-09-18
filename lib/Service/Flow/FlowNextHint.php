<?php

/**
 * Where the person goes after a macro run, as a word rather than a route.
 *
 * `stay`, `next` or `list`. The list host owns its own notion of "the next
 * item" — its sort, its filter, its page — so the engine says WHAT should
 * happen and never a URL (D-3). A run result carrying a route would be a
 * server deciding a client's navigation from a different sort order.
 *
 * A manual trigger declares the default; an end node may override it for the
 * run that reached it, because "close and notify" and "close and move on" can
 * be one flow with two endings.
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
 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * The `next` hint a run answers with.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/flow-engine/spec.md#requirement-a-manual-trigger-declares-where-the-person-goes-next
 */
final class FlowNextHint {

	/**
	 * Stay on the record the macro ran against.
	 *
	 * @var string
	 */
	public const STAY = 'stay';

	/**
	 * Go to the next item in the list the person came from.
	 *
	 * @var string
	 */
	public const NEXT = 'next';

	/**
	 * Go back to the list.
	 *
	 * @var string
	 */
	public const LIST = 'list';

	/**
	 * The whole vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const HINTS = [self::STAY, self::NEXT, self::LIST];

	/**
	 * The manual trigger's node type.
	 *
	 * @var string
	 */
	public const MANUAL_TRIGGER = 'openregister.trigger-manual';

	/**
	 * The node type that ends a run.
	 *
	 * @var string
	 */
	public const END_NODE = 'openregister.end';

	/**
	 * The hint a flow's manual trigger declares.
	 *
	 * Defaults to `stay`, which is what a macro did before this existed: the
	 * page refreshes and the person is still looking at the record.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 *
	 * @return string One of HINTS.
	 */
	public static function declared(array $nodes): string {
		foreach ($nodes as $node) {
			if (is_array($node) === false || (string)($node['type'] ?? '') !== self::MANUAL_TRIGGER) {
				continue;
			}

			$hint = self::read(raw: ((array)($node['config'] ?? []))['next'] ?? null);
			if ($hint !== null) {
				return $hint;
			}
		}

		return self::STAY;
	}//end declared()

	/**
	 * The hint this run actually ends with.
	 *
	 * The end node the run reached wins when it declares one. An end node that
	 * declares nothing is not an override to `stay`: it is silence, and the
	 * trigger's answer stands.
	 *
	 * @param array<int, mixed>        $nodes   The flow's nodes.
	 * @param array<string, mixed>|null $endNode The end node the run reached, if any.
	 *
	 * @return string One of HINTS.
	 */
	public static function effective(array $nodes, ?array $endNode = null): string {
		if ($endNode !== null) {
			$override = self::read(raw: ((array)($endNode['config'] ?? []))['next'] ?? null);
			if ($override !== null) {
				return $override;
			}
		}

		return self::declared(nodes: $nodes);
	}//end effective()

	/**
	 * Read a hint, refusing anything outside the vocabulary.
	 *
	 * An unknown word answers null rather than a default, so the caller can
	 * tell "said nothing" from "said something we do not understand" — and a
	 * validator can refuse the second at authoring time instead of quietly
	 * turning it into `stay`.
	 *
	 * @param mixed $raw The declared value.
	 *
	 * @return string|null The hint, or null.
	 */
	public static function read(mixed $raw): ?string {
		if (is_string($raw) === false) {
			return null;
		}

		$hint = strtolower(trim($raw));
		if (in_array($hint, self::HINTS, true) === false) {
			return null;
		}

		return $hint;
	}//end read()
}//end class
