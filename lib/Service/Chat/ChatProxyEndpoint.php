<?php

/**
 * OpenRegister ChatProxyEndpoint
 *
 * The collaborators that locate and call the chat backend.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Chat;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;

/**
 * The collaborators that locate and call the chat backend.
 *
 * Groups collaborators of {@see ChatProxyHandler} so its constructor stays small;
 * the container autowires it.
 */
final class ChatProxyEndpoint {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IURLGenerator  $urlGenerator  Builds absolute same-instance URLs for the forward/redirect targets.
	 * @param IClientService $clientService NC's outbound HTTP client factory.
	 */
	public function __construct(
		public readonly IURLGenerator $urlGenerator,
		public readonly IClientService $clientService,
	) {
	}//end __construct()
}//end class
