<?php

/**
 * One item of a flow send step and the recipients it resolved.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * An item's json, the users and addresses it resolved, the title and body its
 * templates rendered, and the recipient being sent to.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */
final class FlowSendItem
{

	/**
	 * The rendered title or subject.
	 *
	 * @var string
	 */
	public string $title = '';

	/**
	 * The rendered body.
	 *
	 * @var string
	 */
	public string $body = '';

	/**
	 * The recipient being sent to: a uid, or an address.
	 *
	 * @var string
	 */
	public string $recipient = '';

	/**
	 * The address's display name, when the recipient is an address.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The {@see \OCA\OpenRegister\Event\FlowEmailSentEvent} kind of the recipients being sent to.
	 *
	 * @var string
	 */
	public string $kind = '';

	/**
	 * The users this item delivered to, for web push.
	 *
	 * @var array<int, string>
	 */
	public array $delivered = [];

	/**
	 * Constructor.
	 *
	 * @param array                 $json      The item's json.
	 * @param array<int, string>    $uids      The users the item resolved.
	 * @param array<string, string> $addresses The allowed addresses, address => display name.
	 */
	public function __construct(
		public readonly array $json,
		public readonly array $uids,
		public readonly array $addresses,
	) {
	}//end __construct()

	/**
	 * A lightweight object reference for the item, for a notification's object link.
	 *
	 * An item read from a register carries its uuid; an item built mid-flow
	 * may not, and then the notification simply carries no object deeplink.
	 *
	 * @return ObjectEntity The reference.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function objectReference(): ObjectEntity {
		$object = new ObjectEntity();
		$uuid = ($this->json['uuid'] ?? ($this->json['id'] ?? null));
		if (is_string($uuid) === true && $uuid !== '') {
			$object->setUuid($uuid);
		}

		$name = ($this->json['name'] ?? ($this->json['title'] ?? null));
		if (is_string($name) === true && $name !== '') {
			$object->setName($name);
		}

		return $object;
	}//end objectReference()
}//end class
