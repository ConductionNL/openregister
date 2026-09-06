<?php

/**
 * The OpenRegister half of the portal delivery seam.
 *
 * Every ask, re-ask and reminder addressed to a party outside the instance
 * is RECORDED here as a delivery request per channel (portal inbox message
 * and mail), and portaliq renders and sends it, then reports back through
 * {@see markDelivered()} / {@see markFailed()}. Nothing in this class sends:
 * it has no notification manager, no mailer and no calendar, and a test
 * asserts that absence, because "an external task is never delivered through
 * a Nextcloud channel" has to be structural, not a matter of discipline.
 *
 * FAIL-OPEN IS REFUSED ON BOTH SIDES. Recording never throws: a task whose
 * delivery request could not be written still exists and still holds its
 * run, and the summary of "no rows" is `not-recorded`, which the caseworker
 * reads as "not yet delivered" instead of silence (design D-7).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Portal
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

namespace OCA\OpenRegister\Service\Portal;

use OCA\OpenRegister\Db\PortalTaskDelivery;
use OCA\OpenRegister\Db\PortalTaskDeliveryMapper;
use OCA\OpenRegister\Db\Task;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records, reads and settles portal delivery requests.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PortalTaskDelivery::summarise is a
 * stateless fold over rows; an instance to call it would be a second copy.
 *
 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
 */
class PortalTaskDeliveryService {

	/**
	 * Constructor.
	 *
	 * @param PortalTaskDeliveryMapper $deliveries The delivery records.
	 * @param LoggerInterface $logger Where a recording failure is reported.
	 */
	public function __construct(
		private readonly PortalTaskDeliveryMapper $deliveries,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record a delivery request on every channel for a task's ask.
	 *
	 * One row per channel, so a mail outage and a portal outage are told
	 * apart. Never throws: each failure is logged with the task, and the
	 * rows that could be written are returned.
	 *
	 * @param Task $task The external task.
	 * @param string $kind `ask`, `re-ask` or `reminder`.
	 * @param array<string, mixed> $message What portaliq renders.
	 *
	 * @return array<int, PortalTaskDelivery> The rows written (possibly none).
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function request(Task $task, string $kind, array $message): array {
		$written = [];
		foreach (PortalTaskDelivery::CHANNELS as $channel) {
			$row = new PortalTaskDelivery();
			$row->setTaskUuid((string)$task->getUuid());
			$row->setPartyReference((string)$task->getAssignee());
			$row->setChannel($channel);
			$row->setKind($kind);
			$row->setMessage($message);

			try {
				$written[] = $this->deliveries->insert($row);
			} catch (Throwable $failure) {
				$this->logger->warning(
					'[PortalTaskDeliveryService] Could not record the ' . $channel . ' delivery request of task '
					. $task->getUuid() . '; the task and its run stand, the delivery state reads not-recorded: ' . $failure->getMessage(),
					['task' => $task->getUuid(), 'channel' => $channel, 'kind' => $kind]
				);
			}
		}

		return $written;
	}//end request()

	/**
	 * The message an ask, re-ask or reminder carries to portaliq.
	 *
	 * Descriptors and the case anchor, never case data: portaliq reads the
	 * case itself through its subject-scoped readers (ADR-046 rule 3).
	 *
	 * @param Task $task The external task.
	 * @param string|null $reason The re-ask reason, when this is a re-ask.
	 *
	 * @return array<string, mixed> The message.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function messageFor(Task $task, ?string $reason = null): array {
		$metadata = ($task->getMetadata() ?? []);

		return [
			'taskUuid' => $task->getUuid(),
			'title' => $task->getTitle(),
			'description' => $task->getDescription(),
			'reason' => $reason,
			'cycle' => (int)($metadata['cycle'] ?? 1),
			'partyReference' => $task->getAssignee(),
			'dueAt' => $task->getDueAt()?->format('c'),
			'expiresAt' => $task->getExpiresAt()?->format('c'),
			'case' => [
				'uuid' => $task->getObjectUuid(),
				'register' => $task->getRegisterId(),
				'schema' => $task->getSchemaId(),
			],
			'upload' => ($metadata['upload'] ?? null),
		];
	}//end messageFor()

	/**
	 * The delivery state of one task, summarised for its row.
	 *
	 * @param Task $task The task.
	 *
	 * @return array<string, mixed> {state, channels, requestedAt, deliveredAt}.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function stateFor(Task $task): array {
		return PortalTaskDelivery::summarise(rows: $this->deliveries->findForTask(taskUuid: (string)$task->getUuid()));
	}//end stateFor()

	/**
	 * The requests portaliq has not yet reported on, oldest first.
	 *
	 * @param int $limit Page size.
	 *
	 * @return array<int, PortalTaskDelivery> The pending rows.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function pending(int $limit = 100): array {
		return $this->deliveries->findPending(limit: $limit);
	}//end pending()

	/**
	 * Portaliq reports a request as sent.
	 *
	 * @param string $uuid The delivery uuid.
	 *
	 * @return PortalTaskDelivery The settled row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such request exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function markDelivered(string $uuid): PortalTaskDelivery {
		return $this->deliveries->markDelivered(delivery: $this->deliveries->findByUuid(uuid: $uuid));
	}//end markDelivered()

	/**
	 * Portaliq reports a request as failed, and why.
	 *
	 * @param string $uuid The delivery uuid.
	 * @param string $error The failure.
	 *
	 * @return PortalTaskDelivery The settled row.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such request exists.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-portal-task/spec.md#requirement-delivery-rides-the-portal-contribution-surface-and-nothing-else
	 */
	public function markFailed(string $uuid, string $error): PortalTaskDelivery {
		return $this->deliveries->markFailed(delivery: $this->deliveries->findByUuid(uuid: $uuid), error: $error);
	}//end markFailed()
}//end class
