<?php

/**
 * NotifyPushBatch: the bulk-import batch entry points of the notify_push listener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

/**
 * The batch mode of NotifyPushListener, as the import path drives it.
 *
 * A bulk import suppresses the per-object pushes and emits one collection
 * event per (register, schema) pair when it is done. The state stays on
 * NotifyPushListener, whose handle() reads it; this class is the entry the
 * import path calls, so that path does not name the listener itself.
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-4
 */
final class NotifyPushBatch {

	/**
	 * Enable or disable batch mode.
	 *
	 * @param bool $enabled True to enable batch mode, false to disable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-4
	 */
	public static function setBatchMode(bool $enabled): void {
		NotifyPushListener::setBatchMode(enabled: $enabled);
	}//end setBatchMode()

	/**
	 * Whether any (register, schema) pairs have been accumulated in batch mode.
	 *
	 * @return bool True when at least one collection event is pending flush.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public static function hasBatchedCollections(): bool {
		return NotifyPushListener::hasBatchedCollections();
	}//end hasBatchedCollections()

	/**
	 * Accumulate a (register-slug, schema-slug) pair directly, bypassing event dispatch.
	 *
	 * @param string $registerSlug The register slug (empty = ignored).
	 * @param string $schemaSlug   The schema slug (empty = ignored).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public static function addBatchedCollection(string $registerSlug, string $schemaSlug): void {
		NotifyPushListener::addBatchedCollection(registerSlug: $registerSlug, schemaSlug: $schemaSlug);
	}//end addBatchedCollection()

	/**
	 * Emit one collection event per accumulated (register, schema) pair and clear state.
	 *
	 * @param object $queue Resolved IQueue instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public static function flushBatch(object $queue): void {
		NotifyPushListener::flushBatch(queue: $queue);
	}//end flushBatch()
}//end class
