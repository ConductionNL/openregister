<?php

/**
 * OpenRegister Cospend Project Draft
 *
 * The name and currency of a Cospend project to create and link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec exclude ADR-019 Tier-2 integration link-service value; the create-and-link contract is owned by the integration-cospend capability.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * A Cospend project as a create-and-link request names it, untrimmed.
 *
 * @spec exclude ADR-019 Tier-2 integration link-service value; the create-and-link contract is owned by the integration-cospend capability.
 */
final class CospendProjectDraft {
	/**
	 * Constructor.
	 *
	 * @param string $name     The project name as given.
	 * @param string $currency The currency name as given, possibly blank.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service value; the create-and-link contract is owned by the integration-cospend capability.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $currency,
	) {
	}//end __construct()
}//end class
