<?php

/**
 * OpenRegister TemporalSchemaSweeper
 *
 * Sweeps one register+schema pair: refreshes every object in a
 * non-terminal lifecycle state.
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

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Sweep the objects of one temporal schema.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class TemporalSchemaSweeper {

	/**
	 * Wire the object enumeration, the lifecycle reader, the refresher and the logger.
	 *
	 * @param MagicMapperInterface $objectMapper Raw per-table object enumeration.
	 * @param LifecycleTerminals $terminals Lifecycle field + terminal states.
	 * @param TemporalObjectRefresher $refresher Recomputes and re-saves one object.
	 * @param LoggerInterface $logger Structured logging.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly LifecycleTerminals $terminals,
		private readonly TemporalObjectRefresher $refresher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Recompute the materialised calculations for every object in a
	 * non-terminal lifecycle state and rewrite only objects whose recomputed
	 * values changed.
	 *
	 * @param SweepTarget $target The register, schema and calculations.
	 * @param array<string, int> $summary Running summary counters (by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Tier crossing notifies exactly once)
	 */
	public function sweep(SweepTarget $target, array &$summary): void {
		$objects = $this->objects(target: $target);
		if ($objects === null) {
			$summary['errors']++;
			return;
		}

		$this->sweepObjects(objects: $objects, target: $target, summary: $summary);
	}//end sweep()

	/**
	 * Refresh every object outside a terminal lifecycle state.
	 *
	 * @param array<int, ObjectEntity> $objects The target's objects.
	 * @param SweepTarget $target The register, schema and calculations.
	 * @param array<string, int> $summary Running summary counters (by reference).
	 *
	 * @return void
	 */
	private function sweepObjects(array $objects, SweepTarget $target, array &$summary): void {
		$terminals = $this->terminals->of(schema: $target->schema);
		foreach ($objects as $object) {
			// Terminal cases are left alone (spec scenario) — their clock no
			// longer matters and rewriting them would churn finalised dossiers.
			if ($this->terminals->isTerminal(terminals: $terminals, data: ($object->getObject() ?? [])) === true) {
				continue;
			}

			$summary['objectsEvaluated']++;
			$counter = $this->refresher->refresh(object: $object, target: $target);
			if ($counter !== null) {
				$summary[$counter]++;
			}
		}
	}//end sweepObjects()

	/**
	 * The target's objects, or null after logging a failed enumeration.
	 *
	 * @param SweepTarget $target The register and schema.
	 *
	 * @return array<int, ObjectEntity>|null The objects.
	 */
	private function objects(SweepTarget $target): ?array {
		try {
			return $this->objectMapper->findAllInRegisterSchemaTable(register: $target->register, schema: $target->schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[TemporalCalculationSweep] object enumeration failed for schema ' . (string)$target->schema->getSlug() . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end objects()
}//end class
