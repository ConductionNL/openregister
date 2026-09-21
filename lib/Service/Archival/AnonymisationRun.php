<?php

/**
 * Carrying out one anonymisation, or refusing to start it.
 *
 * 🔴 IT REFUSES RATHER THAN GUESSES, EVERY TIME. This act is irreversible and
 * it reaches several stores, so every ambiguity here is answered with a stop
 * and a sentence rather than a best effort:
 *
 *  - a record under a LEGAL HOLD is not anonymised, and the refusal names the
 *    hold. A hold says a court or a regulator may still need this record as it
 *    is, and "as it is" includes the name;
 *  - a record ALREADY anonymised is refused, naming when and under which
 *    profile. Running again would apply the profile to values that are already
 *    tokens, generalise an already-generalised year to itself, and rewrite the
 *    trail a second time, for no gain and with one real risk: under a rotated
 *    salt the pseudonyms would change, so rows that used to join would stop
 *    joining and nothing on any screen would say so;
 *  - a record HALF anonymised is resumed, not restarted, and only under the
 *    same salt. Under a different one it refuses and says so, because finishing
 *    with a second salt leaves one record carrying two token families;
 *  - a plan that would change NOTHING is refused, because an anonymisation that
 *    touches nothing and reports success is the instrument lying about the
 *    thing it measures.
 *
 * 🔴 ALL OR NOTHING, ACHIEVED BY PREPARING EVERYTHING FIRST. Every target is
 * prepared before any target is applied, so a search index that cannot be
 * reached stops the act while the record is still whole. A half-anonymised
 * record is the worst outcome available: the payload no longer names the
 * person, so every screen reports it anonymised, while the index still answers
 * a query for their name and nobody looks again.
 *
 * WHAT A HALF-FINISHED RUN LEAVES. Preparation cannot write, so a failure
 * before the first apply leaves the record untouched. A failure BETWEEN applies
 * is the honest remaining case: the stores are separate and there is no
 * transaction spanning them. That case is made finishable rather than denied —
 * the record is marked `in_progress` with the profile and the salt fingerprint
 * before the first write, and a resume re-derives the same plan from the same
 * declaration and re-applies every target. The targets are written to be
 * idempotent for exactly this reason, and the marker is cleared only once every
 * target has applied.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeImmutable;
use Throwable;

/**
 * Decides whether an anonymisation may run, and runs it all or not at all.
 */
class AnonymisationRun {

	/**
	 * The record has been anonymised and the act is finished.
	 *
	 * @var string
	 */
	public const COMPLETE = 'complete';

	/**
	 * The act began and has not been confirmed finished.
	 *
	 * @var string
	 */
	public const IN_PROGRESS = 'in_progress';

	/**
	 * Where the marker lives on the record.
	 *
	 * @var string
	 */
	public const MARKER_KEY = 'anonymisation';

	/**
	 * Wire the run.
	 *
	 * @param AnonymisationService $service The treatments.
	 * @param AnonymisationPlanner $planner The declared profile.
	 */
	public function __construct(
		private readonly AnonymisationService $service = new AnonymisationService(),
		private readonly AnonymisationPlanner $planner = new AnonymisationPlanner(),
	) {
	}//end __construct()

	/**
	 * Why this record may not be anonymised now, if it may not.
	 *
	 * @param array<string, mixed> $marker          Its anonymisation marker, if any.
	 * @param bool                 $hasLegalHold    Whether a hold is active.
	 * @param string               $holdReason      The hold's reason, for the refusal.
	 * @param string               $saltFingerprint The salt this run would use.
	 *
	 * @return string|null The refusal, or null when it may run.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function refuse(array $marker, bool $hasLegalHold, string $holdReason, string $saltFingerprint): ?string {
		if ($hasLegalHold === true) {
			$reason = trim($holdReason);
			if ($reason === '') {
				$reason = 'no reason was recorded with the hold';
			}

			return sprintf(
				'This record is under a legal hold (%s), so it is not anonymised. A hold says somebody '
				.'may still need it as it is, and as it is includes the name.',
				$reason
			);
		}

		$state = (string)($marker['state'] ?? '');

		if ($state === self::COMPLETE) {
			return sprintf(
				'This record was already anonymised on %s under the profile "%s". Running again would '
				.'change nothing it has not changed, and under a rotated salt it would give its '
				.'pseudonyms new values, so rows that used to join would stop joining with nothing '
				.'on screen to say so.',
				(string)($marker['anonymisedAt'] ?? 'an unrecorded date'),
				(string)($marker['profile'] ?? 'unnamed')
			);
		}

		if ($state === self::IN_PROGRESS && (string)($marker['saltFingerprint'] ?? '') !== $saltFingerprint) {
			return 'This record was left half anonymised under a different salt. Finishing it now would '
				.'leave one record carrying two families of pseudonym, so it needs the original salt '
				.'or a decision to start again.';
		}

		return null;
	}//end refuse()

	/**
	 * Build the plan for one record, or refuse it.
	 *
	 * @param string               $objectUuid      The record.
	 * @param array<string, mixed> $payload         Its properties.
	 * @param array<string, mixed> $annotation      Its schema's archival annotation.
	 * @param string               $salt            The instance salt.
	 * @param string               $profileName     What to call the profile in the report.
	 *
	 * @return AnonymisationPlan The plan.
	 *
	 * @throws AnonymisationRefusedException When the plan would change nothing.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function plan(string $objectUuid, array $payload, array $annotation, string $salt, string $profileName = ''): AnonymisationPlan {
		$this->planner->assertSound(annotation: $annotation, declaredProperties: array_keys($payload));

		$profile = $this->planner->profileOf(annotation: $annotation);
		$after = $this->service->apply(payload: $payload, profile: $profile, salt: $salt);
		$report = $this->service->report(before: $payload, after: $after, profileName: $profileName);

		$plan = new AnonymisationPlan(
			objectUuid: $objectUuid,
			profileName: $profileName,
			before: $payload,
			after: $after,
			changedProperties: array_keys($report['changed']),
			keptProperties: $report['kept'],
			saltFingerprint: $this->fingerprint(salt: $salt)
		);

		if ($plan->touchesAnything() === false) {
			// 🔴 A RUN THAT CHANGES NOTHING MUST NOT REPORT SUCCESS. That is the
			// instrument lying about the thing it measures, and the record would
			// afterwards be marked anonymised while holding every value it held
			// before.
			throw new AnonymisationRefusedException(
				message: sprintf(
					'Anonymising %s would change nothing: the declared profile touches no property this record carries.',
					$objectUuid
				)
			);
		}

		return $plan;
	}//end plan()

	/**
	 * Apply a plan to every target, or to none of them.
	 *
	 * @param AnonymisationPlan                $plan    The plan.
	 * @param array<int, AnonymisationTarget>  $targets Every store it must reach.
	 *
	 * @return array<string, mixed> The report, once every target has applied.
	 *
	 * @throws AnonymisationRefusedException When any target cannot be reached.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function apply(AnonymisationPlan $plan, array $targets): array {
		if ($targets === []) {
			// Nothing to apply to is not a successful anonymisation. It is a
			// misconfiguration that would otherwise mark the record anonymised
			// while leaving every copy of it intact.
			throw new AnonymisationRefusedException(
				message: sprintf('Anonymising %s was asked for with no stores to reach.', $plan->objectUuid)
			);
		}

		foreach ($targets as $target) {
			try {
				$target->prepare(plan: $plan);
			} catch (Throwable $error) {
				throw new AnonymisationRefusedException(
					message: sprintf(
						'Anonymising %s was stopped before anything was written: %s could not be reached (%s). The record is unchanged.',
						$plan->objectUuid,
						$target->name(),
						$error->getMessage()
					),
					previous: $error
				);
			}
		}

		$applied = [];
		foreach ($targets as $target) {
			try {
				$target->apply(plan: $plan);
				$applied[] = $target->name();
			} catch (Throwable $error) {
				$written = 'no store was written';
				if ($applied !== []) {
					$written = 'writing '.implode(', ', $applied);
				}

				// 🔴 THIS IS THE CASE THAT CANNOT BE UNDONE, AND THE MESSAGE
				// SAYS SO RATHER THAN IMPLYING A ROLLBACK THAT DID NOT HAPPEN.
				// The stores are separate and no transaction spans them. What
				// the run guarantees instead is that the record is marked
				// in_progress with this salt, so a resume re-derives the same
				// plan and re-applies every target.
				throw new AnonymisationRefusedException(
					message: sprintf(
						'Anonymising %s was interrupted after %s. The record is marked in progress and '
						.'can be finished by running it again with the same salt; it must not be '
						.'treated as anonymised until it is. %s failed: %s',
						$plan->objectUuid,
						$written,
						$target->name(),
						$error->getMessage()
					),
					previous: $error
				);
			}
		}

		return $this->marker(plan: $plan, state: self::COMPLETE);
	}//end apply()

	/**
	 * The marker written on the record before the first write, and after the last.
	 *
	 * @param AnonymisationPlan $plan  The plan.
	 * @param string            $state complete or in_progress.
	 *
	 * @return array<string, mixed> The marker.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function marker(AnonymisationPlan $plan, string $state): array {
		return [
			'state' => $state,
			'profile' => $plan->profileName,
			'saltFingerprint' => $plan->saltFingerprint,
			'anonymisedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'changed' => $plan->changedProperties,
			'kept' => $plan->keptProperties,
		];
	}//end marker()

	/**
	 * A fingerprint of the salt, which is not the salt.
	 *
	 * Stored on the record so a resume can tell whether the salt has rotated.
	 * A fingerprint rather than the salt itself, because the salt is what makes
	 * the pseudonyms unguessable and a record is the one place it must not be.
	 *
	 * @param string $salt The instance salt.
	 *
	 * @return string The fingerprint.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function fingerprint(string $salt): string {
		return substr(hash('sha256', 'anonymisation-salt::'.$salt), 0, 12);
	}//end fingerprint()
}//end class
