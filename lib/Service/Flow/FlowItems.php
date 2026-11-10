<?php

/**
 * Normalisation for the item array that travels between flow steps.
 *
 * The engine's data channel is a LIST of items, not one object. A step receives
 * items and returns items, and a step that acts per record acts once per item.
 * This mirrors the model n8n proved out, and it is the reason a flow can express
 * "fetch 200 rows, act on each" without the author drawing a loop.
 *
 * An item is:
 *
 *   [
 *     'json'       => array,       // the record itself
 *     'binary'     => array,       // file attachments, keyed by name
 *     'pairedItem' => array|null,  // which input item produced this one
 *   ]
 *
 * `pairedItem` is what makes a run explainable: given an item at the end of a
 * flow, it is the chain back to the input that caused it. Without it, a step
 * that fans one item into ten leaves no way to answer "where did this come
 * from", which is the question every debugging session starts with.
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
 * Helpers for building and normalising flow item lists.
 */
final class FlowItems {

	/**
	 * The key carrying an item's record.
	 */
	public const JSON = FlowItemShape::JSON;

	/**
	 * The key carrying an item's file attachments.
	 */
	public const BINARY = FlowItemShape::BINARY;

	/**
	 * The key carrying an item's provenance.
	 */
	public const PAIRED_ITEM = FlowItemShape::PAIRED_ITEM;

	/**
	 * The key naming which output branch an item is routed to.
	 *
	 * Optional and usually absent: only a routing node ({@see Nodes\RouterNode})
	 * sets it, and the engine strips it once it has distributed the item, so it
	 * never lingers on an item a downstream step sees. An item without it is
	 * broadcast to every output, which is the ordinary behaviour.
	 */
	public const OUTPUT = FlowItemShape::OUTPUT;

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
		return FlowItemShape::normalise(value: $value, fromItemIndex: $fromItemIndex);
	}//end normalise()

	/**
	 * Build one well-formed item.
	 *
	 * @param array $json The record.
	 * @param array $binary File attachments, keyed by name.
	 * @param int|null $fromItemIndex Input item this came from, when known.
	 * @param string|null $output The output branch this item routes to, when set.
	 *
	 * @return array<string, mixed> The item.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public static function item(array $json, array $binary = [], ?int $fromItemIndex = null, ?string $output = null): array {
		return FlowItemShape::item(json: $json, options: new FlowItemOptions(binary: $binary, fromItemIndex: $fromItemIndex, output: $output));
	}//end item()

	/**
	 * Read a member's output tag, if it carries one.
	 *
	 * @param array $member A returned item.
	 *
	 * @return string|null The output branch, or null when the item is unrouted.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	public static function outputOf(array $member): ?string {
		return FlowItemShape::outputOf(member: $member);
	}//end outputOf()

	/**
	 * Seed a run's item list from the object it is about.
	 *
	 * A run always starts with exactly one item, so a flow that never fans out
	 * reads the same as the single-subject model it replaces.
	 *
	 * @param object $subject The object the run is about.
	 *
	 * @return array<int, array<string, mixed>> A one-item list.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public static function fromSubject(object $subject): array {
		return FlowSubjectItems::fromSubject(subject: $subject);
	}//end fromSubject()

}//end class
