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
	use TypedFieldsJsonTrait;

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
	 * A notice to the party that the task is past its deadline (a postBreach rung, #4166).
	 */
	public const KIND_OVERDUE = 'overdue';

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
			return ['state' => self::STATE_NOT_RECORDED, 'channels' => [], 'requestedAt' => null, 'deliveredAt' => null];
		}

		$latest = self::latestRequestAt(rows: $rows);
		$channels = [];
		$summary = [self::STATE_REQUESTED, null];
		foreach (self::currentRound(rows: $rows, latest: $latest) as $row) {
			$channels[(string)$row->getChannel()] = self::channelSummary(row: $row);
			$summary = self::foldState(summary: $summary, row: $row);
		}

		return ['state' => $summary[0], 'channels' => $channels, 'requestedAt' => $latest?->format('c'), 'deliveredAt' => $summary[1]];
	}//end summarise()

	/**
	 * The rows of the newest request round; rows without a request instant always count.
	 *
	 * @param array<int, PortalTaskDelivery> $rows The task's delivery rows.
	 * @param DateTime|null $latest The newest requestedAt, or null when none carries one.
	 *
	 * @return array<int, PortalTaskDelivery> The rows that count, in their original order.
	 */
	private static function currentRound(array $rows, ?DateTime $latest): array {
		return array_filter(
			$rows,
			static function (PortalTaskDelivery $row) use ($latest): bool {
				$requestedAt = $row->getRequestedAt();
				return $latest === null || $requestedAt === null || ($requestedAt < $latest) === false;
			}
		);
	}//end currentRound()

	/**
	 * The per-channel entry of the summary.
	 *
	 * @param PortalTaskDelivery $row The row.
	 *
	 * @return array<string, mixed> {state, kind, error, deliveredAt}.
	 */
	private static function channelSummary(PortalTaskDelivery $row): array {
		return [
			'state' => $row->getState(),
			'kind' => $row->getKind(),
			'error' => $row->getError(),
			'deliveredAt' => $row->getDeliveredAt()?->format('c'),
		];
	}//end channelSummary()

	/**
	 * Fold one row into the summary state: any failure wins; the portal inbox
	 * going out makes the round delivered (the mail is best-effort).
	 *
	 * @param array{0: string, 1: string|null} $summary The state and deliveredAt so far.
	 * @param PortalTaskDelivery $row The row folded in.
	 *
	 * @return array{0: string, 1: string|null} The state and deliveredAt after.
	 */
	private static function foldState(array $summary, PortalTaskDelivery $row): array {
		if ($row->getState() === self::STATE_FAILED) {
			return [self::STATE_FAILED, $summary[1]];
		}

		if ($row->getChannel() === self::CHANNEL_PORTAL_INBOX
			&& $row->getState() === self::STATE_DELIVERED
			&& $summary[0] !== self::STATE_FAILED
		) {
			return [self::STATE_DELIVERED, $row->getDeliveredAt()?->format('c')];
		}

		return $summary;
	}//end foldState()

	/**
	 * The newest request instant: only that round counts, so a re-ask's fresh
	 * rows are what the caseworker sees.
	 *
	 * @param array<int, PortalTaskDelivery> $rows The task's delivery rows.
	 *
	 * @return DateTime|null The newest requestedAt, or null when none carries one.
	 */
	private static function latestRequestAt(array $rows): ?DateTime {
		$latest = null;
		foreach ($rows as $row) {
			$requestedAt = $row->getRequestedAt();
			if ($requestedAt !== null && ($latest === null || $requestedAt > $latest)) {
				$latest = $requestedAt;
			}
		}

		return $latest;
	}//end latestRequestAt()
}//end class
