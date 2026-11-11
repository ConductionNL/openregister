<?php

/**
 * FileSession
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The acting user's session for the file handlers: who is logged in, whether
 * they are an administrator, and the log the handler reports to.
 *
 * Used by FileLockHandler, FileVersioningHandler and FileFormattingHandler.
 *
 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
 */
final class FileSession {

	/**
	 * Bundle the session services.
	 *
	 * @param IUserSession    $userSession  The current user session.
	 * @param IGroupManager   $groupManager Group lookups for the administrator check.
	 * @param LoggerInterface $logger       Logger.
	 *
	 * @spec exclude ADR-011 constructor aggregate, container plumbing with no behaviour of its own
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
