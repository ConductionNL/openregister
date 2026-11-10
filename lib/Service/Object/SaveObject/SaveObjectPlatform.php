<?php

/**
 * The platform services a single-object save uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The platform services a single-object save uses.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class SaveObjectPlatform {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession      $userSession     User session service.
	 * @param IURLGenerator     $urlGenerator    URL generator service.
	 * @param LoggerInterface   $logger          Logger.
	 * @param ?IEventDispatcher $eventDispatcher Event dispatcher (reference events).
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IURLGenerator $urlGenerator,
		public readonly LoggerInterface $logger,
		public readonly ?IEventDispatcher $eventDispatcher = null,
	) {
	}//end __construct()
}//end class
