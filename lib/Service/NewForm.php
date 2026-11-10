<?php

/**
 * OpenRegister NewForm
 *
 * The initial title and description of an NC Forms form created from an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * What a form created and linked from an object starts with.
 *
 * @spec exclude ADR-019 Tier-2 integration link-service value; the create-and-link contract is owned by the integration-forms capability.
 */
final class NewForm {
	/**
	 * Constructor.
	 *
	 * @param string $title       Initial form title.
	 * @param string $description Initial form description.
	 *
	 * @spec exclude ADR-019 Tier-2 integration link-service value; the create-and-link contract is owned by the integration-forms capability.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $description = '',
	) {
	}//end __construct()
}//end class
