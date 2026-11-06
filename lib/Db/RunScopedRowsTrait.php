<?php

/**
 * RunScopedRowsTrait: delete the rows of a flow run, or of runs that no longer exist.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Cleanup for mappers whose rows carry a run_uuid and live only as long as their run.
 */
trait RunScopedRowsTrait {

	/**
	 * Drop every row of a run, with the run's own deletion.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int Rows deleted.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function deleteByRun(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		return $qb->executeStatement();
	}//end deleteByRun()

	/**
	 * Drop every row whose run no longer exists: the retention pass prunes runs by age.
	 *
	 * @return int Rows deleted.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function deleteOrphans(): int {
		$runs = $this->db->getQueryBuilder();
		$runs->select('uuid')->from('openregister_flow_runs');

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->createFunction('run_uuid NOT IN (' . $runs->getSQL() . ')'));

		return $qb->executeStatement();
	}//end deleteOrphans()
}//end trait
