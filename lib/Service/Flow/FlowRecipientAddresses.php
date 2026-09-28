<?php

/**
 * Sorts a send step's recipients into users and email addresses, and screens
 * the addresses against the step's `externalRecipients` allowlist.
 *
 * Split out of FlowMessagingService so the service keeps its one job, the
 * guard chain and the send, while the rules for "what counts as an address,
 * and which addresses may be mailed" live in one place. Users resolve
 * through the subsystem's own recipient resolver; this class adds no
 * resolver of its own.
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
 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;

/**
 * Recipient address rules for the flow send nodes.
 *
 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
 */
class FlowRecipientAddresses {

	/**
	 * Constructor.
	 *
	 * @param NotificationRecipientResolver $recipientResolver The subsystem's recipient resolver.
	 */
	public function __construct(
		private readonly NotificationRecipientResolver $recipientResolver,
	) {

	}//end __construct()

	/**
	 * Resolve one literal recipient entry.
	 *
	 * A user id wins, then a group id (expanded), then, on the email channel,
	 * anything holding an `@` is a candidate address. Everything else is
	 * unknown. User first, because a Nextcloud uid may itself look like an
	 * address.
	 *
	 * @param string $entry The trimmed entry.
	 * @param bool $acceptAddresses Whether addresses are candidates (email) or unknowns.
	 *
	 * @return array{uids: array<int, string>, addresses: array<int, array{address: string, name: string}>, unknown: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the channel takes addresses is a
	 * fact about the channel, not a mode of this method's own.
	 *
	 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function resolveLiteral(string $entry, bool $acceptAddresses): array {
		$out = ['uids' => [], 'addresses' => [], 'unknown' => []];

		if ($this->recipientResolver->userExists(uid: $entry) === true) {
			$out['uids'][] = $entry;
			return $out;
		}

		if ($this->recipientResolver->groupExists(gid: $entry) === true) {
			$out['uids'] = array_values(
				$this->recipientResolver->resolve(
					recipientsSpec: [
						[
							'kind' => 'groups',
							'groups' => [$entry],
						],
					],
					data: [],
					object: null,
					context: []
				)
			);
			return $out;
		}

		if ($acceptAddresses === true && str_contains($entry, '@') === true) {
			$out['addresses'][] = ['address' => $entry, 'name' => ''];
			return $out;
		}

		$out['unknown'][] = $entry;
		return $out;
	}//end resolveLiteral()

	/**
	 * Resolve one `{{ field }}` recipient entry against the item.
	 *
	 * The field's value goes through the subsystem's relation reader, every
	 * uid verified; a candidate that is not a user is returned as unknown.
	 * With `$acceptAddresses` (the email channel) its addresses are taken out
	 * first and returned as candidates for the caller to screen.
	 *
	 * @param string $field The field name.
	 * @param array $json The item's json.
	 * @param bool $acceptAddresses Whether addresses are candidates (email) or unknowns.
	 *
	 * @return array{uids: array<int, string>, addresses: array<int, array{address: string, name: string}>, unknown: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Whether the channel takes addresses is a
	 * fact about the channel, not a mode of this method's own.
	 *
	 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-notification-step-reads-role-fields-on-the-item
	 */
	public function resolveTemplate(string $field, array $json, bool $acceptAddresses): array {
		$value = $this->normaliseRoleValue(value: ($json[$field] ?? null));
		$addresses = [];
		if ($acceptAddresses === true) {
			$split = $this->splitAddresses(value: $value);
			$value = $split['rest'];
			$addresses = $split['addresses'];
		}

		$resolved = $this->recipientResolver->resolve(
			recipientsSpec: [
				[
					'kind' => 'relation',
					'relation' => $field,
				],
			],
			data: [$field => $value],
			object: null,
			context: []
		);
		$candidates = $this->recipientResolver->extractUidsFromRelation(value: $value);

		return [
			'uids' => array_values($resolved),
			'addresses' => $addresses,
			'unknown' => array_values(array_diff($candidates, $resolved)),
		];
	}//end resolveTemplate()

	/**
	 * The step's `externalRecipients` mode, defaulting to closed.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return string One of the EXTERNAL_* modes.
	 *
	 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function externalRecipientMode(array $config): string {
		$mode = strtolower(trim((string)($config['externalRecipients'] ?? '')));
		if (in_array($mode, FlowMessagingService::EXTERNAL_RECIPIENT_MODES, true) === true) {
			return $mode;
		}

		// An unrecognised value is refused at save time by the node; a stored
		// one that slipped past falls back to the closed mode, never open.
		return FlowMessagingService::EXTERNAL_NONE;
	}//end externalRecipientMode()

	/**
	 * Apply the step's allowlist to one item's candidate addresses.
	 *
	 * @param array<int, array{address: string, name: string}> $addresses The candidates.
	 * @param array $json The item's json.
	 * @param string $mode The allowlist mode.
	 *
	 * @return array{allowed: array<string, string>, refused: array<string, array{recipient: string, reason: string}>}
	 *
	 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	public function screenAddresses(array $addresses, array $json, string $mode): array {
		$allowed = [];
		$refused = [];
		$onItem = null;

		foreach ($addresses as $candidate) {
			$address = trim($candidate['address']);
			$key = strtolower($address);

			$reason = null;
			if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
				$reason = FlowMessagingService::REFUSED_INVALID_ADDRESS;
			} else if ($mode === FlowMessagingService::EXTERNAL_NONE) {
				$reason = FlowMessagingService::REFUSED_EXTERNAL_OFF;
			} else if ($mode === FlowMessagingService::EXTERNAL_OBJECT) {
				$onItem ??= $this->addressesOnItem(value: $json);
				if (isset($onItem[$key]) === false) {
					$reason = FlowMessagingService::REFUSED_NOT_ON_ITEM;
				}
			}

			if ($reason !== null) {
				$refused[$key . '|' . $reason] = ['recipient' => $address, 'reason' => $reason];
				continue;
			}

			// Keyed case-insensitively, so one person is mailed once per item
			// however many fields spell their address.
			if (isset($allowed[$key]) === false) {
				$allowed[$key] = $candidate['name'];
			}
		}//end foreach

		return ['allowed' => $allowed, 'refused' => $refused];
	}//end screenAddresses()

	/**
	 * Every string on the item that could be an address, normalised.
	 *
	 * @param mixed $value The item's json, or a value inside it.
	 *
	 * @return array<string, true> The normalised strings holding an `@`.
	 */
	private function addressesOnItem(mixed $value): array {
		if (is_string($value) === true) {
			$value = strtolower(trim($value));
			if (str_contains($value, '@') === true) {
				return [$value => true];
			}

			return [];
		}

		if (is_array($value) === false) {
			return [];
		}

		$found = [];
		foreach ($value as $inner) {
			$found += $this->addressesOnItem(value: $inner);
		}

		return $found;
	}//end addressesOnItem()

	/**
	 * Wrap a single role object in a list.
	 *
	 * The relation reader walks a list; handed one object it would walk the
	 * object's VALUES and read a display name as a uid. A field holding one
	 * `{ "uid": ..., "displayName": ... }` is a list of one.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return mixed The value, a single role object wrapped.
	 *
	 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-notification-step-reads-role-fields-on-the-item
	 */
	private function normaliseRoleValue(mixed $value): mixed {
		if (is_array($value) === false || $value === [] || array_is_list($value) === true) {
			return $value;
		}

		foreach (['userId', 'uid', 'user_id', 'email', 'emailAddress'] as $key) {
			if (array_key_exists($key, $value) === true) {
				return [$value];
			}
		}

		return $value;
	}//end normaliseRoleValue()

	/**
	 * Take the addresses out of a field's value, leaving the user entries.
	 *
	 * A string is an address when it holds an `@` and names no user (a uid
	 * may itself look like an address, and a user wins). An object is a
	 * user when it carries `uid` / `userId` / `user_id`, otherwise an address
	 * when it carries `email` / `emailAddress`, the convention the party
	 * model reads.
	 *
	 * @param mixed $value The field's value.
	 *
	 * @return array{rest: mixed, addresses: array<int, array{address: string, name: string}>}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Two entry shapes, each with a user and an address branch.
	 *
	 * @spec openspec/changes/flow-send-email-external-recipients/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function splitAddresses(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return ['rest' => $value, 'addresses' => []];
		}

		$rest = [];
		$addresses = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true) {
				$entry = trim($entry);
				if (str_contains($entry, '@') === true && $this->recipientResolver->userExists(uid: $entry) === false) {
					$addresses[] = ['address' => $entry, 'name' => ''];
					continue;
				}

				$rest[] = $entry;
				continue;
			}

			if (is_array($entry) === true && $this->stringOrNull(value: ($entry['userId'] ?? $entry['uid'] ?? $entry['user_id'] ?? null)) === null) {
				$address = $this->stringOrNull(value: ($entry['email'] ?? $entry['emailAddress'] ?? null));
				if ($address !== null) {
					$addresses[] = [
						'address' => $address,
						'name' => (string)($this->stringOrNull(value: ($entry['name'] ?? $entry['displayName'] ?? null)) ?? ''),
					];
					continue;
				}
			}

			$rest[] = $entry;
		}//end foreach

		return ['rest' => $rest, 'addresses' => $addresses];
	}//end splitAddresses()

	/**
	 * A scalar as a non-empty string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null when empty or not scalar.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
