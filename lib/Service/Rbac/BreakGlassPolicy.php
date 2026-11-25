<?php

/**
 * How long a new emergency take lasts, and when taking it again is refused.
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

use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * Pure: the clock is passed in, so a test can stand four hours later.
 *
 * A TAKE GRANTS EXACTLY THE DECLARED PERIOD. The caller never names a duration:
 * the schema's `maxDuration` is the period, so the grant cannot be asked to be
 * longer than the declaration allows.
 *
 * TAKING IT AGAIN DOES NOT RESTART THE CLOCK. While a take still holds, a new
 * take belongs to the same run, and a run may not reach beyond its first take
 * plus the administered limit. With no limit administered the limit IS the
 * declared period, so a second take while the first still holds is refused:
 * the grant cannot be extended at all unless an administrator allows it. A
 * limit shorter than the declared period cannot shorten a first take; the
 * declaration governs that, the limit governs only extension.
 *
 * Once a run has ended, a new take starts a new run. That is a fresh emergency,
 * announced and reasoned again, which is exactly what the trail should show.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
 */
final class BreakGlassPolicy {

	/**
	 * The take a principal may have now.
	 *
	 * @param BreakGlassDeclaration $declaration  The schema's declaration.
	 * @param string                $grantee      Who takes it.
	 * @param array<int, BreakGlassTake> $priorTakes That grantee's earlier takes on this record.
	 * @param int                   $now          The moment it is taken.
	 * @param int|null              $limitSeconds The administered limit on one run; null means the declared period.
	 *
	 * @return BreakGlassTake The new take.
	 *
	 * @throws NotAuthorizedException When the run has reached its limit.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function take(
		BreakGlassDeclaration $declaration,
		string $grantee,
		array $priorTakes,
		int $now,
		?int $limitSeconds = null,
	): BreakGlassTake {
		$current = $this->currentRun(priorTakes: $priorTakes, grantee: $grantee, now: $now);
		$until = $now + $declaration->durationSeconds;
		if ($current === null) {
			return new BreakGlassTake(grantee: $grantee, verbs: $declaration->verbs, takenAt: $now, until: $until, chainStart: $now);
		}

		$limit = max(($limitSeconds ?? 0), $declaration->durationSeconds);
		$until = min($until, $current->chainStart + $limit);
		if ($until <= $current->until) {
			throw new NotAuthorizedException(
				message: 'Emergency access is already held on this record and cannot be extended beyond the administered limit'
			);
		}

		return new BreakGlassTake(
			grantee: $grantee,
			verbs: $declaration->verbs,
			takenAt: $now,
			until: $until,
			chainStart: $current->chainStart
		);
	}//end take()

	/**
	 * The take of this grantee that still holds now, the one ending last, or null.
	 *
	 * @param array<int, BreakGlassTake> $priorTakes The earlier takes.
	 * @param string                     $grantee    The grantee.
	 * @param int                        $now        The moment.
	 *
	 * @return BreakGlassTake|null The take holding now.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function currentRun(array $priorTakes, string $grantee, int $now): ?BreakGlassTake {
		$current = null;
		foreach ($priorTakes as $take) {
			if (($take instanceof BreakGlassTake) === false || $take->grantee !== $grantee || $take->holdsAt(now: $now) === false) {
				continue;
			}

			if ($current === null || $take->until > $current->until) {
				$current = $take;
			}
		}

		return $current;
	}//end currentRun()
}//end class
