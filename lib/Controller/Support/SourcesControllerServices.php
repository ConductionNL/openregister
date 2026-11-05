<?php

/**
 * OpenRegister sources controller services
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
 * The service bundles of the sources API.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final class SourcesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession $session  The session bundle.
	 * @param SourcesMappers    $mappers  The mappers bundle.
	 * @param SourcesDomain     $domain   The domain bundle.
	 * @param SourcesPlatform   $platform The platform bundle.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly SourcesMappers $mappers,
		public readonly SourcesDomain $domain,
		public readonly SourcesPlatform $platform,
	) {
	}//end __construct()
}//end class
