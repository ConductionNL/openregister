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
		$paired = null;
		if ($fromItemIndex !== null) {
			$paired = ['item' => $fromItemIndex];
		}

		$item = [
			self::JSON => $json,
			self::BINARY => $binary,
			self::PAIRED_ITEM => $paired,
		];

		// The output tag is added only when set, so an ordinary item keeps its
		// exact three-key shape and nothing downstream has to know about routing.
		if ($output !== null && $output !== '') {
			$item[self::OUTPUT] = $output;
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
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	public static function outputOf(array $member): ?string {
		$output = ($member[self::OUTPUT] ?? null);
		if (is_string($output) === true && $output !== '') {
			return $output;
		}

		return null;
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
		return [self::item(json: self::recordOf(subject: $subject))];
	}//end fromSubject()

	/**
	 * The record an object serialises to: `jsonSerialize()`, else `getObject()`, else its public fields.
	 *
	 * @param object $subject The object.
	 *
	 * @return array The record.
	 */
	private static function recordOf(object $subject): array {
		if (method_exists($subject, 'jsonSerialize') === true) {
			return (array)$subject->jsonSerialize();
		}

		if (method_exists($subject, 'getObject') === true) {
			return (array)$subject->getObject();
		}

		return get_object_vars($subject);
	}//end recordOf()

	/**
	 * Refresh the subject's own fields on every item that IS the subject.
	 *
	 * The seam between two requirements that are compatible but easy to
	 * conflate. Items survive the pause (flow-runs REQ-FR-003): resuming
	 * continues from the stored list, never a fresh seed, so everything the
	 * earlier steps produced is kept. But an item that is the run's SUBJECT
	 * began as a trigger-time snapshot of it, and a step that branches on a
	 * subject field after a human answered — "is the description filled in
	 * now?" — must read the subject as it stands, not as it stood when the
	 * run started. Without this, the shipped re-ask loop can never succeed:
	 * the answer lands on the object, the re-check reads the frozen snapshot,
	 * and the run strands.
	 *
	 * So: only items carrying the subject's identity are touched, and on
	 * those the live subject's serialised fields are merged OVER the stale
	 * snapshot. A key the live subject does not serialise — a task outcome
	 * bag, a step's computed field — survives untouched, which is what keeps
	 * REQ-FR-003 true. An item that is some OTHER object (a fan-out read)
	 * carries its own identity and is never smeared with the subject's data.
	 *
	 * @param array<int, array<string, mixed>> $items The stored items.
	 * @param object $subject The live subject.
	 * @param string $subjectUuid The run's subject uuid, matching items by identity.
	 *
	 * @return array<int, array<string, mixed>> The items, subject fields refreshed.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md#requirement-resuming-carries-the-runs-own-items-req-fr-003
	 */
	public static function refreshSubjectProjection(array $items, object $subject, string $subjectUuid): array {
		$subjectUuid = trim($subjectUuid);
		if ($subjectUuid === '' || $items === []) {
			return $items;
		}

		$live = (array)(self::fromSubject(subject: $subject)[0][self::JSON] ?? []);
		if ($live === []) {
			return $items;
		}

		foreach (self::subjectIndexes(items: $items, subjectUuid: $subjectUuid) as $index) {
			// Live wins on the subject's own keys; step-produced keys the
			// subject does not serialise are kept as the run carried them.
			$items[$index][self::JSON] = array_merge((array)($items[$index][self::JSON] ?? []), $live);
		}

		return $items;
	}//end refreshSubjectProjection()

	/**
	 * The keys of the items that carry the subject's identity.
	 *
	 * @param array<int|string, mixed> $items       The stored items.
	 * @param string                   $subjectUuid The run's subject uuid.
	 *
	 * @return array<int, int|string> The matching keys, in order.
	 */
	private static function subjectIndexes(array $items, string $subjectUuid): array {
		return array_keys(
			array_filter(
				$items,
				static fn (mixed $item): bool => is_array($item) === true
					&& self::identityOf(json: (array)($item[self::JSON] ?? [])) === $subjectUuid
			)
		);
	}//end subjectIndexes()

	/**
	 * The object identity an item's record carries, if any.
	 *
	 * A subject-seeded item serialises its uuid as `@self.id` mirrored at the
	 * top-level `id`; older shapes carried a flat `uuid`. An item with none of
	 * these has no object identity and can never be "the subject".
	 *
	 * @param array $json The item's record.
	 *
	 * @return string The identity, or '' when the item carries none.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md#requirement-resuming-carries-the-runs-own-items-req-fr-003
	 */
	private static function identityOf(array $json): string {
		$self = ($json['@self'] ?? null);
		if (is_array($self) === true && trim((string)($self['id'] ?? '')) !== '') {
			return trim((string)$self['id']);
		}

		return self::flatIdentity(json: $json);
	}//end identityOf()

	/**
	 * The first non-blank string among the flat `id` and `uuid` keys.
	 *
	 * @param array $json The item's record.
	 *
	 * @return string The identity, or '' when neither carries one.
	 */
	private static function flatIdentity(array $json): string {
		foreach (['id', 'uuid'] as $key) {
			$value = ($json[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end flatIdentity()

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
			binary: (array)($member[self::BINARY] ?? []),
			fromItemIndex: self::pairedIndex(member: $member, fallback: $fallback),
			output: self::outputOf(member: $member)
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
		$paired = ($member[self::PAIRED_ITEM] ?? null);
		if (is_array($paired) === true && isset($paired['item']) === true) {
			return (int)$paired['item'];
		}

		if (is_int($paired) === true) {
			return $paired;
		}

		return $fallback;
	}//end pairedIndex()
}//end class
