<?php

/**
 * Who changes an object's sharing and where that is written down: the session, the group manager, the logger and the grant auditor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The caller and record collaborators of ObjectSharingService, wired by DI autowiring.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class ObjectSharingContext {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession            $userSession  Resolves the caller.
	 * @param IGroupManager           $groupManager Resolves the caller's groups.
	 * @param LoggerInterface         $logger       Logger.
	 * @param GrantChangeAuditor|null $auditor      Writes an audit-trail entry for every grant and revoke. Null only in legacy fixtures.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly LoggerInterface $logger,
		public readonly ?GrantChangeAuditor $auditor = null,
	) {
	}//end __construct()
}//end class
