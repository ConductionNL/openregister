<?php

/**
 * OpenRegister rules controller services
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
 * The service bundles of the rules API.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RulesControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param RulesMappers  $mappers  The mappers bundle.
	 * @param RulesDomain   $domain   The domain bundle.
	 * @param RulesReplay   $replay   The replay bundle.
	 * @param DsarCasePlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function __construct(
		public readonly RulesMappers $mappers,
		public readonly RulesDomain $domain,
		public readonly RulesReplay $replay,
		public readonly DsarCasePlatform $platform,
	) {
	}//end __construct()
}//end class
