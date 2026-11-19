<?php

/**
 * PartyRecipientsInterface: the parties on an object, with the address an outbound message goes to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Notifications;

/**
 * Who a message to an object's parties would reach, and who an indicator refuses.
 *
 * The party notifications (Notifications) ask before they mail; the party
 * records (Processes) answer and bind their answer in their own wiring.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
 */
interface PartyRecipientsInterface {

	/**
	 * The address kind an outbound message picks.
	 *
	 * @var string
	 */
	public const OUTBOUND_KIND = 'correspondence';

	/**
	 * One entry per party linked to the object, in link order.
	 *
	 * @param string      $objectUuid The object.
	 * @param string|null $role       Only the parties in this role, or null for all of them.
	 *
	 * @return array<int, array{party: string, role: ?string, displayName: ?string, address: ?string, refusedBy: ?string}> The recipients.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function recipientsForObject(string $objectUuid, ?string $role = null): array;
}//end interface
