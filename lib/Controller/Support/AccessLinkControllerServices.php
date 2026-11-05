<?php

/**
 * OpenRegister access link controller services
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

/**
 * The service bundles of the access link API.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
final class AccessLinkControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param AccessLinkDomain   $domain   The domain bundle.
	 * @param AccessLinkPlatform $platform The platform bundle.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function __construct(
		public readonly AccessLinkDomain $domain,
		public readonly AccessLinkPlatform $platform,
	) {
	}//end __construct()
}//end class
