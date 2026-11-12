<?php

/**
 * OpenRegister AuditTrailExport
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

/**
 * One export of the audit trail.
 *
 * The operation object AuditTrailPageService::export() builds from its interface signature.
 */
final class AuditTrailExport {

	/**
	 * Constructor.
	 *
	 * @param AuditTrailQuery $query          The filters and search.
	 * @param string          $format         The export format.
	 * @param bool            $includeChanges Whether the change sets go in.
	 * @param string|null     $actor          Who asked, for a queued export.
	 */
	public function __construct(
		public readonly AuditTrailQuery $query,
		public readonly string $format,
		public readonly bool $includeChanges,
		public readonly ?string $actor,
	) {
	}//end __construct()
}//end class
