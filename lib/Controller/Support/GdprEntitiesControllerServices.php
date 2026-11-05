<?php

/**
 * OpenRegister gdpr entities controller services
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
 * The service bundles of the gdpr entities API.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md
 */
final class GdprEntitiesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ControllerSession    $session  The session bundle.
	 * @param GdprEntitiesMappers  $mappers  The mappers bundle.
	 * @param GdprEntitiesDomain   $domain   The domain bundle.
	 * @param GdprEntitiesPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md
	 */
	public function __construct(
		public readonly ControllerSession $session,
		public readonly GdprEntitiesMappers $mappers,
		public readonly GdprEntitiesDomain $domain,
		public readonly GdprEntitiesPlatform $platform,
	) {
	}//end __construct()
}//end class
