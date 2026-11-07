<?php

/**
 * OpenRegister ShowServices
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


use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\CacheHandlerInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * What show() asks of the request's object service.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class ShowServices {
	/**
	 * Hold the services.
	 *
	 * @param ObjectOperationsInterface $objects     The object service.
	 * @param PermissionHandlerInterface         $permissions Answers what a reader may do.
	 * @param CacheHandlerInterface     $names       Answers object names.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly PermissionHandlerInterface $permissions,
		public readonly CacheHandlerInterface $names,
	) {
	}//end __construct()
}//end class
