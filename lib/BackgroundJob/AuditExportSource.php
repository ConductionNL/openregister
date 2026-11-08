<?php

/**
 * Where a queued audit trail export selects and renders its rows
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\AuditTrailPageQueryInterface;
use OCA\OpenRegister\Service\LogService;

/**
 * The row selection and the renderer of a queued audit trail export.
 *
 * @spec openspec/changes/audit-log-page/specs/audit-trail-immutable/spec.md#requirement-the-filtered-audit-list-exports-with-its-hash-chain
 */
final class AuditExportSource {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailPageQueryInterface $pageQuery  Selects the rows.
	 * @param LogService          $logService Renders them.
	 */
	public function __construct(
		public readonly AuditTrailPageQueryInterface $pageQuery,
		public readonly LogService $logService,
	) {
	}//end __construct()
}//end class
