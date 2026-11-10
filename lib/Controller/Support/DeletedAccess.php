<?php

/**
 * OpenRegister deleted access
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

use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizerInterface;
use OCA\OpenRegister\Service\Object\RenderObjectInterface;
use OCP\IUserSession;

/**
 * Services of the deleted API.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DeletedAccess {
	/**
	 * Bundle the services.
	 *
	 * @param DeletedObjectAuthorizerInterface $authorizer   Answers the authorization and schema-resolution questions
	 * @param RenderObjectInterface            $renderObject Strips write-only and unreadable properties before a trashed row is served
	 * @param IUserSession                     $userSession  The user session
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function __construct(
		public readonly DeletedObjectAuthorizerInterface $authorizer,
		public readonly RenderObjectInterface $renderObject,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
