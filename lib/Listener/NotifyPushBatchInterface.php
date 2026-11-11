<?php

/**
 * NotifyPushBatchInterface: the bulk-import batch mode of the notify_push listener.
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
 * What the import path asks of the notify_push batch: switch it on and off,
 * add a (register, schema) pair, and flush one collection event per pair.
 *
 * @spec openspec/changes/add-live-updates/tasks.md#task-4
 */
interface NotifyPushBatchInterface {

	/**
	 * Enable or disable batch mode.
	 *
	 * @param bool $enabled True to enable batch mode, false to disable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-live-updates/tasks.md#task-4
	 */
	public function setBatchMode(bool $enabled): void;

	/**
	 * Whether any (register, schema) pairs have been accumulated in batch mode.
	 *
	 * @return bool True when at least one collection event is pending flush.
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public function hasBatchedCollections(): bool;

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
	public function addBatchedCollection(string $registerSlug, string $schemaSlug): void;

	/**
	 * Emit one collection event per accumulated (register, schema) pair and clear state.
	 *
	 * @param object $queue Resolved IQueue instance.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	public function flushBatch(object $queue): void;
}//end interface
