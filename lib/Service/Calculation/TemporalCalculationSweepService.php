<?php

/**
 * OpenRegister Temporal Calculation Sweep Service
 *
 * The clockwork that makes time-dependent declared behaviour LIVE without
 * object writes (dsar-escalation-and-dpia, closing the pipelinq
 * `consume-or-dsar` deadline-escalation gap): materialised calculations whose
 * expressions reference the evaluation clock (`now`) — e.g. the DSAR
 * `escalationTier` — go stale on objects nobody touches, so the schema's
 * declared `calculatedChange` notification rules (reminder / escalation /
 * breach) never fire for exactly the proactive case they exist for.
 *
 * The sweep periodically re-evaluates those calculations for objects in
 * non-terminal lifecycle states and persists ONLY changed values through the
 * normal `ObjectService` write path. The write re-runs the save-time
 * materialisation listener and emits the standard object-updated event with
 * old+new data, so `AnnotationNotificationListener` evaluates the declared
 * `calculatedChange` rules — zero new notification machinery (ADR-031:
 * behaviour stays declared on the schema; this job is generic clockwork any
 * future `now`-dependent schema inherits for free).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\SweepTarget;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\TemporalCalculations;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\TemporalSchemaScan;
use OCA\OpenRegister\Service\Calculation\TemporalSweep\TemporalSchemaSweeper;

/**
 * Re-materialise `now`-dependent calculations for untouched objects.
 *
 * The enumeration, the temporal detection and the per-schema sweep are each
 * a collaborator in the TemporalSweep namespace; this class runs the pass and
 * keeps the summary.
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
 */
class TemporalCalculationSweepService implements TemporalCalculationSweepServiceInterface {
	/**
	 * Constructor.
	 *
	 * @param TemporalSchemaScan $scan Schema and register enumeration (system scope).
	 * @param TemporalCalculations $calculations Materialised and `now`-dependent calculation detection.
	 * @param TemporalSchemaSweeper $sweeper Sweeps the objects of one temporal schema.
	 * @param MagicTableRowReaderInterface $objectMapper Raw per-table object enumeration.
	 */
	public function __construct(
		private readonly TemporalSchemaScan $scan,
		private readonly TemporalCalculations $calculations,
		private readonly TemporalSchemaSweeper $sweeper,
		private readonly MagicTableRowReaderInterface $objectMapper,
	) {

	}//end __construct()

	/**
	 * Run one sweep over every schema with `now`-dependent materialised
	 * calculations.
	 *
	 * @return array{schemasScanned: int, temporalSchemas: int, objectsEvaluated: int, objectsRewritten: int, errors: int} Sweep summary.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Untouched case crosses the reminder tier)
	 */
	public function runSweep(): array {
		$summary = $this->emptySummary();
		$schemas = $this->scan->schemas();
		if ($schemas === null) {
			$summary['errors']++;
			return $summary;
		}

		$this->sweepAll(schemas: $schemas, summary: $summary);

		return $summary;
	}//end runSweep()

	/**
	 * Sweep every temporal schema that a register holds.
	 *
	 * @param array<int, Schema> $schemas Every schema.
	 * @param array<string, int> $summary Running summary counters (by reference).
	 *
	 * @return void
	 */
	private function sweepAll(array $schemas, array &$summary): void {
		$registers = $this->scan->registers();
		foreach ($schemas as $schema) {
			$summary['schemasScanned']++;
			$target = $this->targetFor(schema: $schema, registers: $registers);
			if ($target === null) {
				continue;
			}

			$summary['temporalSchemas']++;
			$this->sweeper->sweep(target: $target, rows: $this->objectMapper, summary: $summary);
		}
	}//end sweepAll()

	/**
	 * Whether any materialised calculation references the evaluation clock.
	 *
	 * Detects both the `{"now": []}` operator anywhere in the expression tree
	 * and the literal `"now"` argument accepted by `dateDiff`-style
	 * operators.
	 *
	 * @param array<string, mixed> $calculations The schema's materialised calculations.
	 *
	 * @return bool True when at least one expression is time-dependent.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Requirement: Time-dependent calculated fields re-evaluate without object writes)
	 */
	public function hasTemporalCalculation(array $calculations): bool {
		return $this->calculations->anyTemporal(calculations: $calculations);
	}//end hasTemporalCalculation()

	/**
	 * The sweep target for a schema, or null when it is skipped.
	 *
	 * Schemas with no `now`-dependent materialised calculation are skipped
	 * entirely, as are schemas no register holds.
	 *
	 * @param Schema $schema The schema.
	 * @param array<int, Register> $registers All registers.
	 *
	 * @return SweepTarget|null The target, or null.
	 */
	private function targetFor(Schema $schema, array $registers): ?SweepTarget {
		$calcs = $this->calculations->materialised(schema: $schema);
		if ($calcs === [] || $this->hasTemporalCalculation(calculations: $calcs) === false) {
			return null;
		}

		$register = $this->scan->registerOf(schema: $schema, registers: $registers);
		if ($register === null) {
			return null;
		}

		return new SweepTarget(register: $register, schema: $schema, calcs: $calcs);
	}//end targetFor()

	/**
	 * The summary counters, all zero.
	 *
	 * @return array{schemasScanned: int, temporalSchemas: int, objectsEvaluated: int, objectsRewritten: int, errors: int} The summary.
	 */
	private function emptySummary(): array {
		return [
			'schemasScanned' => 0,
			'temporalSchemas' => 0,
			'objectsEvaluated' => 0,
			'objectsRewritten' => 0,
			'errors' => 0,
		];
	}//end emptySummary()
}//end class
