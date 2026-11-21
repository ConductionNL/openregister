<?php

/**
 * OpenRegister photo links controller services
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
use OCA\OpenRegister\Service\PhotoLinkServiceInterface;

/**
 * The services of the photo links API.
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */
final class PhotoLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PhotoLinkServiceInterface $photoLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService    OR object resolver.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public function __construct(
		public readonly PhotoLinkServiceInterface $photoLinkService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
