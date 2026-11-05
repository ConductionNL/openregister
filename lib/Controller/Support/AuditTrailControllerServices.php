<?php

/**
 * OpenRegister audit trail controller services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

/**
 * The service bundles of the audit trail API.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
final class AuditTrailControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param AuditTrailMappers  $mappers  The mappers bundle.
	 * @param AuditTrailLog      $log      The log bundle.
	 * @param AuditTrailReaders  $readers  The readers bundle.
	 * @param AuditTrailPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
	 */
	public function __construct(
		public readonly AuditTrailMappers $mappers,
		public readonly AuditTrailLog $log,
		public readonly AuditTrailReaders $readers,
		public readonly AuditTrailPlatform $platform,
	) {
	}//end __construct()
}//end class
