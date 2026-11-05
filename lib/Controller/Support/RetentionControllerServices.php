<?php

/**
 * OpenRegister retention controller services
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
 * The service bundles of the retention API.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RetentionMappers  $mappers  The mappers bundle.
	 * @param RetentionDomain   $domain   The domain bundle.
	 * @param RetentionPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly RetentionMappers $mappers,
		public readonly RetentionDomain $domain,
		public readonly RetentionPlatform $platform,
	) {
	}//end __construct()
}//end class
