<?php

/**
 * OpenRegister ownership log
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

use Psr\Log\LoggerInterface;

/**
 * Where an ownership change is noted and a change of the owning group audited.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnershipLog {
	/**
	 * Bundle the collaborators.
	 *
	 * @param LoggerInterface         $logger       Where a transfer is noted.
	 * @param GrantChangeAuditor|null $grantAuditor Writes an audit-trail entry for a change of the owning group. Null only in legacy fixtures.
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly ?GrantChangeAuditor $grantAuditor = null,
	) {
	}//end __construct()
}//end class
