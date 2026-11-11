<?php

/**
 * The platform services the timeline visibility check reads.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The schema mapper, the caller's session and the logger the timeline visibility check reads; the RBAC verdict and the audit writer stay direct.
 */
final readonly class TimelineVisibilityPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemaMapper Resolves the object's schema for the permission check.
	 * @param IUserSession          $userSession  Current caller.
	 * @param LoggerInterface       $logger       Logger for the fail-closed paths.
	 */
	public function __construct(
		public SchemaMapperInterface $schemaMapper,
		public IUserSession $userSession,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
