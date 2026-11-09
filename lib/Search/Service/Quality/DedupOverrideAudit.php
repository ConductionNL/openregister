<?php

/**
 * OpenRegister dedup override audit
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
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

namespace OCA\OpenRegister\Search\Service\Quality;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Where an overridden duplicate block is recorded, and where a failure to record it is reported.
 *
 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
 */
final class DedupOverrideAudit {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit writer for the override record.
	 * @param LoggerInterface           $logger           PSR logger.
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-schema-declares-what-a-strong-match-does-at-create
	 */
	public function __construct(
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
