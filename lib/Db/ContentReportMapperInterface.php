<?php

/**
 * ContentReportMapperInterface: what callers outside the component use of ContentReportMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The content report lookups and updates the content report endpoints use.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ContentReportMapperInterface {

	/**
	 * Find by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return ContentReport The report.
	 *
	 * @throws DoesNotExistException When no row matches the id.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function find(int $id): ContentReport;

	/**
	 * List reports, newest first, optionally filtered.
	 *
	 * @param ListQuery $query Optional `status` and `organisation_id` filters (empty is no filter) and the page size
	 *                         (unbounded or not positive is no limit).
	 *
	 * @return ContentReport[] The matching reports.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function findAll(ListQuery $query): array;

	/**
	 * Every report filed against one object, newest first.
	 *
	 * Looked up by uuid rather than by row id on purpose: the object is gone by
	 * the time this matters most, and its row id is gone with it.
	 *
	 * @param string $objectUuid The reported object's uuid.
	 *
	 * @return ContentReport[] The reports.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function findByObjectUuid(string $objectUuid): array;

	/**
	 * Find by uuid.
	 *
	 * @param string $uuid The report uuid.
	 *
	 * @return ContentReport|null Null when no row matches.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function findByUuid(string $uuid): ?ContentReport;

	/**
	 * Insert a report, filling the uuid and the timestamps.
	 *
	 * @param ContentReport $entity The report to insert.
	 *
	 * @return ContentReport The persisted report.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function insert($entity): ContentReport;

	/**
	 * Update a report, moving its change time.
	 *
	 * @param ContentReport $entity The report to update.
	 *
	 * @return ContentReport The persisted report.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function update($entity): ContentReport;
}//end interface
