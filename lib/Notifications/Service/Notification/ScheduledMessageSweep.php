<?php

/**
 * One pass over the scheduled messages: claim what is due, send it once,
 * record what happened.
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

use OCA\OpenRegister\Service\Integration\Providers\MessageDispatcherInterface;
use OCA\OpenRegister\Service\Integration\Providers\OutboundMessage;
use Throwable;

/**
 * The sweep. Every decision is ScheduledMessagePolicy's; this only applies
 * them in order: the policy says whether a row may be claimed, the store
 * performs the claim as a compare-and-set, and only a won claim is sent. A
 * lost claim is another worker's message and is left alone.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
 */
class ScheduledMessageSweep {

	/**
	 * Constructor.
	 *
	 * @param ScheduledMessageStoreInterface $store      The stored messages.
	 * @param ScheduledMessagePolicy         $policy     The rules.
	 * @param MessageDispatcherInterface     $dispatcher The dispatch leaf.
	 */
	public function __construct(
		private readonly ScheduledMessageStoreInterface $store,
		private readonly ScheduledMessagePolicy $policy,
		private readonly MessageDispatcherInterface $dispatcher,
	) {
	}//end __construct()

	/**
	 * Run one bounded pass.
	 *
	 * @param ClaimAttempt $attempt This worker and the moment it runs.
	 *
	 * @return array{sent:int,failed:int,skipped:int} What the pass did.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-the-scheduled-sweep-is-bounded-and-sends-once
	 */
	public function run(ClaimAttempt $attempt): array {
		$tally = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
		foreach ($this->store->open(limit: ScheduledMessagePolicy::SWEEP_LIMIT) as $message) {
			$outcome = $this->handle(message: $message, attempt: $attempt);
			$tally[$outcome]++;
		}

		return $tally;
	}//end run()

	/**
	 * Claim one row and, when the claim is won, send it.
	 *
	 * @param array<string, mixed> $message The row.
	 * @param ClaimAttempt         $attempt This worker and now.
	 *
	 * @return string `sent`, `failed` or `skipped`.
	 */
	private function handle(array $message, ClaimAttempt $attempt): string {
		if ($this->policy->isClaimable(message: $message, now: $attempt->at) === false) {
			return 'skipped';
		}

		$claim = $this->policy->claim(message: $message, attempt: $attempt);
		if ($this->store->compareAndSet(expect: $claim['expect'], set: $claim['set']) === false) {
			return 'skipped';
		}

		return $this->send(message: array_merge($message, $claim['set']));
	}//end handle()

	/**
	 * Send a claimed row and record the outcome.
	 *
	 * @param array<string, mixed> $message The row as claimed.
	 *
	 * @return string `sent` or `failed`.
	 */
	private function send(array $message): string {
		$id = (string)$message['id'];
		try {
			$result = $this->dispatcher->dispatch(
				source: (string)($message['source'] ?? ''),
				message: new OutboundMessage(
					path: (string)($message['path'] ?? ''),
					body: (array)($message['body'] ?? []),
					headers: (array)($message['headers'] ?? [])
				)
			);
		} catch (Throwable $e) {
			$result = ['unavailable' => true, 'cause' => 'dispatch failed'];
		}

		if (($result['status'] ?? null) === 'sent') {
			$this->store->record(
				id: $id,
				fields: $this->policy->afterSuccess(messageId: (string)($message['messageId'] ?? '')) + ['response' => ($result['response'] ?? null)]
			);
			return 'sent';
		}

		$this->store->record(id: $id, fields: $this->policy->afterFailure(message: $message, error: (string)($result['cause'] ?? 'not sent')));

		return 'failed';
	}//end send()
}//end class
