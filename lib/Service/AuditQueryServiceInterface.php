<?php

/**
 * AuditQueryServiceInterface: what callers outside the component use of AuditQueryService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ListQuery;

/**
 * The audit-trail query the audit query endpoint runs.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AuditQueryServiceInterface {

	/**
	 * Query audit-entry objects across all apps/registers/schemas.
	 *
	 * @param ListQuery $query The filters (supported keys: registerId, schemaId, objectId, app (alias
	 *                         for registerId), timestampStart, timestampEnd, sort (default
	 *                         'created:desc')), the page size (clamped to [1, 200]) and the offset
	 *                         (clamped to >= 0).
	 *
	 * @return array{entries: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
	 *
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.1
	 * @spec openspec/changes/public-audit-query-endpoint/tasks.md#task-1.2
	 */
	public function query(ListQuery $query): array;
}//end interface
