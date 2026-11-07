<?php

/**
 * WebhookMapperInterface: what other components ask of WebhookMapper.
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

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * The webhook queries and writes that callers outside lib/Db use.
 *
 * Bound to WebhookMapper in DbRegistrar.
 */
interface WebhookMapperInterface {

	/**
	 * Create webhook from array
	 *
	 * @param array $data Webhook data
	 *
	 * @return Webhook
	 */
	public function createFromArray(array $data): Webhook;

	/**
	 * Delete a webhook
	 *
	 * @param Entity $entity Webhook entity to delete
	 *
	 * @return Webhook The deleted webhook
	 * @throws \Exception
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Find a single webhook by ID
	 *
	 * Retrieves webhook by ID with organisation filtering for multi-tenancy.
	 * Throws exception if webhook not found or doesn't belong to current organisation.
	 *
	 * @param int $id Webhook ID to find
	 *
	 * @return Webhook The found webhook entity
	 *
	 * @throws DoesNotExistException If webhook not found or not accessible
	 * @throws MultipleObjectsReturnedException If multiple webhooks found (should not happen)
	 */
	public function find(int $id): Webhook;

	/**
	 * Find all webhooks
	 *
	 * Retrieves all webhooks with organisation filtering for multi-tenancy.
	 * Returns only webhooks belonging to the current organisation.
	 * Supports pagination and filtering.
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array $filters Optional filters to apply
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 */
	public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = []): array;

	/**
	 * Find all enabled webhooks
	 *
	 * Retrieves all enabled webhooks with organisation filtering for multi-tenancy.
	 * Only returns webhooks that are currently enabled and belong to current organisation.
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 */
	public function findEnabled(): array;

	/**
	 * Find all enabled webhooks WITHOUT organisation filtering
	 *
	 * Tenant-agnostic variant of findEnabled() used exclusively to compute
	 * the global "has interception webhooks for event X" cache flag. The
	 * flag must consider ALL organisations: a per-tenant "no webhooks"
	 * answer cached globally would silently disable another tenant's
	 * interception hooks. Callers that deliver webhooks must still use the
	 * organisation-filtered findEnabled()/findForEvent() paths.
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Webhook>
	 *
	 * @spec openspec/specs/webhook-payload-mapping/spec.md#request-interception-must-support-pre-event-webhooks
	 */
	public function findEnabledForInterceptionScan(): array;

	/**
	 * Find webhooks that match an event
	 *
	 * @param string $eventClass Event class name
	 *
	 * @return Webhook[]
	 *
	 * @psalm-return array<int<0, max>, Webhook>
	 */
	public function findForEvent(string $eventClass): array;

	/**
	 * Update webhook from array
	 *
	 * @param int $id Webhook ID
	 * @param array $data Webhook data
	 *
	 * @return Webhook
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function updateFromArray(int $id, array $data): Webhook;

	/**
	 * Update webhook statistics
	 *
	 * @param Webhook $webhook Webhook to update
	 * @param bool $success Was delivery successful
	 * @param bool $incrementOnly Only increment counters, don't update timestamps
	 *
	 * @return Webhook
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 */
	public function updateStatistics(Webhook $webhook, bool $success, bool $incrementOnly = false): Webhook;
}//end interface
