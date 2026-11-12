<?php

/**
 * OpenRegister configuration controller services
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
 * The service bundles of the configuration API.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ConfigurationControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession     $session  The session bundle.
	 * @param ConfigurationMappers  $mappers  The mappers bundle.
	 * @param ConfigurationDomain   $domain   The domain bundle.
	 * @param ConfigurationPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly ConfigurationMappers $mappers,
		public readonly ConfigurationDomain $domain,
		public readonly ConfigurationPlatform $platform,
	) {
	}//end __construct()
}//end class
