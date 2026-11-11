<?php

/**
 * ScheduledReportMapperInterface: the public surface of ScheduledReportMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Every public method of ScheduledReportMapper. Callers depend on this interface, not on the class.
 */
interface ScheduledReportMapperInterface {

	/**
	 * Find a scheduled report by id.
	 *
	 * @param int $id The scheduled report id.
	 *
	 * @return ScheduledReport
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When more than one row matches (should never happen on a PK lookup).
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function find(int $id): ScheduledReport;

	/**
	 * Find every scheduled report owned by a user.
	 *
	 * @param string $owner The owning Nextcloud user id.
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findByOwner(string $owner): array;

	/**
	 * Find every scheduled report (admin listing).
	 *
	 * @return ScheduledReport[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function findAll(): array;

	/**
	 * Insert through the mapper (QBMapper).
	 *
	 * @param ScheduledReport $entity The entity.
	 *
	 * @return ScheduledReport The stored entity.
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update through the mapper (QBMapper).
	 *
	 * @param ScheduledReport $entity The entity.
	 *
	 * @return ScheduledReport The stored entity.
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete through the mapper (QBMapper).
	 *
	 * @param ScheduledReport $entity The entity.
	 *
	 * @return ScheduledReport The stored entity.
	 */
	public function delete(Entity $entity): Entity;
}//end interface
