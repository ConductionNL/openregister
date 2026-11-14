<?php

/**
 * QualityAuditMapperInterface — the audit definition store, as callers outside Db use it.
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
 * The methods of QualityAuditMapper that callers outside the Db component use.
 *
 * Callers type against this interface; the registrar binds it to QualityAuditMapper.
 */
interface QualityAuditMapperInterface {

	/**
	 * Find an audit by its numeric id.
	 *
	 * @param int $id The audit id.
	 *
	 * @return QualityAudit The audit.
	 *
	 * @throws DoesNotExistException When no such audit exists.
	 */
	public function find(int $id): QualityAudit;

	/**
	 * One page of audits, by name.
	 *
	 * @param ListQuery $query The page.
	 *
	 * @return QualityAudit[] The audits.
	 */
	public function findPage(ListQuery $query): array;

	/**
	 * Every audit that declares a schedule.
	 *
	 * @return QualityAudit[] The scheduled audits.
	 */
	public function findScheduled(): array;

	/**
	 * Persist an audit.
	 *
	 * @param QualityAudit $audit The audit.
	 *
	 * @return QualityAudit The persisted audit.
	 */
	public function persist(QualityAudit $audit): QualityAudit;

	/**
	 * Remove an audit.
	 *
	 * @param QualityAudit $audit The audit.
	 *
	 * @return QualityAudit The removed audit.
	 */
	public function remove(QualityAudit $audit): QualityAudit;
}//end interface
