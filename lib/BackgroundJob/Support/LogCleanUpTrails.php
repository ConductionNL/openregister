<?php

/**
 * OpenRegister log clean-up trails
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SearchTrailCleanupInterface;
use OCA\OpenRegister\Db\StateHistoryMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\MetricsRetentionInterface;

/**
 * The audit trail, search trail, state-history projection and metrics the hourly log clean-up cuts to their retention.
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
 */
final readonly class LogCleanUpTrails {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface    $auditTrailMapper  The audit trail mapper.
	 * @param SearchTrailCleanupInterface  $searchTrailMapper The search trail mapper.
	 * @param ?StateHistoryMapperInterface $stateHistory      The derived state-history projection, pruned with the trail.
	 * @param ?MetricsRetentionInterface   $metrics           The operational metrics, cut to their retention.
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	public function __construct(
		public AuditTrailMapperInterface $auditTrailMapper,
		public SearchTrailCleanupInterface $searchTrailMapper,
		public ?StateHistoryMapperInterface $stateHistory = null,
		public ?MetricsRetentionInterface $metrics = null,
	) {
	}//end __construct()
}//end class
