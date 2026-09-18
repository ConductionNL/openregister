<?php

/**
 * What a refused write tells the person who made it.
 *
 * 🔴 A VERSION NUMBER IN A 409 IS ONLY USEFUL TO A MACHINE THAT WILL RETRY
 * (D-1). A person needs the other value. Today the refusal says "the object was
 * modified since it was read" and hands back two timestamps, so the only thing
 * a client can do is reload and make the person find the difference themselves
 * — and between the refusal and the reload the object can change again, so what
 * they compare is not even what they were refused over.
 *
 * This carries three readings per conflicting property: what the caller SENT,
 * what they READ, and what is STORED. A client can render the choice without a
 * second request and without that race.
 *
 * 🔑 THE "READ" VALUE COMES FROM THE AUDIT TRAIL, NOT FROM THE CALLER. The
 * caller sends a timestamp, not the values they saw. The `old` side of the
 * EARLIEST intervening change is, by construction, the value that was there
 * when they read it. Asking the caller to send what they read would let a
 * confused client report a conflict against a value nobody ever stored.
 *
 * 🔴 ONLY THE PROPERTIES THAT ACTUALLY CONFLICT (D-2). A property the caller
 * did not touch is not a conflict even when the stored object changed, and
 * reporting the whole object as conflicting is how people learn to click
 * through the dialog. The body is the INTERSECTION of what the caller changed
 * and what somebody else changed.
 *
 * 🔴 A REFUSAL IS NOT A READ (D-4). The body is filtered by field-level
 * security: a property the caller may not read is named as conflicting with no
 * values attached. An error path that discloses more than the read path is a
 * security bug that looks like a feature, and it is the shape nobody reviews
 * because it only appears when something went wrong.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use DateTimeInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;

/**
 * Build the body of a 409 so it answers the question it raises.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md
 */
class ConflictReport {

	/**
	 * The machine-readable code a client branches on.
	 *
	 * 🔴 IT IS `code`, AND `error` KEEPS THE SENTENCE IT ALWAYS HELD. The rest
	 * of this app puts a slug in `error`, and this endpoint has always put a
	 * sentence there instead. Correcting that today would break every client
	 * branching on the substring "Conflict", which is the shape the published
	 * body invited. So the sentence stays where clients expect it and the code
	 * arrives beside it, and the outlier is retired when somebody can count the
	 * clients rather than guess at them.
	 *
	 * @var string
	 */
	public const CODE = 'version-conflict';

	/**
	 * The sentence `error` has carried since this endpoint shipped.
	 *
	 * @var string
	 */
	public const ERROR = 'Conflict: the object was modified since it was read. Re-read and retry.';

	/**
	 * What a property carries when the caller may not read it.
	 *
	 * A NAMED absence rather than a missing key: "you may not see this" and
	 * "this did not conflict" are different facts, and a client that only
	 * looked for the values would silently show the second.
	 *
	 * @var string
	 */
	public const WITHHELD = 'withheld';

	/**
	 * Constructor.
	 *
	 * @param PropertyRbacHandler $properties Field-level security.
	 */
	public function __construct(
		private readonly PropertyRbacHandler $properties,
	) {
	}//end __construct()

	/**
	 * The conflicting properties, with the three readings each.
	 *
	 * @param ObjectEntity           $stored     The object as it is now.
	 * @param Schema                 $schema     Its schema, for the field filter.
	 * @param array<string, mixed>   $sent       What the caller is trying to write.
	 * @param array<int, AuditTrail> $intervening The changes since the caller read, newest first.
	 *
	 * @return array<string, mixed> The 409 body.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	public function build(ObjectEntity $stored, Schema $schema, array $sent, array $intervening): array {
		$current = ($stored->getObject() ?? []);
		$changedByOthers = $this->changedByOthers(intervening: $intervening);

		$conflicts = [];
		foreach ($sent as $property => $sentValue) {
			$property = (string)$property;

			// 🔑 BOTH HALVES OF THE INTERSECTION. The caller has to have
			// CHANGED it (sending the same value back is not a conflict, it is
			// agreement), and somebody else has to have changed it too.
			if ($this->same(a: $sentValue, b: ($current[$property] ?? null)) === true) {
				continue;
			}

			if (array_key_exists($property, $changedByOthers) === false) {
				continue;
			}

			$conflicts[$property] = $this->readingsFor(
				schema: $schema,
				current: $current,
				property: $property,
				sentValue: $sentValue,
				readValue: $changedByOthers[$property]
			);
		}

		$cause = ($intervening[0] ?? null);

		return [
			'error' => self::ERROR,
			'code' => self::CODE,
			'message' => (($conflicts === [])
				? 'This object changed since you read it. Re-read it and try again.'
				: 'This object changed since you read it, and somebody else wrote the same '
					. ((count($conflicts) === 1) ? 'field' : 'fields') . '.'),
			'conflicts' => $conflicts,
			'changedBy' => (($cause === null) ? null : $this->actorOf(entry: $cause)),
			'changedAt' => (($cause === null) ? null : $cause->getCreated()?->format(DateTimeInterface::ATOM)),
		];
	}//end build()

	/**
	 * The three readings of one property, filtered by what the caller may read.
	 *
	 * @param Schema               $schema    The schema.
	 * @param array<string, mixed> $current   The stored object.
	 * @param string               $property  The property.
	 * @param mixed                $sentValue What the caller sent.
	 * @param mixed                $readValue What was there when they read it.
	 *
	 * @return array<string, mixed> The readings, or the withheld marker.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-the-conflict-body-discloses-no-more-than-a-read-would-req-cso-002
	 */
	private function readingsFor(
		Schema $schema,
		array $current,
		string $property,
		mixed $sentValue,
		mixed $readValue,
	): array {
		if ($this->properties->canReadProperty(schema: $schema, property: $property, object: $current) === false) {
			// Named, valueless. The caller learns their write collided and
			// learns nothing they could not have read.
			return ['status' => self::WITHHELD];
		}

		return [
			'status' => 'conflict',
			'sent' => $sentValue,
			'read' => $readValue,
			'stored' => ($current[$property] ?? null),
		];
	}//end readingsFor()

	/**
	 * Which properties the intervening writes touched, and what each was before.
	 *
	 * The entries arrive NEWEST FIRST and are walked in that order, so the last
	 * assignment wins and the value kept is the `old` of the EARLIEST change:
	 * that is the one the caller was looking at.
	 *
	 * @param array<int, AuditTrail> $intervening The changes since the read.
	 *
	 * @return array<string, mixed> Property to the value the caller read.
	 *
	 * @spec openspec/changes/a-conflicting-save-shows-the-other-value/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
	 */
	private function changedByOthers(array $intervening): array {
		$was = [];
		foreach ($intervening as $entry) {
			$changed = ($entry->getChanged() ?? []);
			if (is_array($changed) === false) {
				continue;
			}

			foreach ($changed as $property => $delta) {
				if (is_array($delta) === false) {
					// A shape this writer never produced. Recording the
					// property without a value is honest; inventing one is not.
					$was[(string)$property] = null;
					continue;
				}

				$was[(string)$property] = ($delta['old'] ?? null);
			}
		}

		return $was;
	}//end changedByOthers()

	/**
	 * Who made a change, by display name where there is one.
	 *
	 * @param AuditTrail $entry The entry.
	 *
	 * @return string The actor.
	 */
	private function actorOf(AuditTrail $entry): string {
		$name = trim((string)$entry->getUserName());
		if ($name !== '') {
			return $name;
		}

		return trim((string)$entry->getUser());
	}//end actorOf()

	/**
	 * Whether two values are the same for conflict purposes.
	 *
	 * LOOSE on scalars by design: a client that round-trips `"3"` where the
	 * store holds `3` has not changed anything, and reporting that as a
	 * conflict is how the dialog becomes noise people click through. Arrays and
	 * objects compare by their encoded form, so key ORDER does not invent a
	 * conflict either.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 *
	 * @return boolean True when they are the same.
	 */
	private function same(mixed $a, mixed $b): bool {
		if (is_scalar($a) === true && is_scalar($b) === true) {
			return ((string)$a === (string)$b);
		}

		if ($a === null || $b === null) {
			return ($a === $b);
		}

		return (json_encode($this->sorted(value: $a)) === json_encode($this->sorted(value: $b)));
	}//end same()

	/**
	 * A value with its arrays key-sorted, so order is not a difference.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The sorted value.
	 */
	private function sorted(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$sorted = [];
		foreach ($value as $key => $item) {
			$sorted[$key] = $this->sorted(value: $item);
		}

		ksort($sorted);

		return $sorted;
	}//end sorted()
}//end class
