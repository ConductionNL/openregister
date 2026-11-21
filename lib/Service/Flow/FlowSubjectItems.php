<?php

/**
 * Flow items seeded from, and refreshed against, the object a run is about.
 *
 * The logic behind {@see FlowItems::fromSubject()} and
 * {@see FlowItems::refreshSubjectProjection()}; FlowItems keeps the published surface.
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
 * Seeds and refreshes the items that carry a run's subject.
 */
final class FlowSubjectItems {

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
		return [FlowItemShape::item(json: self::recordOf(subject: $subject))];
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
	 * @param array<int, array<string, mixed>> $items   The stored items.
	 * @param LiveSubject                      $subject The live subject and the run's subject uuid,
	 *                                                  matching items by identity.
	 *
	 * @return array<int, array<string, mixed>> The items, subject fields refreshed.
	 *
	 * @spec openspec/specs/flow-runs/spec.md
	 */
	public static function refreshSubjectProjection(array $items, LiveSubject $subject): array {
		$subjectUuid = trim($subject->uuid);
		if ($subjectUuid === '' || $items === []) {
			return $items;
		}

		$live = (array)(self::fromSubject(subject: $subject->subject)[0][FlowItemShape::JSON] ?? []);
		if ($live === []) {
			return $items;
		}

		foreach (self::subjectIndexes(items: $items, subjectUuid: $subjectUuid) as $index) {
			// Live wins on the subject's own keys; step-produced keys the
			// subject does not serialise are kept as the run carried them.
			$items[$index][FlowItemShape::JSON] = array_merge((array)($items[$index][FlowItemShape::JSON] ?? []), $live);
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
					&& self::identityOf(json: (array)($item[FlowItemShape::JSON] ?? [])) === $subjectUuid
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
	 * @spec openspec/specs/flow-runs/spec.md
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
}//end class
