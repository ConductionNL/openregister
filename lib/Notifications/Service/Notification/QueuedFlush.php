<?php

/**
 * OpenRegister QueuedFlush
 *
 * One flush of a group of held deliveries sharing a (rule, recipient) pair.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;

/**
 * What a queued group flushes as: the recipient, the text, the channels and
 * the object, read from the group's first row and its merged summary.
 *
 * Built and used only inside AnnotationNotificationDispatcher::dispatchQueued().
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class QueuedFlush {

	/**
	 * The first row's decoded payload.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $payload;

	/**
	 * The recipient uid.
	 *
	 * @var string
	 */
	public readonly string $recipient;

	/**
	 * The flushed subject.
	 *
	 * @var string
	 */
	public readonly string $subject;

	/**
	 * The flushed message.
	 *
	 * @var string
	 */
	public readonly string $message;

	/**
	 * The channels the rows were held for.
	 *
	 * @var array<int|string, mixed>
	 */
	public readonly array $channels;

	/**
	 * The object the rows are about, rebuilt from the payload.
	 *
	 * @var ObjectEntity
	 */
	public readonly ObjectEntity $object;

	/**
	 * The rule name the payload carries, else the rule key.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * The history origin: the rule key and the object.
	 *
	 * @var DeliveryOrigin
	 */
	public readonly DeliveryOrigin $origin;

	/**
	 * The history detail: the subject and the recipient's locale.
	 *
	 * @var DeliveryDetail
	 */
	public readonly DeliveryDetail $detail;

	/**
	 * Constructor.
	 *
	 * @param QueuedNotification $first   The group's first row.
	 * @param array{0: string, 1: string} $summary The flushed [subject, message].
	 */
	public function __construct(QueuedNotification $first, array $summary) {
		$this->payload   = $first->getDecodedPayload();
		$ruleId          = (string)$first->getRuleKey();
		$this->recipient = (string)$first->getRecipient();
		$this->subject   = $summary[0];
		$this->message   = $summary[1];
		$this->channels  = (array)($this->payload['channels'] ?? []);
		$this->object    = $this->objectFrom(payload: $this->payload);
		$this->origin    = new DeliveryOrigin(ruleId: $ruleId, object: $this->object);
		$this->name      = (string)($this->payload['name'] ?? $ruleId);
		$this->detail    = new DeliveryDetail(subject: $this->subject, locale: $this->localeFrom(payload: $this->payload));
	}//end __construct()

	/**
	 * The object the payload describes: its uuid, and register, schema and name when stored.
	 *
	 * @param array<string, mixed> $payload The decoded payload.
	 *
	 * @return ObjectEntity
	 */
	private function objectFrom(array $payload): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid((string)($payload['objectUuid'] ?? ''));
		if (isset($payload['objectRegister']) === true) {
			$object->setRegister((string)$payload['objectRegister']);
		}

		if (isset($payload['objectSchema']) === true) {
			$object->setSchema((string)$payload['objectSchema']);
		}

		if (isset($payload['objectName']) === true) {
			$object->setName((string)$payload['objectName']);
		}

		return $object;
	}//end objectFrom()

	/**
	 * The recipient's locale the payload stored, if any.
	 *
	 * @param array<string, mixed> $payload The decoded payload.
	 *
	 * @return string|null
	 */
	private function localeFrom(array $payload): ?string {
		if (($payload['locale'] ?? null) !== null) {
			return (string)$payload['locale'];
		}

		return null;
	}//end localeFrom()
}//end class
