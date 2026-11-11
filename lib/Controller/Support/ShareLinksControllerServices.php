<?php

/**
 * OpenRegister ShareLinksController services
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
use OCA\OpenRegister\Service\ShareLinkServiceInterface;

/**
 * The collaborators of ShareLinksController, wired by DI autowiring.
 *
 * @spec openspec/specs/integration-shares/spec.md
 */
final class ShareLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ShareLinkServiceInterface $shareLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService    OR object resolver.
	 */
	public function __construct(
		public readonly ShareLinkServiceInterface $shareLinkService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
