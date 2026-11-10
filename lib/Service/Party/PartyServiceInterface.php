<?php

/**
 * The party records and their addresses, as callers outside lib/Service read them.
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
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The party records and their addresses, as callers outside lib/Service read them.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see PartyService} in ContractBindings.
 */
interface PartyServiceInterface {

	/**
	 * The party object with that uuid, null when there is none the caller may read.
	 *
	 * @param string $partyUuid The party's uuid.
	 *
	 * @return ObjectEntity|null The party.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function find(string $partyUuid): ?ObjectEntity;

	/**
	 * The party declaration of an object's schema, null when it is not a party.
	 *
	 * @param ObjectEntity $party The object.
	 *
	 * @return PartyDefinition|null The declaration.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function definitionFor(ObjectEntity $party): ?PartyDefinition;

	/**
	 * The addresses a party holds, each normalised to `{kind, type, value, label}`.
	 *
	 * An entry that is a bare string reads as a correspondence address, which
	 * is how a register that has always held one e-mail per party keeps
	 * working without being rewritten.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyDefinition|null $definition The declaration, resolved when null.
	 *
	 * @return array<int, array{kind: string, type: string, value: string, label: string|null}> The addresses.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function addresses(ObjectEntity $party, ?PartyDefinition $definition = null): array;

	/**
	 * The addresses of one kind, in declaration order.
	 *
	 * @param ObjectEntity $party The party.
	 * @param string $kind The address kind, `correspondence` / `case` / `location`.
	 * @param PartyDefinition|null $definition The declaration, resolved when null.
	 *
	 * @return array<int, array{kind: string, type: string, value: string, label: string|null}> The addresses.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function addressesOfKind(ObjectEntity $party, string $kind, ?PartyDefinition $definition = null): array;

	/**
	 * The indicators a party carries, each normalised to `{key, label, effect, note}`.
	 *
	 * An entry naming no effect warns: an indicator somebody wrote down is an
	 * indicator somebody meant, and the weakest effect is the safe reading.
	 * An entry naming an effect this version does not know also warns, rather
	 * than being dropped, for the same reason.
	 *
	 * @param ObjectEntity $party The party.
	 * @param PartyDefinition|null $definition The declaration, resolved when null.
	 *
	 * @return array<int, array{key: string, label: string, effect: string, note: string|null}> The indicators.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function indicators(ObjectEntity $party, ?PartyDefinition $definition = null): array;

	/**
	 * The party holding that address, null when no party does.
	 *
	 * Inbound mail from any address a party holds must reach that party
	 * rather than create a second record. The full-text search narrows the
	 * candidates; the exact comparison over the party's own addresses decides,
	 * so a search that matches loosely never returns the wrong party.
	 *
	 * @param string $address The address as it arrived.
	 *
	 * @return ObjectEntity|null The party.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function resolveByAddress(string $address): ?ObjectEntity;

	/**
	 * Refuse a parent that would close a cycle or push the tree past its bound.
	 *
	 * @param string $partyUuid The party being given a parent.
	 * @param string|null $parentUuid The proposed parent, null to clear.
	 *
	 * @return void
	 *
	 * @throws Exception 400 naming the cycle or the depth.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function assertParentAllowed(string $partyUuid, ?string $parentUuid): void;
}//end interface
