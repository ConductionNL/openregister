<?php

/**
 * The stored scheduled messages, as the sweep reads and writes them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Where scheduled messages live. The claim is a compare-and-set: it writes
 * only when the row still holds what the sweep read.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */
interface ScheduledMessageStoreInterface {

	/**
	 * The rows a sweep may consider: pending, or claimed (a claim may be stale).
	 *
	 * Each row carries `id`, `state`, `attempts`, `sendAt`, `claimedAt`,
	 * `source`, `path`, `body`, `headers` and `messageId`.
	 *
	 * @param int $limit The most rows to return, oldest first.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function open(int $limit): array;

	/**
	 * Write `$set` on the row only when its id, state and attempt count are still `$expect`.
	 *
	 * @param array{id:string,state:string,attempts:int} $expect What the row must still hold.
	 * @param array<string, mixed>                        $set    The fields to write.
	 *
	 * @return bool True when this call wrote the row.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function compareAndSet(array $expect, array $set): bool;

	/**
	 * Write the outcome of a send on a row this worker holds.
	 *
	 * @param string               $id     The row id.
	 * @param array<string, mixed> $fields `state`, `lastError`, `messageId`, `response`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function record(string $id, array $fields): void;
}//end interface
