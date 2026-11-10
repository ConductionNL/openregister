<?php

/**
 * OpenRegister object favourite controller services
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
use OCA\OpenRegister\Service\Interaction\FavouriteServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The services of the object favourite API.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 */
final class ObjectFavouriteControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService Resolves an object through the RBAC boundary.
	 * @param FavouriteServiceInterface $favourites    The favourite primitive.
	 * @param LoggerInterface           $logger        Logger.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly FavouriteServiceInterface $favourites,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
