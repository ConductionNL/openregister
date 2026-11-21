<?php

/**
 * OpenRegister TalkLinksController services
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
use OCA\OpenRegister\Service\TalkLinkServiceInterface;

/**
 * The collaborators of TalkLinksController, wired by DI autowiring.
 */
final class TalkLinksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param TalkLinkServiceInterface  $talkLinkService Backing service.
	 * @param ObjectOperationsInterface $objectService   OR object resolver.
	 */
	public function __construct(
		public readonly TalkLinkServiceInterface $talkLinkService,
		public readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()
}//end class
