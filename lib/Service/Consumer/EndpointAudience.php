<?php

/**
 * The users and groups an endpoint admits.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Consumer;

/**
 * An endpoint's allow-list: uids or e-mail addresses, and group ids.
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
final class EndpointAudience {

	/**
	 * Hold the two lists.
	 *
	 * @param array $users  Allowed uids or e-mail addresses.
	 * @param array $groups Allowed group ids.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly array $users,
		public readonly array $groups,
	) {
	}//end __construct()

	/**
	 * Whether both lists are empty, which admits everyone.
	 *
	 * @return bool True when no list restricts the endpoint.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function isOpen(): bool {
		return empty($this->users) === true && empty($this->groups) === true;
	}//end isOpen()
}//end class
