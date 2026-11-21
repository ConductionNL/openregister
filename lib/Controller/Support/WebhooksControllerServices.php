<?php

/**
 * OpenRegister webhooks controller services
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

use OCA\OpenRegister\Service\Outbound\OutboundAllowlist;

/**
 * The service bundles of the webhooks API.
 *
 * @spec openspec/specs/webhook-payload-mapping/spec.md
 */
final class WebhooksControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param WebhooksMappers  $mappers  The mappers bundle.
	 * @param TasksPlatform    $platform The platform bundle.
	 * @param OutboundAllowlist|null $allowlist The administered outbound allowlist; null checks nothing.
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md
	 */
	public function __construct(
		public readonly WebhooksMappers $mappers,
		public readonly TasksPlatform $platform,
		public readonly ?OutboundAllowlist $allowlist = null,
	) {
	}//end __construct()
}//end class
