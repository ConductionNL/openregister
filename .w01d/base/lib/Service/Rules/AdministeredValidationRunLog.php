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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
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
	 * @param ObjectEntity         $object The object being saved.
	 * @param Schema               $schema Its schema.
	 * @param array<string, mixed> $entry  The outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function recordWarning(ObjectEntity $object, Schema $schema, array $entry): void {
		$this->record(object: $object, schema: $schema, entry: $entry, verdict: RuleVocabulary::VERDICT_FIRED);
	}//end recordWarning()

	/**
	 * Record a validation that refused the save.
	 *
	 * @param ObjectEntity         $object The object being saved.
	 * @param Schema               $schema Its schema.
	 * @param array<string, mixed> $entry  The outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
	 */
	public function recordRefusal(ObjectEntity $object, Schema $schema, array $entry): void {
		$this->record(object: $object, schema: $schema, entry: $entry, verdict: RuleVocabulary::VERDICT_REFUSED);
	}//end recordRefusal()

	/**
	 * Record one validation outcome on the rule run log.
	 *
	 * @param ObjectEntity         $object  The object.
	 * @param Schema               $schema  Its schema.
	 * @param array<string, mixed> $entry   The outcome.
	 * @param string               $verdict The verdict to record.
	 *
	 * @return void
	 */
	private function record(ObjectEntity $object, Schema $schema, array $entry, string $verdict): void {
		$slug = (string)($schema->getSlug() ?? '');
		$name = (string)($entry['validation'] ?? '');
		if ($slug === '' || $name === '') {
			return;
		}

		$entryVerdict = $verdict;
		if (($entry['unevaluable'] ?? false) === true) {
			$entryVerdict = RuleVocabulary::VERDICT_ERROR;
		}

		try {
			$this->ruleRuns->record(
				ruleId: RuleDescriptor::idFor(
					kind: RuleVocabulary::KIND_ADMINISTERED_VALIDATION,
					schemaSlug: $slug,
					key: $name
				),
				schemaSlug: $slug,
				trace: new RuleTrace(
					verdict: $entryVerdict,
					operand: implode(', ', ($entry['properties'] ?? [])),
					message: (string)($entry['message'] ?? '')
				),
				objectUuid: ($object->getUuid() ?? null),
				registerSlug: ($object->getRegister() ?? null)
			);
		} catch (Throwable $e) {
			// The run log is a courtesy on a decision already taken. Losing the
			// row must never turn a refusal into a 500.
			$this->logger->warning(
				sprintf('Administered validation run could not be recorded: %s', $e->getMessage())
			);
		}
	}//end record()
}//end class
