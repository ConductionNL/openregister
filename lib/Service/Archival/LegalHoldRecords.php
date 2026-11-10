<?php

/**
 * OpenRegister legal hold records
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
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

namespace OCA\OpenRegister\Service\Archival;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * Where a legal hold reads and writes the held objects and records what it did.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class LegalHoldRecords {
	/**
	 * Bundle the collaborators.
	 *
	 * @param MagicMapperInterface      $objectMapper     Object entity data mapper.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper for logging.
	 * @param ?RetentionRowScanner      $scanner          Walks every table a retention decision can live in, for the list of held records.
	 */
	public function __construct(
		public readonly MagicMapperInterface $objectMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly ?RetentionRowScanner $scanner =null,
	) {
	}//end __construct()
}//end class
