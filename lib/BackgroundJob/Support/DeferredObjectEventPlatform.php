<?php

/**
 * OpenRegister deferred object event platform
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
use Psr\Log\LoggerInterface;

/**
 * The dispatcher a deferred object event goes out on, and the logger.
 *
 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
 */
final class DeferredObjectEventPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param IEventDispatcher $eventDispatcher Dispatches the event.
	 * @param LoggerInterface  $logger          The logger.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function __construct(
		public readonly IEventDispatcher $eventDispatcher,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
