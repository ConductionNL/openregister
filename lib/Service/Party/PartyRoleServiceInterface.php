<?php

/**
 * The parties on an object, as the party API lists and changes them.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Party;

use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The parties on an object, as the party API lists and changes them.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see PartyRoleService} in ContractBindings.
 */
interface PartyRoleServiceInterface {

	/**
	 * The parties on an object, grouped by role, with what the schema accepts.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int|string|null $schemaId The object's schema, for the vocabulary.
	 *
	 * @return array<string, mixed> The listing: `results`, `total`, `byRole`, the
	 *         schema's `kinds` and `roles`, and the `primary` party's uuid.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function listForObject(string $objectUuid, int|string|null $schemaId): array;

	/**
	 * Give a party a role on an object.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|string|null $schemaId The object's schema id.
	 * @param array<string, mixed> $payload `partyUuid`, `role`, `validFrom`, `validUntil`, `note`, `primary`.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 400 for an undeclared kind or role, 404 for an unknown party.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function addParty(string $objectUuid, int $registerId, int|string|null $schemaId, array $payload): ContactLink;

	/**
	 * Take a party off an object: every role, or the one named.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $partyUuid The party.
	 * @param string|null $role Only this role, or null for all.
	 *
	 * @return int How many roles went.
	 *
	 * @throws Exception 404 when the party holds no such role.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function removeParty(string $objectUuid, string $partyUuid, ?string $role = null): int;

	/**
	 * Replace the party the object is filed against, and record it.
	 *
	 * An intake filed on the wrong person is an AVG incident, not a typo, so
	 * the change writes one audit entry naming the party that went, the party
	 * that came, and the actor.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $partyUuid The party that becomes primary.
	 * @param string|null $role The role the new primary party holds.
	 *
	 * @return ContactLink The new primary link.
	 *
	 * @throws Exception 400 for an undeclared kind or role, 404 for an unknown party.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function replacePrimaryParty(ObjectEntity $object, string $partyUuid, ?string $role = null): ContactLink;
}//end interface
