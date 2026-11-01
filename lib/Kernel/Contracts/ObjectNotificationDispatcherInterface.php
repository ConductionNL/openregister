<?php

/**
 * ObjectNotificationDispatcherInterface: send the notifications a schema declares for an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;


/**
 * Dispatches the notifications that a schema's notification annotation declares for an
 * object and a trigger, now or from the queue. Implemented by
 * AnnotationNotificationDispatcher.
 */
interface ObjectNotificationDispatcherInterface {

	/**
	 * Fire any notifications declared on the schema whose trigger matches.
	 *
	 * @param ObjectRowInterface $object The object the event happened on.
	 * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 * @param array<string, mixed> $context Trigger-specific extras (e.g. `action`, `from`, `to`,
	 *                                      `_newData`, `_oldData` for calculatedChange).
	 *
	 * @return void
	 *
	 * organisation gate, idempotency, recipient resolution, rate-limit, coalesce, and preference per
	 * recipient per channel — each branch is a required dispatch condition; extracting sub-methods
	 * would split the sequential guard chain.
	 * + subscription filter + rate-limit + coalesce + preference check + channel fan-out produce many
	 * independent paths; all are required for the notification contract.
	 * all notification gates in order; splitting the chain would break the sequential guard semantics
	 * (e.g. rate-limit must run after idempotency claim).
	 * descriptive names for distinct lifecycle stages of the same template value; abbreviating would
	 * obscure the claim-before-send ordering that prevents duplicate dispatches.
	 */
	public function dispatch(ObjectRowInterface $object, string $trigger, array $context = []): void;

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
	 * @param ObjectRowInterface $object The object (or virtual adapter) the event happened on.
	 * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 * @param array<string, mixed> $context Trigger-specific extras.
	 * @param SchemaInterface $schema Pre-resolved schema carrying the rules.
	 *
	 * @return void
	 */
	public function dispatchWithSchema(ObjectRowInterface $object, string $trigger, array $context, SchemaInterface $schema): void;

	/**
	 * Flush a group of `QueuedNotification` rows sharing the same
	 * `(rule_key, recipient)` pair, called by `NotificationQueueFlushJob`
	 * once it has determined the group's holding condition has cleared.
	 *
	 * Reuses the existing channel fan-out unchanged (`emitNotification`,
	 * `emitEmail`, `emitActivity`). A single row flushes as its own
	 * pre-resolved subject/message; multiple rows are merged into one
	 * summary message so the recipient gets one notification instead of N.
	 * Notification history is updated to `dispatched` on success.
	 *
	 * @param array<int, \OCA\OpenRegister\Db\QueuedNotification> $queuedRows Rows sharing one (rule_key, recipient) pair.
	 *
	 * @return void
	 */
	public function dispatchQueued(array $queuedRows): void;
}//end interface
