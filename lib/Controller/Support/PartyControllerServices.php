<?php

/**
 * OpenRegister party controller services
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
 * The service bundles of the party API.
 *
 * @spec openspec/specs/party-model/spec.md
 */
final class PartyControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param PartyDomain $domain The domain bundle.
	 * @param PartySearch $search The capped party query bundle.
	 *
	 * @spec openspec/specs/party-model/spec.md
	 */
	public function __construct(
		public readonly PartyDomain $domain,
		public readonly PartySearch $search,
	) {
	}//end __construct()
}//end class
