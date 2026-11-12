<?php

/**
 * NotificationRuleDispatcherInterface: the public surface of AnnotationNotificationDispatcher, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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
use OCA\OpenRegister\Db\Schema;

/**
 * Every public method of AnnotationNotificationDispatcher. Callers depend on this interface, not on the class.
 */
interface NotificationRuleDispatcherInterface {

	/**
	 * Fire any notifications declared on the schema whose trigger matches.
	 *
	 * @param ObjectEntity $object The object the event happened on.
	 * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 * @param array<string, mixed> $context Trigger-specific extras (e.g. `action`, `from`, `to`,
	 *                                      `_newData`, `_oldData` for calculatedChange).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  dispatch() iterates notifications, filters by
	 * organisation gate, idempotency, recipient resolution, rate-limit, coalesce, and preference per
	 * recipient per channel — each branch is a required dispatch condition; extracting sub-methods
	 * would split the sequential guard chain.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Per-rule: trigger match + org gate + idempotency claim
	 * + subscription filter + rate-limit + coalesce + preference check + channel fan-out produce many
	 * independent paths; all are required for the notification contract.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) dispatch() is the single entry point that chains
	 * all notification gates in order; splitting the chain would break the sequential guard semantics
	 * (e.g. rate-limit must run after idempotency claim).
	 * @SuppressWarnings(PHPMD.LongVariable)          $resolvedIdempotencyKey and $idempotencyKeyTemplate are
	 * descriptive names for distinct lifecycle stages of the same template value; abbreviating would
	 * obscure the claim-before-send ordering that prevents duplicate dispatches.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
	 */
	public function dispatch(ObjectEntity $object, string $trigger, array $context = []): void;

	/**
	 * Fire notifications declared on a pre-resolved Schema.
	 *
	 * Called by dispatch() after schema resolution, and directly by the
	 * SystemEntityNotificationBridge for system entities whose schema is not in
	 * the SchemaMapper but is instead provided by SystemSchemaRules::buildSchema().
	 *
	 * This is the single entry point for the notification pipeline: every
	 * dispatch path (stored objects and system entities) goes through here.
	 *
	 * @param ObjectEntity $object The object (or virtual adapter) the event happened on.
	 * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 * @param array<string, mixed> $context Trigger-specific extras.
	 * @param Schema $schema Pre-resolved schema carrying the rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
	 */
	public function dispatchWithSchema(ObjectEntity $object, string $trigger, array $context, Schema $schema): void;
}//end interface
