<?php

/**
 * OpenRegister register statistics
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\MagicMapper\StatisticsScope;
use OCA\OpenRegister\Db\Register;

/**
 * The object counts the registers controller reports.
 *
 * Audit figures stay in the controller: who may see them is its decision.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterStatistics {
	/**
	 * Keep the services the counts come from.
	 *
	 * @param RegistersControllerServices $services The controller's services.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		private readonly RegistersControllerServices $services,
	) {
	}//end __construct()

	/**
	 * Per register, the object counts of each of its schemas.
	 *
	 * @param array<int, Register> $registers The registers.
	 *
	 * @return array<int, array> Schema counts by register id.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function schemaCounts(array $registers): array {
		$schemasById = $this->schemasOf(registers: $registers);
		$counts      = [];
		foreach ($registers as $register) {
			$registerId          = (int) $register->getId();
			$counts[$registerId] = $this->services->registers->registerService->getSchemaObjectCounts(
				registerId: $registerId,
				schemas: $this->knownSchemas(register: $register, schemasById: $schemasById)
			);
		}

		return $counts;
	}//end schemaCounts()

	/**
	 * A register's object totals, summed from its schema counts when there are any.
	 *
	 * @param int        $registerId  The register id.
	 * @param array|null $schemaStats The per-schema counts, null when not computed.
	 *
	 * @return array{total: int, size: int, invalid: int, deleted: int, locked: int} The totals.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function objectTotals(int $registerId, ?array $schemaStats): array {
		if ($schemaStats === null) {
			return $this->services->mappers->objectEntityMapper->getStatistics(scope: new StatisticsScope(registerId: $registerId));
		}

		$totals = ['total' => 0, 'size' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0];
		foreach ($schemaStats as $stat) {
			$totals = $this->withSchemaCounts(totals: $totals, stat: $stat);
		}

		return $totals;
	}//end objectTotals()

	/**
	 * All schemas of the registers, fetched at once.
	 *
	 * @param array<int, Register> $registers The registers.
	 *
	 * @return array<int, mixed> The schemas by id.
	 */
	private function schemasOf(array $registers): array {
		$schemaIds = [];
		foreach ($registers as $register) {
			foreach (($register->getSchemas() ?? []) as $schemaId) {
				$schemaIds[] = (int) $schemaId;
			}
		}

		return $this->services->mappers->schemaMapper->findMultipleOptimized(ids: array_values(array_unique($schemaIds)));
	}//end schemasOf()

	/**
	 * The schemas of a register that still exist.
	 *
	 * @param Register          $register    The register.
	 * @param array<int, mixed> $schemasById The fetched schemas by id.
	 *
	 * @return array<int, mixed> The schemas.
	 */
	private function knownSchemas(Register $register, array $schemasById): array {
		$schemas = [];
		foreach (($register->getSchemas() ?? []) as $schemaId) {
			if (isset($schemasById[(int) $schemaId]) === true) {
				$schemas[] = $schemasById[(int) $schemaId];
			}
		}

		return $schemas;
	}//end knownSchemas()

	/**
	 * Totals with one schema's counts added.
	 *
	 * A schema's `total` counts every row and `deleted` is a subset of it, so the
	 * register total subtracts it: `total` means live objects, as in getStatistics().
	 *
	 * @param array<string, int>   $totals The totals so far.
	 * @param array<string, mixed> $stat   The schema's counts.
	 *
	 * @return array<string, int> The new totals.
	 */
	private function withSchemaCounts(array $totals, array $stat): array {
		$deleted            = (int) ($stat['deleted'] ?? 0);
		$totals['total']   += max(0, ((int) ($stat['total'] ?? 0) - $deleted));
		$totals['deleted'] += $deleted;
		foreach (['invalid', 'locked', 'size'] as $key) {
			$totals[$key] += (int) ($stat[$key] ?? 0);
		}

		return $totals;
	}//end withSchemaCounts()
}//end class
