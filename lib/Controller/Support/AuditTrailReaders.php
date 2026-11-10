<?php

/**
 * OpenRegister audit trail domain
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

use OCA\OpenRegister\Service\Audit\ObjectHistoryForReadersInterface;
use OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMaskerInterface;

/**
 * The domain services of the audit trail API.
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
 */
final class AuditTrailReaders {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectHistoryForReadersInterface    $historyForReaders One object's history for a non-admin reader of it.
	 * @param ?ExplicitRevealTrailMaskerInterface $revealMasker      Masks explicit-reveal values in the admin lists.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-use-cryptographic-hash-chaining
	 */
	public function __construct(
		public readonly ObjectHistoryForReadersInterface $historyForReaders,
		public readonly ?ExplicitRevealTrailMaskerInterface $revealMasker = null,
	) {
	}//end __construct()
}//end class
