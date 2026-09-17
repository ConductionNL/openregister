<?php

/**
 * OpenRegister OutboundTransportInterface
 *
 * The seam a leaf app implements so one notification rule can reach a person
 * and an integration in the same firing, rather than publishing to the
 * integration on a second path of its own.
 *
 * The second path is the problem this exists to remove. dossiq publishes ZGW
 * notifications from `ZgwService` while the user's notification goes through
 * this subsystem, so the two are two dispatches with two sets of conditions,
 * two sets of failures and no shared record. A rule that declares an
 * `outbound` transport runs both under one event id, and each records its own
 * outcome.
 *
 * An app registers its implementation in DI and a schema's notification
 * annotation names it:
 *
 *   "transports": [{ "kind": "outbound", "handler": "OCA\\Dossiq\\Zgw\\ZgwTransport" }]
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Apps implement this to receive one firing of a rule as an outbound call.
 *
 * Called once per firing, not once per recipient: the recipients are handed in
 * as a list so an integration that addresses a queue or a case system sends one
 * message rather than one per member of a group.
 *
 * An implementation SHOULD be fast or do its own queueing — it runs inline on
 * the dispatch — and MUST NOT mutate the object.
 */
interface OutboundTransportInterface {
	/**
	 * Send one firing outbound.
	 *
	 * Returning a string is how a transport reports that it did not land; the
	 * dispatcher records that string against this transport's own history row
	 * and leaves every other transport of the same firing alone. Throwing is
	 * caught and recorded the same way, so an implementation need not be
	 * careful to catch everything itself.
	 *
	 * @param ObjectEntity $object The object the event happened on.
	 * @param string $notificationName The rule's annotation key.
	 * @param array<int, string> $recipients The resolved recipient uids for this firing.
	 * @param array<string, mixed> $context Trigger-specific extras (action, from, to, ...).
	 * @param array<string, mixed> $config The transport's own declared config block.
	 * @param string $eventId The firing's event identifier, shared with every other transport.
	 *
	 * @return string|null Why it did not land, or null when it did.
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	public function send(
		ObjectEntity $object,
		string $notificationName,
		array $recipients,
		array $context,
		array $config,
		string $eventId,
	): ?string;
}//end interface
