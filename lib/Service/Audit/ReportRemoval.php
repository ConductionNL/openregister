<?php

/**
 * The removal of reported content, as it is noted on each open report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTime;

/**
 * When the content was removed and which audit entry records it.
 *
 * One instant is shared by every report the removal is noted on, so the
 * reports of one object agree on when it went.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class ReportRemoval {
	/**
	 * Constructor.
	 *
	 * @param DateTime    $at        The instant of the removal.
	 * @param string|null $auditUuid The uuid of the audit entry recording the removal.
	 */
	public function __construct(
		public readonly DateTime $at,
		public readonly ?string $auditUuid,
	) {
	}//end __construct()
}//end class
