<?php

/**
 * The shape of one flow item, and the coercion of a step's return value into a list of them.
 *
 * The logic behind {@see FlowItems::normalise()}, {@see FlowItems::item()} and
 * {@see FlowItems::outputOf()}; FlowItems keeps the published surface.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Builds and normalises flow items.
 */
final class FlowItemShape {

	/**
	 * The key carrying an item's record.
	 */
	public const JSON = 'json';

	/**
	 * The key carrying an item's file attachments.
	 */
	public const BINARY = 'binary';

	/**
	 * The key carrying an item's provenance.
	 */
	public const PAIRED_ITEM = 'pairedItem';

	/**
	 * The key naming which output branch an item is routed to.
	 *
	 * Optional and usually absent: only a routing node ({@see Nodes\RouterNode})
	 * sets it, and the engine strips it once it has distributed the item, so it
	 * never lingers on an item a downstream step sees. An item without it is
	 * broadcast to every output, which is the ordinary behaviour.
	 */
	public const OUTPUT = 'output';

	/**
	 * Coerce whatever a step returned into a well-formed item list.
	 *
	 * Steps are written by consuming apps, so the engine cannot assume the
	 * shape it gets back. Three authoring shapes are accepted and normalised
	 * rather than rejected, because rejecting them would push the same
	 * boilerplate into every dispatcher:
	 *
	 *   - a full item list             -> used as-is
	 *   - a single item (`['json'=>…]`) -> wrapped into a one-item list
	 *   - a bare record (`['a'=>1]`)    -> wrapped as that item's `json`
	 *
	 * A step that legitimately produces nothing returns an empty list, which
	 * is meaningful: it ends that branch's data, exactly as a filter should.
	 *
	 * @param mixed $value What the step returned.
	 * @param int|null $fromItemIndex Input item this output came from, when known.
	 *
	 * @return array<int, array<string, mixed>> A well-formed item list.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public static function normalise(mixed $value, ?int $fromItemIndex = null): array {
		if (is_array($value) === false || $value === []) {
			return [];
		}

		// A list whose members are arrays is treated as an item list; each
		// member is normalised in turn so a half-shaped list still lands well.
		if (array_is_list($value) === true) {
			return self::normaliseList(members: $value, fromItemIndex: $fromItemIndex);
		}

		// A single item, or a bare record standing in for one.
		return [self::fromMember(member: $value, fallback: $fromItemIndex)];
	}//end normalise()

	/**
	 * Build one well-formed item.
	 *
	 * @param array           $json    The record.
	 * @param FlowItemOptions $options The attachments, the source item and the output branch.
	 *
	 * @return array<string, mixed> The item.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public static function item(array $json, FlowItemOptions $options=new FlowItemOptions()): array {
		$paired = null;
		if ($options->fromItemIndex !== null) {
			$paired = ['item' => $options->fromItemIndex];
		}

		$item = [
			self::JSON => $json,
			self::BINARY => $options->binary,
			self::PAIRED_ITEM => $paired,
		];

		// The output tag is added only when set, so an ordinary item keeps its
		// exact three-key shape and nothing downstream has to know about routing.
		if ($options->output !== null && $options->output !== '') {
			$item[self::OUTPUT] = $options->output;
		}

		return $item;
	}//end item()

	/**
	 * Read a member's output tag, if it carries one.
	 *
	 * @param array $member A returned item.
	 *
	 * @return string|null The output branch, or null when the item is unrouted.
	 *
	 * @spec openspec/specs/flow-per-item-routing/spec.md
	 */
	public static function outputOf(array $member): ?string {
		$output = ($member[self::OUTPUT] ?? null);
		if (is_string($output) === true && $output !== '') {
			return $output;
		}

		return null;
	}//end outputOf()

	/**
	 * The well-formed items of a list, skipping members that are not arrays.
	 *
	 * @param array<int, mixed> $members       The returned list.
	 * @param int|null          $fromItemIndex Input item the list came from, when known.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 */
	private static function normaliseList(array $members, ?int $fromItemIndex): array {
		$items = [];
		foreach ($members as $index => $member) {
			if (is_array($member) === true) {
				$items[] = self::fromMember(member: $member, fallback: ($fromItemIndex ?? $index));
			}
		}

		return $items;
	}//end normaliseList()

	/**
	 * One returned member as a well-formed item: a full item, or a bare record as its `json`.
	 *
	 * @param array    $member   The returned member.
	 * @param int|null $fallback The paired index to use when the member carries none.
	 *
	 * @return array<string, mixed> The item.
	 */
	private static function fromMember(array $member, ?int $fallback): array {
		return self::item(
			json: (array)($member[self::JSON] ?? $member),
			options: new FlowItemOptions(
				binary: (array)($member[self::BINARY] ?? []),
				fromItemIndex: self::pairedIndex(member: $member, fallback: $fallback),
				output: self::outputOf(member: $member)
			)
		);
	}//end fromMember()

	/**
	 * Read an already-set `pairedItem` index, falling back when absent.
	 *
	 * A dispatcher that tracked provenance itself keeps it; one that did not
	 * gets the engine's best guess rather than nothing.
	 *
	 * @param array $member The returned member.
	 * @param int|null $fallback The index to use when the member carries none.
	 *
	 * @return int|null The paired input index.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private static function pairedIndex(array $member, ?int $fallback): ?int {
		$tracked = self::trackedIndex(paired: ($member[self::PAIRED_ITEM] ?? null));
		if ($tracked !== null) {
			return $tracked;
		}

		return $fallback;
	}//end pairedIndex()

	/**
	 * The input index a member's own provenance names: `{item: n}` or a bare int.
	 *
	 * @param mixed $paired The member's paired-item value.
	 *
	 * @return int|null The index, or null when it names none.
	 */
	private static function trackedIndex(mixed $paired): ?int {
		if (is_array($paired) === true && isset($paired['item']) === true) {
			return (int)$paired['item'];
		}

		if (is_int($paired) === true) {
			return $paired;
		}

		return null;
	}//end trackedIndex()
}//end class
