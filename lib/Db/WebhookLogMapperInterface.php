<?php

/**
 * WebhookLogMapperInterface: what callers outside the Db component use of WebhookLogMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use DateTime;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\Entity;

/**
 * The methods of WebhookLogMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to WebhookLogMapper.
 */
interface WebhookLogMapperInterface {

	/**
	 * Find a webhook log by ID
	 *
	 * @param int $id Log entry ID
	 *
	 * @return WebhookLog
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): WebhookLog;

	/**
	 * Find all rows, newest first.
	 *
	 * @param int|null $limit  Maximum number of rows, or null for all.
	 * @param int|null $offset Rows to skip, or null for none.
	 *
	 * @return \OCP\AppFramework\Db\Entity[] The entities of this mapper.
	 *
	 * @spec exclude shared mapper plumbing, not a capability of its own
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array;

	/**
	 * Find logs for a specific webhook
	 *
	 * @param int $webhookId Webhook ID
	 * @param ListQuery $page The page (limit, offset); unbounded by default. Other fields are ignored.
	 *
	 * @return WebhookLog[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\WebhookLog>
	 */
	public function findByWebhook(int $webhookId, ListQuery $page=new ListQuery(limit: ListQuery::UNBOUNDED)): array;

	/**
	 * Find failed logs that need retry
	 *
	 * @param DateTime $before Before timestamp
	 *
	 * @return WebhookLog[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\WebhookLog>
	 */
	public function findFailedForRetry(DateTime $before): array;

	/**
	 * Get statistics for a webhook
	 *
	 * @param int $webhookId Webhook ID (0 for all webhooks)
	 *
	 * @return int[] Statistics
	 *
	 * @psalm-return array{total: int, successful: int, failed: int}
	 */
	public function getStatistics(int $webhookId): array;

	/**
	 * Insert a new webhook log
	 *
	 * @param Entity $entity WebhookLog entity to insert
	 *
	 * @return WebhookLog The inserted log
	 *
	 */
	public function insert(Entity $entity): Entity;
}//end interface
