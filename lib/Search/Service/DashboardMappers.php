<?php

/**
 * OpenRegister dashboard mappers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service
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

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\WebhookLogMapperInterface;

/**
 * The mappers the dashboard statistics read from.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
 */
final class DashboardMappers {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface    $catalog          Magic table mapper for object statistics, register mapper for register lookups.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for log statistics.
	 * @param WebhookLogMapperInterface $webhookLogMapper Webhook log mapper for webhook statistics.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-5
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly WebhookLogMapperInterface $webhookLogMapper,
	) {
	}//end __construct()
}//end class
