<?php

/**
 * OpenRegister TemporalObjectRefresher
 *
 * Re-saves one object through the normal write path when the recompute of
 * its time-dependent calculations moved a value.
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
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use Psr\Log\LoggerInterface;

/**
 * Refresh one object whose time-dependent calculations moved.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class TemporalObjectRefresher {

	/**
	 * Summary counter for an object that was re-saved.
	 */
	public const REWRITTEN = 'objectsRewritten';

	/**
	 * Summary counter for an object whose refresh failed.
	 */
	public const FAILED = 'errors';

	/**
	 * Wire the recompute, the write path and the logger.
	 *
	 * @param CalculationRecomputer $recomputer Detects a moved calculated value.
	 * @param ObjectOperationsInterface $objectService The normal object write path (events + audit).
	 * @param LoggerInterface $logger Structured logging.
	 * @param SchemaTypeConverter $types Restores the stored form of `type: string` values the read decoded.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CalculationRecomputer $recomputer,
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
		private readonly SchemaTypeConverter $types = new SchemaTypeConverter(),
	) {
	}//end __construct()

	/**
	 * Recompute an object and re-save it when a value changed.
	 *
	 * @param ObjectEntity $object The object.
	 * @param SweepTarget $target Its register, schema and calculations.
	 *
	 * @return string|null The summary counter to bump ({@see self::REWRITTEN}, {@see self::FAILED}), or null when unchanged.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
	 *   (Scenario: Tier crossing notifies exactly once)
	 */
	public function refresh(ObjectEntity $object, SweepTarget $target): ?string {
		try {
			if ($this->recomputer->changes(object: $object, target: $target) === false) {
				// Unchanged recomputation — no write, no duplicate notification.
				return null;
			}

			$this->rewrite(object: $object, target: $target);
			return self::REWRITTEN;
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[TemporalCalculationSweep] recompute failed for object ' . (string)$object->getUuid() . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return self::FAILED;
		}
	}//end refresh()

	/**
	 * Re-save the object UNCHANGED through the normal write path.
	 *
	 * CalculationOnSaveListener re-materialises the calculations (producing
	 * the same new values just detected) and the update event carries
	 * old+new data, so the declared calculatedChange rules fire exactly on
	 * the crossing. System sweep: RBAC and tenancy scoping are bypassed
	 * deliberately (no user session); the write is still audited through the
	 * normal path. The read decodes JSON held in a `type: string` property;
	 * it is handed back as stored.
	 *
	 * @param ObjectEntity $object The object.
	 * @param SweepTarget $target Its register, schema and calculations.
	 *
	 * @return void
	 */
	private function rewrite(ObjectEntity $object, SweepTarget $target): void {
		$schema = $target->schema;
		$this->objectService->saveObject(
			object: $this->types->restoreStringTypedValues(data: ($object->getObject() ?? []), properties: ($schema->getProperties() ?? [])),
			register: $target->register,
			schema: $schema,
			uuid: (string)$object->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
	}//end rewrite()
}//end class
