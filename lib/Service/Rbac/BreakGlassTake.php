<?php

/**
 * One emergency grant taken on one record: who, which verbs, from when, until when.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * The grant a take carries, as the chained audit trail stores it.
 *
 * THE TRAIL ROW IS THE GRANT. A take is written once, as a sealed entry of the
 * object's chained audit trail, and is read back from there when access is
 * evaluated. Nothing is written onto the record's authorization block, so no
 * other reader's access changes, and a grant that cannot be edited cannot be
 * quietly lengthened: the only way to extend it is another take, which the
 * policy bounds and the trail records.
 *
 * Moments are Unix timestamps internally and ISO-8601 in UTC on the trail, so
 * the server's timezone never changes when a grant ends.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
 */
final class BreakGlassTake {

	/**
	 * Constructor.
	 *
	 * @param string             $grantee    The user who took it.
	 * @param array<int, string> $verbs      The verbs it carries.
	 * @param int                $takenAt    When it was taken.
	 * @param int                $until      When it ends by itself.
	 * @param int                $chainStart When the first take of an unbroken run of takes began.
	 */
	public function __construct(
		public readonly string $grantee,
		public readonly array $verbs,
		public readonly int $takenAt,
		public readonly int $until,
		public readonly int $chainStart,
	) {
	}//end __construct()

	/**
	 * Whether the grant holds at this moment: taken, and not yet ended.
	 *
	 * @param int $now The moment.
	 *
	 * @return bool True while it holds.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function holdsAt(int $now): bool {
		return $this->takenAt <= $now && $now < $this->until;
	}//end holdsAt()

	/**
	 * Whether the grant carries the verb at this moment.
	 *
	 * @param string $verb The verb.
	 * @param int    $now  The moment.
	 *
	 * @return bool True when it holds and names the verb.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function grantsAt(string $verb, int $now): bool {
		return $this->holdsAt(now: $now) === true && in_array($verb, $this->verbs, true) === true;
	}//end grantsAt()

	/**
	 * The trail context of this take, with the reason it was taken for.
	 *
	 * @param string $reason The reason given.
	 *
	 * @return array<string, mixed> The context.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/audit-trail-immutable/spec.md
	 */
	public function toContext(string $reason): array {
		return [
			'grantee' => $this->grantee,
			'verbs' => $this->verbs,
			'reason' => $reason,
			'takenAt' => self::moment(timestamp: $this->takenAt),
			'until' => self::moment(timestamp: $this->until),
			'chainStart' => self::moment(timestamp: $this->chainStart),
		];
	}//end toContext()

	/**
	 * A take read back from its trail context, or null when the context is not one.
	 *
	 * @param mixed $context The `changed` value of a take's trail row.
	 *
	 * @return self|null The take.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public static function fromContext(mixed $context): ?self {
		if (is_array($context) === false || is_string($context['grantee'] ?? null) === false || $context['grantee'] === '') {
			return null;
		}

		$verbs = ($context['verbs'] ?? null);
		if (is_array($verbs) === false || $verbs === []) {
			return null;
		}

		$takenAt = self::timestampOf(value: ($context['takenAt'] ?? null));
		$until = self::timestampOf(value: ($context['until'] ?? null));
		if ($takenAt === null || $until === null || $until <= $takenAt) {
			return null;
		}

		return new self(
			grantee: $context['grantee'],
			verbs: array_values(array_filter($verbs, 'is_string')),
			takenAt: $takenAt,
			until: $until,
			chainStart: (self::timestampOf(value: ($context['chainStart'] ?? null)) ?? $takenAt)
		);
	}//end fromContext()

	/**
	 * A timestamp as an ISO-8601 moment in UTC.
	 *
	 * @param int $timestamp The timestamp.
	 *
	 * @return string The moment.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/audit-trail-immutable/spec.md
	 */
	public static function moment(int $timestamp): string {
		return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
	}//end moment()

	/**
	 * The timestamp of an ISO-8601 moment, or null when it is not one.
	 *
	 * @param mixed $value The moment.
	 *
	 * @return int|null The timestamp.
	 */
	private static function timestampOf(mixed $value): ?int {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Throwable $e) {
			return null;
		}
	}//end timestampOf()
}//end class
