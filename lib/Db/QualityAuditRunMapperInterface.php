<?php

/**
 * QualityAuditRunMapperInterface — the run store of quality audits, as callers outside Db use it.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-quality-scoring/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of QualityAuditRunMapper that callers outside the Db component use.
 *
 * Callers type against this interface; the registrar binds it to QualityAuditRunMapper.
 */
interface QualityAuditRunMapperInterface {

	/**
	 * Find a run by its numeric id.
	 *
	 * @param int $id The run id.
	 *
	 * @return QualityAuditRun The run.
	 *
	 * @throws DoesNotExistException When no such run exists.
	 */
	public function find(int $id): QualityAuditRun;

	/**
	 * One page of an audit's runs, oldest first.
	 *
	 * @param int       $auditId The audit id.
	 * @param ListQuery $query   The page.
	 *
	 * @return QualityAuditRun[] The runs.
	 */
	public function findByAudit(int $auditId, ListQuery $query): array;

	/**
	 * Store a run.
	 *
	 * @param QualityAuditRun $run The run.
	 *
	 * @return QualityAuditRun The stored run.
	 */
	public function persist(QualityAuditRun $run): QualityAuditRun;

	/**
	 * Store the failing records of a run.
	 *
	 * @param int                                                          $runId    The run id.
	 * @param array<int, array{record: string, rules: array<int, string>}> $failures The failures.
	 *
	 * @return void
	 */
	public function addFailures(int $runId, array $failures): void;

	/**
	 * One page of a run's failing records.
	 *
	 * @param int       $runId The run id.
	 * @param ListQuery $query The page.
	 *
	 * @return QualityAuditFailure[] The failures.
	 */
	public function findFailures(int $runId, ListQuery $query): array;

	/**
	 * Remove every run of an audit and their failing records.
	 *
	 * @param int $auditId The audit id.
	 *
	 * @return void
	 */
	public function deleteByAudit(int $auditId): void;
}//end interface
