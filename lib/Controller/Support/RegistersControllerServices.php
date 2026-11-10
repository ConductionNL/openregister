<?php

/**
 * OpenRegister registers controller services
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
 * Everything the registers controller injects besides the request and the controller session.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegistersControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMappers      $mappers      Register, schema, audit and object mappers.
	 * @param RegisterServices     $registers    Register service, cache, serializer and packs.
	 * @param RegisterIntegrations $integrations Configuration, GitHub, OpenAPI and container.
	 * @param ObjectTransfer       $transfer     Export and import.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public readonly RegisterMappers $mappers,
		public readonly RegisterServices $registers,
		public readonly RegisterIntegrations $integrations,
		public readonly ObjectTransfer $transfer,
	) {
	}//end __construct()
}//end class
