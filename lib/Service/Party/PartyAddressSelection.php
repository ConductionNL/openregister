<?php

/**
 * OpenRegister party address selection
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
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

namespace OCA\OpenRegister\Service\Party;

/**
 * Which of a party's addresses to read: one kind, under the party's declaration.
 *
 * @spec openspec/specs/party-model/spec.md
 */
final class PartyAddressSelection {
	/**
	 * Constructor.
	 *
	 * @param string               $kind       The address kind, `correspondence` / `case` / `location`.
	 * @param PartyDefinition|null $definition The declaration, resolved when null.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly ?PartyDefinition $definition = null,
	) {
	}//end __construct()
}//end class
