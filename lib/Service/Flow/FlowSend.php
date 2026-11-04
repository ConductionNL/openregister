<?php

/**
 * The state of one flow send step while it runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * One send step over its items: what it sends where, the recipients it
 * resolved, the outcome of every recipient, and the failures that make the
 * step fail.
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
 */
final class FlowSend
{

	/**
	 * The step type id, the rate-limit rule identity.
	 *
	 * @var string
	 */
	public string $stepName = '';

	/**
	 * The config key holding the title or subject template.
	 *
	 * @var string
	 */
	public string $titleKey = 'title';

	/**
	 * The config key holding the body template.
	 *
	 * @var string
	 */
	public string $bodyKey = 'message';

	/**
	 * The acting user the step sends as.
	 *
	 * @var string
	 */
	public string $actor = '';

	/**
	 * Each item with the recipients it resolved, in item order.
	 *
	 * @var array<int|string, FlowSendItem>
	 */
	public array $perItem = [];

	/**
	 * The distinct user recipients, uid => true.
	 *
	 * @var array<string, true>
	 */
	public array $distinct = [];

	/**
	 * The distinct allowed addresses, address => true.
	 *
	 * @var array<string, true>
	 */
	public array $distinctAddresses = [];

	/**
	 * Recipient entries that resolved to no user or group, entry => true.
	 *
	 * @var array<string, true>
	 */
	public array $unknown = [];

	/**
	 * Addresses the step's allowlist refused, each with its reason.
	 *
	 * @var array<int|string, array>
	 */
	public array $refused = [];

	/**
	 * The users whose preference allows the channel, uid => true.
	 *
	 * @var array<string, true>
	 */
	public array $sendable = [];

	/**
	 * The opt-out authority's decisions, by address.
	 *
	 * @var array<string, array>
	 */
	public array $decisions = [];

	/**
	 * The outcome buckets, bucket => recipients.
	 *
	 * @var array<string, array<int, string>>
	 */
	public array $outcomes = [
		'delivered' => [],
		'skippedByPreference' => [],
		'skippedByKillSwitch' => [],
		'rateLimited' => [],
		'failed' => [],
	];

	/**
	 * The failure descriptions; any makes the step fail.
	 *
	 * @var array<int, string>
	 */
	public array $failures = [];

	/**
	 * Constructor.
	 *
	 * @param string $channel The channel: `nc-notification`, `email` or `talk`.
	 * @param array  $config  The step configuration.
	 * @param array  $items   The flow items.
	 * @param array  $context The run context.
	 */
	public function __construct(
		public readonly string $channel,
		public readonly array $config,
		public readonly array $items,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * Whether the step may send to an email address: addresses are an email-channel concept.
	 *
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function acceptsAddresses(): bool {
		return $this->channel === 'email';
	}//end acceptsAddresses()

	/**
	 * Record one outcome.
	 *
	 * @param string $bucket    The outcome bucket.
	 * @param string $recipient The recipient (or conversation token).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function add(string $bucket, string $recipient): void {
		$this->outcomes[$bucket][] = $recipient;
	}//end add()

	/**
	 * Record a send that did not dispatch: a kill-switch skip, else a failure naming the outcome.
	 *
	 * @param string $recipient The recipient not reached.
	 * @param string $outcome   The sender's outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function undelivered(string $recipient, string $outcome): void {
		if ($outcome === 'kill-switch') {
			$this->add(bucket: 'skippedByKillSwitch', recipient: $recipient);
			return;
		}

		$this->add(bucket: 'failed', recipient: $recipient);
		$this->failures[] = sprintf('%s to "%s" failed (%s)', $this->channel, $recipient, $outcome);
	}//end undelivered()

	/**
	 * Record every resolved recipient, users then addresses, in one bucket.
	 *
	 * @param string $bucket The outcome bucket.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function addAll(string $bucket): void {
		foreach (array_merge(array_keys($this->distinct), array_keys($this->distinctAddresses)) as $recipient) {
			$this->add(bucket: $bucket, recipient: (string)$recipient);
		}
	}//end addAll()

	/**
	 * The resolved distinct recipient count, users and addresses.
	 *
	 *
	 * @return int
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function recipientCount(): int {
		return (count($this->distinct) + count($this->distinctAddresses));
	}//end recipientCount()

	/**
	 * Note an item's resolved users, unknown entries, and screened addresses.
	 *
	 * @param array $resolved The resolver's `uids` and `unknown`.
	 * @param array $screened The screen's `allowed` and `refused` addresses.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function note(array $resolved, array $screened): void {
		foreach ($resolved['uids'] as $uid) {
			$this->distinct[$uid] = true;
		}

		foreach (array_keys($screened['allowed']) as $address) {
			$this->distinctAddresses[$address] = true;
		}

		foreach ($resolved['unknown'] as $bad) {
			$this->unknown[$bad] = true;
		}

		foreach ($screened['refused'] as $key => $entry) {
			$this->refused[$key] = $entry;
		}
	}//end note()

	/**
	 * The bounded outcome report for the run log: each bucket's true count and at most `\$sample` entries.
	 *
	 * @param int $sample The most entries a bucket lists.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function report(int $sample): array {
		$report = ['channel' => $this->channel, 'actor' => $this->actor, 'recipients' => $this->recipientCount()];
		$lists = $this->outcomes;
		// Refused is not unknown: the address resolved, and the step's
		// allowlist declined it. Each entry names why.
		$extra = ['unknownRecipients' => array_keys($this->unknown), 'refusedRecipients' => array_values($this->refused)];
		$truncated = false;
		foreach ($lists + array_filter($extra) as $bucket => $entries) {
			$report[$bucket] = ['count' => count($entries), 'sample' => array_slice(array_values($entries), 0, $sample)];
			$truncated = ($truncated || count($entries) > $sample);
		}

		$report['truncated'] = $truncated;
		return $report;
	}//end report()
}//end class
