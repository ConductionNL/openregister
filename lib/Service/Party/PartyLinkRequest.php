<?php

/**
 * One request to put a party on an object in a role.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Party
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/party-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

/**
 * The object the link goes on, the party, its role, and the named linker of a trusted import.
 *
 * ADR-011 Rule 2: the operation object PartyRoleService checks and PartyLinkWriter writes.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
 */
final class PartyLinkRequest {
	/**
	 * Constructor.
	 *
	 * @param PartyLinkTarget $target    The object, register and schema the link records.
	 * @param string          $partyUuid The party object's uuid.
	 * @param string|null     $role      The role.
	 * @param string|null     $linkedBy  Who links it when nobody is signed in, such as an example set's app.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function __construct(
		public readonly PartyLinkTarget $target,
		public readonly string $partyUuid,
		public readonly ?string $role,
		public readonly ?string $linkedBy=null,
	) {
	}//end __construct()

	/**
	 * Whether a linker is named: the trusted import path, which has no signed-in user.
	 *
	 * @return bool True for a named linker.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-parties-on-its-objects
	 */
	public function namesLinker(): bool {
		return $this->linkedBy !== null;
	}//end namesLinker()
}//end class
