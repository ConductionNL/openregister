<?php

/**
 * OpenRegister CalculationRecomputer
 *
 * Recomputes an object's materialised calculations the way the save-time
 * listener does and reports whether any value moved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation\TemporalSweep
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation\TemporalSweep;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use Psr\Log\LoggerInterface;

/**
 * Detect whether recomputing an object's calculations changes a stored value.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class CalculationRecomputer {

	/**
	 * Wire the evaluator, the payload builder and the logger.
	 *
	 * @param CalculationEvaluatorInterface $evaluator Expression evaluator (exposes `now`).
	 * @param CalculationPayloadBuilder $payloadBuilder Shared @self/@ref/@aggregate payload prep.
	 * @param LoggerInterface $logger Structured logging.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalculationEvaluatorInterface $evaluator,
		private readonly CalculationPayloadBuilder $payloadBuilder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recompute the target's materialised calculations for an object and
	 * report whether ANY value differs from the stored one.
	 *
	 * Evaluates sequentially in declaration order against the shared payload
	 * shape (`@self` / `@ref` / `@aggregate` injected), exactly like the
	 * save-time listener, so a calculation may reference an earlier one.
	 *
	 * @param ObjectEntity $object The object to probe.
	 * @param SweepTarget $target The schema and its materialised calculations.
	 *
	 * @return bool True when at least one recomputed value changed.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Tier crossing notifies exactly once)
	 * @spec openspec/changes/calculations-resolve-references-regardless-of-saver/specs/computed-fields/spec.md
	 */
	public function changes(ObjectEntity $object, SweepTarget $target): bool {
		$run = new RecomputeRun(object: $object, payload: $this->payloadBuilder->build(object: $object, schema: $target->schema));
		foreach ($target->calcs as $name => $spec) {
			$run->name = (string)$name;
			$this->recompute(run: $run, expression: ($spec['expression'] ?? null));
		}

		return $run->changed;
	}//end changes()

	/**
	 * Recompute the run's current calculation into its payload, noting whether the value changed.
	 *
	 * @param RecomputeRun $run        The pass, positioned on one calculation.
	 * @param mixed        $expression The calculation's expression.
	 *
	 * @return void
	 */
	private function recompute(RecomputeRun $run, mixed $expression): void {
		if ($this->isSkipped(payload: $run->payload, expression: $expression) === true) {
			return;
		}

		try {
			$value = $this->evaluate(payload: $run->payload, expression: $expression);
		} catch (EvaluationException $e) {
			// A failing calculation is skipped like the save-time listener skips it.
			$this->warnFailed(label: sprintf('"%s" failed on %s', $run->name, (string)$run->object->getUuid()), failure: $e);
			return;
		}

		$run->changed = ($run->changed || ($run->payload[$run->name] ?? null) !== $value);
		// Later calculations may reference this one's fresh value.
		$run->payload[$run->name] = $value;
	}//end recompute()

	/**
	 * Log a failing calculation; it never fails the sweep.
	 *
	 * @param string $label Names the calculation and the object.
	 * @param EvaluationException $failure The evaluation failure.
	 *
	 * @return void
	 */
	private function warnFailed(string $label, EvaluationException $failure): void {
		$this->logger->warning(
			message: '[TemporalCalculationSweep] calculation ' . $label . ': ' . $failure->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warnFailed()

	/**
	 * Whether a calculation is left out of the recompute.
	 *
	 * A `sequence` only resolves on the create path, where a SequenceContext
	 * is active; the sweep never has one. Recomputing here would render the
	 * node as "" and report a spurious change on every pass (and, before
	 * openregister#3075, persist the truncated value). And, mirroring the
	 * save-time listener, a calculation reading a reference that could not be
	 * resolved keeps its stored value, so it is no change worth a write.
	 *
	 * @param array<string, mixed> $payload The evaluation payload.
	 * @param mixed $expression The calculation's expression.
	 *
	 * @return bool True when the calculation is skipped.
	 */
	private function isSkipped(array $payload, mixed $expression): bool {
		if ($this->evaluator->expressionUsesSequence($expression) === true) {
			return true;
		}

		return $this->payloadBuilder->unresolvedReferencesUsedBy(payload: $payload, expression: $expression) !== [];
	}//end isSkipped()

	/**
	 * Evaluate an expression, dates rendered in ATOM form.
	 *
	 * @param array<string, mixed> $payload The evaluation payload.
	 * @param mixed $expression The calculation's expression.
	 *
	 * @return mixed The value.
	 *
	 * @throws EvaluationException When the expression fails.
	 */
	private function evaluate(array $payload, mixed $expression): mixed {
		$value = $this->evaluator->evaluate($payload, $expression);
		if ($value instanceof \DateTimeInterface) {
			return $value->format(DATE_ATOM);
		}

		return $value;
	}//end evaluate()
}//end class
