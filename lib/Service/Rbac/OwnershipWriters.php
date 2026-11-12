<?php

/**
 * Where an ownership change is written and announced.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\Notification\IManager;

/**
 * The targeted writes, the sealed trail and the notification of an ownership change.
 *
 * A constructor bundle, autowired by Nextcloud: it carries no behaviour.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class OwnershipWriters {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOwnerWriter         $ownerWriter         The targeted write of the owner column.
	 * @param ObjectAuthorizationWriter $authorizationWriter The targeted write of the authorization block.
	 * @param AuditTrailMapperInterface $auditTrailMapper    The hash-sealed trail.
	 * @param IManager                  $notificationManager Tells the previous owner.
	 */
	public function __construct(
		public readonly ObjectOwnerWriter $ownerWriter,
		public readonly ObjectAuthorizationWriter $authorizationWriter,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly IManager $notificationManager,
	) {
	}//end __construct()
}//end class
