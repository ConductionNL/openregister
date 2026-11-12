<?php

/**
 * The services of the object watchers API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The services of the object watchers API.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final readonly class ObjectWatchersControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService Resolves the watched object.
	 * @param WatcherServiceInterface   $watchers      The watcher service.
	 * @param IUserManager              $userManager   Resolves the user a watcher is added for.
	 * @param LoggerInterface           $logger        Logger for unexpected failures.
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public WatcherServiceInterface $watchers,
		public IUserManager $userManager,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
