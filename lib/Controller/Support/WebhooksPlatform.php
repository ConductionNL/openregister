<?php

/**
 * OpenRegister webhooks platform
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

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The platform services of the webhooks API.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhooksPlatform {
	/**
	 * Bundle the services.
	 *
	 * @param ?IUserSession  $userSession  User session (for write-endpoint admin gate)
	 * @param ?IGroupManager $groupManager Group manager (for admin check)
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function __construct(
		public readonly ?IUserSession $userSession = null,
		public readonly ?IGroupManager $groupManager = null,
	) {
	}//end __construct()
}//end class
