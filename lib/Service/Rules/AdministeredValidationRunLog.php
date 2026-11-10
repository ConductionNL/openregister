<?php

/**
 * Writing an administered validation's outcome to the rule run log.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
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
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records what an administered validation decided, and never throws.
 *
 * 🔴 THE LOG IS A COURTESY ON A DECISION ALREADY TAKEN. By the time anything
 * reaches here the save has been allowed or refused, so losing a row must
 * never turn a refusal into a 500. That is why every write is wrapped and
 * why the listener holds this rather than the recorder: one place decides
 * that a logging failure is survivable, instead of each caller deciding
 * again.
 *
 * The two entry points name the verdict instead of taking it as an argument,
 * so a caller cannot record a refusal as a warning by passing the wrong
 * constant.
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class AdministeredValidationRunLog {

	/**
	 * Constructor.
	 *
	 * @param RuleRunRecorder $ruleRuns The rule run log.
	 * @param LoggerInterface $logger   Where a lost row is noted.
	 */
	public function __construct(
		private readonly RuleRunRecorder $ruleRuns,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record a validation that fired as a warning.
	 *
	 * @param ValidatedWrite       $write The object being saved and its schema.
	 * @param array<string, mixed> $entry The outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function recordWarning(ValidatedWrite $write, array $entry): void {
		$this->record(write: $write, verdict: new ValidationVerdict(entry: $entry, verdict: RuleVocabulary::VERDICT_FIRED));
	}//end recordWarning()

	/**
	 * Record a validation that refused the save.
	 *
	 * @param ValidatedWrite       $write The object being saved and its schema.
	 * @param array<string, mixed> $entry The outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function recordRefusal(ValidatedWrite $write, array $entry): void {
		$this->record(write: $write, verdict: new ValidationVerdict(entry: $entry, verdict: RuleVocabulary::VERDICT_REFUSED));
	}//end recordRefusal()

	/**
	 * Record one validation outcome on the rule run log.
	 *
	 * @param ValidatedWrite    $write   The object and its schema.
	 * @param ValidationVerdict $verdict The outcome and the verdict to record.
	 *
	 * @return void
	 */
	private function record(ValidatedWrite $write, ValidationVerdict $verdict): void {
		if ($write->schemaSlug() === '' || $verdict->validation() === '') {
			return;
		}

		try {
			$this->write(write: $write, verdict: $verdict);
		} catch (Throwable $e) {
			// The run log is a courtesy on a decision already taken. Losing the
			// row must never turn a refusal into a 500.
			$this->logger->warning(sprintf('Administered validation run could not be recorded: %s', $e->getMessage()));
		}
	}//end record()

	/**
	 * Write one validation outcome to the rule run log.
	 *
	 * An outcome that could not be evaluated is logged as an error whatever
	 * verdict the caller named, because nothing was decided.
	 *
	 * @param ValidatedWrite    $write   The object and its schema, which has a slug.
	 * @param ValidationVerdict $verdict The outcome, naming its validation.
	 *
	 * @return void
	 */
	private function write(ValidatedWrite $write, ValidationVerdict $verdict): void {
		$slug = $write->schemaSlug();
		$this->ruleRuns->record(
			subject: new RuleRunSubject(
				ruleId: RuleDescriptor::idFor(kind: RuleVocabulary::KIND_ADMINISTERED_VALIDATION, schemaSlug: $slug, key: $verdict->validation()),
				schemaSlug: $slug,
				objectUuid: ($write->object->getUuid() ?? null),
				registerSlug: ($write->object->getRegister() ?? null)
			),
			trace: $verdict->trace()
		);
	}//end write()
}//end class
