<?php

/**
 * Who a mention names, who wrote it, and the watcher list that pulls them in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Timeline;

use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Who a mention names, who wrote it, and the watcher list that pulls them in.
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
final readonly class MentionPrincipals {
	/**
	 * Bundle the services.
	 *
	 * @param WatcherServiceInterface $watchers Subscribes the named principal.
	 * @param IUserManager $userManager Resolves a token to a real principal.
	 * @param IUserSession $userSession The caller, who is never notified of their own mention.
	 */
	public function __construct(
		public WatcherServiceInterface $watchers,
		public IUserManager $userManager,
		public IUserSession $userSession,
	) {
	}//end __construct()
}//end class
