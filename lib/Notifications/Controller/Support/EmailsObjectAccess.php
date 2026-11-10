<?php

/**
 * The object lookup and update right of the object email API.
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
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * The object lookup and update right of the object email API.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final readonly class EmailsObjectAccess {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService Object service for object validation.
	 * @param PermissionHandlerInterface $permissions Answers the object update right.
	 */
	public function __construct(
		public ObjectOperationsInterface $objectService,
		public PermissionHandlerInterface $permissions,
	) {
	}//end __construct()
}//end class
