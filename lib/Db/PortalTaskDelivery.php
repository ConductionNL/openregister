<?php

/**
 * One delivery request of a portal task's ask: which channel, which party,
 * and whether it went out.
 *
 * The record IS the queryable delivery state the spec demands. A task whose
 * delivery could not be recorded still exists and still holds its run; this
 * row is what makes the difference between "not yet delivered" and silence
 * visible to the caseworker. It knows no channel implementation: the portal
 * inbox message and the mail are rendered and sent by portaliq, which reads
 * the pending rows and reports back through the mapper's two state moves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class PortalTaskDelivery
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getTaskUuid()
 * @method void setTaskUuid(?string $taskUuid)
 * @method string|null getPartyReference()
 * @method void setPartyReference(?string $partyReference)
 * @method string|null getChannel()
 * @method void setChannel(?string $channel)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method array|null getMessage()
 * @method void setMessage(?array $message)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method DateTime|null getRequestedAt()
 * @method void setRequestedAt(?DateTime $requestedAt)
 * @method DateTime|null getDeliveredAt()
 * @method void setDeliveredAt(?DateTime $deliveredAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */
class PortalTaskDelivery extends Entity implements JsonSerializable {

	/**
	 * The two channels an ask travels by. Nothing Nextcloud-facing is a
	 * channel here: no notification, no calendar projection.
	 */
	public const CHANNEL_PORTAL_INBOX = 'portal-inbox';

	public const CHANNEL_MAIL = 'mail';

	/**
	 * Every channel.
	 *
	 * @var array<int, string>
	 */
	public const CHANNELS = [
		self::CHANNEL_PORTAL_INBOX,
		self::CHANNEL_MAIL,
	];

	/**
	 * What is being delivered: the first ask, a re-ask, or a timer reminder.
	 */
	public const KIND_ASK = 'ask';

	public const KIND_RE_ASK = 're-ask';

	public const KIND_REMINDER = 'reminder';

	/**
	 * Delivery states. `not-recorded` is never stored: it is the summary of
	 * a task with NO rows, which is the outage the spec wants readable.
	 */
	public const STATE_REQUESTED = 'requested';

	public const STATE_DELIVERED = 'delivered';

	public const STATE_FAILED = 'failed';

	public const STATE_NOT_RECORDED = 'not-recorded';

	/**
	 * Public identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The task whose ask this delivers.
	 *
	 * @var string|null
	 */
	protected ?string $taskUuid = null;

	/**
	 * The matched party (the task's stored party reference).
	 *
	 * @var string|null
	 */
	protected ?string $partyReference = null;

	/**
	 * `portal-inbox` or `mail`.
	 *
	 * @var string|null
	 */
	protected ?string $channel = null;

	/**
	 * `ask`, `re-ask` or `reminder`.
	 *
	 * @var string|null
	 */
	protected ?string $kind = null;

	/**
	 * `requested`, `delivered` or `failed`.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * What portaliq renders: title, description, reason, cycle, case context.
	 *
	 * @var array|null
	 */
	protected ?array $message = null;

	/**
	 * Why a delivery failed, when it did.
	 *
	 * @var string|null
	 */
	protected ?string $error = null;

	/**
	 * When the request was recorded.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $requestedAt = null;

	/**
	 * When the channel reported the delivery.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $deliveredAt = null;

	/**
	 * Row creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'taskUuid', type: 'string');
		$this->addType(fieldName: 'partyReference', type: 'string');
		$this->addType(fieldName: 'channel', type: 'string');
		$this->addType(fieldName: 'kind', type: 'string');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'message', type: 'json');
		$this->addType(fieldName: 'error', type: 'string');
		$this->addType(fieldName: 'requestedAt', type: 'datetime');
		$this->addType(fieldName: 'deliveredAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Summarise a task's delivery rows into the one state its row reports.
	 *
	 * `not-recorded` for no rows; `failed` if any channel failed; `delivered`
	 * once the portal inbox message went out (the mail is best-effort and
	 * does not hold the state back); otherwise `requested`. The most recent
	 * request decides, so a re-ask's fresh rows are what the caseworker sees.
	 *
	 * @param array<int, PortalTaskDelivery> $rows The task's delivery rows, any order.
	 *
	 * @return array<string, mixed> {state, channels, requestedAt, deliveredAt}.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public static function summarise(array $rows): array {
		if ($rows === []) {
			return [
				'state' => self::STATE_NOT_RECORDED,
				'channels' => [],
				'requestedAt' => null,
				'deliveredAt' => null,
			];
		}

		// Only the latest request round counts: the newest requestedAt, and
		// every row sharing it.
		$latest = null;
		foreach ($rows as $row) {
			$at = $row->getRequestedAt();
			if ($at !== null && ($latest === null || $at > $latest)) {
				$latest = $at;
			}
		}

		$channels = [];
		$state = self::STATE_REQUESTED;
		$deliveredAt = null;
		foreach ($rows as $row) {
			$at = $row->getRequestedAt();
			if ($latest !== null && $at !== null && $at < $latest) {
				continue;
			}

			$channels[(string)$row->getChannel()] = [
				'state' => $row->getState(),
				'kind' => $row->getKind(),
				'error' => $row->getError(),
				'deliveredAt' => $row->getDeliveredAt()?->format('c'),
			];

			if ($row->getState() === self::STATE_FAILED) {
				$state = self::STATE_FAILED;
			}

			if ($row->getChannel() === self::CHANNEL_PORTAL_INBOX
				&& $row->getState() === self::STATE_DELIVERED
				&& $state !== self::STATE_FAILED
			) {
				$state = self::STATE_DELIVERED;
				$deliveredAt = $row->getDeliveredAt()?->format('c');
			}
		}

		return [
			'state' => $state,
			'channels' => $channels,
			'requestedAt' => $latest?->format('c'),
			'deliveredAt' => $deliveredAt,
		];
	}//end summarise()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The delivery as plain data.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'taskUuid' => $this->taskUuid,
			'partyReference' => $this->partyReference,
			'channel' => $this->channel,
			'kind' => $this->kind,
			'state' => $this->state,
			'message' => $this->message,
			'error' => $this->error,
			'requestedAt' => $this->requestedAt?->format('c'),
			'deliveredAt' => $this->deliveredAt?->format('c'),
			'created' => $this->created?->format('c'),
		];
	}//end jsonSerialize()
}//end class
