<?php

/**
 * OpenRegister view alert sweep platform
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The users, event dispatcher and logger an alert sweep runs as, reports through and logs with.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
 */
final readonly class ViewAlertSweepPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IUserManager     $users      Resolves each view owner.
	 * @param IEventDispatcher $dispatcher Dispatches the crossed-alert event.
	 * @param LoggerInterface  $logger     Logger.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-the-alert-sweep-is-bounded
	 */
	public function __construct(
		public IUserManager $users,
		public IEventDispatcher $dispatcher,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
