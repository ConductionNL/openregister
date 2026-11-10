<?php

/**
 * OpenRegister reach revocation log
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\AuthorizationAuditService;
use Psr\Log\LoggerInterface;

/**
 * Where a reach revocation is written down: the authorization audit and the PSR log.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
final class ReachRevocationLog {
	/**
	 * Bundle the collaborators.
	 *
	 * @param AuthorizationAuditService $audit  The record of what was removed.
	 * @param LoggerInterface           $logger PSR logger.
	 */
	public function __construct(
		public readonly AuthorizationAuditService $audit,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
