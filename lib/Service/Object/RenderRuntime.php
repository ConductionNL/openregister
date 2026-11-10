<?php

/**
 * OpenRegister render runtime
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The runtime a render works in: the logger, the request, the lazy container and the read extensions.
 *
 * The container lazily resolves services that would make a construction cycle
 * (ObjectService -> RenderObject -> RegistrySubscriptionService -> ObjectService).
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderRuntime {
	/**
	 * Bundle the services.
	 *
	 * @param LoggerInterface           $logger     The logger.
	 * @param RenderReadExtensions      $extensions The optional read-time extensions.
	 * @param IRequest|null             $request    The current request, read for `?recurrenceOccurrences=N`.
	 * @param ContainerInterface|null   $container  Lazily resolves cycle-prone services.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly LoggerInterface $logger,
		public readonly RenderReadExtensions $extensions,
		public readonly ?IRequest $request = null,
		public readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()
}//end class
